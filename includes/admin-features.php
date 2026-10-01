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
			'Registered Agent authorization email'                       => false,
			'University Place email defaults and brand switching'        => false,
		),
		'Compliance'         => array(
			'Compliance entities and filings (routes, views)' => false,
			'Compliance reminder job'                         => false,
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
	echo '</tbody></table></div>';
}
