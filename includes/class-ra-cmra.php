<?php
/**
 * Client Portal > CMRA: the commercial mail receiving agency side of the business (private mailboxes).
 * Lists mailbox customers (PMB, start date, ID and Form 1583 state) and opens one mailbox as a full PS Form 1583
 * record (field set ported from the old NAC Insiders CMRA screens). The record lives in customer_profiles.extra
 * ('form_1583'); the overlapping columns (PMB, start date, ID type/issuer/expiry) are kept in sync so ID reminders keep
 * working. USPS integration comes next.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_CMRA {

	const TAB = 'ra-cmra';

	public static function init() {
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ), 9 );
		add_action( 'ajcore_admin_portal_tab_render', array( __CLASS__, 'render_admin_tab' ) );
		add_action( 'admin_post_ajcore_ra_cmra_save', array( __CLASS__, 'handle_save' ) );
	}

	/** PS Form 1583 fields by section: key => label override or '' (label is derived from the key). 'type': date|check|area|text. */
	private static function sections() {
		$f = static function ( array $keys ) {
			$out = array();
			foreach ( $keys as $k ) {
				$type = 'text';
				if ( preg_match( '/(_date|_expiration|_expires|pmb_opened_date|pmb_closed_date)$/', $k ) ) {
					$type = 'date';
				} elseif ( preg_match( '/court_order|seal_present/', $k ) ) {
					$type = 'check';
				} elseif ( 'additional_recipients' === $k ) {
					$type = 'area';
				}
				$out[ $k ] = $type;
			}
			return $out;
		};
		return array(
			'Mailbox'            => $f( array( 'pmb_opened_date', 'pmb_closed_date', 'cmra_pmb_number', 'cmra_delivery_street', 'cmra_city', 'cmra_state', 'cmra_zip4', 'service_type' ) ),
			'Applicant'          => $f( array( 'applicant_last_name', 'applicant_first_name', 'applicant_middle_initial', 'applicant_phone', 'applicant_email', 'applicant_home_street', 'applicant_home_city', 'applicant_home_state', 'applicant_home_zip4', 'applicant_home_country', 'applicant_court_order_protected', 'applicant_court_order_attached' ) ),
			'Authorized person'  => $f( array( 'authorized_last_name', 'authorized_first_name', 'authorized_middle_initial', 'authorized_phone', 'authorized_email', 'authorized_home_street', 'authorized_home_city', 'authorized_home_state', 'authorized_home_zip4', 'authorized_home_country' ) ),
			'Mail transfer'      => $f( array( 'mail_transfer_street', 'mail_transfer_city', 'mail_transfer_state', 'mail_transfer_zip4', 'mail_transfer_country', 'mail_transfer_phone', 'mail_transfer_email' ) ),
			'Business'           => $f( array( 'business_name', 'business_type', 'business_street', 'business_city', 'business_state', 'business_zip4', 'business_country', 'business_phone', 'business_place_of_registration' ) ),
			'Applicant ID'       => $f( array( 'applicant_photo_id_name', 'applicant_photo_id_number', 'applicant_photo_id_issuing_entity', 'applicant_photo_id_expiration', 'applicant_photo_id_type', 'applicant_address_id_name', 'applicant_address_id_street', 'applicant_address_id_city', 'applicant_address_id_state', 'applicant_address_id_zip4', 'applicant_address_id_country', 'applicant_address_id_type' ) ),
			'Authorized person ID' => $f( array( 'authorized_photo_id_name', 'authorized_photo_id_number', 'authorized_photo_id_issuing_entity', 'authorized_photo_id_expiration', 'authorized_photo_id_type', 'authorized_address_id_name', 'authorized_address_id_street', 'authorized_address_id_city', 'authorized_address_id_state', 'authorized_address_id_zip4', 'authorized_address_id_country', 'authorized_address_id_type' ) ),
			'Other recipients'   => $f( array( 'additional_recipients' ) ),
			'Signatures'         => $f( array( 'applicant_signature', 'applicant_signature_date', 'cmra_signature', 'cmra_signature_date' ) ),
			'Notary'             => $f( array( 'notary_state', 'notary_county', 'notary_date', 'notary_applicant_name', 'notary_signature', 'notary_commission_expires', 'notary_official_seal_present' ) ),
		);
	}

	private static function label( $key ) {
		$words = array_map( static function ( $w ) {
			return in_array( $w, array( 'cmra', 'pmb', 'id', 'zip4' ), true ) ? strtoupper( $w ) : ucfirst( $w );
		}, explode( '_', $key ) );
		return implode( ' ', $words );
	}

	/** Form field => customer_profiles column kept in sync. */
	private static function column_map() {
		return array(
			'cmra_pmb_number'                    => 'pmb_number',
			'pmb_opened_date'                    => 'mailbox_start_date',
			'applicant_photo_id_type'            => 'id_type',
			'applicant_photo_id_issuing_entity'  => 'id_issuer',
			'applicant_photo_id_expiration'      => 'id_expiration_date',
		);
	}

	private static function db() {
		if ( ! class_exists( 'AJCore_Extensions' ) ) {
			return null;
		}
		$kit = AJCore_Extensions::rest_toolkit();
		$db  = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
		if ( ! $db ) {
			return null;
		}
		$t = $kit['portal_table']( 'aj_portal_customer_profiles' );
		return $kit['table_exists']( $db, $t ) ? array( 'db' => $db, 'table' => $t ) : null;
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB ), $args ), admin_url( 'admin.php' ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_cmra_save' );
		$cid = sanitize_text_field( wp_unslash( $_POST['customer_id'] ?? '' ) );
		$ctx = self::db();
		if ( '' === $cid || ! $ctx ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		$db  = $ctx['db'];
		$row = $db->get_row( $db->prepare( "SELECT extra FROM `{$ctx['table']}` WHERE customer_id = %s", $cid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		$extra = json_decode( (string) $row['extra'], true );
		$extra = is_array( $extra ) ? $extra : array();
		$rec   = array();
		foreach ( self::sections() as $fields ) {
			foreach ( $fields as $key => $type ) {
				$raw = wp_unslash( $_POST['f'][ $key ] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( 'check' === $type ) {
					$rec[ $key ] = $raw ? '1' : '';
				} elseif ( 'area' === $type ) {
					$rec[ $key ] = sanitize_textarea_field( $raw );
				} elseif ( 'date' === $type ) {
					$rec[ $key ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $raw ) ? $raw : '';
				} else {
					$rec[ $key ] = sanitize_text_field( $raw );
				}
			}
		}
		$extra['form_1583'] = $rec;
		$status = sanitize_key( wp_unslash( $_POST['form_1583_status'] ?? 'none' ) );
		$date   = sanitize_text_field( wp_unslash( $_POST['form_1583_date'] ?? '' ) );
		$data   = array(
			'extra'            => wp_json_encode( $extra ),
			'form_1583_status' => in_array( $status, array( 'none', 'sent', 'received', 'notarized' ), true ) ? $status : 'none',
			'form_1583_date'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : null,
		);
		foreach ( self::column_map() as $field => $col ) {
			if ( '' !== (string) $rec[ $field ] ) {
				$data[ $col ] = $rec[ $field ];
			}
		}
		$db->update( $ctx['table'], $data, array( 'customer_id' => $cid ) );
		wp_safe_redirect( self::url( array( 'pmb' => $cid, 'saved' => 1 ) ) );
		exit;
	}

	public static function admin_tab( $tabs ) {
		$tabs[ self::TAB ] = array( 'label' => __( 'CMRA', 'ajcore-ra' ) );
		return $tabs;
	}

	public static function render_admin_tab( $tab ) {
		if ( self::TAB === $tab ) {
			self::render();
		}
	}

	/** @return array<int,array<string,string>> Mailbox customers, lowest PMB first. */
	private static function mailboxes() {
		if ( ! class_exists( 'AJCore_Extensions' ) ) {
			return array();
		}
		$kit = AJCore_Extensions::rest_toolkit();
		$db  = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
		if ( ! $db ) {
			return array();
		}
		$t = $kit['portal_table']( 'aj_portal_customer_profiles' );
		if ( ! $kit['table_exists']( $db, $t ) ) {
			return array();
		}
		$names = class_exists( 'AJCore_RA_Website' ) ? AJCore_RA_Website::customers() : array();
		$rows  = (array) $db->get_results( "SELECT customer_id, pmb_number, mailbox_start_date, id_type, id_issuer, id_expiration_date, form_1583_status, form_1583_date, extra FROM `{$t}` WHERE pmb_number IS NOT NULL AND pmb_number <> '' ORDER BY CAST(pmb_number AS UNSIGNED), pmb_number", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as &$r ) {
			$r['name'] = isset( $names[ $r['customer_id'] ] ) ? (string) $names[ $r['customer_id'] ]['name'] : (string) $r['customer_id'];
		}
		unset( $r );
		return $rows;
	}

	private static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rows    = self::mailboxes();
		if ( ! empty( $_GET['pmb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::render_detail( sanitize_text_field( wp_unslash( $_GET['pmb'] ) ), $rows ); // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$today   = gmdate( 'Y-m-d' );
		$soon    = gmdate( 'Y-m-d', strtotime( '+60 days' ) );
		$expired = 0;
		$expsoon = 0;
		$noid    = 0;
		$no1583  = 0;
		foreach ( $rows as $r ) {
			$e = (string) $r['id_expiration_date'];
			if ( 'notarized' !== (string) $r['form_1583_status'] ) {
				$no1583++;
			}
			if ( '' === $e ) {
				$noid++;
			} elseif ( $e < $today ) {
				$expired++;
			} elseif ( $e <= $soon ) {
				$expsoon++;
			}
		}
		$chip = static function ( $n, $label, $color ) {
			return '<span style="display:inline-block;margin-right:8px;padding:4px 10px;border-radius:8px;background:#f6f7f7;border:1px solid #dcdcde"><b style="font-size:15px;color:' . esc_attr( $color ) . '">' . (int) $n . '</b> ' . esc_html( $label ) . '</span>';
		};

		echo '<div style="max-width:1100px">';
		echo '<p style="margin:12px 0">';
		echo $chip( count( $rows ), __( 'mailboxes', 'ajcore-ra' ), '#1d2327' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $chip( $expired, __( 'ID expired', 'ajcore-ra' ), $expired ? '#b32d2e' : '#1d2327' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $chip( $expsoon, __( 'ID expires in 60 days', 'ajcore-ra' ), $expsoon ? '#996800' : '#1d2327' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $chip( $noid, __( 'no ID date', 'ajcore-ra' ), $noid ? '#996800' : '#1d2327' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $chip( $no1583, __( '1583 not notarized', 'ajcore-ra' ), $no1583 ? '#996800' : '#1d2327' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<span style="margin-left:6px;padding:4px 10px;border-radius:8px;background:#fcf9e8;border:1px solid #dba617">' . esc_html__( 'USPS: not connected', 'ajcore-ra' ) . '</span>';
		echo '</p>';

		if ( ! $rows ) {
			echo '<p style="color:#646970">' . esc_html__( 'No mailboxes yet. A customer becomes a mailbox customer when their profile has a PMB number.', 'ajcore-ra' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="font-size:13px"><thead><tr>';
			foreach ( array( 'PMB', 'Customer', 'Mailbox since', 'ID type', 'ID issuer', 'ID expires', 'Form 1583' ) as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$e   = (string) $r['id_expiration_date'];
				$cls = '';
				$txt = '' !== $e ? mysql2date( get_option( 'date_format' ), $e ) : '—';
				if ( '' !== $e && $e < $today ) {
					$cls = 'color:#b32d2e;font-weight:600';
					$txt .= ' · ' . __( 'expired', 'ajcore-ra' );
				} elseif ( '' !== $e && $e <= $soon ) {
					$cls = 'color:#996800;font-weight:600';
				}
				$st    = (string) ( $r['form_1583_status'] ?: 'none' );
				$stcol = array( 'none' => '#b32d2e', 'sent' => '#996800', 'received' => '#996800', 'notarized' => '#00a32a' );
				$stlbl = array( 'none' => 'Not on file', 'sent' => 'Sent', 'received' => 'Received', 'notarized' => 'Notarized' );
				echo '<tr><td><b><a href="' . esc_url( self::url( array( 'pmb' => $r['customer_id'] ) ) ) . '">' . esc_html( $r['pmb_number'] ) . '</a></b></td><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $r['mailbox_start_date'] ? mysql2date( get_option( 'date_format' ), $r['mailbox_start_date'] ) : '—' ) . '</td><td>' . esc_html( $r['id_type'] ?: '—' ) . '</td><td>' . esc_html( $r['id_issuer'] ?: '—' ) . '</td><td style="' . esc_attr( $cls ) . '">' . esc_html( $txt ) . '</td><td style="color:' . esc_attr( $stcol[ $st ] ?? '#1d2327' ) . ';font-weight:600">' . esc_html( ( $stlbl[ $st ] ?? $st ) . ( $r['form_1583_date'] ? ' · ' . mysql2date( get_option( 'date_format' ), $r['form_1583_date'] ) : '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<details style="margin-top:16px"><summary style="cursor:pointer;font-weight:600">' . esc_html__( 'USPS integration plan', 'ajcore-ra' ) . '</summary><ul style="list-style:disc;margin:8px 0 0 20px;color:#50575e">';
		foreach ( array(
			'PS Form 1583 record per mailbox (open a PMB above); signed copy filed under Files > USPS Form 1583',
			'ID expiry reminders (already tracked above)',
			'USPS CMRA registration and reporting',
			'Mail log and notices tied to the PMB',
		) as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul></details></div>';
	}

	private static function render_detail( $cid, $rows ) {
		$row = null;
		foreach ( $rows as $r ) {
			if ( (string) $r['customer_id'] === $cid ) {
				$row = $r;
				break;
			}
		}
		echo '<div style="max-width:1100px">';
		echo '<p style="margin:12px 0"><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All mailboxes', 'ajcore-ra' ) . '</a></p>';
		if ( ! $row ) {
			echo '<p>' . esc_html__( 'Mailbox not found.', 'ajcore-ra' ) . '</p></div>';
			return;
		}
		$extra = json_decode( (string) $row['extra'], true );
		$rec   = is_array( $extra ) && isset( $extra['form_1583'] ) && is_array( $extra['form_1583'] ) ? $extra['form_1583'] : array();
		// Overlapping fields fall back to the profile columns until a record has been saved.
		foreach ( self::column_map() as $field => $col ) {
			if ( ! isset( $rec[ $field ] ) || '' === (string) $rec[ $field ] ) {
				$rec[ $field ] = (string) ( $row[ $col ] ?? '' );
			}
		}
		if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Saved.', 'ajcore-ra' ) . '</p></div>';
		}
		echo '<h2 style="margin:6px 0 12px">' . esc_html( sprintf( 'PMB %s · %s', $row['pmb_number'], $row['name'] ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ajcore_ra_cmra_save' );
		echo '<input type="hidden" name="action" value="ajcore_ra_cmra_save"><input type="hidden" name="customer_id" value="' . esc_attr( $cid ) . '">';
		$st = (string) ( $row['form_1583_status'] ?: 'none' );
		echo '<p style="display:flex;gap:12px;align-items:center;flex-wrap:wrap"><label><b>' . esc_html__( 'Form 1583', 'ajcore-ra' ) . '</b> <select name="form_1583_status">';
		foreach ( array( 'none' => 'Not on file', 'sent' => 'Sent', 'received' => 'Received', 'notarized' => 'Notarized' ) as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $st, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></label><label>' . esc_html__( 'Date', 'ajcore-ra' ) . ' <input type="date" name="form_1583_date" value="' . esc_attr( (string) $row['form_1583_date'] ) . '"></label></p>';
		foreach ( self::sections() as $title => $fields ) {
			echo '<details open style="margin:8px 0;border:1px solid #dcdcde;border-radius:6px;background:#fff"><summary style="cursor:pointer;padding:8px 12px;font-weight:600">' . esc_html( $title ) . '</summary>';
			echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px 12px;padding:4px 12px 12px">';
			foreach ( $fields as $key => $type ) {
				$val = (string) ( $rec[ $key ] ?? '' );
				echo '<label style="display:block;font-size:12px;color:#50575e' . ( 'area' === $type ? ';grid-column:1/-1' : '' ) . '">' . esc_html( self::label( $key ) ) . '<br>';
				$n = 'f[' . esc_attr( $key ) . ']';
				if ( 'check' === $type ) {
					echo '<input type="checkbox" name="' . $n . '" value="1"' . checked( '1', $val, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
				} elseif ( 'area' === $type ) {
					echo '<textarea name="' . $n . '" rows="3" style="width:100%">' . esc_textarea( $val ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput
				} else {
					echo '<input type="' . ( 'date' === $type ? 'date' : 'text' ) . '" name="' . $n . '" value="' . esc_attr( $val ) . '" style="width:100%">'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</label>';
			}
			echo '</div></details>';
		}
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'ajcore-ra' ) . '</button></p></form></div>';
	}
}
