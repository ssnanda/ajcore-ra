<?php
/**
 * AJCore > AJCore RA: read-only checklist of what this plugin provides, so it's visible
 * what has moved out of AJCore. Update the 'done' flags here in the same change that moves
 * a feature. No settings, no actions.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

add_action( 'admin_menu', 'ajcore_ra_add_features_menu', 32 );

function ajcore_ra_add_features_menu() {
	add_submenu_page( 'ajforms', __( 'AJCore RA', 'ajcore-ra' ), __( 'AJCore RA', 'ajcore-ra' ), 'manage_options', 'ajcore-ra', 'ajcore_ra_render_features_page' );
}

/** @return array<string,array<string,bool>> Group => feature label => moved into RA? */
function ajcore_ra_features() {
	return array(
		'Defaults & content' => array(
			'NC LLC Agents email defaults (subjects, follow-up, footer)' => true,
			'BOI banner and Helpful Reading links'                       => true,
			'Registered Agent authorization email'                       => true,
			'University Place email brand and defaults'                  => true,
			'University Place portal address block'                      => true,
			'University Place partner customer rules (opus, alliance_vo)' => false,
		),
		'Compliance'         => array(
			'Compliance API (entities, filings, portal)'      => true,
			'Compliance portal tab and admin screens'         => false,
			'Compliance reminder job and emails'                 => true,
		),
		'Mail & documents'   => array(
			'Mail items and mail routes'                  => false,
			'Gmail intake rules (AOO, Change of RA)'      => false,
			'Customer flyers'                             => false,
			'Service requests'                            => false,
		),
		'Integrations'      => array(
			'Rentec'                           => false,
			'UPOS Temps (Honeywell)'           => false,
			'AJPhone'                          => false,
			'Zoho Calendar and Zoho Mail'      => false,
			'Expense invoices, accounting catalog' => false,
		),
	);
}

function ajcore_ra_render_features_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$groups = ajcore_ra_features();
	$total  = 0;
	$done   = 0;
	foreach ( $groups as $features ) {
		$total += count( $features );
		$done  += count( array_filter( $features ) );
	}

	echo '<div class="wrap"><h1>' . esc_html__( 'AJCore RA', 'ajcore-ra' ) . ' <small>' . esc_html( AJCORE_RA_VERSION ) . '</small></h1>';
	echo '<p style="font-size:14px"><strong>' . esc_html( sprintf( '%d of %d moved', $done, $total ) ) . '</strong></p>';
	echo '<table class="widefat" style="max-width:640px;font-size:14px"><tbody>';
	foreach ( $groups as $group => $features ) {
		echo '<tr><th colspan="2" style="background:#1d2327;color:#fff;padding:8px 12px">' . esc_html( $group ) . '</th></tr>';
		foreach ( $features as $label => $is_done ) {
			$pill = $is_done
				? '<span style="background:#00a32a;color:#fff;padding:2px 10px;border-radius:10px;font-weight:600">Moved</span>'
				: '<span style="background:#dcdcde;color:#50575e;padding:2px 10px;border-radius:10px">Pending</span>';
			$row  = $is_done ? 'background:#edfaef;font-weight:600' : 'color:#50575e';
			echo '<tr style="' . esc_attr( $row ) . '"><td style="padding:8px 12px">' . esc_html( $label ) . '</td><td style="width:90px;text-align:right;padding:8px 12px">' . $pill . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		}
	}
	echo '</tbody></table>';
	ajcore_ra_render_tables_status();
	ajcore_ra_render_defaults_check();
	echo '</div>';
}

/**
 * Shows, per setting, whether the value in use is saved on this site, supplied by RA, or
 * AJCore's own neutral default. Read-only; compare with RA off by checking Email Templates.
 */
