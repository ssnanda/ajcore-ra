<?php
/**
 * Creates the compliance tables when they are missing.
 *
 * The table definitions live in AJCore (AJForms_Activator::get_shared_portal_table_sql). AJCore
 * only creates its shared-DB tables when someone clicks "Initialize shared schema", so tables
 * added later (these two) can be missing on a site that works fine otherwise. This reuses
 * AJCore's own SQL, limited to the two compliance tables, through dbDelta: it only adds what is
 * missing and never drops or alters data. It writes to the portal DB (the shared DB when the
 * multi-site portal is on, this site's DB otherwise), same as the compliance API reads from.
 *
 * Runs on RA activation and from the button on AJCore > AJCore RA.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Compliance_Schema {

	/** Table names without the DB prefix. */
	const TABLES = array( 'aj_portal_compliance_entities', 'aj_portal_compliance_filings' );

	public static function init() {
		add_action( 'admin_post_ajcore_ra_create_compliance_tables', array( __CLASS__, 'handle_button' ) );
	}

	/** Plugin activation hook. Quiet if AJCore isn't loaded yet; the button covers that. */
	public static function activate() {
		if ( class_exists( 'AJCore_Extensions' ) && class_exists( 'AJCore_REST_API' ) ) {
			self::ensure();
		}
	}

	private static function db() {
		if ( ! class_exists( 'AJCore_Extensions' ) || ! method_exists( 'AJCore_Extensions', 'rest_toolkit' ) ) {
			return null;
		}
		$kit = AJCore_Extensions::rest_toolkit();
		return $kit['portal_db']();
	}

	/** Name of the database the tables live in (the shared DB when the shared portal is on). */
	public static function db_name() {
		$db = self::db();
		return $db && ! empty( $db->dbname ) ? (string) $db->dbname : '';
	}

	/** @return array<string,bool>|null table (with prefix) => exists, or null when AJCore isn't ready. */
	public static function status() {
		$db = self::db();
		if ( ! $db ) {
			return null;
		}
		$out = array();
		foreach ( self::TABLES as $base ) {
			$table         = $db->prefix . $base;
			$out[ $table ] = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		return $out;
	}

	/** @return array{ok:bool,message:string} */
	public static function ensure() {
		$db = self::db();
		if ( ! $db ) {
			return array( 'ok' => false, 'message' => __( 'AJ Core is not ready (needs an up-to-date AJ Core).', 'ajcore-ra' ) );
		}
		if ( ! class_exists( 'AJForms_Activator' ) && defined( 'AJFORMS_PLUGIN_DIR' ) ) {
			require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
		}
		if ( ! class_exists( 'AJForms_Activator' ) || ! method_exists( 'AJForms_Activator', 'get_shared_portal_table_sql' ) ) {
			return array( 'ok' => false, 'message' => __( 'AJ Core table definitions not found.', 'ajcore-ra' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = AJForms_Activator::get_shared_portal_table_sql( $db->prefix, $db->get_charset_collate() );

		// Keep only the compliance tables' CREATE statements.
		$statements = array();
		foreach ( preg_split( '/(?=CREATE TABLE )/i', $sql ) as $statement ) {
			if ( '' === trim( $statement ) ) {
				continue;
			}
			foreach ( self::TABLES as $base ) {
				if ( preg_match( '/^CREATE TABLE\s+`?' . preg_quote( $db->prefix . $base, '/' ) . '`?\s*\(/i', ltrim( $statement ) ) ) {
					$statements[] = $statement;
					break;
				}
			}
		}
		if ( count( $statements ) !== count( self::TABLES ) ) {
			return array( 'ok' => false, 'message' => __( 'Could not find both compliance table definitions in AJ Core.', 'ajcore-ra' ) );
		}

		// dbDelta writes through the global $wpdb, so point it at the portal DB for the call.
		global $wpdb;
		$original = $wpdb;
		$wpdb     = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		try {
			dbDelta( $statements );
		} finally {
			$wpdb = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}

		$status  = self::status();
		$missing = array_keys( array_filter( (array) $status, static function ( $exists ) {
			return ! $exists;
		} ) );
		if ( $missing ) {
			return array( 'ok' => false, 'message' => sprintf( __( 'Could not create: %s. Check the database user has CREATE permission.', 'ajcore-ra' ), implode( ', ', $missing ) ) );
		}
		return array( 'ok' => true, 'message' => __( 'Compliance tables are ready.', 'ajcore-ra' ) );
	}

	public static function handle_button() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_create_compliance_tables' );
		$result = self::ensure();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'ajcore-ra',
					'ajcore_ra_tables'  => $result['ok'] ? 'ok' : 'fail',
					'ajcore_ra_message' => rawurlencode( $result['message'] ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
