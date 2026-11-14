<?php
/**
 * Purchases: a sub-pipeline under Pipeline. Reads a Zoho shared mailbox (e.g. "shop") that holds purchase emails (Lowe's, Home
 * Depot, city utility payments), groups them into orders (one order = several emails), and records vendor, order/invoice number,
 * the property tag from the subject ("PO#Ardale"), date and totals. Staff move each order through stages:
 *   new -> verified (amount and property confirmed) -> charged (billed to the property) -> posted (recorded in Rentec, later)
 * and `ignored` for non-purchases.
 *
 * Mail access is AJCore's Zoho connection (AJCore_Zoho_Mail): same login, any shared mailbox it belongs to, optionally narrowed
 * by Zoho labels and subject/sender terms. Orders live in their own tables (not the customer-filing log).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Purchases {

	const OPTION = 'ajcore_ra_purchases';
	const CRON   = 'ajcore_ra_purchases_pass';
	const STAGES = array( 'new', 'verified', 'charged', 'posted', 'ignored' );

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init() {
		add_filter( 'ajcore_pipeline_views', array( __CLASS__, 'register_view' ) );
		add_filter( 'ajcore_ops_modules', array( __CLASS__, 'ops_module' ) );
		add_filter( 'ajcore_ops_sections', array( __CLASS__, 'ops_section' ) );
		add_filter( 'ajcore_ops_route_sections', array( __CLASS__, 'ops_route_section' ) );
		add_filter( 'ajcore_ops_counts', array( __CLASS__, 'ops_counts' ) );
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'boot_routes' ) );
		add_action( 'admin_post_ajcore_ra_purchases_save', array( __CLASS__, 'handle_save' ) );
		add_action( self::CRON, array( __CLASS__, 'cron_pass' ) );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_event( time() + 900, 'hourly', self::CRON );
			}
		} );
	}

	// ── registration with AJCore ────────────────────────────────────────────────

	public static function register_view( $views ) {
		$views['purchases'] = array(
			'label'  => __( 'Purchases', 'ajcore-ra' ),
			'badge'  => array( __CLASS__, 'needs_verification_count' ),
			'render' => array( __CLASS__, 'render_view' ),
		);
		return $views;
	}

	public static function ops_module( $modules ) {
		$modules   = (array) $modules;
		$modules[] = 'purchases';
		return $modules;
	}

	public static function ops_section( $sections ) {
		$sections              = (array) $sections;
		$sections['purchases'] = 'Purchases';
		return $sections;
	}

	public static function ops_route_section( $map ) {
		$map              = (array) $map;
		$map['purchases'] = 'purchases';
		return $map;
	}

	public static function ops_counts( $counts ) {
		try {
			$n                   = self::needs_verification_count();
			$counts['purchases'] = array( 'value' => $n, 'label' => 'Needs verification', 'alert' => $n > 0 );
		} catch ( \Throwable $e ) {
			// Counts are optional; never break the menu over them.
		}
		return $counts;
	}

	public static function needs_verification_count() {
		$self = self::instance();
		if ( ! $self->ensure_tables() ) {
			return 0;
		}
		$pdb = $self->pdb();
		return (int) $pdb->get_var( "SELECT COUNT(*) FROM `{$self->table( 'aj_purchase_orders' )}` WHERE label = '' AND stage = 'new' AND ( total IS NULL OR property_tag = '' )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── database ────────────────────────────────────────────────────────────────

	private function kit() {
		return class_exists( 'AJCore_Extensions' ) ? AJCore_Extensions::rest_toolkit() : array();
	}

	private function pdb() {
		$kit = $this->kit();
		return $kit['portal_db']();
	}

	private function table( $suffix ) {
		return $this->pdb()->prefix . $suffix;
	}

	/** Creates the tables on first use (portal/shared DB, like the Pipeline log). */
	public function ensure_tables() {
		static $done = null;
		if ( null !== $done ) {
			return $done;
		}
		$pdb     = $this->pdb();
		$kit     = $this->kit();
		$orders  = $this->table( 'aj_purchase_orders' );
		$emails  = $this->table( 'aj_purchase_emails' );
		$props   = $this->table( 'aj_purchase_properties' );
		if ( $kit['table_exists']( $pdb, $orders ) && $kit['table_exists']( $pdb, $emails ) && $kit['table_exists']( $pdb, $props ) ) {
			if ( ! $pdb->get_var( "SHOW COLUMNS FROM `{$orders}` LIKE 'label'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pdb->query( "ALTER TABLE `{$orders}` ADD COLUMN label varchar(16) NOT NULL DEFAULT '' AFTER stage" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return $done = true;
		}
		$charset = $pdb->get_charset_collate();
		$pdb->query( "CREATE TABLE IF NOT EXISTS `{$orders}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			vendor varchar(60) NOT NULL DEFAULT '',
			order_no varchar(80) NOT NULL DEFAULT '',
			invoice_no varchar(80) NOT NULL DEFAULT '',
			property_tag varchar(120) NOT NULL DEFAULT '',
			property_name varchar(190) NOT NULL DEFAULT '',
			label varchar(16) NOT NULL DEFAULT '',
			order_date datetime DEFAULT NULL,
			subtotal decimal(12,2) DEFAULT NULL,
			tax decimal(12,2) DEFAULT NULL,
			total decimal(12,2) DEFAULT NULL,
			stage varchar(20) NOT NULL DEFAULT 'new',
			confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
			notes text,
			rentec_expense_id varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY vendor_order (vendor, order_no),
			KEY stage (stage),
			KEY property_tag (property_tag),
			KEY order_date (order_date)
		) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pdb->query( "CREATE TABLE IF NOT EXISTS `{$emails}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id varchar(120) NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(24) NOT NULL DEFAULT '',
			subject varchar(500) NOT NULL DEFAULT '',
			sender varchar(255) NOT NULL DEFAULT '',
			received_at datetime DEFAULT NULL,
			parsed longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY message_id (message_id),
			KEY order_id (order_id)
		) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pdb->query( "CREATE TABLE IF NOT EXISTS `{$props}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tag varchar(120) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			rentec_property_id varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY tag (tag)
		) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $done = $kit['table_exists']( $pdb, $orders ) && $kit['table_exists']( $pdb, $emails ) && $kit['table_exists']( $pdb, $props );
	}

	// ── settings ────────────────────────────────────────────────────────────────

	public function settings() {
		$opt = get_option( self::OPTION, array() );
		$opt = is_array( $opt ) ? $opt : array();
		return array_merge( array(
			'mailbox'  => '',                 // shared mailbox address
			'labels'   => array(),            // Zoho label ids (narrow to these); empty = every message
			'subject'  => '',                 // comma-separated terms
			'sender'   => '',
			'match'    => 'all',              // all|any across labels? no: labels narrow; subject/sender follow this
			'enabled'  => '1',
			'cursor'   => 0,                  // first page offset that may still hold unseen mail
			'last_run' => array(),
		), $opt );
	}

	private function save_settings( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_purchases_save' );
		$self = self::instance();
		$s    = $self->settings();
		$s['mailbox'] = isset( $_POST['mailbox'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['mailbox'] ) ) ) : '';
		$s['labels']  = isset( $_POST['labels'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['labels'] ) ) ) ) : array();
		$s['subject'] = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$s['sender']  = isset( $_POST['sender'] ) ? sanitize_text_field( wp_unslash( $_POST['sender'] ) ) : '';
		$s['match']   = ( isset( $_POST['match'] ) && 'any' === $_POST['match'] ) ? 'any' : 'all';
		$s['enabled'] = ! empty( $_POST['enabled'] ) ? '1' : '0';
		$s['cursor']  = 0; // selection changed: re-scan from the newest mail
		$self->save_settings( $s );
		wp_safe_redirect( AJCore_Pipeline_Tab::view_url( 'purchases', array( 'saved' => 1 ) ) );
		exit;
	}

	// ── classify + extract (pure functions, no I/O) ─────────────────────────────

	/** "Name <a@b.com>" or a plain address => the lowercase address. */
	public static function address_of( $sender ) {
		if ( preg_match( '/<([^>]+)>/', (string) $sender, $m ) ) {
			return strtolower( trim( $m[1] ) );
		}
		return strtolower( trim( (string) $sender ) );
	}

	/**
	 * Vendor and kind of an email from its sender and subject: kind = receipt | confirmation | status | statement | payment |
	 * reminder | other. Only receipt / confirmation / statement / payment carry amounts.
	 */
	public static function classify( $sender, $subject ) {
		$addr    = self::address_of( $sender );
		$domain  = strpos( $addr, '@' ) !== false ? substr( $addr, strrpos( $addr, '@' ) + 1 ) : $addr;
		$subject = (string) $subject;
		if ( false !== strpos( $domain, 'lowes.com' ) ) {
			$kind = 'status';
			if ( 0 === strpos( $domain, 'receipt.' ) ) {
				$kind = 'receipt';
			} elseif ( 0 === strpos( $domain, 'confirmation.' ) ) {
				$kind = 'confirmation';
			} elseif ( preg_match( '/receipt/i', $subject ) ) {
				$kind = 'receipt';
			}
			if ( preg_match( '/earned points|rewards|\bquote\b|survey|feedback/i', $subject ) && ! preg_match( '/receipt|order\s*#|#\s*\d{8,}/i', $subject ) ) {
				$kind = 'noise';
			}
			return array( 'vendor' => "Lowe's", 'kind' => $kind );
		}
		if ( false !== strpos( $domain, 'homedepot.com' ) ) {
			if ( preg_match( '/feedback|survey|would love|perk|offer|reward|unlocked|savings|deals|sale\b/i', $subject ) && ! preg_match( '/receipt|statement|order\s*#/i', $subject ) ) {
				return array( 'vendor' => 'Home Depot', 'kind' => 'noise' ); // surveys and marketing: not purchases
			}
			$kind = preg_match( '/statement/i', $subject ) ? 'noise' : ( preg_match( '/receipt|invoice|order/i', $subject ) ? 'receipt' : 'status' ); // the Pro Xtra statement mail only links to the statement
			return array( 'vendor' => 'Home Depot', 'kind' => $kind );
		}
		if ( false !== strpos( $domain, 'flooranddecor.com' ) ) {
			return array( 'vendor' => 'Floor & Decor', 'kind' => preg_match( '/purchase|order|receipt|invoice/i', $subject ) ? 'confirmation' : 'noise' );
		}
		if ( false !== strpos( $domain, 'terminix' ) ) {
			return array( 'vendor' => 'Terminix', 'kind' => 'status' );
		}
		if ( false !== strpos( $domain, 'invoicecloud.net' ) ) {
			$vendor = preg_match( '/^\s*(?:re:\s*)?(.+?)\s+Invoice#/i', $subject, $m ) ? trim( $m[1] ) : 'Invoice Cloud';
			$kind   = preg_match( '/payment confirmation/i', $subject ) ? 'payment' : ( preg_match( '/reminder/i', $subject ) ? 'reminder' : 'other' );
			return array( 'vendor' => $vendor, 'kind' => $kind );
		}
		return array( 'vendor' => '' !== $domain ? $domain : 'Unknown', 'kind' => 'other' );
	}

	/** The property tag from "PO#Ardale - ..." / "PO # 215 Orchard: ..." in the subject, else from the body. */
	public static function property_tag( $subject, $body = '' ) {
		// Lowe's receipts: "Customer PO #" / "Customer Code" followed by the value (often on the next line).
		if ( preg_match( '/Customer\s+(?:PO\s*#|Code)\s*:?\s*([^\r\n]{2,60})/i', (string) $body, $cm ) && preg_match( '/[A-Za-z0-9]/', $cm[1] ) ) {
			return trim( preg_replace( '/\s+/', ' ', $cm[1] ) );
		}
		foreach ( array( (string) $subject, (string) $body ) as $text ) {
			if ( preg_match( '/\bPO\s*#\s*([^\r\n\-–—:|]{1,80}?)\s*(?:[\-–—:|]|\r|\n|$)/u', $text, $m ) ) {
				$tag = trim( preg_replace( '/\s+/', ' ', $m[1] ) );
				if ( '' !== $tag ) {
					return $tag;
				}
			}
		}
		return '';
	}

	/**
	 * The delivery street address: the first street line (starts with a number) after a Deliver/Ship/Delivery label.
	 * Property = this when present; the PO# tag is the fallback.
	 */
	public static function delivery_address( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/<br\s*\/?>|<\/(?:p|div|tr|td|li)>/i', "\n", $text );
		$text = trim( preg_replace( '/[ \t\x{00a0}]+/u', ' ', wp_strip_all_tags( $text ) ) );
		$lines = preg_split( '/\R/u', $text );
		foreach ( $lines as $i => $line ) {
			if ( ! preg_match( '/\b(?:deliver(?:y|ed)?(?:\s+(?:to|address|information|location))?|ship(?:ping)?\s+(?:to|address))\b\s*:?\s*(.*)$/i', $line, $m ) ) {
				continue;
			}
			$window = array_merge( array( trim( $m[1] ) ), array_slice( array_map( 'trim', $lines ), $i + 1, 4 ) );
			foreach ( $window as $cand ) {
				if ( preg_match( '/^(\d{1,6}\s+[A-Za-z0-9][A-Za-z0-9 .\'#\-]{2,60}?)(?:\s*,|\s*$|\s+(?:Salisbury|[A-Z]{2}\s+\d{5}))/u', $cand, $a ) ) {
					return trim( preg_replace( '/\s+/', ' ', $a[1] ) );
				}
			}
		}
		return '';
	}

	public static function parse_money( $s ) {
		$s = str_replace( array( ',', '$', ' ' ), '', (string) $s );
		return is_numeric( $s ) ? round( (float) $s, 2 ) : null;
	}

	/** First amount that follows one of the labels (in priority order), or null. */
	private static function amount_after( $text, array $labels ) {
		foreach ( $labels as $label ) {
			// The label must be followed straight by the amount (spaces, a colon or a line break in between), so "Total Tax" or
			// "Total Savings" never count as "Total". The last match wins: item lines ("Subtotal: $19.34") come before the real totals.
			if ( preg_match_all( '/(?<![\\w])' . $label . '(?![\\w])\\s*:?\\s*\\$\\s*(-?[\\d,]+\\.\\d{2})/i', $text, $m ) && ! empty( $m[1] ) ) {
				return self::parse_money( end( $m[1] ) );
			}
		}
		return null;
	}

	/**
	 * Fields found in one email: order_no, invoice_no, property_tag, subtotal, tax, total. $kind decides whether to look for money.
	 * Everything is best-effort; what isn't found stays empty/null and the order is flagged for verification.
	 */
	public static function extract( $kind, $subject, $body ) {
		$text = html_entity_decode( (string) $body, ENT_QUOTES, 'UTF-8' );
		$text = str_replace( "\xc2\xa0", ' ', $text );
		$text = preg_replace( '/[\x{034F}\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00AD}]/u', '', (string) $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$out  = array( 'order_no' => '', 'invoice_no' => '', 'property_src' => '', 'property_tag' => '', 'subtotal' => null, 'tax' => null, 'total' => null );

		// Property: delivery address in the email first, the PO# tag as the fallback.
		$addr = self::delivery_address( $body );
		$out['property_src'] = '' !== $addr ? 'address' : '';
		$out['property_tag'] = '' !== $addr ? $addr : self::property_tag( $subject, $text );
		if ( '' === $addr && '' !== $out['property_tag'] ) {
			$out['property_src'] = 'po';
		}

		foreach ( array( (string) $subject, $text ) as $hay ) {
			if ( '' === $out['order_no'] && preg_match( '/\border\s*(?:number|no\.?|#)?\s*:?\s*#?\s*(\d{8,})/i', $hay, $m ) ) {
				$out['order_no'] = $m[1];
			}
			if ( '' === $out['invoice_no'] && preg_match( '/\binvoice\s*(?:number|no\.?|#)?\s*:?\s*#?\s*(\d[0-9A-Za-z\-]{2,})/i', $hay, $m ) ) {
				$out['invoice_no'] = $m[1];
			}
		}
		if ( '' === $out['order_no'] ) {
			if ( preg_match( '/\b(?:receipt|order)\s+(?:for\s+)?#\s*([A-Z]\d{3,5}-\d{4,})/i', $subject . ' ' . $text, $m ) ) {
				$out['order_no'] = strtoupper( $m[1] ); // Home Depot: "receipt for # H3603-366250"
			} elseif ( preg_match( '/\([^;()]{3,60};\s*(\d{10,})\)/', $text, $m ) ) {
				$out['order_no'] = $m[1]; // Floor & Decor: "(958 Plaza Walk; 1017905613017483)"
			} elseif ( preg_match( '/\border\s+([A-Z]{2}\d{10,})\b/i', $text, $m ) ) {
				$out['order_no'] = $m[1]; // Floor & Decor pickup mails: "order SB017905613168667 for 958 plaza wall"
			}
		}
		if ( '' === $out['property_tag'] && preg_match( '/\border\s+[A-Z]{2}\d{10,}\s+for\s+(\d{1,6}\s+[A-Za-z][A-Za-z .\']{2,30}?)(?=\s+(?:have|has|is|are)\b|[.,\n]|$)/i', $text, $m ) ) {
			$out['property_tag'] = trim( $m[1] );
			$out['property_src'] = 'address';
		}
		if ( '' === $out['property_tag'] && preg_match( '/\(\s*(\d{1,6}\s+[A-Za-z][A-Za-z0-9 .\']{2,40}?);\s*\d{8,}\)/', $text, $m ) ) {
			$out['property_tag'] = trim( $m[1] );
			$out['property_src'] = 'address';
		}
		if ( '' === $out['property_tag'] && preg_match( '/\binvoice\s*(?:#|number|no\.?)\s*:?\s*(\d{6,8})-\d{4,}/i', $subject . ' ' . $text, $m ) ) {
			$out['property_tag'] = 'City account ' . $m[1]; // utility bills carry no address: name this tag once and every bill of the account follows
			$out['property_src'] = 'account';
		}
		if ( in_array( $kind, array( 'receipt', 'confirmation', 'statement', 'payment' ), true ) ) {
			$out['total']    = self::amount_after( $text, array( 'Order\s+Total', 'Grand\s+Total', 'Total\s+Amount', 'Amount\s+Paid', 'Payment\s+Amount', 'Total\s+Paid', 'Total' ) );
			$out['subtotal'] = self::amount_after( $text, array( 'Sub\s*total', 'Merchandise' ) );
			$out['tax']      = self::amount_after( $text, array( 'Total\s+Tax', 'Sales\s+Tax', 'Tax' ) );
			if ( null === $out['total'] && preg_match( '/\b(?:purchase|payment|charge[d]?)\s+(?:of|for)\s+\$\s*([\d,]+\.\d{2})/i', $text, $m ) ) {
				$out['total'] = self::parse_money( $m[1] ); // Floor & Decor: "for a purchase of $380.90"
			}
			if ( null === $out['total'] && 'payment' === $kind && preg_match( '/payment[^$\r\n]{0,60}\$\s*([\d,]+\.\d{2})/i', $text, $m ) ) {
				$out['total'] = self::parse_money( $m[1] );
			}
		}
		return $out;
	}

	// ── ingestion ───────────────────────────────────────────────────────────────

	private function zoho() {
		return class_exists( 'AJCore_Zoho_Mail' ) ? AJCore_Zoho_Mail::instance() : null;
	}

	/** Does a listed message pass the subject/sender terms? Labels are applied by listing messages per label instead. */
	private function passes_terms( array $row, array $s ) {
		$subject_terms = AJCore_Pipeline::split_terms( $s['subject'] );
		$sender_terms  = AJCore_Pipeline::split_terms( $s['sender'] );
		if ( ! $subject_terms && ! $sender_terms ) {
			return true;
		}
		$hits   = array();
		$hay_s  = strtolower( $row['subject'] );
		$hay_f  = strtolower( $row['from_name'] . ' ' . $row['from_email'] );
		foreach ( array( array( $subject_terms, $hay_s ), array( $sender_terms, $hay_f ) ) as $field ) {
			if ( ! $field[0] ) {
				continue;
			}
			$hit = false;
			foreach ( $field[0] as $term ) {
				if ( false !== strpos( $field[1], strtolower( $term ) ) ) {
					$hit = true;
					break;
				}
			}
			$hits[] = $hit;
		}
		return 'any' === $s['match'] ? in_array( true, $hits, true ) : ! in_array( false, $hits, true );
	}

	/** One page of candidate message rows (label-aware), or WP_Error. */
	private function list_page( array $s, $start ) {
		$zoho = $this->zoho();
		if ( ! empty( $s['labels'] ) ) {
			// Several labels: a message with any of them (union), de-duplicated by id.
			$rows = array();
			foreach ( $s['labels'] as $label ) {
				$part = $zoho->get_messages_by_label( $label, $s['mailbox'], $start, 50 );
				if ( is_wp_error( $part ) ) {
					return $part;
				}
				foreach ( $part as $row ) {
					$rows[ $row['message_id'] ] = $row;
				}
			}
			return array_values( $rows );
		}
		return $zoho->get_recent_messages( 50, 'all', $start, $s['mailbox'] );
	}

	/**
	 * One pass: scan the newest page and the not-yet-caught-up pages, process up to $max_new unseen messages. The cursor moves past
	 * pages that are fully known, so repeated passes (cron, Refresh) walk the whole history and then only look at new mail.
	 * Returns a summary array or WP_Error.
	 */
	public function run_pass( $max_new = 30 ) {
		$zoho = $this->zoho();
		$s    = $this->settings();
		if ( ! $zoho || ! $zoho->is_connected() ) {
			return new WP_Error( 'zoho_not_connected', __( 'Zoho is not connected.', 'ajcore-ra' ) );
		}
		if ( '' === $s['mailbox'] ) {
			return new WP_Error( 'no_mailbox', __( 'Pick the shared mailbox first.', 'ajcore-ra' ) );
		}
		if ( ! $this->ensure_tables() ) {
			return new WP_Error( 'no_table', __( 'Purchases tables could not be created.', 'ajcore-ra' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$pdb     = $this->pdb();
		$emails  = $this->table( 'aj_purchase_emails' );
		$summary = array( 'scanned' => 0, 'new' => 0, 'orders_touched' => 0, 'errors' => array(), 'complete' => false );
		$touched = array();
		$cursor  = max( 0, (int) $s['cursor'] );
		$pages   = array_unique( array( 0, $cursor ) );
		$guard   = 0;

		while ( $pages && $summary['new'] < $max_new && $guard++ < 40 ) {
			$start = (int) array_shift( $pages );
			$rows  = $this->list_page( $s, $start );
			if ( is_wp_error( $rows ) ) {
				$summary['errors'][] = $rows->get_error_message();
				break;
			}
			$summary['scanned'] += count( $rows );
			$ids   = wp_list_pluck( $rows, 'message_id' );
			$known = array();
			if ( $ids ) {
				$ph = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$known = array_flip( (array) $pdb->get_col( $pdb->prepare( "SELECT message_id FROM `{$emails}` WHERE message_id IN ({$ph})", $ids ) ) );
			}
			foreach ( $rows as $row ) {
				if ( isset( $known[ $row['message_id'] ] ) || ! $this->passes_terms( $row, $s ) ) {
					continue;
				}
				if ( $summary['new'] >= $max_new ) {
					break;
				}
				$order_id = $this->process_message( $row, $s['mailbox'] );
				if ( is_wp_error( $order_id ) ) {
					$summary['errors'][] = $order_id->get_error_message();
					continue;
				}
				$summary['new']++;
				$touched[ (int) $order_id ] = true;
				if ( class_exists( 'AJCore_Marketing' ) && AJCore_Marketing::is_marketing_sender( trim( $row['from_name'] . ' <' . $row['from_email'] . '>' ) ) ) {
					$pdb->query( $pdb->prepare( "UPDATE `{$this->table( 'aj_purchase_orders' )}` SET label = 'marketing' WHERE id = %d AND stage = 'new' AND label = ''", (int) $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			$all_known = true;
			foreach ( $rows as $row ) {
				if ( ! isset( $known[ $row['message_id'] ] ) && $this->passes_terms( $row, $s ) ) {
					$all_known = false;
					break;
				}
			}
			if ( count( $rows ) < 50 ) {
				$summary['complete'] = $start >= $cursor; // reached the end of the mailbox
				if ( $start >= $cursor ) {
					$cursor = 0;
				}
				break;
			}
			if ( $start === $cursor && $all_known ) {
				$cursor = $start + 50; // this page is fully caught up: next pass starts after it
				$pages[] = $cursor;
			}
		}
		$summary['orders_touched'] = count( $touched );
		$s['cursor']   = $cursor;
		$s['last_run'] = array( 'at' => current_time( 'mysql' ), 'new' => $summary['new'], 'scanned' => $summary['scanned'], 'errors' => $summary['errors'] );
		$this->save_settings( $s );
		return $summary;
	}

	/**
	 * Re-runs the field extraction on emails already read (after the parser was improved), for orders still in "new".
	 * Uses the stored email text; emails read before the text was kept are fetched again (up to $max_fetch per call).
	 * Orders left without any email afterwards (their key changed) are removed.
	 */
	public function reparse( $max_fetch = 25 ) {
		if ( ! $this->ensure_tables() ) {
			return new WP_Error( 'no_table', __( 'Purchases tables could not be created.', 'ajcore-ra' ) );
		}
		$pdb     = $this->pdb();
		$emails  = $this->table( 'aj_purchase_emails' );
		$orders  = $this->table( 'aj_purchase_orders' );
		$rows    = (array) $pdb->get_results( "SELECT e.* FROM `{$emails}` e JOIN `{$orders}` o ON o.id = e.order_id WHERE o.stage = 'new' ORDER BY e.id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$zoho    = $this->zoho();
		$mailbox = $this->settings()['mailbox'];
		$done    = 0;
		$fetched = 0;
		$left    = 0;
		foreach ( $rows as $e ) {
			$parsed = json_decode( (string) $e->parsed, true );
			$parsed = is_array( $parsed ) ? $parsed : array();
			$cls    = isset( $parsed['classify'] ) && is_array( $parsed['classify'] ) ? $parsed['classify'] : self::classify( (string) $e->sender, (string) $e->subject );
			// Every email's text is read (status mails like "delivery scheduled" carry the order and invoice number too).
			if ( isset( $parsed['bv'] ) && (int) $parsed['bv'] >= 3 && empty( $parsed['body_debug'] ) ) {
				$body = (string) ( $parsed['body'] ?? '' );
			} elseif ( $fetched < $max_fetch && $zoho ) {
				$m = $zoho->intake_message( (string) $e->message_id, $mailbox );
				$fetched++;
				if ( is_wp_error( $m ) ) {
					continue;
				}
				$body                 = (string) $m['body_text'];
				$parsed['body']       = mb_substr( $body, 0, 20000 );
				$parsed['body_debug'] = (string) ( $m['body_debug'] ?? '' );
				$parsed['html']       = (string) ( $m['body_html'] ?? '' );
				$parsed['bv']         = 3;
			} else {
				$left++;
				continue;
			}
			$fields = self::extract( $cls['kind'], (string) $e->subject, $body );
			if ( 'address' !== $fields['property_src'] ) {
				$found = $this->property_from_text( $body );
				if ( '' !== $found ) {
					$fields['property_tag'] = $found;
					$fields['property_src'] = 'text';
				}
			}
			$parsed['classify'] = $cls;
			$parsed['fields']   = $fields;
			$row                = array( 'message_id' => (string) $e->message_id, 'subject' => (string) $e->subject, 'from_name' => '', 'from_email' => '', 'received_at' => (string) $e->received_at );
			$oid                = $this->upsert_order( $cls, $fields, (string) $e->received_at, $row );
			if ( $oid ) {
				$pdb->update( $emails, array( 'order_id' => $oid, 'parsed' => wp_json_encode( $parsed ) ), array( 'id' => (int) $e->id ) );
				if ( 'noise' === $cls['kind'] ) { // surveys / marketing: not purchases
					$pdb->query( $pdb->prepare( "UPDATE `{$orders}` SET stage = 'ignored' WHERE id = %d AND stage = 'new' AND order_no LIKE %s", $oid, 'msg:%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				$done++;
			}
		}
		// Orders in "new" that no longer have an email (merged into another order).
		$pdb->query( "DELETE o FROM `{$orders}` o LEFT JOIN `{$emails}` e ON e.order_id = o.id WHERE o.stage = 'new' AND e.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( 'reparsed' => $done, 'fetched' => $fetched, 'remaining' => $left );
	}

	/** Set or clear the Marketing label on orders. Body: ids (order ids), label ('marketing' | '' to clear), sender_rule ('' | 'address' | 'domain'). */
	public function rest_marketing( WP_REST_Request $request ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
		if ( ! $ids || ! $this->ensure_tables() ) {
			return new WP_Error( 'bad_request', __( 'Select orders first.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$label  = 'marketing' === sanitize_key( (string) $request->get_param( 'label' ) ) ? 'marketing' : '';
		$rule   = sanitize_key( (string) $request->get_param( 'sender_rule' ) );
		$pdb    = $this->pdb();
		$orders = $this->table( 'aj_purchase_orders' );
		$ph     = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		if ( 'marketing' === $label && in_array( $rule, array( 'address', 'domain' ), true ) && class_exists( 'AJCore_Marketing' ) ) {
			foreach ( (array) $pdb->get_col( $pdb->prepare( "SELECT DISTINCT sender FROM `{$this->table( 'aj_purchase_emails' )}` WHERE order_id IN ({$ph})", $ids ) ) as $sender ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				AJCore_Marketing::add_sender( (string) $sender, 'domain' === $rule );
			}
		}
		$n = $pdb->query( $pdb->prepare( "UPDATE `{$orders}` SET label = %s, updated_at = %s WHERE label <> 'deleted' AND id IN ({$ph})", array_merge( array( $label, current_time( 'mysql' ) ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return rest_ensure_response( array( 'updated' => (int) $n, 'label' => $label ) );
	}

	/** Delete marketing orders: every email goes to the Zoho Trash (recoverable there); the orders stay as hidden records so the next read does not bring them back. */
	public function rest_delete_marketing( WP_REST_Request $request ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
		if ( ! $ids || ! $this->ensure_tables() ) {
			return new WP_Error( 'bad_request', __( 'Select orders first.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$pdb     = $this->pdb();
		$orders  = $this->table( 'aj_purchase_orders' );
		$emails  = $this->table( 'aj_purchase_emails' );
		$ph      = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$oids    = array_map( 'intval', (array) $pdb->get_col( $pdb->prepare( "SELECT id FROM `{$orders}` WHERE label = 'marketing' AND id IN ({$ph})", $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$zoho    = $this->zoho();
		$mailbox = $this->settings()['mailbox'];
		$errors  = array();
		if ( $oids ) {
			$ph2 = implode( ', ', array_fill( 0, count( $oids ), '%d' ) );
			foreach ( (array) $pdb->get_col( $pdb->prepare( "SELECT message_id FROM `{$emails}` WHERE order_id IN ({$ph2})", $oids ) ) as $mid ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $zoho && '' !== $mailbox ) {
					$res = $zoho->trash_message( (string) $mid, $mailbox );
					if ( is_wp_error( $res ) ) {
						$errors[] = $res->get_error_message();
					}
				}
			}
			$pdb->query( $pdb->prepare( "UPDATE `{$orders}` SET label = 'deleted', updated_at = %s WHERE id IN ({$ph2})", array_merge( array( current_time( 'mysql' ) ), $oids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return rest_ensure_response( array( 'deleted' => count( $oids ), 'zoho_errors' => array_values( array_unique( $errors ) ) ) );
	}

	public function rest_senders( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Marketing' ) ) {
			return rest_ensure_response( array( 'senders' => array() ) );
		}
		$add = strtolower( trim( (string) $request->get_param( 'add' ) ) );
		$rem = strtolower( trim( (string) $request->get_param( 'remove' ) ) );
		if ( '' !== $add && preg_match( '/^[a-z0-9@._\-]+$/', $add ) ) {
			AJCore_Marketing::add_sender( false === strpos( $add, '@' ) ? 'x@' . $add : $add, false === strpos( $add, '@' ) );
		}
		if ( '' !== $rem ) {
			AJCore_Marketing::remove_sender( $rem );
		}
		return rest_ensure_response( array( 'senders' => AJCore_Marketing::senders() ) );
	}

	public function rest_reparse() {
		$r = $this->reparse();
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function cron_pass() {
		$self = self::instance();
		$s    = $self->settings();
		if ( '1' === $s['enabled'] && '' !== $s['mailbox'] ) {
			$self->run_pass( 40 );
		}
	}

	/** Reads one message, classifies it, and records the email and its order. Returns the order id or WP_Error. */
	private function process_message( array $row, $mailbox ) {
		$zoho = $this->zoho();
		$cls  = self::classify( $row['from_email'] ? $row['from_email'] : $row['from_name'], $row['subject'] );
		$body = '';
		$ts   = null;
		$bdbg = '';
		$bhtml = '';
		if ( true ) {
			$m = $zoho->intake_message( $row['message_id'], $mailbox );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			$body = (string) $m['body_text'];
			$ts   = $m['date_ts'];
			$bdbg = (string) ( $m['body_debug'] ?? '' );
			$bhtml = (string) ( $m['body_html'] ?? '' );
		}
		$fields = self::extract( $cls['kind'], $row['subject'], $body );
		if ( 'address' !== $fields['property_src'] ) {
			$found = $this->property_from_text( $body );
			if ( '' !== $found ) {
				$fields['property_tag'] = $found;
				$fields['property_src'] = 'text';
			}
		}
		$when   = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : ( '' !== (string) $row['received_at'] ? $row['received_at'] : current_time( 'mysql', true ) );
		$order_id = $this->upsert_order( $cls, $fields, $when, $row );
		if ( ! $order_id ) {
			return new WP_Error( 'order_failed', __( 'Could not record the order.', 'ajcore-ra' ) );
		}
		$pdb = $this->pdb();
		$pdb->insert( $this->table( 'aj_purchase_emails' ), array(
			'message_id'  => $row['message_id'],
			'order_id'    => $order_id,
			'kind'        => $cls['kind'],
			'subject'     => mb_substr( (string) $row['subject'], 0, 500 ),
			'sender'      => mb_substr( trim( $row['from_name'] . ' <' . $row['from_email'] . '>' ), 0, 255 ),
			'received_at' => $when,
			'parsed'      => wp_json_encode( array( 'classify' => $cls, 'fields' => $fields, 'body' => mb_substr( $body, 0, 20000 ), 'body_debug' => isset( $bdbg ) ? $bdbg : '', 'bv' => 3, 'html' => $bhtml ) ),
			'created_at'  => current_time( 'mysql' ),
		) );
		return $order_id;
	}

	/** Finds the order by (vendor, order no) or (vendor, invoice no), else creates it; fills blanks, never overwrites verified data. */
	private function upsert_order( array $cls, array $f, $when, array $row ) {
		$pdb    = $this->pdb();
		$orders = $this->table( 'aj_purchase_orders' );
		$vendor = $cls['vendor'];
		$key    = '' !== $f['order_no'] ? $f['order_no'] : ( '' !== $f['invoice_no'] ? 'inv:' . $f['invoice_no'] : 'msg:' . $row['message_id'] );
		$existing = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$orders}` WHERE vendor = %s AND order_no = %s", $vendor, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $existing && '' !== $f['invoice_no'] ) {
			$existing = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$orders}` WHERE vendor = %s AND invoice_no = %s LIMIT 1", $vendor, $f['invoice_no'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$name = $this->property_name_for( $f['property_tag'] );
		if ( $existing ) {
			$new = array( 'updated_at' => current_time( 'mysql' ) );
			$editable = 'new' === $existing->stage; // verified/charged/posted orders keep the figures you confirmed
			if ( $editable && null !== $f['total'] && null === $existing->total ) {
				$new['total'] = $f['total'];
			}
			if ( $editable && null !== $f['subtotal'] && null === $existing->subtotal ) {
				$new['subtotal'] = $f['subtotal'];
			}
			if ( $editable && null !== $f['tax'] && null === $existing->tax ) {
				$new['tax'] = $f['tax'];
			}
			if ( $editable && '' !== $f['property_tag'] && in_array( $f['property_src'] ?? '', array( 'address', 'text' ), true ) && '' === (string) $existing->property_name && $f['property_tag'] !== $existing->property_tag ) {
				$new['property_tag'] = $f['property_tag']; // a real address beats a PO# tag, while the order has no name yet
				$existing->property_tag = $f['property_tag'];
			}
			if ( '' === $existing->property_tag && '' !== $f['property_tag'] ) {
				$new['property_tag']  = $f['property_tag'];
				$new['property_name'] = $name;
			}
			if ( '' === (string) $existing->property_name && '' === $existing->property_tag && '' === $f['property_tag'] ) {
				// nothing to name
			} elseif ( '' === (string) $existing->property_name && ! isset( $new['property_name'] ) ) {
				$nm = $this->property_name_for( '' !== $existing->property_tag ? (string) $existing->property_tag : (string) $f['property_tag'] );
				if ( '' !== $nm ) {
					$new['property_name'] = $nm;
				}
			}
			if ( '' === $existing->invoice_no && '' !== $f['invoice_no'] ) {
				$new['invoice_no'] = $f['invoice_no'];
			}
			if ( empty( $existing->order_date ) || strtotime( $when . ' UTC' ) < strtotime( $existing->order_date . ' UTC' ) ) {
				$new['order_date'] = $when; // the order date is the earliest email
			}
			$merged = array_merge( (array) $existing, $new );
			$new['confidence'] = ( '' !== $merged['order_no'] && 0 !== strpos( $merged['order_no'], 'msg:' ) ? 1 : 0 ) + ( null !== $merged['total'] ? 1 : 0 ) + ( '' !== $merged['property_tag'] ? 1 : 0 );
			$pdb->update( $orders, $new, array( 'id' => (int) $existing->id ) );
			return (int) $existing->id;
		}
		$conf = ( '' !== $f['order_no'] ? 1 : 0 ) + ( null !== $f['total'] ? 1 : 0 ) + ( '' !== $f['property_tag'] ? 1 : 0 );
		$pdb->insert( $orders, array(
			'vendor'        => $vendor,
			'order_no'      => $key,
			'invoice_no'    => $f['invoice_no'],
			'property_tag'  => $f['property_tag'],
			'property_name' => $name,
			'order_date'    => $when,
			'subtotal'      => $f['subtotal'],
			'tax'           => $f['tax'],
			'total'         => $f['total'],
			'stage'         => 'noise' === $cls['kind'] ? 'ignored' : 'new',
			'confidence'    => $conf,
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		) );
		return (int) $pdb->insert_id;
	}

	/**
	 * Property found anywhere in an email's text: an address with "Ardale" in it, else a Rentec property whose street address appears in
	 * the text (exactly one). Needs no "Delivery Address" label. Returns the address to use as the tag, or ''.
	 */
	public function property_from_text( $body ) {
		$body = (string) $body;
		if ( '' === trim( $body ) ) {
			return '';
		}
		foreach ( array_keys( $this->name_rules() ) as $needle ) {
			if ( preg_match( '/\b\d{1,6}\s+(?:[A-Za-z0-9.\']+\s+){0,3}' . preg_quote( (string) $needle, '/' ) . '\b(?:\s+[A-Za-z.]+)?/i', $body, $m ) ) {
				return trim( preg_replace( '/\s+/', ' ', $m[0] ) );
			}
		}
		$hay  = ' ' . implode( ' ', self::addr_tokens( $body ) ) . ' ';
		$hits = array();
		foreach ( $this->rentec_properties() as $p ) {
			$street = trim( explode( ',', $p['label'] )[0] ); // "6822 Trimbach Way"
			$st     = ' ' . implode( ' ', self::addr_tokens( $street ) ) . ' ';
			if ( strlen( $st ) > 8 && false !== strpos( $hay, $st ) ) {
				$hits[ $p['id'] ] = $street;
			}
		}
		return 1 === count( $hits ) ? array_values( $hits )[0] : '';
	}

	/** Fixed rules: a tag (delivery address / PO#) containing the key gets that property label. Filterable. */
	private function name_rules() {
		return (array) apply_filters( 'ajcore_ra_purchase_property_rules', array( 'ardale' => 'High Point Property' ) );
	}

	/** Words of an address, lower-cased, with street types written one way (Road = Rd, Street = St, ...), so "6822" or "Nimes Rd" can be matched to a full address. */
	private static function addr_tokens( $s ) {
		$s   = preg_replace( '/[^a-z0-9 ]+/', ' ', strtolower( (string) $s ) );
		$abb = array( 'road' => 'rd', 'street' => 'st', 'drive' => 'dr', 'avenue' => 'ave', 'lane' => 'ln', 'court' => 'ct', 'boulevard' => 'blvd', 'circle' => 'cir', 'place' => 'pl', 'parkway' => 'pkwy', 'highway' => 'hwy', 'trail' => 'trl', 'terrace' => 'ter' );
		$out = array();
		foreach ( preg_split( '/\s+/', trim( (string) $s ) ) as $w ) {
			if ( '' !== $w ) {
				$out[] = isset( $abb[ $w ] ) ? $abb[ $w ] : $w;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Why the Rentec property list is empty (shown on the screen), '' when loaded. */
	private static $rentec_note = '';

	/** The words of a tag for matching: like addr_tokens() without unit words ("Apt 3" -> just "3"). */
	private static function tag_tokens( $tag ) {
		$drop = array( 'apt', 'apartment', 'unit', 'ste', 'suite', 'bldg', 'building', 'no', 'number', 'us', 'nc', 'north', 'carolina', 'charlotte', 'concord', 'salisbury' );
		return array_values( array_diff( self::addr_tokens( $tag ), $drop ) );
	}

	/** Rentec properties (top level, not their units) with the words of their address, from AJCore-RA's own cached Rentec list. Empty when Rentec is off. */
	private function rentec_properties() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache             = array();
		self::$rentec_note = '';
		if ( ! class_exists( 'AJCore_RA_Rentec' ) ) {
			self::$rentec_note = 'Rentec is not available';
			return $cache;
		}
		try {
			$res = AJCore_RA_Rentec::rows_for_sync( 'properties' );
		} catch ( \Throwable $e ) {
			self::$rentec_note = 'Rentec: ' . $e->getMessage();
			return $cache;
		}
		if ( is_wp_error( $res ) ) {
			self::$rentec_note = 'Rentec: ' . $res->get_error_message();
			return $cache;
		}
		foreach ( (array) $res as $account ) {
			foreach ( (array) ( isset( $account['rows'] ) ? $account['rows'] : array() ) as $p ) {
				if ( ! is_array( $p ) || empty( $p['address'] ) || empty( $p['property_id'] ) ) {
					continue;
				}
				$label   = trim( preg_replace( '/\s+/', ' ', $p['address'] . ( ! empty( $p['city'] ) ? ', ' . $p['city'] : '' ) . ( ! empty( $p['state'] ) ? ', ' . $p['state'] : '' ) . ( ! empty( $p['zip'] ) ? ' ' . $p['zip'] : '' ) ) );
				$cache[] = array( 'id' => (string) $p['property_id'], 'label' => $label, 'tokens' => self::addr_tokens( $label . ' ' . ( isset( $p['nickname'] ) ? $p['nickname'] : '' ) ) );
			}
		}
		return $cache;
	}

	/** The one Rentec property whose address holds every word of the tag ("6822" -> 6822 Trimbach Way, Charlotte, NC 28269), or null when none or several match. */
	private function match_rentec( $tag ) {
		$want = self::tag_tokens( $tag );
		if ( ! $want || preg_match( '#^\d{1,2}/\d{1,2}(/\d{2,4})?$#', trim( (string) $tag ) ) ) { // empty, or a date like 09/10
			return null;
		}
		$hits = array();
		foreach ( $this->rentec_properties() as $p ) {
			if ( ! array_diff( $want, $p['tokens'] ) ) {
				$hits[] = $p;
			}
		}
		if ( 1 === count( $hits ) ) {
			return $hits[0];
		}
		// A street number that only one Rentec property starts with is enough ("6822 Trim batch" -> 6822 Trimbach Way).
		if ( ctype_digit( (string) $want[0] ) && strlen( $want[0] ) >= 3 ) {
			$by_no = array();
			foreach ( $this->rentec_properties() as $p ) {
				if ( isset( $p['tokens'][0] ) && $p['tokens'][0] === $want[0] ) {
					$by_no[] = $p;
				}
			}
			if ( 1 === count( $by_no ) ) {
				return $by_no[0];
			}
		}
		return null;
	}

	/** Property label for a tag: a saved name, else a fixed rule (Ardale), else the matching Rentec property (remembered for next time). */
	private function property_name_for( $tag ) {
		if ( '' === $tag ) {
			return '';
		}
		$pdb   = $this->pdb();
		$props = $this->table( 'aj_purchase_properties' );
		$row   = $pdb->get_row( $pdb->prepare( "SELECT name FROM `{$props}` WHERE tag = %s", $tag ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row && '' !== (string) $row->name ) {
			return (string) $row->name;
		}
		foreach ( $this->name_rules() as $needle => $name ) {
			if ( false !== stripos( $tag, (string) $needle ) ) {
				return (string) $name;
			}
		}
		$m = $this->match_rentec( $tag );
		if ( $m ) {
			$pdb->query( $pdb->prepare( "INSERT IGNORE INTO `{$props}` (tag, name, rentec_property_id) VALUES (%s, %s, %s)", $tag, $m['label'], $m['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $m['label'];
		}
		return '';
	}

	// ── REST (/ops/purchases*) ──────────────────────────────────────────────────

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public function register_routes() {
		$ns  = AJCore_Extensions::rest_namespace();
		$ops = array( 'AJCore_Extensions', 'can_manage_ops' );
		register_rest_route( $ns, '/ops/purchases', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_list' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/marketing', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_marketing' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/marketing/delete', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_delete_marketing' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/senders', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_senders' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/reparse', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_reparse' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/process', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_process' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/stage', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_stage' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/properties', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_properties' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/properties', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_save_property' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/labels', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_labels' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_get' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/purchases/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_update' ), 'permission_callback' => $ops ) );
	}

	public static function catalog( $catalog ) {
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/purchases', 'auth' => 'Admin', 'purpose' => 'Purchase orders read from the shop mailbox (filters: stage, property, vendor, q, from, to) with totals by property and month.', 'app' => 'OPS purchases' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/purchases/{id}', 'auth' => 'Admin', 'purpose' => 'One order with its email timeline.', 'app' => 'OPS purchases' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/purchases/{id}', 'auth' => 'Admin', 'purpose' => 'Edit an order: property_tag, property_name, total, notes, stage.', 'app' => 'OPS purchases' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/purchases/stage', 'auth' => 'Admin', 'purpose' => 'Move several orders to a stage (ids, stage = new|verified|charged|ignored).', 'app' => 'OPS purchases' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/purchases/process', 'auth' => 'Admin', 'purpose' => 'Reads the next batch of mail from the shop mailbox now.', 'app' => 'OPS purchases' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/purchases/properties', 'auth' => 'Admin', 'purpose' => 'Property tags seen in subjects and the name each maps to.', 'app' => 'OPS purchases' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/purchases/properties', 'auth' => 'Admin', 'purpose' => 'Map a property tag to a property name (tag, name).', 'app' => 'OPS purchases' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/purchases/labels', 'auth' => 'Admin', 'purpose' => 'Zoho labels of a shared mailbox (query mailbox).', 'app' => 'OPS purchases' );
		return $catalog;
	}

	private function row_out( $r ) {
		return array(
			'id'            => (int) $r->id,
			'vendor'        => (string) $r->vendor,
			'order_no'      => 0 === strpos( (string) $r->order_no, 'msg:' ) || 0 === strpos( (string) $r->order_no, 'inv:' ) ? '' : (string) $r->order_no,
			'invoice_no'    => (string) $r->invoice_no,
			'property_tag'  => (string) $r->property_tag,
			'property_name' => (string) $r->property_name,
			'order_date'    => (string) $r->order_date,
			'subtotal'      => null === $r->subtotal ? null : (float) $r->subtotal,
			'tax'           => null === $r->tax ? null : (float) $r->tax,
			'total'         => null === $r->total ? null : (float) $r->total,
			'stage'         => (string) $r->stage,
			'confidence'    => (int) $r->confidence,
			'notes'         => (string) $r->notes,
			'rentec_expense_id' => (string) $r->rentec_expense_id,
		);
	}

	public function rest_list( WP_REST_Request $request ) {
		if ( ! $this->ensure_tables() ) {
			return new WP_Error( 'no_table', __( 'Purchases tables could not be created.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$pdb    = $this->pdb();
		$orders = $this->table( 'aj_purchase_orders' );
		$view   = 'marketing' === sanitize_key( (string) $request->get_param( 'label' ) ) ? 'marketing' : '';
		$where  = array( '' === $view ? "label = ''" : "label = 'marketing'" ); // the normal list hides marketing; the Marketing label shows only those
		$params = array();
		$stage  = sanitize_key( (string) $request->get_param( 'stage' ) );
		if ( '' === $view && in_array( $stage, self::STAGES, true ) ) {
			$where[]  = 'stage = %s';
			$params[] = $stage;
		}
		if ( '' === $view && ( 'verify' === $stage || '1' === (string) $request->get_param( 'needs_verification' ) ) ) {
			$where[] = "( stage = 'new' AND ( total IS NULL OR property_tag = '' ) )";
		}
		$property = sanitize_text_field( (string) $request->get_param( 'property' ) );
		if ( '' !== $property ) {
			$where[]  = '( property_name = %s OR property_tag = %s )';
			$params[] = $property;
			$params[] = $property;
		}
		if ( '__none__' === $property ) {
			$where = array_filter( $where, static function ( $w ) {
				return false === strpos( $w, 'property_name = %s' );
			} );
			$params = array_values( array_filter( $params, static function ( $p ) {
				return '__none__' !== $p;
			} ) );
			$where[] = "property_tag = ''";
		}
		$vendor = sanitize_text_field( (string) $request->get_param( 'vendor' ) );
		if ( '' !== $vendor ) {
			$where[]  = 'vendor = %s';
			$params[] = $vendor;
		}
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like     = '%' . $pdb->esc_like( $q ) . '%';
			$where[]  = '( order_no LIKE %s OR invoice_no LIKE %s OR property_tag LIKE %s OR property_name LIKE %s OR vendor LIKE %s )';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}
		foreach ( array( 'from' => '>=', 'to' => '<=' ) as $key => $op ) {
			$d = sanitize_text_field( (string) $request->get_param( $key ) );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				$where[]  = 'order_date ' . $op . ' %s';
				$params[] = $d . ( 'from' === $key ? ' 00:00:00' : ' 23:59:59' );
			}
		}
		$where_sql = implode( ' AND ', $where );
		$limit     = min( 1000, max( 1, absint( $request->get_param( 'per_page' ) ?: 300 ) ) );
		$sql       = "SELECT * FROM `{$orders}` WHERE {$where_sql} ORDER BY order_date DESC, id DESC LIMIT %d";
		$rows      = (array) $pdb->get_results( $pdb->prepare( $sql, array_merge( $params, array( $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Totals ignore ignored orders and orders without an amount; they respect the same filters except stage.
		$sum_where  = array_filter( $where, static function ( $w ) {
			return false === strpos( $w, 'stage = %s' ) && false === strpos( $w, "stage = 'new'" ) && 0 !== strpos( $w, 'label =' );
		} );
		$sum_params = $params;
		if ( '' === $view && in_array( $stage, self::STAGES, true ) ) {
			$sum_params = array_slice( $params, 1 );
		}
		$sum_sql   = ( $sum_where ? implode( ' AND ', $sum_where ) . ' AND ' : '' ) . "label = '' AND stage <> 'ignored' AND total IS NOT NULL";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$by_property = (array) $pdb->get_results( $sum_params ? $pdb->prepare( "SELECT IF(property_name <> '', property_name, IF(property_tag <> '', property_tag, '(no property)')) AS property, COUNT(*) AS orders, SUM(total) AS total FROM `{$orders}` WHERE {$sum_sql} GROUP BY property ORDER BY total DESC", $sum_params ) : "SELECT IF(property_name <> '', property_name, IF(property_tag <> '', property_tag, '(no property)')) AS property, COUNT(*) AS orders, SUM(total) AS total FROM `{$orders}` WHERE {$sum_sql} GROUP BY property ORDER BY total DESC" );
		$by_month    = (array) $pdb->get_results( $sum_params ? $pdb->prepare( "SELECT DATE_FORMAT(order_date, '%%Y-%%m') AS month, COUNT(*) AS orders, SUM(total) AS total FROM `{$orders}` WHERE {$sum_sql} GROUP BY month ORDER BY month DESC LIMIT 24", $sum_params ) : "SELECT DATE_FORMAT(order_date, '%Y-%m') AS month, COUNT(*) AS orders, SUM(total) AS total FROM `{$orders}` WHERE {$sum_sql} GROUP BY month ORDER BY month DESC LIMIT 24" );
		$total_all   = (float) $pdb->get_var( $sum_params ? $pdb->prepare( "SELECT SUM(total) FROM `{$orders}` WHERE {$sum_sql}", $sum_params ) : "SELECT SUM(total) FROM `{$orders}` WHERE {$sum_sql}" );
		$this_month  = (float) $pdb->get_var( "SELECT SUM(total) FROM `{$orders}` WHERE label = '' AND stage <> 'ignored' AND total IS NOT NULL AND order_date >= '" . gmdate( 'Y-m-01 00:00:00' ) . "'" );
		$unassigned  = (int) $pdb->get_var( "SELECT COUNT(*) FROM `{$orders}` WHERE label = '' AND stage <> 'ignored' AND property_tag = ''" );
		$vendors     = (array) $pdb->get_col( "SELECT DISTINCT vendor FROM `{$orders}` ORDER BY vendor" );
		$stage_counts = (array) $pdb->get_results( "SELECT stage, COUNT(*) AS n FROM `{$orders}` WHERE label = '' GROUP BY stage" );
		$marketing    = (int) $pdb->get_var( "SELECT COUNT(*) FROM `{$orders}` WHERE label = 'marketing'" );
		// phpcs:enable
		$counts = array_fill_keys( self::STAGES, 0 );
		foreach ( $stage_counts as $c ) {
			$counts[ (string) $c->stage ] = (int) $c->n;
		}
		$s      = $this->settings();
		$orders_out = array_map( array( $this, 'row_out' ), $rows );
		// Every email of each listed order (one query), oldest first, so a screen can show the order with its emails nested under it.
		if ( $orders_out ) {
			$order_ids = wp_list_pluck( $orders_out, 'id' );
			$ph        = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$mail_rows = (array) $pdb->get_results( $pdb->prepare( "SELECT order_id, message_id, kind, subject, sender, received_at FROM `{$this->table( 'aj_purchase_emails' )}` WHERE order_id IN ({$ph}) ORDER BY received_at ASC, id ASC", $order_ids ) );
			$by_order  = array();
			foreach ( $mail_rows as $e ) {
				$by_order[ (int) $e->order_id ][] = array( 'message_id' => (string) $e->message_id, 'kind' => (string) $e->kind, 'subject' => (string) $e->subject, 'sender' => (string) $e->sender, 'received_at' => (string) $e->received_at );
			}
			foreach ( $orders_out as &$o ) {
				$o['emails'] = isset( $by_order[ $o['id'] ] ) ? $by_order[ $o['id'] ] : array();
			}
			unset( $o );
		}
		return rest_ensure_response( array(
			'orders' => $orders_out,
			'stats'  => array(
				'total_spent'        => round( $total_all, 2 ),
				'this_month'         => round( $this_month, 2 ),
				'needs_verification' => self::needs_verification_count(),
				'unassigned'         => $unassigned,
				'stages'             => $counts,
			),
			'by_property' => array_map( static function ( $r ) {
				return array( 'property' => (string) $r->property, 'orders' => (int) $r->orders, 'total' => round( (float) $r->total, 2 ) );
			}, $by_property ),
			'by_month'    => array_map( static function ( $r ) {
				return array( 'month' => (string) $r->month, 'orders' => (int) $r->orders, 'total' => round( (float) $r->total, 2 ) );
			}, $by_month ),
			'vendors'     => array_values( $vendors ),
			'mailbox'     => $s['mailbox'],
			'last_run'    => $s['last_run'],
			'marketing'   => $marketing,
			'senders'     => class_exists( 'AJCore_Marketing' ) ? AJCore_Marketing::senders() : array(),
			'rentec'      => array( 'count' => count( $this->rentec_properties() ), 'note' => self::$rentec_note ),
		) );
	}

	public function rest_get( WP_REST_Request $request ) {
		if ( ! $this->ensure_tables() ) {
			return new WP_Error( 'no_table', __( 'Purchases tables could not be created.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$pdb = $this->pdb();
		$row = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$this->table( 'aj_purchase_orders' )}` WHERE id = %d", absint( $request['id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$emails = (array) $pdb->get_results( $pdb->prepare( "SELECT id, message_id, kind, subject, sender, received_at, parsed FROM `{$this->table( 'aj_purchase_emails' )}` WHERE order_id = %d ORDER BY received_at ASC", (int) $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Opening an order shows its emails: fetch (and keep) the text of any email whose body was not stored yet.
		$zoho    = $this->zoho();
		$mailbox = $this->settings()['mailbox'];
		foreach ( $emails as $e ) {
			$parsed = json_decode( (string) $e->parsed, true );
			$parsed = is_array( $parsed ) ? $parsed : array();
			if ( ( isset( $parsed['bv'] ) && (int) $parsed['bv'] >= 3 && empty( $parsed['body_debug'] ) ) || ! $zoho || '' === $mailbox ) { // full text + rich HTML already stored
				continue;
			}
			$m = $zoho->intake_message( (string) $e->message_id, $mailbox );
			if ( is_wp_error( $m ) ) {
				continue;
			}
			$parsed['body']       = mb_substr( (string) $m['body_text'], 0, 20000 );
			$parsed['body_debug'] = (string) ( $m['body_debug'] ?? '' );
			$parsed['html']       = (string) ( $m['body_html'] ?? '' );
			$parsed['bv']         = 3;
			$e->parsed      = wp_json_encode( $parsed );
			$pdb->update( $this->table( 'aj_purchase_emails' ), array( 'parsed' => $e->parsed ), array( 'id' => (int) $e->id ) );
		}
		return rest_ensure_response( array(
			'order'  => $this->row_out( $row ),
			'emails' => array_map( static function ( $e ) {
				return array( 'message_id' => (string) $e->message_id, 'kind' => (string) $e->kind, 'subject' => (string) $e->subject, 'sender' => (string) $e->sender, 'received_at' => (string) $e->received_at, 'parsed' => json_decode( (string) $e->parsed, true ) );
			}, $emails ),
		) );
	}

	/** Allowed stage changes: anything except into/out of "posted" (that is the Rentec step, later); posted rows stay as they are. */
	private function stage_ok( $from, $to ) {
		return in_array( $to, array( 'new', 'verified', 'charged', 'ignored' ), true ) && 'posted' !== $from;
	}

	public function rest_update( WP_REST_Request $request ) {
		if ( ! $this->ensure_tables() ) {
			return new WP_Error( 'no_table', __( 'Purchases tables could not be created.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$pdb  = $this->pdb();
		$tbl  = $this->table( 'aj_purchase_orders' );
		$id   = absint( $request['id'] );
		$row  = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$tbl}` WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$new = array( 'updated_at' => current_time( 'mysql' ) );
		if ( null !== $request->get_param( 'property_tag' ) ) {
			$new['property_tag']  = mb_substr( sanitize_text_field( (string) $request->get_param( 'property_tag' ) ), 0, 120 );
			$new['property_name'] = $this->property_name_for( $new['property_tag'] );
		}
		if ( null !== $request->get_param( 'property_name' ) ) {
			$new['property_name'] = mb_substr( sanitize_text_field( (string) $request->get_param( 'property_name' ) ), 0, 190 );
		}
		if ( null !== $request->get_param( 'total' ) ) {
			$raw          = (string) $request->get_param( 'total' );
			$new['total'] = '' === trim( $raw ) ? null : self::parse_money( $raw );
		}
		if ( null !== $request->get_param( 'notes' ) ) {
			$new['notes'] = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		}
		$stage = sanitize_key( (string) $request->get_param( 'stage' ) );
		if ( '' !== $stage ) {
			if ( ! $this->stage_ok( (string) $row->stage, $stage ) ) {
				return new WP_Error( 'bad_stage', __( 'That stage change is not allowed.', 'ajcore-ra' ), array( 'status' => 400 ) );
			}
			$new['stage'] = $stage;
		}
		$pdb->update( $tbl, $new, array( 'id' => $id ) );
		return $this->rest_get( $request );
	}

	public function rest_stage( WP_REST_Request $request ) {
		if ( ! $this->ensure_tables() ) {
			return new WP_Error( 'no_table', __( 'Purchases tables could not be created.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$ids   = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
		$stage = sanitize_key( (string) $request->get_param( 'stage' ) );
		if ( empty( $ids ) || ! in_array( $stage, array( 'new', 'verified', 'charged', 'ignored' ), true ) ) {
			return new WP_Error( 'bad_request', __( 'Select orders and a stage (new, verified, charged or ignored).', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$pdb = $this->pdb();
		$ph  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql = "UPDATE `{$this->table( 'aj_purchase_orders' )}` SET stage = %s, updated_at = %s WHERE id IN ({$ph}) AND stage <> 'posted'";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$n = $pdb->query( $pdb->prepare( $sql, array_merge( array( $stage, current_time( 'mysql' ) ), $ids ) ) );
		return rest_ensure_response( array( 'updated' => (int) $n, 'stage' => $stage ) );
	}

	public function rest_process( WP_REST_Request $request ) {
		$result = $this->run_pass( 30 );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
	}

	public function rest_properties( WP_REST_Request $request ) {
		if ( ! $this->ensure_tables() ) {
			return new WP_Error( 'no_table', __( 'Purchases tables could not be created.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$pdb   = $this->pdb();
		$names = (array) $pdb->get_results( "SELECT tag, name, rentec_property_id FROM `{$this->table( 'aj_purchase_properties' )}` ORDER BY tag" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$map   = array();
		foreach ( $names as $n ) {
			$map[ strtolower( $n->tag ) ] = $n;
		}
		$seen = (array) $pdb->get_results( "SELECT property_tag AS tag, COUNT(*) AS orders FROM `{$this->table( 'aj_purchase_orders' )}` WHERE property_tag <> '' GROUP BY property_tag ORDER BY orders DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $seen as $r ) {
			$m     = $map[ strtolower( $r->tag ) ] ?? null;
			$out[] = array( 'tag' => (string) $r->tag, 'orders' => (int) $r->orders, 'name' => $m ? (string) $m->name : '', 'rentec_property_id' => $m ? (string) $m->rentec_property_id : '' );
		}
		return rest_ensure_response( array( 'properties' => $out ) );
	}

	public function rest_save_property( WP_REST_Request $request ) {
		if ( ! $this->ensure_tables() ) {
			return new WP_Error( 'no_table', __( 'Purchases tables could not be created.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$tag  = mb_substr( sanitize_text_field( (string) $request->get_param( 'tag' ) ), 0, 120 );
		$name = mb_substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 190 );
		if ( '' === $tag ) {
			return new WP_Error( 'no_tag', __( 'Property tag is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$pdb = $this->pdb();
		$pdb->query( $pdb->prepare( "INSERT INTO `{$this->table( 'aj_purchase_properties' )}` (tag, name) VALUES (%s, %s) ON DUPLICATE KEY UPDATE name = VALUES(name)", $tag, $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Orders carrying this tag (still unconfirmed) pick up the name.
		$pdb->query( $pdb->prepare( "UPDATE `{$this->table( 'aj_purchase_orders' )}` SET property_name = %s WHERE property_tag = %s AND stage = 'new'", $name, $tag ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->rest_properties( $request );
	}

	public function rest_labels( WP_REST_Request $request ) {
		$zoho = $this->zoho();
		if ( ! $zoho ) {
			return new WP_Error( 'no_zoho', __( 'Zoho is not available.', 'ajcore-ra' ), array( 'status' => 503 ) );
		}
		$mailbox = strtolower( sanitize_email( (string) $request->get_param( 'mailbox' ) ) );
		if ( '' === $mailbox ) {
			return new WP_Error( 'no_mailbox', __( 'Enter the shared mailbox address first.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$raw    = null;
		$labels = $zoho->list_labels( $mailbox, $raw );
		if ( is_wp_error( $labels ) ) {
			return new WP_Error( $labels->get_error_code(), $labels->get_error_message(), array( 'status' => 502 ) );
		}
		// Empty list: include a snippet of what Zoho actually returned so the shape can be checked.
		return rest_ensure_response( array( 'labels' => $labels, 'debug' => $labels ? '' : substr( (string) wp_json_encode( $raw ), 0, 400 ) ) );
	}

	// ── WP admin view (inside the Pipeline tab) ─────────────────────────────────
	// Same screen as AJOps > Pipeline > Purchases: both read /ops/purchases*. Plain JS; text goes in via textContent.

	public static function render_view() {
		$self = self::instance();
		$zoho = $self->zoho();
		$s    = $self->settings();
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'ajcore-ra' ) . '</p></div>';
		}
		if ( ! $zoho || ! $zoho->is_connected() ) {
			echo '<div class="ajmr-card ajmr-empty">' . esc_html__( 'Zoho is not connected. Connect it from the Filings view (Zoho gear), then come back.', 'ajcore-ra' ) . '</div>';
			return;
		}
		$boxes = $zoho->mailboxes();
		?>
		<div id="ajra-pu">
			<div class="ajmr-row ajmr-row-main" id="ajra-pu-tiles"></div>
			<div class="ajmr-filters" id="ajra-pu-chips"></div>
			<div class="ajmr-row ajmr-row-tools">
				<label class="ajmr-tile ajmr-search"><span class="ajmr-tile-ico"><?php echo '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><input type="search" id="ajra-pu-q" placeholder="<?php esc_attr_e( 'Search order, invoice, property, vendor…', 'ajcore-ra' ); ?>" autocomplete="off"></label>
				<select id="ajra-pu-property" class="ajmr-tile ajmr-select"><option value=""><?php esc_html_e( 'All properties', 'ajcore-ra' ); ?></option></select>
				<select id="ajra-pu-vendor" class="ajmr-tile ajmr-select"><option value=""><?php esc_html_e( 'All vendors', 'ajcore-ra' ); ?></option></select>
				<button type="button" class="ajmr-tile ajmr-tool" id="ajra-pu-clear"><span><?php esc_html_e( 'Clear', 'ajcore-ra' ); ?></span></button>
				<button type="button" class="ajmr-tile ajmr-tool" id="ajra-pu-run"><span><?php esc_html_e( 'Read mailbox', 'ajcore-ra' ); ?></span></button>
				<button type="button" class="ajmr-tile ajmr-tool" id="ajra-pu-reparse"><span><?php esc_html_e( 'Re-parse', 'ajcore-ra' ); ?></span></button>
				<button type="button" class="ajmr-tile ajmr-tool" id="ajra-pu-csv"><span><?php esc_html_e( 'Export CSV', 'ajcore-ra' ); ?></span></button>
			</div>
			<div class="ajmr-filters"><span class="ajmr-fnote" id="ajra-pu-status"></span></div>
			<div class="ajmr-panel" id="ajra-pu-settings" hidden style="margin-top:14px">
				<h3><?php esc_html_e( 'Mailbox and selection', 'ajcore-ra' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ajcore_ra_purchases_save">
					<?php wp_nonce_field( 'ajcore_ra_purchases_save' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label for="ajra-pu-mailbox"><?php esc_html_e( 'Shared mailbox', 'ajcore-ra' ); ?></label></th><td>
							<input type="text" name="mailbox" id="ajra-pu-mailbox" class="regular-text" list="ajra-pu-boxes" value="<?php echo esc_attr( $s['mailbox'] ); ?>" placeholder="shop@example.com">
							<datalist id="ajra-pu-boxes"><?php foreach ( $boxes as $b ) : ?><option value="<?php echo esc_attr( $b['address'] ); ?>"><?php echo esc_html( $b['purpose'] ); ?></option><?php endforeach; ?></datalist>
							<p class="description"><?php esc_html_e( 'Add mailboxes under Zoho settings, "Other shared mailboxes" (Filings view).', 'ajcore-ra' ); ?></p>
						</td></tr>
						<tr><th><?php esc_html_e( 'Zoho labels', 'ajcore-ra' ); ?></th><td>
							<button type="button" class="button" id="ajra-pu-labels"><?php esc_html_e( 'Load labels', 'ajcore-ra' ); ?></button>
							<div id="ajra-pu-label-list" style="margin-top:8px;"><?php foreach ( (array) $s['labels'] as $l ) : ?><label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="labels[]" value="<?php echo esc_attr( $l ); ?>" checked> <?php echo esc_html( $l ); ?></label><?php endforeach; ?></div>
							<p class="description"><?php esc_html_e( 'Only mail carrying a ticked label is read. None ticked = every message.', 'ajcore-ra' ); ?></p>
						</td></tr>
						<tr><th><label for="ajra-pu-subject"><?php esc_html_e( 'Subject contains', 'ajcore-ra' ); ?></label></th><td><input type="text" name="subject" id="ajra-pu-subject" class="regular-text" value="<?php echo esc_attr( $s['subject'] ); ?>"></td></tr>
						<tr><th><label for="ajra-pu-sender"><?php esc_html_e( 'Sender contains', 'ajcore-ra' ); ?></label></th><td><input type="text" name="sender" id="ajra-pu-sender" class="regular-text" value="<?php echo esc_attr( $s['sender'] ); ?>"><p class="description"><?php esc_html_e( 'Comma-separated; any one matches. Blank = every message.', 'ajcore-ra' ); ?></p></td></tr>
						<tr><th><label for="ajra-pu-match"><?php esc_html_e( 'Match', 'ajcore-ra' ); ?></label></th><td><select name="match" id="ajra-pu-match"><option value="all" <?php selected( $s['match'], 'all' ); ?>><?php esc_html_e( 'All filled fields (narrow)', 'ajcore-ra' ); ?></option><option value="any" <?php selected( $s['match'], 'any' ); ?>><?php esc_html_e( 'Any filled field (broad)', 'ajcore-ra' ); ?></option></select></td></tr>
						<tr><th><?php esc_html_e( 'Hourly pass', 'ajcore-ra' ); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( '1', (string) $s['enabled'] ); ?>> <?php esc_html_e( 'Read the mailbox every hour', 'ajcore-ra' ); ?></label></td></tr>
					</table>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ajcore-ra' ); ?></button></p>
				</form>
			</div>

			<div class="ajmr-panel" id="ajra-pu-senders-box" hidden><h3><?php esc_html_e( 'Marketing senders (their future mail is labelled automatically)', 'ajcore-ra' ); ?></h3><div id="ajra-pu-senders"></div><p><input type="text" id="ajra-pu-sender-in" class="regular-text" placeholder="sender@domain.com or domain.com"> <button type="button" class="button" id="ajra-pu-sender-add"><?php esc_html_e( 'Add', 'ajcore-ra' ); ?></button></p></div>

			<div class="ajmr-bulk" id="ajra-pu-bulk" hidden>
				<strong id="ajra-pu-bulk-n">0</strong> <?php esc_html_e( 'selected', 'ajcore-ra' ); ?>
				<button type="button" class="button" data-stage="verified"><?php esc_html_e( 'Mark verified', 'ajcore-ra' ); ?></button>
				<button type="button" class="button" data-stage="charged"><?php esc_html_e( 'Mark charged', 'ajcore-ra' ); ?></button>
				<button type="button" class="button" data-stage="new"><?php esc_html_e( 'Back to new', 'ajcore-ra' ); ?></button>
				<button type="button" class="button" data-stage="ignored"><?php esc_html_e( 'Ignore', 'ajcore-ra' ); ?></button>
				<button type="button" class="button" id="ajra-pu-bulk-marketing" title="<?php echo esc_attr__( 'Label as Marketing', 'ajcore-ra' ); ?>"><?php esc_html_e( 'Marketing', 'ajcore-ra' ); ?></button>
				<button type="button" class="button button-primary" id="ajra-pu-bulk-delete" hidden><?php esc_html_e( 'Delete (to Zoho Trash)', 'ajcore-ra' ); ?></button>
				<button type="button" class="button" id="ajra-pu-bulk-notmk" hidden><?php esc_html_e( 'Not marketing', 'ajcore-ra' ); ?></button>
				<button type="button" class="button-link" id="ajra-pu-bulk-clear"><?php esc_html_e( 'Clear selection', 'ajcore-ra' ); ?></button>
			</div>

			<div class="ajmr-card">
				<table class="widefat striped" id="ajra-pu-table">
					<thead><tr>
						<th style="width:28px"><input type="checkbox" id="ajra-pu-all" aria-label="<?php esc_attr_e( 'Select all', 'ajcore-ra' ); ?>"></th>
						<th><?php esc_html_e( 'Time', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Vendor', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Order / invoice', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Property', 'ajcore-ra' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Total', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Stage', 'ajcore-ra' ); ?></th>
					</tr></thead>
					<tbody></tbody>
				</table>
			</div>

			<div class="ajmr-rules-grid" style="margin-top:14px">
				<div class="ajmr-card"><h4><?php esc_html_e( 'By property', 'ajcore-ra' ); ?></h4><table class="widefat striped" id="ajra-pu-byprop"><tbody></tbody></table></div>
				<div class="ajmr-card"><h4><?php esc_html_e( 'By month', 'ajcore-ra' ); ?></h4><table class="widefat striped" id="ajra-pu-bymonth"><tbody></tbody></table></div>
			</div>


			<div class="ajmr-modal" id="ajra-pu-detail" hidden><div class="ajmr-modal-box"><div id="ajra-pu-detail-body"></div></div></div>
		</div>
		<script>
		( function () {
			var rest = <?php echo wp_json_encode( rest_url( 'ajcore/v1' ) ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var STAGES = [ 'new', 'verified', 'charged', 'posted', 'ignored' ], LABEL = { 'new': 'New', verified: 'Verified', charged: 'Charged', posted: 'Posted', ignored: 'Ignored' };
			var st = { stage: '', property: '', vendor: '', q: '', label: '' }, data = null, selected = {};
			function $( id ) { return document.getElementById( id ); }
			function el( tag, cls, text ) { var e = document.createElement( tag ); if ( cls ) { e.className = cls; } if ( text != null ) { e.textContent = text; } return e; }
			function money( v ) { return v == null ? '—' : '$' + Number( v ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ); }
			function day( s ) { if ( ! s ) { return '—'; } var d = new Date( s.replace( ' ', 'T' ) + 'Z' ); return isNaN( d ) ? s : d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } ); }
			function emailFrame( html ) { var f = document.createElement( 'iframe' ); f.setAttribute( 'sandbox', 'allow-popups' ); f.setAttribute( 'referrerpolicy', 'no-referrer' ); f.srcdoc = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: data:; style-src \'unsafe-inline\'; font-src https: data:"><base target="_blank"><style>body{margin:10px;font:14px/1.45 -apple-system,Segoe UI,Arial,sans-serif;color:#111;word-wrap:break-word}img{max-width:100%;height:auto}</style>' + html; f.style.cssText = 'width:100%;height:680px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;resize:vertical;'; return f; }
			function api( path, opts ) { opts = opts || {}; opts.headers = Object.assign( { 'X-WP-Nonce': nonce }, opts.headers || {} ); return fetch( rest + path, opts ).then( function ( r ) { return r.json().then( function ( d ) { if ( ! r.ok ) { throw new Error( d.message || 'Request failed' ); } return d; } ); } ); }
			function post( path, body ) { return api( path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } ); }

			function load() {
				var q = new URLSearchParams(); [ 'stage', 'property', 'vendor', 'q', 'label' ].forEach( function ( k ) { if ( st[ k ] ) { q.set( k === 'stage' && st.stage === 'verify' ? 'stage' : k, st[ k ] ); } } );
				api( '/ops/purchases?' + q.toString() ).then( function ( d ) { var first = ! data; data = d; render(); if ( first && d.mailbox && ! ( d.last_run && typeof d.last_run.at === 'string' ) ) { $( 'ajra-pu-run' ).click(); } } ).catch( function ( e ) { $( 'ajra-pu-status' ).textContent = e.message; } );
			}

			function tile( key, label, count, tint, icon ) {
				var t = el( 'a', 'ajmr-tile ajmr-stat ' + tint + ( st.stage === key ? ' is-on' : '' ) ); t.href = '#';
				var ico = el( 'span', 'ajmr-tile-ico' ); ico.innerHTML = icon; t.appendChild( ico );
				var tx = el( 'span', 'ajmr-tile-txt' ); tx.appendChild( el( 'small', null, label ) ); tx.appendChild( el( 'strong', null, String( count ) ) ); t.appendChild( tx );
				t.addEventListener( 'click', function ( e ) { e.preventDefault(); st.label = ''; st.stage = st.stage === key ? '' : key; selected = {}; load(); } );
				return t;
			}
			var ICON = { alert: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>', check: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>', list: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>', cog: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>' };

			function render() {
				var s = data.stats, c = s.stages, total = STAGES.reduce( function ( a, k ) { return a + ( c[ k ] || 0 ); }, 0 );
				var tiles = $( 'ajra-pu-tiles' ); tiles.innerHTML = '';
				tiles.appendChild( tile( 'verify', 'Needs verification', s.needs_verification, 'is-amber', ICON.alert ) );
				tiles.appendChild( tile( 'new', 'New', c[ 'new' ] || 0, 'is-amber', ICON.list ) );
				tiles.appendChild( tile( 'verified', 'Verified', c.verified || 0, 'is-green', ICON.check ) );
				tiles.appendChild( tile( 'charged', 'Charged', c.charged || 0, 'is-green', ICON.check ) );
				// The Marketing label: a filter on these orders (not a pipeline of its own).
				var mt = el( 'a', 'ajmr-tile ajmr-stat is-amber' + ( st.label === 'marketing' ? ' is-on' : '' ) ); mt.href = '#'; mt.title = 'Orders labelled Marketing: see, preview and delete them';
				var mtx = el( 'span', 'ajmr-tile-txt' ); mtx.appendChild( el( 'small', null, 'Marketing' ) ); mtx.appendChild( el( 'strong', null, String( data.marketing || 0 ) ) ); mt.appendChild( mtx );
				mt.addEventListener( 'click', function ( e ) { e.preventDefault(); st.label = st.label === 'marketing' ? '' : 'marketing'; st.stage = ''; selected = {}; load(); } ); tiles.appendChild( mt );
				var sb = $( 'ajra-pu-senders-box' ); sb.hidden = st.label !== 'marketing'; var sl = $( 'ajra-pu-senders' ); sl.innerHTML = '';
				if ( ! ( data.senders || [] ).length ) { sl.appendChild( el( 'span', 'description', 'None yet.' ) ); }
				( data.senders || [] ).forEach( function ( s ) { var b = el( 'button', 'button button-small', s + '  ×' ); b.type = 'button'; b.style.marginRight = '6px'; b.title = 'Remove'; b.addEventListener( 'click', function () { post( '/ops/purchases/senders', { remove: s } ).then( load ); } ); sl.appendChild( b ); } );
				var side = el( 'div', 'ajmr-mailboxes' ), g = el( 'div', 'ajmr-tile ajmr-tile-sm' ), gb = el( 'button', 'ajmr-tile-main' ); gb.type = 'button';
				var gi = el( 'span', 'ajmr-tile-ico' ); gi.innerHTML = ICON.cog; gb.appendChild( gi ); var gt = el( 'span', 'ajmr-tile-txt' ); gt.appendChild( el( 'small', null, 'Mailbox' ) ); gt.appendChild( el( 'strong', null, data.mailbox ? data.mailbox.split( '@' )[ 0 ] : 'Not set' ) ); gb.appendChild( gt );
				gb.addEventListener( 'click', function () { var p = $( 'ajra-pu-settings' ); p.hidden = ! p.hidden; } ); g.appendChild( gb ); side.appendChild( g ); tiles.appendChild( side );

				var chips = $( 'ajra-pu-chips' ); chips.innerHTML = '';
				[ [ 'Total spent', money( s.total_spent ) ], [ 'This month', money( s.this_month ) ], [ 'Orders', String( total ) ], [ 'No property', String( s.unassigned ) ] ].forEach( function ( x ) {
					var chip = el( 'span', 'ajmr-fchip' ); chip.appendChild( el( 'b', null, x[ 0 ] ) ); chip.appendChild( document.createTextNode( ' ' + x[ 1 ] ) ); chips.appendChild( chip );
				} );
				if ( data.rentec ) { chips.appendChild( el( 'span', 'ajmr-fnote', data.rentec.count ? 'Rentec: ' + data.rentec.count + ' properties loaded' : 'Rentec: no properties (' + ( data.rentec.note || 'none returned' ) + ')' ) ); }
				if ( data.last_run && typeof data.last_run.at === 'string' ) { chips.appendChild( el( 'span', 'ajmr-fnote', 'Last pass ' + data.last_run.at + ' · ' + data.last_run.scanned + ' scanned · ' + data.last_run[ 'new' ] + ' new' + ( data.last_run.errors && data.last_run.errors.length ? ' · ERROR: ' + data.last_run.errors.join( ' | ' ) : '' ) ) ); }

				fillSelect( 'ajra-pu-property', data.by_property.map( function ( p ) { return p.property; } ).filter( function ( p ) { return p !== '(no property)'; } ), st.property, 'All properties' );
				fillSelect( 'ajra-pu-vendor', data.vendors, st.vendor, 'All vendors' );

				var tb = $( 'ajra-pu-table' ).querySelector( 'tbody' ); tb.innerHTML = '';
				if ( ! data.orders.length ) { var r0 = el( 'tr' ), c0 = el( 'td', null, 'No orders match.' ); c0.colSpan = 7; r0.appendChild( c0 ); tb.appendChild( r0 ); }
				// Date first (newest day on top), then each order of that day with all of its emails nested under it.
				var days = [], byDay = {};
				data.orders.forEach( function ( o ) { var k = ( o.order_date || '' ).slice( 0, 10 ) || '—'; if ( ! byDay[ k ] ) { byDay[ k ] = []; days.push( k ); } byDay[ k ].push( o ); } );
				days.sort().reverse();
				days.forEach( function ( k ) {
					var list = byDay[ k ], sum = list.reduce( function ( a, o ) { return a + ( Number( o.total ) || 0 ); }, 0 );
					var hr = el( 'tr', 'ajra-pu-day' ), hc = el( 'td', null, day( k + ' 00:00:00' ) + '  ·  ' + list.length + ( list.length === 1 ? ' order' : ' orders' ) + '  ·  ' + money( sum ) ); hc.colSpan = 7; hr.appendChild( hc ); tb.appendChild( hr );
					list.forEach( function ( o ) {
						var tr = el( 'tr' ); tr.style.cursor = 'pointer';
						var td = el( 'td' ); td.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );
						var cb = el( 'input' ); cb.type = 'checkbox'; cb.checked = !! selected[ o.id ]; cb.addEventListener( 'change', function () { if ( cb.checked ) { selected[ o.id ] = true; } else { delete selected[ o.id ]; } syncBulk(); } ); td.appendChild( cb ); tr.appendChild( td );
						tr.appendChild( el( 'td', null, ( o.order_date || '' ).slice( 11, 16 ) ) ); tr.appendChild( el( 'td', null, o.vendor ) );
						var ord = el( 'td', 'ajra-pu-ord' ); ord.appendChild( el( 'strong', null, o.order_no || o.invoice_no || '—' ) );
						var nb = el( 'span', 'ajra-pu-nums' ); ( o.emails || [] ).forEach( function ( m, mi ) { var c = el( 'button', 'ajra-pu-num k-' + ( m.kind || 'other' ), String( mi + 1 ) ); c.type = 'button'; c.title = m.kind + ' · ' + m.subject; c.addEventListener( 'click', function ( ev ) { ev.stopPropagation(); openDetail( o.id, mi ); } ); nb.appendChild( c ); } ); ord.appendChild( nb );
						if ( o.order_no && o.invoice_no ) { ord.appendChild( el( 'div', 'description', 'Invoice ' + o.invoice_no ) ); }
						tr.appendChild( ord );
						var pc = el( 'td', 'ajra-pu-prop' );
						if ( o.property_name ) { pc.appendChild( el( 'strong', null, o.property_name ) ); if ( o.property_tag && o.property_tag.toLowerCase() !== o.property_name.toLowerCase() ) { pc.appendChild( el( 'div', 'description', o.property_tag ) ); } }
						else if ( o.property_tag ) { var pt = el( 'span', 'ajra-pu-unnamed', o.property_tag ); pt.title = 'No property name yet: open the order and set one'; pc.appendChild( pt ); }
						else { pc.appendChild( el( 'span', 'ajra-pu-none', 'no property' ) ); }
						tr.appendChild( pc );
						var tot = el( 'td', 'ajra-pu-tot', money( o.total ) ); tot.style.textAlign = 'right'; tr.appendChild( tot );
						var stg = el( 'td' ); stg.appendChild( el( 'span', 'ajcore-status-pill ' + ( o.stage === 'new' ? '' : ( o.stage === 'ignored' ? 'dup' : 'active' ) ), LABEL[ o.stage ] || o.stage ) ); tr.appendChild( stg );
						tr.addEventListener( 'click', function () { openDetail( o.id ); } );
						tb.appendChild( tr );
					} );
				} );
				fillSummary( 'ajra-pu-byprop', data.by_property.map( function ( p ) { return [ p.property, p.orders, p.total ]; } ), function ( name ) { st.property = name === '(no property)' ? '' : name; load(); } );
				fillSummary( 'ajra-pu-bymonth', data.by_month.map( function ( m ) { return [ m.month, m.orders, m.total ]; } ) );
				syncBulk();
			}
			function fillSelect( id, values, current, first ) {
				var sel = $( id ); sel.innerHTML = ''; var o0 = el( 'option', null, first ); o0.value = ''; sel.appendChild( o0 );
				values.forEach( function ( v ) { var o = el( 'option', null, v ); o.value = v; if ( v === current ) { o.selected = true; } sel.appendChild( o ); } );
			}
			function fillSummary( id, rows, onClick ) {
				var tb = $( id ).querySelector( 'tbody' ); tb.innerHTML = '';
				rows.forEach( function ( r ) {
					var tr = el( 'tr' ); tr.appendChild( el( 'td', null, r[ 0 ] ) ); var n = el( 'td', null, r[ 1 ] + ' orders' ); n.className = 'description'; tr.appendChild( n ); var m = el( 'td', null, money( r[ 2 ] ) ); m.style.textAlign = 'right'; tr.appendChild( m );
					if ( onClick ) { tr.style.cursor = 'pointer'; tr.addEventListener( 'click', function () { onClick( r[ 0 ] ); } ); }
					tb.appendChild( tr );
				} );
				if ( ! rows.length ) { var e = el( 'tr' ), c = el( 'td', null, '—' ); e.appendChild( c ); tb.appendChild( e ); }
			}
			function syncBulk() {
				var n = Object.keys( selected ).length; $( 'ajra-pu-bulk' ).hidden = ! n; $( 'ajra-pu-bulk-n' ).textContent = n;
				var inMk = st.label === 'marketing';
				document.querySelectorAll( '#ajra-pu-bulk [data-stage], #ajra-pu-bulk-marketing' ).forEach( function ( b ) { b.hidden = inMk; } );
				[ 'ajra-pu-bulk-delete', 'ajra-pu-bulk-notmk' ].forEach( function ( i ) { $( i ).hidden = ! inMk; } );
				var all = $( 'ajra-pu-all' ); all.checked = n > 0 && data && n === data.orders.length; all.indeterminate = n > 0 && data && n < data.orders.length;
			}

			var curEmail = 0, emailCount = 0, navTo = null;
			function openDetail( id, idx ) {
				api( '/ops/purchases/' + id ).then( function ( d ) {
					var o = d.order, outer = $( 'ajra-pu-detail-body' ); outer.innerHTML = '';
					outer.appendChild( el( 'h3', null, ( o.vendor || '' ) + ' ' + ( o.order_no || o.invoice_no || '' ) ) );
					// Email on the left (the main thing), the order fields on the right.
					var box = el( 'div' ), left = el( 'div' ), grid = el( 'div' ); grid.style.cssText = 'display:grid;grid-template-columns:minmax(0,1.8fr) minmax(300px,1fr);gap:24px;align-items:start;'; grid.appendChild( left ); grid.appendChild( box ); outer.appendChild( grid );
					function field( label, input ) { var w = el( 'p' ); w.appendChild( el( 'label', null, label ) ); w.appendChild( document.createElement( 'br' ) ); w.appendChild( input ); box.appendChild( w ); return input; }
					var tag = el( 'input' ); tag.type = 'text'; tag.className = 'regular-text'; tag.value = o.property_tag; field( 'Property (delivery address, else PO# tag)', tag );
					var name = el( 'input' ); name.type = 'text'; name.className = 'regular-text'; name.value = o.property_name; field( 'Property name', name );
					var total = el( 'input' ); total.type = 'text'; total.value = o.total == null ? '' : o.total; field( 'Total ($)', total );
					var notes = el( 'textarea' ); notes.rows = 2; notes.className = 'large-text'; notes.value = o.notes; field( 'Notes', notes );
					var apply = el( 'label' ); var ac = el( 'input' ); ac.type = 'checkbox'; ac.checked = true; apply.appendChild( ac ); apply.appendChild( document.createTextNode( ' Use this name for every order tagged "' + ( o.property_tag || '…' ) + '"' ) ); box.appendChild( apply );
					var bar = el( 'p' ); bar.style.marginTop = '12px';
					function save( stage ) {
						var body = { property_tag: tag.value, property_name: name.value, total: total.value, notes: notes.value }; if ( stage ) { body.stage = stage; }
						post( '/ops/purchases/' + id, body ).then( function () { return ( ac.checked && tag.value && name.value ) ? post( '/ops/purchases/properties', { tag: tag.value, name: name.value } ) : null; } ).then( function () { $( 'ajra-pu-detail' ).hidden = true; load(); } ).catch( function ( e ) { alert( e.message ); } );
					}
					var sv = el( 'button', 'button button-primary', 'Save' ); sv.type = 'button'; sv.addEventListener( 'click', function () { save( '' ); } ); bar.appendChild( sv );
					[ [ 'verified', 'Save + verified' ], [ 'charged', 'Save + charged' ], [ 'ignored', 'Ignore' ], [ 'new', 'Back to new' ] ].forEach( function ( s ) { if ( o.stage === 'posted' || o.stage === s[ 0 ] ) { return; } var b = el( 'button', 'button', s[ 1 ] ); b.type = 'button'; b.style.marginLeft = '6px'; b.addEventListener( 'click', function () { save( s[ 0 ] ); } ); bar.appendChild( b ); } );
					var mk2 = el( 'button', 'button', st.label === 'marketing' ? 'Not marketing' : 'Marketing' ); mk2.type = 'button'; mk2.addEventListener( 'click', function () { var on = st.label !== 'marketing'; var rule = on && confirm( 'Label as Marketing.\n\nAlso label future mail from this sender automatically?\nOK = yes   Cancel = only this order' ) ? 'address' : ''; post( '/ops/purchases/marketing', { ids: [ id ], label: on ? 'marketing' : '', sender_rule: rule } ).then( function () { $( 'ajra-pu-detail' ).hidden = true; load(); } ).catch( function ( e ) { alert( e.message ); } ); } ); bar.appendChild( mk2 );
					if ( st.label === 'marketing' ) { var dl2 = el( 'button', 'button button-primary', 'Delete (to Zoho Trash)' ); dl2.type = 'button'; dl2.style.marginLeft = '6px'; dl2.addEventListener( 'click', function () { post( '/ops/purchases/marketing/delete', { ids: [ id ] } ).then( function () { $( 'ajra-pu-detail' ).hidden = true; load(); } ).catch( function ( e ) { alert( e.message ); } ); } ); bar.appendChild( dl2 ); }
					var cl = el( 'button', 'button-link', 'Close' ); cl.type = 'button'; cl.style.marginLeft = '12px'; cl.addEventListener( 'click', function () { $( 'ajra-pu-detail' ).hidden = true; } ); bar.appendChild( cl ); box.appendChild( bar );
					// Numbered tabs: 1, 2, 3 ... each previews that email of the order.
					left.appendChild( el( 'h4', null, 'Emails (' + d.emails.length + ')' ) );
					var tabs = el( 'div' ), pane = el( 'div' ); tabs.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px;'; left.appendChild( tabs ); left.appendChild( pane );
					function showEmail( n ) {
						curEmail = n; emailCount = d.emails.length; navTo = showEmail;
						Array.prototype.forEach.call( tabs.children, function ( b, bi ) { b.className = 'button' + ( bi === n ? ' button-primary' : '' ); } );
						pane.innerHTML = ''; var e = d.emails[ n ]; if ( ! e ) { pane.appendChild( el( 'p', null, 'No emails.' ) ); return; }
						pane.appendChild( el( 'strong', null, day( e.received_at ) + ' ' + ( e.received_at || '' ).slice( 11, 16 ) + ' · ' + e.kind + ' · ' + e.subject ) );
						var sd = el( 'div', 'description', e.sender ); sd.style.margin = '2px 0 8px'; pane.appendChild( sd );
						var tx = e.parsed && e.parsed.body ? e.parsed.body : '';
						if ( e.parsed && e.parsed.body_debug ) { var wn = el( 'div', null, 'Only the preview could be read: ' + e.parsed.body_debug ); wn.style.cssText = 'color:#b45309;font-size:12px;margin:4px 0;'; pane.appendChild( wn ); }
						if ( e.parsed && e.parsed.html ) { pane.appendChild( emailFrame( e.parsed.html ) ); }
						if ( tx ) { var dt = el( 'details' ); dt.open = ! ( e.parsed && e.parsed.html ); dt.appendChild( el( 'summary', null, 'Plain text' ) ); var pre = el( 'pre', null, tx ); pre.style.cssText = 'max-height:360px;overflow:auto;white-space:pre-wrap;font-size:12px;background:#f8fafc;padding:8px;border-radius:6px;'; dt.appendChild( pre ); pane.appendChild( dt ); }
					}
					d.emails.forEach( function ( e, n ) { var b = el( 'button', 'button', String( n + 1 ) ); b.type = 'button'; b.title = e.kind + ' · ' + e.subject; b.addEventListener( 'click', function () { showEmail( n ); } ); tabs.appendChild( b ); } );
					showEmail( Math.min( Math.max( 0, idx || 0 ), Math.max( 0, d.emails.length - 1 ) ) );
					$( 'ajra-pu-detail' ).hidden = false;
				} ).catch( function ( e ) { alert( e.message ); } );
			}

			// events
			var qt = null; $( 'ajra-pu-q' ).addEventListener( 'input', function ( e ) { clearTimeout( qt ); qt = setTimeout( function () { st.q = e.target.value.trim(); load(); }, 350 ); } );
			$( 'ajra-pu-property' ).addEventListener( 'change', function ( e ) { st.property = e.target.value; load(); } );
			$( 'ajra-pu-vendor' ).addEventListener( 'change', function ( e ) { st.vendor = e.target.value; load(); } );
			$( 'ajra-pu-clear' ).addEventListener( 'click', function () { st = { stage: '', property: '', vendor: '', q: '', label: '' }; $( 'ajra-pu-q' ).value = ''; selected = {}; load(); } );
			$( 'ajra-pu-all' ).addEventListener( 'change', function ( e ) { selected = {}; if ( e.target.checked && data ) { data.orders.forEach( function ( o ) { selected[ o.id ] = true; } ); } render(); } );
			$( 'ajra-pu-bulk-clear' ).addEventListener( 'click', function () { selected = {}; render(); } );
			$( 'ajra-pu-bulk-marketing' ).addEventListener( 'click', function () {
				var ids = Object.keys( selected ).map( Number ); if ( ! ids.length ) { return; }
				var rule = confirm( 'Label ' + ids.length + ' order(s) as Marketing.\n\nAlso label future mail from these senders automatically?\nOK = yes (by sender address)   Cancel = only these' ) ? 'address' : '';
				post( '/ops/purchases/marketing', { ids: ids, label: 'marketing', sender_rule: rule } ).then( function () { selected = {}; load(); } ).catch( function ( e ) { alert( e.message ); } );
			} );
			$( 'ajra-pu-bulk-notmk' ).addEventListener( 'click', function () { var ids = Object.keys( selected ).map( Number ); if ( ids.length ) { post( '/ops/purchases/marketing', { ids: ids, label: '' } ).then( function () { selected = {}; load(); } ); } } );
			$( 'ajra-pu-bulk-delete' ).addEventListener( 'click', function () {
				var ids = Object.keys( selected ).map( Number ); if ( ! ids.length || ! confirm( 'Move the emails of ' + ids.length + ' order(s) to the Zoho Trash? They stay recoverable there.' ) ) { return; }
				post( '/ops/purchases/marketing/delete', { ids: ids } ).then( function ( d ) { selected = {}; $( 'ajra-pu-status' ).textContent = d.deleted + ' deleted' + ( d.zoho_errors.length ? ' · Zoho: ' + d.zoho_errors.join( ' | ' ) : '' ); load(); } ).catch( function ( e ) { alert( e.message ); } );
			} );
			$( 'ajra-pu-sender-add' ).addEventListener( 'click', function () { var v = $( 'ajra-pu-sender-in' ).value.trim(); if ( v ) { post( '/ops/purchases/senders', { add: v } ).then( function () { $( 'ajra-pu-sender-in' ).value = ''; load(); } ); } } );
			document.querySelectorAll( '#ajra-pu-bulk [data-stage]' ).forEach( function ( b ) { b.addEventListener( 'click', function () {
				var ids = Object.keys( selected ).map( Number ); if ( ! ids.length || ! window.confirm( 'Move ' + ids.length + ' order(s) to "' + LABEL[ b.getAttribute( 'data-stage' ) ] + '"?' ) ) { return; }
				post( '/ops/purchases/stage', { ids: ids, stage: b.getAttribute( 'data-stage' ) } ).then( function () { selected = {}; load(); } ).catch( function ( e ) { alert( e.message ); } );
			} ); } );
			$( 'ajra-pu-run' ).addEventListener( 'click', function () {
				if ( ! data || ! data.mailbox ) { $( 'ajra-pu-status' ).textContent = 'Mailbox not saved yet: enter shop@… in the gear panel and click Save, then Read mailbox.'; $( 'ajra-pu-settings' ).hidden = false; return; }
				var b = $( 'ajra-pu-run' ); b.disabled = true; $( 'ajra-pu-status' ).textContent = 'Reading the mailbox…';
				post( '/ops/purchases/process', {} ).then( function ( d ) { $( 'ajra-pu-status' ).textContent = d.new + ' new, ' + d.scanned + ' scanned' + ( d.complete ? ' (whole mailbox read)' : ' (more to read: click Read mailbox again)' ) + ( d.errors && d.errors.length ? ' · ' + d.errors.join( ' | ' ) : '' ); b.disabled = false; load(); } ).catch( function ( e ) { $( 'ajra-pu-status' ).textContent = e.message; b.disabled = false; } );
			} );
			$( 'ajra-pu-reparse' ).addEventListener( 'click', function () {
				var b = this; b.disabled = true; $( 'ajra-pu-status' ).textContent = 'Re-parsing…';
				post( '/ops/purchases/reparse', {} ).then( function ( d ) { $( 'ajra-pu-status' ).textContent = d.reparsed + ' re-parsed, ' + d.fetched + ' fetched' + ( d.remaining ? ', ' + d.remaining + ' left: click Re-parse again' : '' ); b.disabled = false; load(); } ).catch( function ( e ) { $( 'ajra-pu-status' ).textContent = e.message; b.disabled = false; } );
			} );
			$( 'ajra-pu-csv' ).addEventListener( 'click', function () {
				if ( ! data ) { return; }
				var rows = [ [ 'Date', 'Vendor', 'Order', 'Invoice', 'Property tag', 'Property', 'Subtotal', 'Tax', 'Total', 'Stage' ] ].concat( data.orders.map( function ( o ) { return [ o.order_date, o.vendor, o.order_no, o.invoice_no, o.property_tag, o.property_name, o.subtotal, o.tax, o.total, o.stage ]; } ) );
				var csv = rows.map( function ( r ) { return r.map( function ( v ) { return '"' + String( v == null ? '' : v ).replace( /"/g, '""' ) + '"'; } ).join( ',' ); } ).join( '\n' );
				var a = document.createElement( 'a' ); a.href = URL.createObjectURL( new Blob( [ csv ], { type: 'text/csv' } ) ); a.download = 'purchases.csv'; a.click();
			} );
			$( 'ajra-pu-detail' ).addEventListener( 'click', function ( e ) { if ( e.target === this ) { this.hidden = true; } } );
			document.addEventListener( 'keydown', function ( e ) {
				var dlg = $( 'ajra-pu-detail' ); if ( dlg.hidden ) { return; }
				if ( e.key === 'Escape' ) { dlg.hidden = true; }
				else if ( navTo && ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) && ! /^(INPUT|TEXTAREA|SELECT)$/.test( ( e.target || {} ).tagName || '' ) ) { navTo( Math.min( emailCount - 1, Math.max( 0, curEmail + ( e.key === 'ArrowRight' ? 1 : -1 ) ) ) ); }
			} );
			var lb = $( 'ajra-pu-labels' ), list = $( 'ajra-pu-label-list' );
			lb.addEventListener( 'click', function () {
				var checked = {}; list.querySelectorAll( 'input:checked' ).forEach( function ( i ) { checked[ i.value ] = true; } );
				api( '/ops/purchases/labels?mailbox=' + encodeURIComponent( $( 'ajra-pu-mailbox' ).value ) ).then( function ( d ) {
					list.innerHTML = '';
					d.labels.forEach( function ( l ) { var w = el( 'label' ); w.style.cssText = 'display:inline-block;margin-right:12px;'; var cb = el( 'input' ); cb.type = 'checkbox'; cb.name = 'labels[]'; cb.value = l.id; cb.checked = !! checked[ l.id ]; w.appendChild( cb ); w.appendChild( document.createTextNode( ' ' + l.name ) ); list.appendChild( w ); } );
					if ( ! d.labels.length ) { list.textContent = 'No labels returned. Zoho said: ' + ( d.debug || '(empty)' ); }
				} ).catch( function ( e ) { list.textContent = e.message; } );
			} );
			load();
		} )();
		</script>
		<?php
	}
}
