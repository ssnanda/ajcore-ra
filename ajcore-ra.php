<?php
/**
 * Plugin Name:       AJ Core RA
 * Description:       Registered Agent (NC LLC Agents) extension for AJ Core. Requires the AJ Core plugin.
 * Version:           0.1.4
 * Requires PHP:      7.4
 * Author:            IT Spector LLC
 * Author URI:        https://itspector.com
 * Update URI:        false
 * License:           GPL-2.0+
 * Text Domain:       ajcore-ra
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'AJCORE_RA_VERSION', '0.1.4' );
define( 'AJCORE_RA_MIN_EXTENSION_API', 1 );
define( 'AJCORE_RA_BASENAME', plugin_basename( __FILE__ ) );

// Loads regardless of AJCore so RA stays updatable on its own.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ajcore-ra-updater.php';
AJCore_RA_Updater::init();

/**
 * Plugins load alphabetically by path ("ajcore-ra/" sorts before "ajcore/"), so AJCore
 * isn't loaded yet when this file runs. Check at plugins_loaded instead.
 */
function ajcore_ra_boot() {
	if ( ! class_exists( 'AJCore_Extensions' ) || AJCORE_RA_MIN_EXTENSION_API > (int) ( defined( 'AJCORE_EXTENSION_API' ) ? AJCORE_EXTENSION_API : 0 ) ) {
		add_action( 'admin_notices', 'ajcore_ra_missing_core_notice' );
		return;
	}

	AJCore_Extensions::register(
		'ajcore-ra',
		array(
			'name'        => 'AJCore RA',
			'version'     => AJCORE_RA_VERSION,
			'diagnostics' => 'ajcore_ra_diagnostics',
		)
	);
}
add_action( 'plugins_loaded', 'ajcore_ra_boot', 20 );

function ajcore_ra_missing_core_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'AJCore RA is inactive: it requires an active, up-to-date AJ Core plugin.', 'ajcore-ra' ) . '</p></div>';
}

/** Rows shown on AJCore > Extensions. */
function ajcore_ra_diagnostics() {
	return array(
		'Status'         => 'Active',
		'AJCore RA'      => AJCORE_RA_VERSION,
		'AJCore'         => defined( 'AJCORE_VERSION' ) ? AJCORE_VERSION : '?',
		'Extension API'  => AJCORE_EXTENSION_API . ' (min ' . AJCORE_RA_MIN_EXTENSION_API . ')',
	);
}
