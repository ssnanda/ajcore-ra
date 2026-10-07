<?php
/**
 * Website tab for the client portal: the customer's hosted site (e.g. nc.itspector.com) plus a
 * landing page for web services (custom-domain packages).
 *
 * - Tab: added through AJCore's 'ajcore_portal_menu_default_items' / 'ajcore_portal_tab_content'
 *   filters, so it shows only while AJCore-RA is active. Admins can still hide it in the portal
 *   menu editor.
 * - Data: no new table. Each site is one row in AJCore's existing aj_portal_entity_mappings
 *   (portal DB, so shared across connected sites): entity_type 'website', entity_key = host,
 *   entity_label = display name, metadata = JSON { status, note }. AJCore's customer view already
 *   lists these rows.
 * - Staff assign sites in AJCore > Websites. Customers can also request an address themselves
 *   (saved as a Planned site with metadata by=customer, changeable until staff start building).
 * - Packages: edited on the same staff page (one per line: Name | Price | Description).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Website {

	const TYPE    = 'website';
	const PACKAGES = 'ajcore_ra_website_packages';

	/** @return array<string,string> */
	private static function statuses() {
		return array(
			'planned'  => __( 'Planned', 'ajcore-ra' ),
			'building' => __( 'We are building it', 'ajcore-ra' ),
			'live'     => __( 'Live', 'ajcore-ra' ),
		);
	}

	public static function base_domain() {
		return (string) apply_filters( 'ajcore_ra_website_base_domain', 'itspector.com' );
	}

	public static function init() {
		add_filter( 'ajcore_portal_menu_default_items', array( __CLASS__, 'add_tab' ) );
		add_filter( 'ajcore_portal_tab_content', array( __CLASS__, 'render_tab' ), 10, 3 );
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ), 9 );
		add_action( 'ajcore_admin_portal_tab_render', array( __CLASS__, 'render_admin_tab' ) );
		add_action( 'admin_post_ajcore_ra_request_website', array( __CLASS__, 'handle_request_site' ) );
		add_filter( 'ajcore_portal_admin_post_actions', static function ( $actions ) {
			$actions[] = 'ajcore_ra_request_website'; // portal customers post here; AJCore otherwise bounces them out of wp-admin
			return $actions;
		} );
		add_action( 'admin_post_ajcore_ra_save_website', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ajcore_ra_remove_website', array( __CLASS__, 'handle_remove' ) );
		add_action( 'admin_post_ajcore_ra_save_website_packages', array( __CLASS__, 'handle_save_packages' ) );
	}

	// ── data ────────────────────────────────────────────────────────────────────

	public static function db() {
		if ( ! class_exists( 'AJCore_Extensions' ) || ! method_exists( 'AJCore_Extensions', 'rest_toolkit' ) ) {
			return null;
		}
		$kit = AJCore_Extensions::rest_toolkit();
		return array( 'db' => $kit['portal_db'](), 'kit' => $kit );
	}

	public static function table( $ctx, $suffix ) {
		return $ctx['kit']['portal_table']( $suffix );
	}

	/** @return array<int,array{key:string,label:string,status:string,note:string}> */
	public static function sites_for( $stripe_customer_id ) {
		$ctx = self::db();
		if ( ! $ctx || '' === (string) $stripe_customer_id ) {
			return array();
		}
		$db = $ctx['db'];
		$t  = self::table( $ctx, 'aj_portal_entity_mappings' );
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return array();
		}
		$rows = $db->get_results( $db->prepare( "SELECT entity_key, entity_label, metadata FROM `{$t}` WHERE stripe_customer_id = %s AND entity_type = %s ORDER BY id ASC", $stripe_customer_id, self::TYPE ) );
		return self::format_rows( $rows );
	}

	private static function format_rows( $rows ) {
		$out = array();
		foreach ( (array) $rows as $r ) {
			$meta  = json_decode( (string) $r->metadata, true );
			$meta  = is_array( $meta ) ? $meta : array();
			$out[] = array(
				'customer' => isset( $r->stripe_customer_id ) ? (string) $r->stripe_customer_id : '',
				'key'      => (string) $r->entity_key,
				'label'    => (string) $r->entity_label,
				'status'   => isset( $meta['status'] ) && isset( self::statuses()[ $meta['status'] ] ) ? $meta['status'] : 'planned',
				'note'     => isset( $meta['note'] ) ? (string) $meta['note'] : '',
				'by'       => isset( $meta['by'] ) ? (string) $meta['by'] : '',
			);
		}
		return $out;
	}

	/** Valid subdomain label: letters, digits, hyphens; 2-30 chars; no leading/trailing hyphen. */
	public static function clean_subdomain( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/\.' . preg_quote( self::base_domain(), '/' ) . '$/', '', $value );
		$value = preg_replace( '/[^a-z0-9-]+/', '', $value );
		$value = trim( $value, '-' );
		return ( strlen( $value ) >= 2 && strlen( $value ) <= 30 ) ? $value : '';
	}

	/** "Trident Audio Video, Inc" -> "tav" (example of how initials are suggested). */
	public static function suggest_subdomain( $business ) {
		$words = preg_split( '/[^A-Za-z0-9]+/', (string) $business, -1, PREG_SPLIT_NO_EMPTY );
		$skip  = array( 'inc', 'llc', 'corp', 'co', 'ltd', 'company', 'corporation', 'incorporated', 'the', 'and', 'of' );
		$words = array_values( array_filter( $words, static function ( $w ) use ( $skip ) {
			return ! in_array( strtolower( $w ), $skip, true );
		} ) );
		if ( ! $words ) {
			return '';
		}
		if ( 1 === count( $words ) ) {
			return strtolower( substr( $words[0], 0, 10 ) );
		}
		return strtolower( implode( '', array_map( static function ( $w ) {
			return $w[0];
		}, $words ) ) );
	}

	private static function packages() {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) get_option( self::PACKAGES, '' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			if ( '' !== $parts[0] ) {
				$lines[] = array(
					'name'  => $parts[0],
					'price' => isset( $parts[1] ) ? $parts[1] : '',
					'desc'  => isset( $parts[2] ) ? $parts[2] : '',
				);
			}
		}
		return $lines;
	}

	/** @return array{ok:bool,message:string,host:string} */
	public static function save_site( $customer, $subdomain, $status, $note, $label, array $meta_extra = array() ) {
		$ctx = self::db();
		if ( ! $ctx ) {
			return array( 'ok' => false, 'message' => __( 'AJ Core is not ready.', 'ajcore-ra' ), 'host' => '' );
		}
		$sub    = self::clean_subdomain( $subdomain );
		$status = isset( self::statuses()[ $status ] ) ? $status : 'planned';
		if ( '' === (string) $customer || '' === $sub ) {
			return array( 'ok' => false, 'message' => __( 'Pick a customer and a valid subdomain (2-30 letters, numbers or hyphens).', 'ajcore-ra' ), 'host' => '' );
		}
		$db   = $ctx['db'];
		$t    = self::table( $ctx, 'aj_portal_entity_mappings' );
		$host = $sub . '.' . self::base_domain();
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return array( 'ok' => false, 'message' => __( 'The entity mappings table is missing. Run "Initialize shared schema" in AJ Core.', 'ajcore-ra' ), 'host' => $host );
		}
		// One address belongs to one customer.
		$owner = $db->get_var( $db->prepare( "SELECT stripe_customer_id FROM `{$t}` WHERE entity_type = %s AND entity_key = %s LIMIT 1", self::TYPE, $host ) );
		if ( $owner && (string) $owner !== (string) $customer ) {
			return array( 'ok' => false, 'message' => sprintf( __( '%s is already assigned to another customer.', 'ajcore-ra' ), $host ), 'host' => $host );
		}
		$row    = array(
			'stripe_customer_id' => (string) $customer,
			'entity_key'         => $host,
			'entity_label'       => sanitize_text_field( (string) $label ),
			'entity_type'        => self::TYPE,
			'metadata'           => wp_json_encode( array_merge( array( 'status' => $status, 'note' => sanitize_text_field( (string) $note ) ), $meta_extra ) ),
		);
		$fmt    = array( '%s', '%s', '%s', '%s', '%s' );
		$exists = $db->get_var( $db->prepare( "SELECT id FROM `{$t}` WHERE stripe_customer_id = %s AND entity_key = %s LIMIT 1", $customer, $host ) );
		if ( $exists ) {
			$db->update( $t, $row, array( 'id' => (int) $exists ), $fmt, array( '%d' ) );
		} else {
			$db->insert( $t, $row, $fmt );
		}
		return array( 'ok' => true, 'message' => sprintf( __( 'Saved %s.', 'ajcore-ra' ), $host ), 'host' => $host );
	}

	public static function remove_site( $customer, $host ) {
		$ctx = self::db();
		if ( ! $ctx || '' === (string) $customer || '' === (string) $host ) {
			return false;
		}
		$ctx['db']->delete( self::table( $ctx, 'aj_portal_entity_mappings' ), array( 'stripe_customer_id' => (string) $customer, 'entity_key' => (string) $host, 'entity_type' => self::TYPE ), array( '%s', '%s', '%s' ) );
		return true;
	}

	/** All sites across customers, newest address order, with the customer's name. */
	public static function list_sites() {
		$sites = self::all_sites();
		$names = self::customers();
		foreach ( $sites as &$site ) {
			$site['customer_name'] = isset( $names[ $site['customer'] ] ) ? $names[ $site['customer'] ]['name'] : $site['customer'];
		}
		unset( $site );
		return $sites;
	}

	public static function statuses_public() {
		return self::statuses();
	}

	// ── customer-facing tab ─────────────────────────────────────────────────────

	public static function add_tab( $items ) {
		$items[] = array(
			'id'      => 'website',
			'label'   => __( 'Website', 'ajcore-ra' ),
			'type'    => 'built_in',
			'url'     => '',
			'enabled' => true,
		);
		return $items;
	}

	/** Where customers send website questions: the portal support email, else contactus@<this site>. */
	private static function support_email() {
		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		if ( ! empty( $settings['portal_support_email'] ) && is_email( $settings['portal_support_email'] ) ) {
			return sanitize_email( (string) $settings['portal_support_email'] );
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/^www\./i', '', strtolower( $host ) ) : '';
		return ( '' !== $host && false !== strpos( $host, '.' ) ) ? 'contactus@' . $host : '';
	}

	/** mailto link with a prefilled subject (no dependency on a contact page existing on this site). */
	private static function mailto( $subject ) {
		$email = self::support_email();
		return '' === $email ? '' : 'mailto:' . $email . '?subject=' . rawurlencode( $subject );
	}

	private static function customer_id() {
		return (string) apply_filters( 'ajcore_current_portal_customer_id', '' );
	}

	/** Subdomains customers cannot claim. */
	private static function reserved_subdomains() {
		return (array) apply_filters( 'ajcore_ra_website_reserved_subdomains', array( 'www', 'mail', 'smtp', 'ftp', 'admin', 'api', 'ns1', 'ns2', 'webmail', 'cpanel', 'portal', 'autodiscover' ) );
	}

	public static function render_tab( $html, $tab, $context ) {
		if ( 'website' !== $tab ) {
			return $html;
		}
		$customer = isset( $context['stripe_customer_id'] ) ? (string) $context['stripe_customer_id'] : '';
		$sites    = self::sites_for( $customer );
		$statuses = self::statuses();
		$base     = self::base_domain();
		$saved    = isset( $_GET['website_saved'] ) ? sanitize_key( wp_unslash( $_GET['website_saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$messages = array(
			'ok'       => array( 'is-success', __( 'Thanks. We saved your address request and will be in touch.', 'ajcore-ra' ) ),
			'invalid'  => array( 'is-error', __( 'Use 2-30 letters, numbers or hyphens, with no spaces.', 'ajcore-ra' ) ),
			'reserved' => array( 'is-error', __( 'That address is not available. Please choose another.', 'ajcore-ra' ) ),
			'taken'    => array( 'is-error', __( 'That address is already taken. Please choose another.', 'ajcore-ra' ) ),
			'fail'     => array( 'is-error', __( 'We could not save your request. Please try again.', 'ajcore-ra' ) ),
		);

		// A request the customer made themselves can be changed until we start building it.
		$editable = null;
		foreach ( $sites as $site ) {
			if ( 'customer' === $site['by'] && 'planned' === $site['status'] ) {
				$editable = $site;
				break;
			}
		}

		ob_start();
		?>
		<section class="aj-customer-portal-panel aj-ra-website">
			<h2><?php esc_html_e( 'Website', 'ajcore-ra' ); ?></h2>

			<div style="margin:0 0 16px;padding:12px 16px;border-radius:12px;background:#fef3c7;color:#92400e;font-weight:700;">
				<?php esc_html_e( 'Under development', 'ajcore-ra' ); ?>
				<span style="font-weight:500;"> &mdash; <?php esc_html_e( 'we are still building this area, but you are welcome to inquire about a website for your business.', 'ajcore-ra' ); ?></span>
			</div>

			<?php if ( isset( $messages[ $saved ] ) ) : ?>
				<div class="aj-portal-add-service-message <?php echo esc_attr( $messages[ $saved ][0] ); ?>"><?php echo esc_html( $messages[ $saved ][1] ); ?></div>
			<?php endif; ?>

			<?php foreach ( $sites as $site ) : ?>
				<?php $url = 'https://' . $site['key']; ?>
				<div class="aj-portal-account-summary" style="margin:0 0 14px;">
					<h3 style="margin:0 0 6px;"><?php echo esc_html( '' !== $site['label'] ? $site['label'] : $site['key'] ); ?></h3>
					<p style="margin:0 0 8px;">
						<?php if ( 'live' === $site['status'] ) : ?>
							<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" style="font-weight:800;font-size:18px;"><?php echo esc_html( $site['key'] ); ?></a>
						<?php else : ?>
							<strong style="font-size:18px;"><?php echo esc_html( $site['key'] ); ?></strong>
						<?php endif; ?>
						<span class="aj-ra-status" style="margin-left:8px;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;background:<?php echo 'live' === $site['status'] ? '#dcfce7;color:#166534' : '#fef3c7;color:#92400e'; ?>"><?php echo esc_html( $statuses[ $site['status'] ] ); ?></span>
					</p>
					<?php if ( '' !== $site['note'] ) : ?>
						<p style="margin:0 0 8px;"><?php echo esc_html( $site['note'] ); ?></p>
					<?php endif; ?>
					<p style="margin:0;">
						<?php if ( 'live' === $site['status'] ) : ?>
							<a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Visit website', 'ajcore-ra' ); ?></a>
						<?php endif; ?>
						<?php $change_url = self::mailto( 'Website change: ' . $site['key'] ); ?>
						<?php if ( '' !== $change_url ) : ?>
							<a class="button" href="<?php echo esc_url( $change_url, array( 'mailto' ) ); ?>"><?php esc_html_e( 'Request a change', 'ajcore-ra' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			<?php endforeach; ?>

			<?php if ( ! $sites || $editable ) : ?>
				<div class="aj-portal-account-summary" style="margin:0 0 14px;">
					<h3 style="margin:0 0 6px;"><?php echo $editable ? esc_html__( 'Change your requested address', 'ajcore-ra' ) : esc_html__( 'Request a website', 'ajcore-ra' ); ?></h3>
					<p style="margin:0 0 10px;"><?php echo esc_html( sprintf( /* translators: %s: base domain */ __( 'We design, host and maintain websites for our customers. Choose the address you would like. Your website will be at <name>.%s. You can change it until we start building.', 'ajcore-ra' ), $base ) ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
						<input type="hidden" name="action" value="ajcore_ra_request_website">
						<?php wp_nonce_field( 'ajcore_ra_request_website' ); ?>
						<label for="aj-ra-subdomain" class="screen-reader-text"><?php esc_html_e( 'Website address', 'ajcore-ra' ); ?></label>
						<input type="text" id="aj-ra-subdomain" name="subdomain" required minlength="2" maxlength="30" pattern="[A-Za-z0-9\-]{2,30}" placeholder="yourname" value="<?php echo $editable ? esc_attr( preg_replace( '/\.' . preg_quote( $base, '/' ) . '$/', '', $editable['key'] ) ) : ''; ?>" style="min-width:180px;">
						<strong>.<?php echo esc_html( $base ); ?></strong>
						<button type="submit" class="button button-primary"><?php echo $editable ? esc_html__( 'Save address', 'ajcore-ra' ) : esc_html__( 'Request this address', 'ajcore-ra' ); ?></button>
					</form>
					<p class="description" style="margin:8px 0 0;"><?php esc_html_e( '2-30 letters, numbers or hyphens.', 'ajcore-ra' ); ?></p>
				</div>
			<?php endif; ?>

			<h3 style="margin:22px 0 8px;"><?php esc_html_e( 'Use your own domain', 'ajcore-ra' ); ?></h3>
			<?php $packages = self::packages(); ?>
			<?php if ( $packages ) : ?>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
					<?php foreach ( $packages as $pkg ) : ?>
						<div class="aj-portal-account-summary">
							<h3 style="margin:0 0 4px;"><?php echo esc_html( $pkg['name'] ); ?></h3>
							<?php if ( '' !== $pkg['price'] ) : ?><p style="margin:0 0 6px;font-size:20px;font-weight:800;"><?php echo esc_html( $pkg['price'] ); ?></p><?php endif; ?>
							<?php if ( '' !== $pkg['desc'] ) : ?><p style="margin:0 0 10px;"><?php echo esc_html( $pkg['desc'] ); ?></p><?php endif; ?>
							<?php $pkg_url = self::mailto( 'Website: ' . $pkg['name'] ); ?>
							<?php if ( '' !== $pkg_url ) : ?><a class="button" href="<?php echo esc_url( $pkg_url, array( 'mailto' ) ); ?>"><?php esc_html_e( 'Email us about this', 'ajcore-ra' ); ?></a><?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="aj-portal-account-summary">
					<p style="margin:0 0 10px;"><?php esc_html_e( 'Prefer your own domain, such as yourcompany.com? We can build and host that too. Email us for a quote.', 'ajcore-ra' ); ?></p>
					<?php $quote_url = self::mailto( 'Website on my own domain' ); ?>
					<?php if ( '' !== $quote_url ) : ?><a class="button" href="<?php echo esc_url( $quote_url, array( 'mailto' ) ); ?>"><?php esc_html_e( 'Email us for a quote', 'ajcore-ra' ); ?></a><?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Customer submits (or changes) the address they want. Saved as a Planned site marked by=customer. */
	public static function handle_request_site() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in.', 'ajcore-ra' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ajcore_ra_request_website' );
		$customer = self::customer_id();
		$back     = wp_get_referer() ? remove_query_arg( 'website_saved', wp_get_referer() ) : home_url( '/' );
		$go       = static function ( $flag ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'website_saved', $flag, $back ) );
			exit;
		};
		if ( '' === $customer ) {
			$go( 'fail' );
		}
		$sub = self::clean_subdomain( isset( $_POST['subdomain'] ) ? wp_unslash( $_POST['subdomain'] ) : '' );
		if ( '' === $sub ) {
			$go( 'invalid' );
		}
		if ( in_array( $sub, self::reserved_subdomains(), true ) ) {
			$go( 'reserved' );
		}
		$host = $sub . '.' . self::base_domain();

		// Only a customer-made, still-planned request may be replaced; staff-assigned sites are left alone.
		$previous = null;
		foreach ( self::sites_for( $customer ) as $site ) {
			if ( 'customer' === $site['by'] && 'planned' === $site['status'] ) {
				$previous = $site;
				break;
			}
		}
		$user  = wp_get_current_user();
		$label = $previous ? $previous['label'] : '';
		$result = self::save_site( $customer, $sub, 'planned', __( 'Requested by the customer in the portal.', 'ajcore-ra' ), $label, array( 'by' => 'customer' ) );
		if ( ! $result['ok'] ) {
			$go( false !== strpos( $result['message'], 'already assigned' ) ? 'taken' : 'fail' );
		}
		if ( $previous && $previous['key'] !== $host ) {
			self::remove_site( $customer, $previous['key'] );
		}
		$to = self::support_email();
		if ( '' !== $to && ( ! $previous || $previous['key'] !== $host ) ) {
			wp_mail(
				$to,
				sprintf( /* translators: %s: host */ __( 'Website address requested: %s', 'ajcore-ra' ), $host ),
				sprintf( "%s (%s) asked for %s in the client portal.\nCustomer: %s", $user->display_name, $user->user_email, $host, $customer )
			);
		}
		$go( 'ok' );
	}

	// ── staff page: AJCore > Websites ───────────────────────────────────────────

	/** "Websites" in the Client Portal tab bar of AJCore admin (opens in that page). */
	public static function admin_tab( $tabs ) {
		$tabs['ra-websites'] = array( 'label' => __( 'Websites', 'ajcore-ra' ) );
		return $tabs;
	}

	public static function render_admin_tab( $tab ) {
		if ( 'ra-websites' === $tab ) {
			self::render_admin();
		}
	}

	private static function tab_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => 'ra-websites' ), $args ), admin_url( 'admin.php' ) );
	}

	public static function customers() {
		$ctx = self::db();
		if ( ! $ctx ) {
			return array();
		}
		$db = $ctx['db'];
		$t  = self::table( $ctx, 'aj_portal_stripe_customers' );
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $db->get_results( "SELECT stripe_customer_id, name, email, metadata FROM `{$t}` WHERE portal_status IS NULL OR portal_status <> 'archived' ORDER BY name ASC, email ASC" ) as $c ) {
			$meta     = json_decode( (string) $c->metadata, true );
			$business = '';
			if ( is_array( $meta ) ) {
				foreach ( array( 'business_name', 'business', 'company', 'company_name' ) as $k ) {
					if ( ! empty( $meta[ $k ] ) && is_string( $meta[ $k ] ) ) {
						$business = trim( $meta[ $k ] );
						break;
					}
				}
			}
			$out[ (string) $c->stripe_customer_id ] = array(
				'name'     => '' !== trim( (string) $c->name ) ? (string) $c->name : (string) $c->email,
				'business' => $business,
			);
		}
		return $out;
	}

	/** Every website row across customers. */
	public static function all_sites() {
		$ctx = self::db();
		if ( ! $ctx ) {
			return array();
		}
		$db = $ctx['db'];
		$t  = self::table( $ctx, 'aj_portal_entity_mappings' );
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return array();
		}
		return self::format_rows( $db->get_results( $db->prepare( "SELECT stripe_customer_id, entity_key, entity_label, metadata FROM `{$t}` WHERE entity_type = %s ORDER BY entity_key ASC", self::TYPE ) ) );
	}

	private static function back( $notice, $ok = true ) {
		wp_safe_redirect( self::tab_url( array( 'ra_notice' => rawurlencode( $notice ), 'ra_ok' => $ok ? 1 : 0 ) ) );
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_save_website' );
		$r = self::save_site(
			isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : '',
			isset( $_POST['subdomain'] ) ? wp_unslash( $_POST['subdomain'] ) : '',
			isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'planned',
			isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '',
			isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : ''
		);
		self::back( $r['message'], $r['ok'] );
	}

	public static function handle_remove() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_remove_website' );
		$customer = isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : '';
		$host     = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
		self::remove_site( $customer, $host );
		self::back( sprintf( __( 'Removed %s from the customer. The website itself was not touched.', 'ajcore-ra' ), $host ) );
	}

	public static function handle_save_packages() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_save_website_packages' );
		$raw = isset( $_POST['packages'] ) ? sanitize_textarea_field( wp_unslash( $_POST['packages'] ) ) : '';
		update_option( self::PACKAGES, $raw, false );
		self::back( __( 'Packages saved.', 'ajcore-ra' ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$customers = self::customers();
		$sites     = self::all_sites();
		$statuses  = self::statuses();
		$base      = self::base_domain();
		$suggest   = array();
		foreach ( $customers as $cid => $c ) {
			$business        = '' !== $c['business'] ? $c['business'] : $c['name'];
			$suggest[ $cid ] = array( 'sub' => self::suggest_subdomain( $business ), 'name' => $business );
		}

		echo '<div class="ajforms-settings-card"><h2>' . esc_html__( 'Websites', 'ajcore-ra' ) . '</h2>';
		if ( isset( $_GET['ra_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$ok = ! empty( $_GET['ra_ok'] ); // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['ra_notice'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}

		// Assign / edit.
		echo '<h2>' . esc_html__( 'Assign a website', 'ajcore-ra' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;max-width:1100px;">';
		echo '<input type="hidden" name="action" value="ajcore_ra_save_website">';
		wp_nonce_field( 'ajcore_ra_save_website' );
		echo '<label>' . esc_html__( 'Customer', 'ajcore-ra' ) . '<br><select name="customer" id="ajcore-ra-customer" style="min-width:280px;"><option value="">—</option>';
		foreach ( $customers as $cid => $c ) {
			echo '<option value="' . esc_attr( $cid ) . '">' . esc_html( $c['name'] . ( '' !== $c['business'] && $c['business'] !== $c['name'] ? ' — ' . $c['business'] : '' ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Address', 'ajcore-ra' ) . '<br><input type="text" name="subdomain" id="ajcore-ra-subdomain" placeholder="nc" size="14"> <code>.' . esc_html( $base ) . '</code></label>';
		echo '<label>' . esc_html__( 'Business name', 'ajcore-ra' ) . '<br><input type="text" name="label" id="ajcore-ra-label" placeholder="NC LLC Agents Inc"></label>';
		echo '<label>' . esc_html__( 'Status', 'ajcore-ra' ) . '<br><select name="status">';
		foreach ( $statuses as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Note for the customer', 'ajcore-ra' ) . '<br><input type="text" name="note" size="34"></label>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save', 'ajcore-ra' ) . '</button></form>';
		// Picking a customer fills the address and the business name; a field you typed in yourself is left alone.
		echo '<script>(function(){var s=' . wp_json_encode( $suggest ) . ';var c=document.getElementById("ajcore-ra-customer"),a=document.getElementById("ajcore-ra-subdomain"),n=document.getElementById("ajcore-ra-label");if(!c||!a||!n){return;}[a,n].forEach(function(el){el.addEventListener("input",function(){el.dataset.touched="1";});});c.addEventListener("change",function(){var v=s[c.value]||{sub:"",name:""};if(!a.dataset.touched){a.value=v.sub;}if(!n.dataset.touched){n.value=v.name;}});})();</script>';

		// Current sites.
		echo '<h2 style="margin-top:24px">' . esc_html__( 'Customer websites', 'ajcore-ra' ) . ' <small>· ' . esc_html( (string) count( $sites ) ) . '</small></h2>';
		if ( ! $sites ) {
			echo '<p>' . esc_html__( 'None yet.', 'ajcore-ra' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__( 'Address', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Customer', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Status', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Note', 'ajcore-ra' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $sites as $s ) {
				$name = isset( $customers[ $s['customer'] ] ) ? $customers[ $s['customer'] ]['name'] : $s['customer'];
				echo '<tr><td><a href="' . esc_url( 'https://' . $s['key'] ) . '" target="_blank" rel="noopener">' . esc_html( $s['key'] ) . '</a></td><td>' . esc_html( $name ) . '</td><td>' . esc_html( $statuses[ $s['status'] ] ) . '</td><td>' . esc_html( $s['note'] ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Remove this website from the customer?', 'ajcore-ra' ) ) . '\');" style="display:inline">';
				echo '<input type="hidden" name="action" value="ajcore_ra_remove_website"><input type="hidden" name="customer" value="' . esc_attr( $s['customer'] ) . '"><input type="hidden" name="host" value="' . esc_attr( $s['key'] ) . '">';
				wp_nonce_field( 'ajcore_ra_remove_website' );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Remove', 'ajcore-ra' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}

		// Packages shown on the customer landing page.
		echo '<h2 style="margin-top:24px">' . esc_html__( 'Custom-domain packages', 'ajcore-ra' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:1000px">';
		echo '<input type="hidden" name="action" value="ajcore_ra_save_website_packages">';
		wp_nonce_field( 'ajcore_ra_save_website_packages' );
		echo '<textarea name="packages" rows="5" class="large-text code" placeholder="Starter | $499 | 5-page website on your own domain">' . esc_textarea( (string) get_option( self::PACKAGES, '' ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One per line: Name | Price | Description. Blank = a "contact us for pricing" box.', 'ajcore-ra' ) . '</p>';
		echo '<button type="submit" class="button">' . esc_html__( 'Save packages', 'ajcore-ra' ) . '</button></form></div>';
	}
}
