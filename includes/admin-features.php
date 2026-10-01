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
	echo '<p>' . esc_html( sprintf( '%d / %d moved', $done, $total ) ) . '</p>';
	echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;max-width:1000px">';
	foreach ( $groups as $group => $features ) {
		echo '<table class="widefat striped"><thead><tr><th colspan="2">' . esc_html( $group ) . '</th></tr></thead><tbody>';
		foreach ( $features as $label => $is_done ) {
			echo '<tr><td style="width:24px">' . ( $is_done ? '&#9745;' : '&#9744;' ) . '</td><td>' . esc_html( $label ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div></div>';
}