function ajcore_ra_render_defaults_check() {
	if ( ! function_exists( 'ajforms_get_settings_defaults' ) ) {
		return;
	}
	$keys = array(
		'wp_password_reset_subject',
		'wp_welcome_email_subject',
		'lead_followup_email_subject',
		'lead_followup_body',
		'email_footer_address',
	);

	$with_ra = ajforms_get_settings_defaults();
	remove_filter( 'ajforms_settings_defaults', 'ajcore_ra_settings_defaults' );
	$core_only = ajforms_get_settings_defaults();
	add_filter( 'ajforms_settings_defaults', 'ajcore_ra_settings_defaults' );

	$saved = get_option( 'ajforms_settings', array() );
	$saved = is_array( $saved ) ? $saved : array();

	echo '<h2 style="margin-top:24px">' . esc_html__( 'Email defaults check', 'ajcore-ra' ) . '</h2>';
	echo '<table class="widefat striped" style="max-width:900px;font-size:13px"><thead><tr><th>Setting</th><th style="width:110px">In use</th><th>Value in use</th><th>RA default</th><th>AJCore-only default</th></tr></thead><tbody>';
	foreach ( $keys as $key ) {
		$is_saved = isset( $saved[ $key ] ) && '' !== (string) $saved[ $key ];
		$in_use   = $is_saved ? (string) $saved[ $key ] : (string) ( $with_ra[ $key ] ?? '' );
		$source   = $is_saved ? 'Saved' : ( ( $with_ra[ $key ] ?? '' ) !== ( $core_only[ $key ] ?? '' ) ? 'RA default' : 'AJCore default' );
		$short    = static function ( $v ) {
			$v = trim( preg_replace( '/\s+/', ' ', (string) $v ) );
			return '' === $v ? '(blank)' : ( strlen( $v ) > 60 ? substr( $v, 0, 60 ) . '…' : $v );
		};
		echo '<tr><td>' . esc_html( $key ) . '</td><td><strong>' . esc_html( $source ) . '</strong></td><td>' . esc_html( $short( $in_use ) ) . '</td><td>' . esc_html( $short( $with_ra[ $key ] ?? '' ) ) . '</td><td>' . esc_html( $short( $core_only[ $key ] ?? '' ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

/** Compliance tables: status and a button that creates any that are missing (never drops data). */
function ajcore_ra_render_tables_status() {
	if ( ! class_exists( 'AJCore_RA_Compliance_Schema' ) ) {
		return;
	}
	if ( isset( $_GET['ajcore_ra_tables'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$ok  = 'ok' === sanitize_key( wp_unslash( $_GET['ajcore_ra_tables'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$msg = isset( $_GET['ajcore_ra_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ajcore_ra_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
	$status = AJCore_RA_Compliance_Schema::status();

	echo '<h2 style="margin-top:24px">' . esc_html__( 'Compliance tables', 'ajcore-ra' ) . '</h2>';
	if ( null === $status ) {
		echo '<p>' . esc_html__( 'AJ Core is not ready.', 'ajcore-ra' ) . '</p>';
		return;
	}
	echo '<table class="widefat" style="max-width:640px;font-size:14px"><tbody>';
	$missing = false;
	foreach ( $status as $table => $exists ) {
		$missing = $missing || ! $exists;
		$pill    = $exists
			? '<span style="background:#00a32a;color:#fff;padding:2px 10px;border-radius:10px;font-weight:600">Exists</span>'
			: '<span style="background:#d63638;color:#fff;padding:2px 10px;border-radius:10px;font-weight:600">Missing</span>';
		echo '<tr><td style="padding:8px 12px"><code>' . esc_html( $table ) . '</code></td><td style="width:90px;text-align:right;padding:8px 12px">' . $pill . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
	}
	echo '</tbody></table>';
	if ( $missing ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		echo '<input type="hidden" name="action" value="ajcore_ra_create_compliance_tables">';
		wp_nonce_field( 'ajcore_ra_create_compliance_tables' );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Create missing tables', 'ajcore-ra' ) . '</button></form>';
	}
}
