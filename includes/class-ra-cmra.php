<?php
/**
 * Client Portal > CMRA: the commercial mail receiving agency side of the business (private mailboxes).
 * Today: who has a mailbox (PMB), when it started, and the state of their ID on file. USPS integration comes next
 * (status card below lists what it will cover).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_CMRA {

	const TAB = 'ra-cmra';

	public static function init() {
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ), 9 );
		add_action( 'ajcore_admin_portal_tab_render', array( __CLASS__, 'render_admin_tab' ) );
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
		$rows  = (array) $db->get_results( "SELECT customer_id, pmb_number, mailbox_start_date, id_type, id_issuer, id_expiration_date FROM `{$t}` WHERE pmb_number IS NOT NULL AND pmb_number <> '' ORDER BY CAST(pmb_number AS UNSIGNED), pmb_number", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
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
		$today   = gmdate( 'Y-m-d' );
		$soon    = gmdate( 'Y-m-d', strtotime( '+60 days' ) );
		$expired = 0;
		$expsoon = 0;
		$noid    = 0;
		foreach ( $rows as $r ) {
			$e = (string) $r['id_expiration_date'];
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
		echo '<span style="margin-left:6px;padding:4px 10px;border-radius:8px;background:#fcf9e8;border:1px solid #dba617">' . esc_html__( 'USPS: not connected', 'ajcore-ra' ) . '</span>';
		echo '</p>';

		if ( ! $rows ) {
			echo '<p style="color:#646970">' . esc_html__( 'No mailboxes yet. A customer becomes a mailbox customer when their profile has a PMB number.', 'ajcore-ra' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="font-size:13px"><thead><tr>';
			foreach ( array( 'PMB', 'Customer', 'Mailbox since', 'ID type', 'ID issuer', 'ID expires' ) as $h ) {
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
				echo '<tr><td><b>' . esc_html( $r['pmb_number'] ) . '</b></td><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $r['mailbox_start_date'] ? mysql2date( get_option( 'date_format' ), $r['mailbox_start_date'] ) : '—' ) . '</td><td>' . esc_html( $r['id_type'] ?: '—' ) . '</td><td>' . esc_html( $r['id_issuer'] ?: '—' ) . '</td><td style="' . esc_attr( $cls ) . '">' . esc_html( $txt ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<details style="margin-top:16px"><summary style="cursor:pointer;font-weight:600">' . esc_html__( 'USPS integration plan', 'ajcore-ra' ) . '</summary><ul style="list-style:disc;margin:8px 0 0 20px;color:#50575e">';
		foreach ( array(
			'PS Form 1583 on file per mailbox (signed, ID copies, notarization)',
			'ID expiry reminders (already tracked above)',
			'USPS CMRA registration and reporting',
			'Mail log and notices tied to the PMB',
		) as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul></details></div>';
	}
}
