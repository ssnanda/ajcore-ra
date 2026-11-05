<?php
/**
 * Gmail Intake (state-filing notification mailbox → customer Files), moved out of AJCore.
 *
 * Same /ajcore/v1 routes (/ops/gmail-intake*, /gmail-intake/* incl. the OAuth callback — so the
 * redirect URI registered in Google Cloud is unchanged), same log table (aj_gmail_intake_log, defined
 * in AJCore's activator and created on demand), same row shape and behavior. The processing, OAuth,
 * classification and filing code below is unchanged apart from where AJCore's own helpers are reached.
 *
 * Settings (`gmail_intake_*`: OAuth client, address, tokens): option `ajcore_ra_gmail_intake`, secrets
 * encrypted at rest. With the shared database on, the master publishes them to the same shared row as
 * before (`ajcore_gmail_intake_settings`) and other sites read it; settings are managed on the master.
 * Values previously kept in AJCore's `ajforms_settings` (or that shared row) are copied in once.
 *
 * UI: Client Portal admin tab "Gmail Intake" (connection settings in a collapsed box + the activity
 * list), the "Documents Filed" email template (gmail_intake) and its notification.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Gmail_Intake {

	const OPTION     = 'ajcore_ra_gmail_intake';
	const SHARED_ROW = 'ajcore_gmail_intake_settings';
	const TAB        = 'gmail-intake';

	/** @var array<string,callable> */
	private $kit;

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init() {
		self::seed_settings();
		add_action( 'rest_api_init', array( __CLASS__, 'boot_routes' ) );
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog' ) );
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ), 9 );
		add_action( 'ajcore_admin_portal_tab_render', array( __CLASS__, 'render_admin_tab' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_actions' ) );
		add_action( 'admin_post_ajcore_ra_save_gmail_intake', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ajcore_ra_gmail_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_filter( 'ajcore_additional_branded_email_templates', array( __CLASS__, 'add_templates' ) );
		add_filter( 'ajcore_preserved_setting_prefixes', array( __CLASS__, 'preserved_prefixes' ) );
	}

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public static function preserved_prefixes( $prefixes ) {
		$prefixes[] = 'wp_gmail_intake_';
		return $prefixes;
	}

	public function __construct() {
		$this->kit = class_exists( 'AJCore_Extensions' ) ? AJCore_Extensions::rest_toolkit() : array();
	}

	public static function tab_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB ), $args ), admin_url( 'admin.php' ) );
	}

	// ── AJCore helpers (forwarded) ──────────────────────────────────────────────

	private function get_portal_db() {
		return $this->kit['portal_db']();
	}

	private function get_pdb() {
		return $this->get_portal_db();
	}

	private function portal_table( $suffix ) {
		return $this->kit['portal_table']( $suffix );
	}

	private function table_exists( $db, $table ) {
		return $this->kit['table_exists']( $db, $table );
	}

	private function get_portal_stripe_customers_table() {
		return $this->portal_table( 'aj_portal_stripe_customers' );
	}

	/** AJCore's admin object: its email toolkit and its file-upload helper. */
	private static function admin() {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return null;
		}
		return AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
	}

	private function email_kit() {
		$admin = self::admin();
		return $admin && method_exists( $admin, 'get_email_toolkit' ) ? $admin->get_email_toolkit() : null;
	}

	/** Rest of AJCore's settings (email overrides etc.) overlaid with this feature's own gmail_intake_* values. */
	private function get_plugin_settings() {
		$kit  = $this->email_kit();
		$base = $kit ? (array) $kit['settings']() : array();
		return array_merge( $base, $this->get_gmail_settings() );
	}

	// ── settings ────────────────────────────────────────────────────────────────

	private static function defaults() {
		return array(
			'gmail_intake_client_id'        => '',
			'gmail_intake_client_secret'    => '',
			'gmail_intake_address'          => 'universityplaceofficesuites@gmail.com',
			'gmail_intake_access_token'     => '',
			'gmail_intake_refresh_token'    => '',
			'gmail_intake_token_expires_at' => 0,
			'gmail_intake_connected_email'  => '',
			'gmail_intake_connected_at'     => '',
			'gmail_intake_label_id'         => '',
		);
	}

	private static function secret_keys() {
		return array( 'gmail_intake_client_secret', 'gmail_intake_access_token', 'gmail_intake_refresh_token' );
	}

	private static function is_shared() {
		return function_exists( 'ajcore_is_shared_db_enabled' ) && ajcore_is_shared_db_enabled();
	}

	private static function is_master() {
		return ! function_exists( 'ajcore_is_stripe_sync_owner' ) || ajcore_is_stripe_sync_owner();
	}

	private function read_only() {
		return self::is_shared() && ! self::is_master();
	}

	private static function decrypt( $value ) {
		return function_exists( 'ajcore_decrypt_setting_value' ) ? (string) ajcore_decrypt_setting_value( (string) $value ) : (string) $value;
	}

	private static function encrypt( $value ) {
		return function_exists( 'ajcore_encrypt_setting_value' ) ? ajcore_encrypt_setting_value( (string) $value ) : (string) $value;
	}

	private static function shared_db_and_table() {
		if ( ! function_exists( 'ajcore_get_shared_db' ) ) {
			return array( null, '' );
		}
		$db = ajcore_get_shared_db();
		if ( ! $db ) {
			return array( null, '' );
		}
		$table = $db->prefix . 'aj_shared_settings';
		return $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ? array( $db, $table ) : array( null, '' );
	}

	private static function read_shared() {
		list( $db, $table ) = self::shared_db_and_table();
		if ( ! $db ) {
			return array();
		}
		$value   = $db->get_var( $db->prepare( "SELECT setting_value FROM `{$table}` WHERE setting_name = %s LIMIT 1", self::SHARED_ROW ) );
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Master only: publishes the (plain) settings, as AJCore's shared Gmail Intake row always held them. */
	private static function write_shared( array $settings ) {
		if ( ! self::is_shared() || ! self::is_master() ) {
			return false;
		}
		list( $db, $table ) = self::shared_db_and_table();
		if ( ! $db ) {
			return false;
		}
		$data = array_intersect_key( $settings, self::defaults() );
		$encoded = wp_json_encode( $data );
		if ( false === $encoded ) {
			return false;
		}
		$exists = $db->get_var( $db->prepare( "SELECT setting_name FROM `{$table}` WHERE setting_name = %s LIMIT 1", self::SHARED_ROW ) );
		if ( $exists ) {
			return false !== $db->update( $table, array( 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ), array( 'setting_name' => self::SHARED_ROW ), array( '%s', '%s' ), array( '%s' ) );
		}
		return false !== $db->insert( $table, array( 'setting_name' => self::SHARED_ROW, 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ), array( '%s', '%s', '%s' ) );
	}

	/** gmail_intake_* values (secrets decrypted); on a secondary shared-DB site the master's published values win. */
	public function get_gmail_settings() {
		$opt = get_option( self::OPTION, array() );
		$opt = is_array( $opt ) ? $opt : array();
		$out = array();
		foreach ( self::defaults() as $key => $default ) {
			$value       = isset( $opt[ $key ] ) ? $opt[ $key ] : $default;
			$out[ $key ] = in_array( $key, self::secret_keys(), true ) ? trim( self::decrypt( $value ) ) : $value;
		}
		if ( self::is_shared() && ! self::is_master() ) {
			$shared = self::read_shared();
			if ( ! empty( $shared ) ) {
				$out = array_merge( $out, array_intersect_key( $shared, $out ) );
			}
		}
		return $out;
	}

	/** Persists the gmail_intake_* keys found in $settings (OAuth connect, token refresh, disconnect, form save). */
	private function store_settings( array $settings ) {
		$opt = array( 'seeded' => 1 );
		foreach ( self::defaults() as $key => $default ) {
			$value       = array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
			$opt[ $key ] = in_array( $key, self::secret_keys(), true ) ? self::encrypt( (string) $value ) : $value;
		}
		update_option( self::OPTION, $opt, false );
		self::write_shared( array_intersect_key( $settings, self::defaults() ) );
	}

	/** One-time copy of AJCore's old `ajforms_settings` gmail_intake_* keys, else the shared row. */
	private static function seed_settings() {
		$opt = get_option( self::OPTION, null );
		if ( is_array( $opt ) && ! empty( $opt['seeded'] ) ) {
			return;
		}
		$found = array();
		if ( function_exists( 'ajforms_get_settings' ) ) {
			$legacy = ajforms_get_settings();
			foreach ( self::defaults() as $key => $default ) {
				if ( isset( $legacy[ $key ] ) && '' !== (string) $legacy[ $key ] && 0 !== $legacy[ $key ] && $legacy[ $key ] !== $default ) {
					$found[ $key ] = $legacy[ $key ];
				}
			}
		}
		if ( empty( $found ) ) {
			$found = array_intersect_key( self::read_shared(), self::defaults() );
		}
		$settings = array_merge( self::defaults(), $found );
		$store    = array( 'seeded' => 1 );
		foreach ( $settings as $key => $value ) {
			$store[ $key ] = in_array( $key, self::secret_keys(), true ) ? self::encrypt( (string) $value ) : $value;
		}
		update_option( self::OPTION, $store, false );
		if ( self::is_shared() && self::is_master() && empty( self::read_shared() ) && ! empty( $found ) ) {
			self::write_shared( $settings );
		}
	}

	public static function handle_save() {
		$gi = self::instance();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_save_gmail_intake' );
		if ( $gi->read_only() ) {
			wp_die( esc_html__( 'Gmail Intake is managed by the AJCore master site.', 'ajcore-ra' ) );
		}
		$settings                             = $gi->get_gmail_settings();
		$settings['gmail_intake_client_id']   = isset( $_POST['gmail_intake_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gmail_intake_client_id'] ) ) : '';
		$settings['gmail_intake_address']     = isset( $_POST['gmail_intake_address'] ) ? sanitize_email( wp_unslash( $_POST['gmail_intake_address'] ) ) : $settings['gmail_intake_address'];
		$secret                               = isset( $_POST['gmail_intake_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['gmail_intake_client_secret'] ) ) : '';
		if ( '' !== $secret ) { // blank keeps the saved secret
			$settings['gmail_intake_client_secret'] = $secret;
		}
		$gi->store_settings( $settings );
		wp_safe_redirect( self::tab_url( array( 'gmail-intake-saved' => 1 ) ) );
		exit;
	}

	public static function handle_disconnect() {
		$gi = self::instance();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_gmail_intake_disconnect' );
		if ( $gi->read_only() ) {
			wp_die( esc_html__( 'Gmail Intake is managed by the AJCore master site.', 'ajcore-ra' ) );
		}
		$settings = $gi->get_gmail_settings();
		foreach ( array( 'gmail_intake_access_token', 'gmail_intake_refresh_token', 'gmail_intake_connected_email', 'gmail_intake_connected_at', 'gmail_intake_label_id' ) as $field ) {
			$settings[ $field ] = '';
		}
		$settings['gmail_intake_token_expires_at'] = 0;
		$gi->store_settings( $settings );
		wp_safe_redirect( self::tab_url( array( 'gmail-intake-disconnected' => 'true' ) ) );
		exit;
	}

	// ── Client Portal admin tab ─────────────────────────────────────────────────

	public static function admin_tab( $tabs ) {
		$tabs[ self::TAB ] = array( 'label' => __( 'Gmail Intake', 'ajcore-ra' ) );
		return $tabs;
	}

	public static function render_admin_tab( $tab ) {
		if ( self::TAB !== $tab || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$gi   = self::instance();
		$open = isset( $_GET['gmail-intake-connected'] ) || isset( $_GET['gmail-intake-disconnected'] ) || isset( $_GET['gmail-intake-error'] ) || isset( $_GET['gmail-intake-saved'] );
		echo '<details class="ajforms-settings-card" style="margin-bottom:14px"' . ( $open || ! $gi->is_connected() ? ' open' : '' ) . '><summary style="cursor:pointer;font-weight:600">' . esc_html__( 'Connection', 'ajcore-ra' ) . ' — ' . esc_html( $gi->is_connected() ? __( 'connected', 'ajcore-ra' ) : __( 'not connected', 'ajcore-ra' ) ) . '</summary>';
		if ( $gi->read_only() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Gmail Intake is managed by the AJCore master site; this site uses the shared connection.', 'ajcore-ra' ) . '</p></div>';
		}
		$gi->render_settings();
		echo '</details>';
		$gi->render_tab();
	}

	private function is_connected() {
		$s = $this->get_gmail_settings();
		return '' !== (string) $s['gmail_intake_access_token'] || '' !== (string) $s['gmail_intake_refresh_token'];
	}

	/** Resolve form posts from the Gmail Intake tab. */
	public static function maybe_handle_actions() {
		if ( ! is_admin() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_GET['page'], $_GET['tab'] ) ) {
			return;
		}
		if ( 'ajforms-client-portal' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || self::TAB !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}
		self::instance()->handle_portal_gmail_intake_actions();
	}

	// ── REST routes (same URLs as before) ───────────────────────────────────────

	public function register_routes() {
		$ns    = AJCore_Extensions::rest_namespace();
		$ops   = array( 'AJCore_Extensions', 'can_manage_ops' );
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		$r = WP_REST_Server::READABLE;

		// Google redirects the browser here after consent: no nonce is possible, so security is the
		// one-time state token the handler checks.
		register_rest_route( $ns, '/gmail-intake/oauth/callback', array( 'methods' => $r, 'callback' => array( $this, 'gmail_intake_oauth_callback' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/gmail-intake/test', array( 'methods' => $r, 'callback' => array( $this, 'gmail_intake_test_connection' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/gmail-intake/process', array( 'methods' => 'POST', 'callback' => array( $this, 'gmail_intake_process_now' ), 'permission_callback' => $admin ) );

		// WP-admin siblings (cookie/nonce auth) of the /ops routes below.
		register_rest_route( $ns, '/gmail-intake/(?P<id>\d+)/preview', array( 'methods' => $r, 'callback' => array( $this, 'get_ops_gmail_intake_preview' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/gmail-intake/(?P<id>\d+)/attachment', array( 'methods' => $r, 'callback' => array( $this, 'get_ops_gmail_intake_attachment' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/gmail-intake/(?P<id>\d+)/file', array( 'methods' => 'POST', 'callback' => array( $this, 'file_ops_gmail_intake_item' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/gmail-intake/(?P<id>\d+)/mark-filed', array( 'methods' => 'POST', 'callback' => array( $this, 'mark_ops_gmail_intake_item_filed' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/gmail-intake/flyers/merge', array( 'methods' => 'POST', 'callback' => array( $this, 'flyers_merge_route' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/gmail-intake/reset', array( 'methods' => 'POST', 'callback' => array( $this, 'reset_ops_gmail_intake_log' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/gmail-intake/bulk-delete', array( 'methods' => 'POST', 'callback' => array( $this, 'bulk_delete_ops_gmail_intake_items' ), 'permission_callback' => $admin ) );

		// AJOps / iOS (ops auth).
		register_rest_route( $ns, '/ops/gmail-intake/process', array( 'methods' => 'POST', 'callback' => array( $this, 'process_ops_gmail_intake' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/gmail-intake/reset', array( 'methods' => 'POST', 'callback' => array( $this, 'reset_ops_gmail_intake_log' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/gmail-intake/bulk-delete', array( 'methods' => 'POST', 'callback' => array( $this, 'bulk_delete_ops_gmail_intake_items' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/gmail-intake/(?P<id>\d+)/resolve', array( 'methods' => 'POST', 'callback' => array( $this, 'resolve_ops_gmail_intake_item' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/gmail-intake/(?P<id>\d+)/mark-filed', array( 'methods' => 'POST', 'callback' => array( $this, 'mark_ops_gmail_intake_item_filed' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/gmail-intake/(?P<id>\d+)/preview', array( 'methods' => $r, 'callback' => array( $this, 'get_ops_gmail_intake_preview' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/gmail-intake/(?P<id>\d+)/attachment', array( 'methods' => $r, 'callback' => array( $this, 'get_ops_gmail_intake_attachment' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/gmail-intake/(?P<id>\d+)/file', array( 'methods' => 'POST', 'callback' => array( $this, 'file_ops_gmail_intake_item' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/gmail-intake/(?P<id>\d+)', array( 'methods' => $r, 'callback' => array( $this, 'get_ops_gmail_intake_item' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/gmail-intake', array(
			'methods'             => $r,
			'callback'            => array( $this, 'get_ops_gmail_intake_items' ),
			'permission_callback' => $ops,
			'args'                => array(
				'per_page' => array( 'default' => 25, 'sanitize_callback' => 'absint' ),
				'search'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	public static function catalog( $catalog ) {
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/gmail-intake/process', 'auth' => 'Admin', 'purpose' => 'Runs one pass of the Gmail Intake mailbox check now (same as the "Process Now" button in CP Settings).', 'app' => 'OPS gmail intake' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/gmail-intake', 'auth' => 'Admin', 'purpose' => 'Gmail Intake activity log with stats. Filters: search, status (filed|matched_pending_file|skipped_no_attachment|needs_review|resolved).', 'app' => 'OPS gmail intake' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/gmail-intake/{id}', 'auth' => 'Admin', 'purpose' => 'Single Gmail Intake log entry.', 'app' => 'OPS gmail intake' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/gmail-intake/{id}/resolve', 'auth' => 'Admin', 'purpose' => 'Manually resolve a needs-review entry: re-fetches the email from Gmail, files its PDF attachments to the given stripe_customer_id.', 'app' => 'OPS gmail intake' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/gmail-intake/{id}/preview', 'auth' => 'Admin', 'purpose' => 'In-app email preview: subject/sender/date/body plus the list of PDF attachments (with suggested renamed filenames).', 'app' => 'OPS gmail intake' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/gmail-intake/{id}/attachment', 'auth' => 'Admin', 'purpose' => 'Fetches one PDF attachment\'s bytes on demand (query param attachment_id) for in-app preview.', 'app' => 'OPS gmail intake' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/gmail-intake/{id}/file', 'auth' => 'Admin', 'purpose' => 'Files the given (possibly renamed) attachments to a customer with an explicit category + tag.', 'app' => 'OPS gmail intake' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/gmail-intake/reset', 'auth' => 'Admin', 'purpose' => 'Clears the whole Gmail Intake activity log (local table only; does not touch the real mailbox).', 'app' => 'OPS gmail intake' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/gmail-intake/bulk-delete', 'auth' => 'Admin', 'purpose' => 'Bulk-deletes selected Gmail Intake log entries (body: ids).', 'app' => 'OPS gmail intake' );
		return $catalog;
	}

	// ── "Documents Filed" email template (moved from AJCore's catalog) ──────────

	public static function add_templates( $templates ) {
		return array_merge( $templates, self::templates() );
	}

	private static function templates() {
		return array(
			'gmail_intake' => array(
				'label' => __( 'Documents Filed (Gmail Intake)', 'ajcore-ra' ),
				'key' => 'wp_gmail_intake',
				'sender_key' => 'wp_gmail_intake',
				'brand_variants' => false,
				'to' => __( 'Customer email supplied when intake documents are filed.', 'ajcore-ra' ),
				'fixed' => __( 'Fixed checklist: Get your EIN from the IRS at irs.gov; Open a business bank account; Get liability insurance for your business. View Your Files links to the configured customer portal page, falling back to /client-portal/.', 'ajcore-ra' ),
				'default_subject' => __( 'Your business formation documents are ready', 'ajcore-ra' ),
				'default_heading' => __( 'Your documents are in your portal', 'ajcore-ra' ),
				'default_body' => array(
					sprintf( __( 'Hi %s,', 'ajcore-ra' ), '{name}' ),
					__( "We've added the following document(s) to your client portal: {files}.", 'ajcore-ra' ),
				),
			),
		);
	}

	// ── Gmail Intake handlers (moved unchanged from AJCore) ────────────────────

	private function handle_portal_gmail_intake_actions() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['ajcore_gmail_intake_resolve_nonce'] ) ) {
			return;
		}
		check_admin_referer( 'ajcore_gmail_intake_resolve', 'ajcore_gmail_intake_resolve_nonce' );

		$log_id             = isset( $_POST['gmail_intake_log_id'] ) ? absint( $_POST['gmail_intake_log_id'] ) : 0;
		$stripe_customer_id = isset( $_POST['stripe_customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_customer_id'] ) ) : '';
		$args               = array( 'page' => 'ajforms-client-portal', 'tab' => 'gmail-intake' );

		if ( ! $log_id || '' === $stripe_customer_id ) {
			$args['portal-error'] = rawurlencode( __( 'Pick a customer before filing.', 'ajcore-ra' ) );
		} else {
			$result = $this->resolve_gmail_intake_needs_review( $log_id, $stripe_customer_id );
			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['gmail-intake-resolved'] = 1;
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function get_gmail_intake_redirect_uri() {
		return rest_url( 'ajcore/v1/gmail-intake/oauth/callback' );
	}

	public function complete_gmail_intake_oauth_connection( $code ) {
		$settings      = $this->get_plugin_settings();
		$client_id     = trim( (string) $settings['gmail_intake_client_id'] );
		$client_secret = trim( (string) $settings['gmail_intake_client_secret'] );
		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'gmail_intake_not_configured', __( 'Gmail Intake Client ID/Secret are not saved.', 'ajcore-ra' ) );
		}

		$token_response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $this->get_gmail_intake_redirect_uri(),
					'code'          => sanitize_text_field( (string) $code ),
				),
			)
		);
		if ( is_wp_error( $token_response ) ) {
			return $token_response;
		}
		$token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );
		if ( empty( $token_data['access_token'] ) ) {
			$message = ! empty( $token_data['error_description'] ) ? (string) $token_data['error_description'] : ( ! empty( $token_data['error'] ) ? (string) $token_data['error'] : __( 'Google did not return an access token.', 'ajcore-ra' ) );
			return new WP_Error( 'gmail_intake_token_exchange_failed', $message );
		}

		$access_token = sanitize_text_field( (string) $token_data['access_token'] );
		// Google only returns a refresh_token on first consent (or a forced re-consent via
		// prompt=consent) — keep the existing one on a reconnect that doesn't get a new one.
		$refresh_token = ! empty( $token_data['refresh_token'] ) ? sanitize_text_field( (string) $token_data['refresh_token'] ) : trim( (string) $settings['gmail_intake_refresh_token'] );
		$expires_in    = ! empty( $token_data['expires_in'] ) ? absint( $token_data['expires_in'] ) : 3600;

		if ( '' === $refresh_token ) {
			return new WP_Error( 'gmail_intake_no_refresh_token', __( 'Google did not grant offline access (no refresh token). Disconnect and reconnect, approving the consent screen fully.', 'ajcore-ra' ) );
		}

		$profile_response = wp_remote_get(
			'https://gmail.googleapis.com/gmail/v1/users/me/profile',
			array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $access_token ) )
		);
		if ( is_wp_error( $profile_response ) ) {
			return $profile_response;
		}
		$profile_data    = json_decode( wp_remote_retrieve_body( $profile_response ), true );
		$connected_email = ! empty( $profile_data['emailAddress'] ) ? sanitize_email( $profile_data['emailAddress'] ) : trim( (string) $settings['gmail_intake_address'] );

		$settings['gmail_intake_access_token']     = $access_token;
		$settings['gmail_intake_refresh_token']    = $refresh_token;
		$settings['gmail_intake_token_expires_at'] = time() + $expires_in;
		$settings['gmail_intake_connected_email']  = $connected_email;
		$settings['gmail_intake_connected_at']     = current_time( 'mysql' );
		$this->store_settings( $settings );

		return true;
	}

	public function get_valid_gmail_intake_access_token() {
		$settings      = $this->get_plugin_settings();
		$access_token  = trim( (string) $settings['gmail_intake_access_token'] );
		$refresh_token = trim( (string) $settings['gmail_intake_refresh_token'] );
		$expires_at    = absint( $settings['gmail_intake_token_expires_at'] );

		if ( '' === $refresh_token ) {
			return new WP_Error( 'gmail_intake_not_connected', __( 'Gmail Intake is not connected.', 'ajcore-ra' ) );
		}
		if ( '' !== $access_token && $expires_at > time() + 60 ) {
			return $access_token;
		}

		$client_id     = trim( (string) $settings['gmail_intake_client_id'] );
		$client_secret = trim( (string) $settings['gmail_intake_client_secret'] );
		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'gmail_intake_not_configured', __( 'Gmail Intake Client ID/Secret are not saved.', 'ajcore-ra' ) );
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$message = ! empty( $data['error_description'] ) ? (string) $data['error_description'] : ( ! empty( $data['error'] ) ? (string) $data['error'] : __( 'Google did not return a refreshed access token.', 'ajcore-ra' ) );
			return new WP_Error( 'gmail_intake_refresh_failed', $message );
		}

		$access_token = sanitize_text_field( (string) $data['access_token'] );
		$expires_in   = ! empty( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600;

		$settings['gmail_intake_access_token']     = $access_token;
		$settings['gmail_intake_token_expires_at'] = time() + $expires_in;
		$this->store_settings( $settings );

		return $access_token;
	}

	/** Thin request wrapper for the Gmail API, with the same raw-response debug capture used for Zoho Mail. */
	private function gmail_intake_api_request( $method, $path, $args = array() ) {
		$access_token = $this->get_valid_gmail_intake_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}
		$url = 'https://gmail.googleapis.com/gmail/v1/users/me' . $path;
		if ( ! empty( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}
		$request_args = array(
			'timeout' => 20,
			'method'  => $method,
			'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
		);
		if ( isset( $args['body'] ) ) {
			$request_args['headers']['Content-Type'] = 'application/json';
			$request_args['body']                    = wp_json_encode( $args['body'] );
		}
		$response = wp_remote_request( $url, $request_args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $raw_body, true );

		set_transient(
			'ajcore_gmail_intake_api_debug',
			array(
				'url'    => $url,
				'status' => $status_code,
				'body'   => $raw_body,
				'at'     => current_time( 'mysql' ),
			),
			15 * MINUTE_IN_SECONDS
		);

		if ( $status_code >= 400 ) {
			$message = ! empty( $data['error']['message'] ) ? (string) $data['error']['message'] : sprintf(
				/* translators: %d: HTTP status code */
				__( 'Gmail API returned an error (%d).', 'ajcore-ra' ),
				$status_code
			);
			return new WP_Error( 'gmail_intake_api_error', $message );
		}
		return is_array( $data ) ? $data : array();
	}

	/** Ensures the "AJCore-Processed"/"AJCore-NeedsReview" Gmail labels exist, returns their IDs keyed 'processed'/'review'. */
	private function gmail_intake_header_value( $headers, $name ) {
		foreach ( (array) $headers as $header ) {
			if ( isset( $header['name'] ) && 0 === strcasecmp( $header['name'], $name ) ) {
				return isset( $header['value'] ) ? (string) $header['value'] : '';
			}
		}
		return '';
	}

	/** Recursively walks a Gmail message payload's MIME parts, collecting PDF attachment descriptors into $out. */
	private function gmail_intake_collect_pdf_parts( $payload, &$out ) {
		if ( ! is_array( $payload ) ) {
			return;
		}
		$filename  = isset( $payload['filename'] ) ? (string) $payload['filename'] : '';
		$mime_type = isset( $payload['mimeType'] ) ? (string) $payload['mimeType'] : '';
		$body      = isset( $payload['body'] ) ? $payload['body'] : array();
		$is_pdf    = ( 'application/pdf' === $mime_type ) || ( '' !== $filename && 0 === strcasecmp( substr( $filename, -4 ), '.pdf' ) );
		if ( $is_pdf && '' !== $filename && ! empty( $body['attachmentId'] ) ) {
			$out[] = array(
				'filename'      => $filename,
				'attachment_id' => (string) $body['attachmentId'],
				'size'          => isset( $body['size'] ) ? (int) $body['size'] : 0,
			);
		}
		if ( ! empty( $payload['parts'] ) && is_array( $payload['parts'] ) ) {
			foreach ( $payload['parts'] as $part ) {
				$this->gmail_intake_collect_pdf_parts( $part, $out );
			}
		}
	}

	/**
	 * Best-effort company name extraction from these state-filing notification subjects. Tries,
	 * in order: "Filing: {name} (", "Filing: {name}" to end, "... for {name}" ending at a
	 * business-entity suffix, and finally the whole subject if it itself ends with a suffix
	 * (covers e.g. an EIN letter's subject being just "PAINT MY CASTLE LLC"). Returns '' if none
	 * match — callers must treat that as "needs manual review", not guess further.
	 */
	private function extract_company_name_from_subject( $subject ) {
		$subject = trim( (string) $subject );
		$suffix  = '(?:LLC|L\.L\.C\.|Inc\.?|Incorporated|Corp\.?|Corporation|LLP|L\.L\.P\.|LP|PLLC|PLC|Co\.)';

		if ( preg_match( '/Filing:\s*(.+?)\s*\(/i', $subject, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/Filing:\s*(.+)$/i', $subject, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/\bfor\s+([A-Z0-9][A-Za-z0-9&,.\'\-\s]*?\s*' . $suffix . ')\b/i', $subject, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/^([A-Z0-9][A-Za-z0-9&,.\'\-\s]*?\s*' . $suffix . ')$/i', $subject, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/** Subject first, then body — the real NC SOS approval template's subject is a generic "Congratulations..." with no company name in it at all; the name only appears in the body. */
	private function extract_company_name_from_gmail_message( $subject, $body_text ) {
		$from_subject = $this->extract_company_name_from_subject( $subject );
		if ( '' !== $from_subject ) {
			return $from_subject;
		}
		return $this->extract_company_name_from_body( $body_text );
	}

	/**
	 * Body-text fallback for extraction. The NC Secretary of State's actual filing-approval
	 * template reads "Document Filed: ARTICLES OF ORGANIZATIONfor LUX VENTUREZ LLC" — note there
	 * is NO space between the document-type name and "for" in their template, so (unlike the
	 * subject-line patterns above) this deliberately does not require a word boundary before
	 * "for"; the required business-entity suffix on the captured name is what keeps this from
	 * over-matching random body text.
	 */
	private function extract_company_name_from_body( $body_text ) {
		$body_text = (string) $body_text;
		if ( '' === trim( $body_text ) ) {
			return '';
		}
		$suffix = '(?:LLC|L\.L\.C\.|Inc\.?|Incorporated|Corp\.?|Corporation|LLP|L\.L\.P\.|LP|PLLC|PLC|Co\.)';

		if ( preg_match( '/Document Filed:\s*.*?for\s+([A-Z0-9][A-Za-z0-9&,.\'\-\s]*?\s*' . $suffix . ')\b/is', $body_text, $m ) ) {
			return trim( preg_replace( '/\s+/', ' ', $m[1] ) );
		}
		if ( preg_match( '/for\s+([A-Z0-9][A-Za-z0-9&,.\'\-\s]*?\s*' . $suffix . ')\b/i', $body_text, $m ) ) {
			return trim( preg_replace( '/\s+/', ' ', $m[1] ) );
		}
		return '';
	}

	/** Recursively walks a Gmail message payload's MIME parts, collecting the first text/plain and text/html body parts found (by reference, first-found-wins per type). */
	private function gmail_intake_collect_body_parts( $payload, &$plain, &$html ) {
		if ( ! is_array( $payload ) ) {
			return;
		}
		$mime_type = isset( $payload['mimeType'] ) ? (string) $payload['mimeType'] : '';
		$body_data = isset( $payload['body']['data'] ) ? (string) $payload['body']['data'] : '';
		if ( '' !== $body_data ) {
			$decoded = (string) base64_decode( strtr( $body_data, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( 'text/plain' === $mime_type && '' === $plain ) {
				$plain = $decoded;
			} elseif ( 'text/html' === $mime_type && '' === $html ) {
				$html = $decoded;
			}
		}
		if ( ! empty( $payload['parts'] ) && is_array( $payload['parts'] ) ) {
			foreach ( $payload['parts'] as $part ) {
				$this->gmail_intake_collect_body_parts( $part, $plain, $html );
			}
		}
	}

	/** Plain text preferred; falls back to the HTML part stripped to text (handles forwarded emails, which are often HTML-only). */
	private function gmail_intake_get_text_body( $payload ) {
		$plain = '';
		$html  = '';
		$this->gmail_intake_collect_body_parts( $payload, $plain, $html );
		if ( '' !== $plain ) {
			return $plain;
		}
		if ( '' !== $html ) {
			$text = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $html );
			$text = preg_replace( '/<br\s*\/?>|<\/p>|<\/div>|<\/tr>|<\/li>/i', "\n", (string) $text );
			$text = wp_strip_all_tags( (string) $text, false );
			return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		}
		return '';
	}

	/** Finds exactly one customer matching $company_name across Stripe and local customers. Returns null on zero or 2+ matches — never auto-file on an ambiguous guess. */
	private function find_portal_customer_by_company_name( $company_name ) {
		$company_name = trim( (string) $company_name );
		if ( '' === $company_name ) {
			return null;
		}
		$pdb         = $this->get_pdb();
		$local_table = $pdb->prefix . 'aj_portal_local_customers';

		$matches = array_merge(
			(array) $pdb->get_results( $pdb->prepare( "SELECT stripe_customer_id, name, customer_number FROM {$this->get_portal_stripe_customers_table()} WHERE name = %s", $company_name ) ),
			(array) $pdb->get_results( $pdb->prepare( "SELECT local_customer_id AS stripe_customer_id, name, customer_number FROM {$local_table} WHERE name = %s", $company_name ) )
		);

		if ( 1 !== count( $matches ) ) {
			// No unambiguous exact match — fall back to a fuzzy pass, still requiring exactly one hit.
			$like    = '%' . $pdb->esc_like( $company_name ) . '%';
			$matches = array_merge(
				(array) $pdb->get_results( $pdb->prepare( "SELECT stripe_customer_id, name, customer_number FROM {$this->get_portal_stripe_customers_table()} WHERE name LIKE %s", $like ) ),
				(array) $pdb->get_results( $pdb->prepare( "SELECT local_customer_id AS stripe_customer_id, name, customer_number FROM {$local_table} WHERE name LIKE %s", $like ) )
			);
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/**
	 * Heuristic only, pending real attachment filenames from a live test: a PDF is treated as a
	 * real filing document (and gets filed to the customer) if its filename contains one of these
	 * document-type keywords; anything else (informational flyers bundled into the same email) is
	 * skipped rather than cluttering a customer's Files with unrelated marketing PDFs.
	 */
	private function gmail_intake_pdf_looks_like_real_document( $filename ) {
		$name     = strtolower( (string) $filename );
		$keywords = array( 'articles', 'certificate', 'ein', 'notice', 'filing', 'annual-report', 'annual_report', 'order', 'letter', 'statement', 'formation', 'authority', 'organization', 'dissolution', 'amendment', 'reinstatement', 'application' );
		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $name, $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rule-based filename suggestions for the rename field in the Gmail Intake preview/file panel,
	 * added incrementally as new naming rules are specified. Current rules:
	 * - Articles of Organization → "AOO {Company Name}.pdf"
	 * Falls back to the original attachment filename if no rule matches or there's no company name yet.
	 */
	private function gmail_intake_suggest_filename( $original_filename, $company_name ) {
		$lower        = strtolower( (string) $original_filename );
		$company_name = trim( (string) $company_name );

		$rules = array(
			'application for certificate of authority' => 'COA Application',
			'articles of incorporation'                => 'AOI',
			'certificate of authority'                 => 'COA',
			'articles'                                 => 'AOO',
		);

		if ( '' !== $company_name ) {
			foreach ( $rules as $keyword => $prefix ) {
				if ( false !== strpos( $lower, $keyword ) ) {
					return $prefix . ' ' . $company_name . '.pdf';
				}
			}
		}

		return $original_filename;
	}

	/**
	 * Best-effort, dependency-free PDF text extraction — no vendor library, no shell-out (this
	 * mailbox's real attachment filenames are opaque hashes, so the filename alone can't tell us
	 * what a document is; the actual PDF text can). Walks stream...endstream objects, tries
	 * gzuncompress() for FlateDecode-compressed ones, and pulls the parenthesized string operands
	 * of the Tj/TJ text-showing operators. Good enough to search for known keywords — not meant
	 * to reproduce exact layout/text fidelity, and won't find text in scanned/image-only PDFs.
	 */
	private function gmail_intake_extract_pdf_text( $binary ) {
		$binary = (string) $binary;
		if ( '' === $binary || ! preg_match_all( '/stream\r?\n(.*?)endstream/s', $binary, $stream_matches ) ) {
			return '';
		}

		$text = '';
		foreach ( $stream_matches[1] as $stream ) {
			$decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$content = ( false !== $decoded ) ? $decoded : $stream;

			if ( preg_match_all( '/\(((?:\\\\.|[^()])*)\)\s*Tj/', $content, $tj ) ) {
				foreach ( $tj[1] as $piece ) {
					$text .= $this->gmail_intake_unescape_pdf_string( $piece ) . ' ';
				}
			}
			if ( preg_match_all( '/\[((?:\\\\.|[^\[\]])*)\]\s*TJ/', $content, $tj_arrays ) ) {
				foreach ( $tj_arrays[1] as $array_body ) {
					if ( preg_match_all( '/\(((?:\\\\.|[^()])*)\)/', $array_body, $pieces ) ) {
						foreach ( $pieces[1] as $piece ) {
							$text .= $this->gmail_intake_unescape_pdf_string( $piece );
						}
						$text .= ' ';
					}
				}
			}
		}

		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	private function gmail_intake_unescape_pdf_string( $s ) {
		$s = preg_replace_callback( '/\\\\([0-7]{1,3})/', function ( $m ) {
			return chr( octdec( $m[1] ) );
		}, $s );
		return str_replace( array( '\\(', '\\)', '\\\\', '\\n', '\\r', '\\t' ), array( '(', ')', '\\', "\n", "\r", "\t" ), $s );
	}

	/**
	 * Content-based rules for suggesting a filename + category from a Gmail Intake attachment's
	 * actual PDF text. Extend $flyer_rules (or add another branch above it) as more document
	 * types get identified — this list grows incrementally as the user specifies each one.
	 * Returns null (no rule matched) if the caller should fall back to the filename-only guess.
	 */
	private function gmail_intake_classify_pdf_content( $pdf_text, $company_name, $subject = '', $body_text = '' ) {
		$company_name = trim( (string) $company_name );

		$result = $this->gmail_intake_match_classification_rules( (string) $pdf_text, $company_name );
		if ( null !== $result ) {
			return $result;
		}

		// The PDF's own extracted text didn't match anything — either it's a genuinely scanned/
		// image-only page (the state's stamped charter certificate has no text layer at all), or
		// our dependency-free extractor failed on this particular PDF's internal encoding (e.g. a
		// modern compressed object stream, which happened for a real Change of Registered Office/
		// Agent confirmation — its text simply never made it into $pdf_text). Try the subject line
		// next (some SOS/NC templates put the filing type there), then the email body — the actual
		// live template puts it there instead: "Document Filed: ARTICLES OF ORGANIZATIONfor
		// {company}" appears in the forwarded message body, not the subject (confirmed against a
		// real Patriot Pilot Cars LLC filing 2026-07-27) — see extract_company_name_from_body(),
		// which already relies on this same body text for the same reason.
		$result = $this->gmail_intake_match_classification_rules( (string) $subject, $company_name );
		if ( null !== $result ) {
			return $result;
		}
		return $this->gmail_intake_match_classification_rules( (string) $body_text, $company_name );
	}

	/** Shared keyword rules, run against the PDF's own extracted text or (as a fallback) the
	 *  email subject line, then the email body — see gmail_intake_classify_pdf_content() above. */
	private function gmail_intake_match_classification_rules( $haystack, $company_name ) {
		$lower = strtolower( $haystack );
		if ( '' === $lower ) {
			return null;
		}

		// Other formation filings (corporations, out-of-state companies registering in NC): one generic
		// category, a short name per document type. Checked before Articles of Organization because a
		// multi-page application can mention other filings in its text. Order matters: the application
		// (whose text also says "certificate of authority") must be tested before the certificate itself.
		$formation_rules = array(
			'application for certificate of authority' => 'COA Application of ',
			'articles of incorporation'                => 'AOI of ',
			'certificate of authority'                 => 'COA of ',
		);
		if ( '' !== $company_name ) {
			foreach ( $formation_rules as $keyword => $prefix ) {
				if ( false !== strpos( $lower, $keyword ) ) {
					return array(
						'filename' => $prefix . $company_name,
						'category' => 'Formation Documents',
					);
				}
			}
		}

		if ( '' !== $company_name && false !== strpos( $lower, 'articles of organization' ) ) {
			return array(
				'filename' => 'AOO of ' . $company_name,
				'category' => 'Articles of Organization',
			);
		}

		// SOS/NC's confirmation that a Change of Registered Office/Agent filing went through —
		// this is the proof document for the "RA Change Filed with SOS/NC" service status, so it's
		// per-company like the AOO scan above, not a generic flyer. Own category ("Change of RA")
		// rather than the "Others" catch-all it used to land in — see the ajcore_get_portal_file_
		// settings() backfill in ajcore.php that makes sure this category actually exists.
		if ( '' !== $company_name && false !== strpos( $lower, 'change of registered office and/or registered agent' ) ) {
			return array(
				'filename' => 'RA Change Confirmation - ' . $company_name,
				'category' => 'Change of RA',
			);
		}

		$flyer_rules = array(
			'rural rise'           => array( 'label' => 'Rural Rise' ),
			'misleading mailings'  => array( 'label' => 'Misleading Mailings Targeting New Companies' ),
			'notice to employers'  => array( 'label' => 'Important Notice to Employers' ),
		);
		foreach ( $flyer_rules as $keyword => $rule ) {
			if ( false !== strpos( $lower, $keyword ) ) {
				return array(
					'filename' => 'Flyer ' . $rule['label'],
					'category' => 'SOSNC Flyers',
					'link'     => isset( $rule['link'] ) ? $rule['link'] : '',
				);
			}
		}

		return null;
	}

	private function gmail_intake_build_filename( $customer_number, $company_name, $original_filename ) {
		$base  = preg_replace( '/\.pdf$/i', '', (string) $original_filename );
		$label = ucwords( str_replace( array( '_', '-' ), ' ', $base ) );

		$keyword_labels = array(
			'articles'      => 'Articles of Organization',
			'certificate'   => 'Certificate',
			'ein'           => 'EIN Letter',
			'annual-report' => 'Annual Report',
			'annual_report' => 'Annual Report',
			'formation'     => 'Formation Document',
			'authority'     => 'Certificate of Authority',
			'organization'  => 'Articles of Organization',
			'dissolution'   => 'Dissolution',
			'amendment'     => 'Amendment',
			'reinstatement' => 'Reinstatement',
		);
		$lower = strtolower( $base );
		foreach ( $keyword_labels as $keyword => $friendly ) {
			if ( false !== strpos( $lower, $keyword ) ) {
				$label = $friendly;
				break;
			}
		}

		$parts    = array_filter( array( trim( (string) $customer_number ), trim( (string) $company_name ), $label, current_time( 'Y-m-d' ) ) );
		$filename = implode( ' - ', $parts ) . '.pdf';
		return preg_replace( '/[\\\\\/:*?"<>|]/', '', $filename );
	}

	/**
	 * Polls the connected Gmail Intake inbox for messages not yet in our own log table, matches
	 * each to a customer via the company name in its subject or body, and logs the result
	 * (matched_pending_file or needs_review — see log_gmail_intake_item()). Dedup is entirely
	 * local (by gmail_message_id in aj_gmail_intake_log), NOT via Gmail labels — a Gmail label is
	 * permanent and lives in the real shared mailbox, so a bug here used to be very hard to undo.
	 * Returns a summary array for the Test/Process-Now UI.
	 */
	public function process_gmail_intake_inbox( $max_messages = 20, $show_all = false ) {
		if ( ! $this->ensure_gmail_intake_log_table() ) {
			return new WP_Error( 'no_table', __( 'Gmail Intake log table could not be created.', 'ajcore-ra' ) );
		}

		// Was 'in:inbox -from:...' — but a mailbox whose forwarding filter also archives
		// (skips the inbox, a common setup for a dedicated intake box) never carries the
		// INBOX label at all, so that query silently matched nothing even though messages
		// were sitting right there in All Mail (root cause of "Test Connection: 100 total
		// messages" vs "Process Now: checked 0"). -in:spam -in:trash keeps out the junk
		// in:inbox used to exclude as a side effect (messages.list already excludes spam/trash
		// by default, but this makes it explicit rather than relying on that default).
		//
		// Filter by subject content, NOT sender: a "Show all mailbox messages" pass on 2026-08-07
		// showed every real filing forward (e.g. "NC Secretary of State Document Filed: ... for
		// TherapyQuest, PLLC") comes FROM sandip@intlord.com — this staff mailbox forwards its own
		// filings there. The old '-from:sandip@intlord.com' exclusion (added to quiet unrelated
		// dev/test noise from that same address) was silently blocking every real match; '-from:
		// google.com' is left off entirely now for the same reason — automated Google Voice/
		// Payments mail already can't match this subject filter, so excluding by sender added risk
		// for no benefit. "sosnc.gov" never actually appeared in the matched messages' indexed text
		// (0 results even though real filings were present) — the reliable signal that's actually in
		// the data is the subject line itself, so that's what's matched on now.
		$query = '-in:spam -in:trash';
		if ( ! $show_all ) {
			$query = '(subject:"Secretary of State" OR sosnc.gov) ' . $query;
		}

		$list = $this->gmail_intake_api_request(
			'GET',
			'/messages',
			array(
				'query' => array(
					'q'          => $query,
					'maxResults' => max( 1, min( 50, (int) $max_messages ) ),
				),
			)
		);
		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$summary = array(
			'checked'               => 0,
			'filed'                 => 0,
			'matched_pending_file'  => 0,
			'needs_review'          => 0,
			'skipped_no_attachment' => 0,
			'errors'                => array(),
			'query'                 => $query,
			'result_size_estimate'  => isset( $list['resultSizeEstimate'] ) ? (int) $list['resultSizeEstimate'] : count( (array) ( isset( $list['messages'] ) ? $list['messages'] : array() ) ),
			// Every message the query matched, whether newly processed this run or already
			// logged from a previous run — this is the actual list a staff member asked to see,
			// not just a count, so a zero-match query is visibly a zero-match query rather than
			// silence.
			'messages'              => array(),
		);

		foreach ( (array) ( isset( $list['messages'] ) ? $list['messages'] : array() ) as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}
			$message_id = (string) $item['id'];

			// Already logged (any status) — never re-fetch or reprocess. Dedup lives entirely in
			// our own table now, not as a Gmail label on the message (a Gmail label is permanent
			// and shared with the real mailbox, so a bug here used to be very hard to undo). Still
			// shown in the returned list (from the local log row, no extra Gmail API call) so a
			// re-run doesn't look like it found nothing.
			$existing_log = $this->fetch_gmail_intake_log_row_by_message_id( $message_id );
			if ( $existing_log ) {
				$summary['messages'][] = array(
					'id'      => $message_id,
					'subject' => (string) $existing_log['subject'],
					'sender'  => (string) $existing_log['sender'],
					'status'  => (string) $existing_log['status'] . ' (already logged)',
				);
				continue;
			}

			$message = $this->gmail_intake_api_request( 'GET', '/messages/' . rawurlencode( $message_id ), array( 'query' => array( 'format' => 'full' ) ) );
			if ( is_wp_error( $message ) ) {
				$summary['errors'][] = $message->get_error_message();
				continue;
			}

			$summary['checked']++;
			$headers = isset( $message['payload']['headers'] ) ? $message['payload']['headers'] : array();
			$subject = $this->gmail_intake_header_value( $headers, 'Subject' );
			$sender  = $this->gmail_intake_header_value( $headers, 'From' );
			$date_ts = strtotime( (string) $this->gmail_intake_header_value( $headers, 'Date' ) );

			$body_text    = $this->gmail_intake_get_text_body( isset( $message['payload'] ) ? $message['payload'] : array() );
			$company_name = $this->extract_company_name_from_gmail_message( $subject, $body_text );
			$customer     = '' !== $company_name ? $this->find_portal_customer_by_company_name( $company_name ) : null;

			if ( ! $customer ) {
				$summary['needs_review']++;
				$summary['messages'][] = array( 'id' => $message_id, 'subject' => $subject, 'sender' => $sender, 'status' => 'needs_review' );
				$this->log_gmail_intake_item( array(
					'gmail_message_id'       => $message_id,
					'subject'                => $subject,
					'sender'                 => $sender,
					'snippet'                => isset( $message['snippet'] ) ? $message['snippet'] : '',
					'company_name_extracted' => $company_name,
					'status'                 => 'needs_review',
					'received_at'            => false !== $date_ts ? $date_ts : null,
				) );
				continue;
			}

			// Matched to a customer — for now, only identify which real filing-document PDFs
			// would be filed and log the match. Actual upload/renaming is intentionally paused
			// pending a confirmed attachment-naming convention; nothing is written to the
			// customer's Files yet. (Re-enable by restoring the download+upload_files_for_portal_
			// customer() loop that used to sit here — see git history — once that's ready.)
			$pdf_parts = array();
			$this->gmail_intake_collect_pdf_parts( isset( $message['payload'] ) ? $message['payload'] : array(), $pdf_parts );

			$pending_filenames = array();
			foreach ( $pdf_parts as $pdf ) {
				if ( ! $this->gmail_intake_pdf_looks_like_real_document( $pdf['filename'] ) ) {
					continue; // Looks like a bundled flyer, not a real filing document — skip it.
				}
				$pending_filenames[] = $pdf['filename'];
			}

			$summary['matched_pending_file']++;
			$summary['messages'][] = array( 'id' => $message_id, 'subject' => $subject, 'sender' => $sender, 'status' => 'matched_pending_file' );

			$this->log_gmail_intake_item( array(
				'gmail_message_id'       => $message_id,
				'subject'                => $subject,
				'sender'                 => $sender,
				'snippet'                => isset( $message['snippet'] ) ? $message['snippet'] : '',
				'company_name_extracted' => $company_name,
				'status'                 => 'matched_pending_file',
				'stripe_customer_id'     => $customer->stripe_customer_id,
				'customer_name'          => $customer->name,
				'filed_filenames'        => $pending_filenames,
				'received_at'            => false !== $date_ts ? $date_ts : null,
			) );
		}

		return $summary;
	}

	private function get_gmail_intake_log_table() {
		return $this->get_pdb()->prefix . 'aj_gmail_intake_log';
	}

	private function ensure_gmail_intake_log_table() {
		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();
		if ( $this->table_exists( $pdb, $table ) ) {
			return true;
		}
		if ( ! class_exists( 'AJForms_Activator' ) && defined( 'AJFORMS_PLUGIN_DIR' ) ) {
			require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
		}
		if ( class_exists( 'AJForms_Activator' ) && method_exists( 'AJForms_Activator', 'create_gmail_intake_log_table_in_portal_db' ) ) {
			AJForms_Activator::create_gmail_intake_log_table_in_portal_db( $pdb->prefix, $pdb->get_charset_collate(), $pdb );
		}
		return $this->table_exists( $pdb, $table );
	}

	private function gmail_intake_log_exists( $gmail_message_id ) {
		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();
		return (bool) $pdb->get_var( $pdb->prepare( "SELECT id FROM `{$table}` WHERE gmail_message_id = %s", $gmail_message_id ) );
	}

	/** Like gmail_intake_log_exists() but returns the row (subject/sender/status) instead of a
	 *  bool, so a re-run of process_gmail_intake_inbox() can list already-logged messages
	 *  without an extra Gmail API call. Null when not logged. */
	private function fetch_gmail_intake_log_row_by_message_id( $gmail_message_id ) {
		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();
		$row   = $pdb->get_row( $pdb->prepare( "SELECT subject, sender, status FROM `{$table}` WHERE gmail_message_id = %s", $gmail_message_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Clears everything EXCEPT already-filed/resolved rows — those are a real record of what was
	 * actually filed to a customer and must survive a reset. Only pending rows (needs_review,
	 * matched_pending_file, skipped_no_attachment) are cleared so the next Refresh re-evaluates
	 * them from scratch.
	 */
	public function reset_gmail_intake_log() {
		if ( ! $this->ensure_gmail_intake_log_table() ) {
			return new WP_Error( 'no_table', __( 'Gmail Intake log table could not be created.', 'ajcore-ra' ) );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();
		$pdb->query( "DELETE FROM `{$table}` WHERE status NOT IN ( 'filed', 'resolved' )" );
		return true;
	}

	/** Deletes specific Gmail Intake log rows by id (bulk-selection "Delete" action). */
	public function bulk_delete_gmail_intake_log_rows( $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'no_ids', __( 'No items selected.', 'ajcore-ra' ) );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pdb->query( $pdb->prepare( "DELETE FROM `{$table}` WHERE id IN ({$placeholders})", $ids ) );
		return count( $ids );
	}

	private function log_gmail_intake_item( $data ) {
		if ( ! $this->ensure_gmail_intake_log_table() ) {
			return 0;
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();

		$gmail_message_id = isset( $data['gmail_message_id'] ) ? sanitize_text_field( (string) $data['gmail_message_id'] ) : '';
		if ( '' === $gmail_message_id ) {
			return 0;
		}

		$row = array(
			// Not sanitize_text_field() — it strip_tags()-strips anything that looks like a tag,
			// which silently deletes the "<email@domain>" portion of a "Display Name <addr>"
			// From/Subject header. Escaped properly with esc_html() at every render site instead.
			'subject'                => mb_substr( wp_check_invalid_utf8( (string) ( $data['subject'] ?? '' ), true ), 0, 500 ),
			'sender'                 => mb_substr( wp_check_invalid_utf8( (string) ( $data['sender'] ?? '' ), true ), 0, 255 ),
			'snippet'                => sanitize_textarea_field( (string) ( $data['snippet'] ?? '' ) ),
			'company_name_extracted' => mb_substr( sanitize_text_field( (string) ( $data['company_name_extracted'] ?? '' ) ), 0, 255 ),
			'status'                 => sanitize_key( (string) ( $data['status'] ?? 'needs_review' ) ),
			'stripe_customer_id'     => sanitize_text_field( (string) ( $data['stripe_customer_id'] ?? '' ) ),
			'customer_name'          => sanitize_text_field( (string) ( $data['customer_name'] ?? '' ) ),
			'filed_filenames'        => wp_json_encode( array_values( (array) ( $data['filed_filenames'] ?? array() ) ) ),
			'error_message'          => sanitize_textarea_field( (string) ( $data['error_message'] ?? '' ) ),
			'received_at'            => ! empty( $data['received_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $data['received_at'] ) : null,
			'updated_at'             => current_time( 'mysql' ),
		);

		$existing_id = (int) $pdb->get_var( $pdb->prepare( "SELECT id FROM `{$table}` WHERE gmail_message_id = %s", $gmail_message_id ) );
		if ( $existing_id ) {
			$pdb->update( $table, $row, array( 'id' => $existing_id ) );
			return $existing_id;
		}

		$row['gmail_message_id'] = $gmail_message_id;
		$row['created_at']       = current_time( 'mysql' );
		$pdb->insert( $table, $row );
		return (int) $pdb->insert_id;
	}

	private function find_portal_customer_by_stripe_id( $stripe_customer_id ) {
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$pdb                = $this->get_pdb();
		if ( 0 === strpos( $stripe_customer_id, 'local_' ) ) {
			$local_table = $pdb->prefix . 'aj_portal_local_customers';
			return $pdb->get_row( $pdb->prepare( "SELECT local_customer_id AS stripe_customer_id, name, customer_number, email FROM `{$local_table}` WHERE local_customer_id = %s", $stripe_customer_id ) );
		}
		return $pdb->get_row( $pdb->prepare( "SELECT stripe_customer_id, name, customer_number, email FROM {$this->get_portal_stripe_customers_table()} WHERE stripe_customer_id = %s", $stripe_customer_id ) );
	}

	/**
	 * Manually resolves a "needs review" Gmail Intake log entry: re-fetches the original message
	 * from Gmail (the raw PDF was never stored, only metadata), files any real filing-document PDF
	 * attachments to the chosen customer, and marks the log row resolved. Called by the AJOps
	 * "needs review" queue via AJCore_REST_API::resolve_ops_gmail_intake_item().
	 */
	public function resolve_gmail_intake_needs_review( $log_id, $stripe_customer_id ) {
		if ( ! $this->ensure_gmail_intake_log_table() ) {
			return new WP_Error( 'no_table', __( 'Gmail Intake log table could not be created.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();
		$row   = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $log_id ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Gmail Intake log entry not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}

		$customer = $this->find_portal_customer_by_stripe_id( $stripe_customer_id );
		if ( ! $customer ) {
			return new WP_Error( 'customer_not_found', __( 'Customer not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}

		$message = $this->gmail_intake_api_request( 'GET', '/messages/' . rawurlencode( $row->gmail_message_id ), array( 'query' => array( 'format' => 'full' ) ) );
		if ( is_wp_error( $message ) ) {
			return $message;
		}

		$pdf_parts = array();
		$this->gmail_intake_collect_pdf_parts( isset( $message['payload'] ) ? $message['payload'] : array(), $pdf_parts );

		$filed_filenames = array();
		$errors          = array();
		foreach ( $pdf_parts as $pdf ) {
			if ( ! $this->gmail_intake_pdf_looks_like_real_document( $pdf['filename'] ) ) {
				continue;
			}
			$attachment = $this->gmail_intake_api_request( 'GET', '/messages/' . rawurlencode( $row->gmail_message_id ) . '/attachments/' . rawurlencode( $pdf['attachment_id'] ) );
			if ( is_wp_error( $attachment ) || empty( $attachment['data'] ) ) {
				$errors[] = is_wp_error( $attachment ) ? $attachment->get_error_message() : __( 'Attachment had no data.', 'ajcore-ra' );
				continue;
			}
			$binary = base64_decode( strtr( $attachment['data'], '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $binary ) {
				continue;
			}

			$tmp_path     = wp_tempnam( $pdf['filename'] );
			file_put_contents( $tmp_path, $binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$new_filename = $this->gmail_intake_build_filename( $customer->customer_number, $customer->name, $pdf['filename'] );
			$files        = array(
				'name'     => $new_filename,
				'type'     => 'application/pdf',
				'tmp_name' => $tmp_path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $tmp_path ),
			);
			$result = self::admin()->upload_files_for_portal_customer( $customer->stripe_customer_id, $files, 'Gmail Intake', '' );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$filed_filenames[] = $new_filename;
			}
			if ( file_exists( $tmp_path ) ) {
				unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		if ( empty( $filed_filenames ) ) {
			return new WP_Error(
				'resolve_failed',
				! empty( $errors ) ? implode( ' | ', $errors ) : __( 'No filing-document PDF attachments were found on this email.', 'ajcore-ra' ),
				array( 'status' => 400 )
			);
		}

		$pdb->update(
			$table,
			array(
				'status'             => 'resolved',
				'stripe_customer_id' => $customer->stripe_customer_id,
				'customer_name'      => $customer->name,
				'filed_filenames'    => wp_json_encode( $filed_filenames ),
				'error_message'      => implode( ' | ', $errors ),
				'resolved_at'        => current_time( 'mysql' ),
				'resolved_by'        => get_current_user_id(),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => (int) $row->id )
		);

		return true;
	}

	/**
	 * Marks a pending log entry as filed WITHOUT uploading/attaching anything — for emails staff
	 * already handled some other way (filed manually, a duplicate, not something that needs a
	 * customer file). Just clears it out of the Pending queue and records who/when.
	 */
	public function mark_gmail_intake_item_filed( $log_id ) {
		if ( ! $this->ensure_gmail_intake_log_table() ) {
			return new WP_Error( 'no_table', __( 'Gmail Intake log table could not be created.', 'ajcore-ra' ) );
		}
		$pdb    = $this->get_pdb();
		$table  = $this->get_gmail_intake_log_table();
		$log_id = absint( $log_id );

		$row = $pdb->get_row( $pdb->prepare( "SELECT id FROM `{$table}` WHERE id = %d", $log_id ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Gmail Intake log entry not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}

		$pdb->update(
			$table,
			array(
				'status'      => 'filed',
				'resolved_at' => current_time( 'mysql' ),
				'resolved_by' => get_current_user_id(),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $log_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/** Fetches the source Gmail message for a log row and lists its PDF attachments, for in-app preview (no more "open in Gmail" link). */
	public function get_gmail_intake_message_preview( $log_id ) {
		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();
		$row   = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $log_id ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Gmail Intake log entry not found.', 'ajcore-ra' ) );
		}

		$message = $this->gmail_intake_api_request( 'GET', '/messages/' . rawurlencode( $row->gmail_message_id ), array( 'query' => array( 'format' => 'full' ) ) );
		if ( is_wp_error( $message ) ) {
			return $message;
		}

		$headers   = isset( $message['payload']['headers'] ) ? $message['payload']['headers'] : array();
		$payload   = isset( $message['payload'] ) ? $message['payload'] : array();
		$subject   = $this->gmail_intake_header_value( $headers, 'Subject' );
		$body_text = $this->gmail_intake_get_text_body( $payload );

		// needs_review rows (no matched customer, e.g. a brand-new company formation with no
		// portal customer yet) have an empty customer_name — company_name_extracted is what was
		// used for the failed match attempt, and is still the best company name we have for
		// suggesting a filename/classifying the PDF. Without this fallback, the rules below (which
		// all require a non-empty company name) could never fire for exactly the highest-value
		// case: a freshly filed Articles of Organization for a company not onboarded yet.
		$company_name = '' !== trim( (string) $row->customer_name ) ? (string) $row->customer_name : (string) $row->company_name_extracted;

		$pdf_parts = array();
		$this->gmail_intake_collect_pdf_parts( $payload, $pdf_parts );

		$attachments = array();
		foreach ( $pdf_parts as $pdf ) {
			$looks_like_document = $this->gmail_intake_pdf_looks_like_real_document( $pdf['filename'] );
			$suggested_filename  = $this->gmail_intake_suggest_filename( $pdf['filename'], $company_name );
			$suggested_category  = '';
			$related_link        = '';

			// Real attachment filenames from this mailbox are opaque hashes, not descriptive —
			// the only reliable signal is the PDF's actual text content.
			$fetched = $this->gmail_intake_api_request( 'GET', '/messages/' . rawurlencode( $row->gmail_message_id ) . '/attachments/' . rawurlencode( $pdf['attachment_id'] ) );
			if ( ! is_wp_error( $fetched ) && ! empty( $fetched['data'] ) ) {
				$binary = base64_decode( strtr( $fetched['data'], '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if ( false !== $binary ) {
					$pdf_text   = $this->gmail_intake_extract_pdf_text( $binary );
					$classified = $this->gmail_intake_classify_pdf_content( $pdf_text, $company_name, $subject, $body_text );
					if ( null !== $classified ) {
						$suggested_filename  = $classified['filename'];
						$suggested_category  = $classified['category'];
						$related_link        = isset( $classified['link'] ) ? $classified['link'] : '';
						$looks_like_document = true;
					}
				}
			}

			$attachments[] = array(
				'attachment_id'       => $pdf['attachment_id'],
				'filename'            => $pdf['filename'],
				'size'                => $pdf['size'],
				'looks_like_document' => $looks_like_document,
				'suggested_filename'  => $suggested_filename,
				'suggested_category'  => $suggested_category,
				'related_link'        => $related_link,
			);
		}

		return array(
			'id'          => (int) $row->id,
			'subject'     => $subject,
			'sender'      => $this->gmail_intake_header_value( $headers, 'From' ),
			'date'        => $this->gmail_intake_header_value( $headers, 'Date' ),
			'body_text'   => $body_text,
			'attachments' => $attachments,
		);
	}

	/** Fetches one PDF attachment's bytes on demand (never stored) for in-app preview or download. */
	public function get_gmail_intake_attachment_data( $log_id, $attachment_id ) {
		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();
		$row   = $pdb->get_row( $pdb->prepare( "SELECT gmail_message_id FROM `{$table}` WHERE id = %d", (int) $log_id ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Gmail Intake log entry not found.', 'ajcore-ra' ) );
		}

		$attachment = $this->gmail_intake_api_request( 'GET', '/messages/' . rawurlencode( $row->gmail_message_id ) . '/attachments/' . rawurlencode( $attachment_id ) );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( empty( $attachment['data'] ) ) {
			return new WP_Error( 'no_data', __( 'Attachment had no data.', 'ajcore-ra' ) );
		}
		$binary = base64_decode( strtr( $attachment['data'], '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $binary ) {
			return new WP_Error( 'decode_failed', __( 'Could not decode attachment data.', 'ajcore-ra' ) );
		}

		return array(
			'mime_type'   => 'application/pdf',
			'data_base64' => base64_encode( $binary ), // phpcs:ignore WordPress.PHP.DiscouragedFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * Files the given (possibly staff-renamed) attachments from a Gmail Intake email to a
	 * customer's Files, and marks the log row filed. Each attachment carries its own category
	 * (they can be different document types on the same email); $tag is shared across the whole
	 * batch. This is the explicit "attach to customer with labels/tags" action — separate from the
	 * automatic matching in process_gmail_intake_inbox(), which only ever logs matched_pending_file.
	 */
	public function file_gmail_intake_attachments( $log_id, $stripe_customer_id, $tag, $attachments, $notify = false ) {
		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();
		$row   = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $log_id ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Gmail Intake log entry not found.', 'ajcore-ra' ) );
		}
		if ( empty( $attachments ) || ! is_array( $attachments ) ) {
			return new WP_Error( 'no_attachments', __( 'Select at least one attachment to file.', 'ajcore-ra' ) );
		}

		$customer = $this->find_portal_customer_by_stripe_id( $stripe_customer_id );
		if ( ! $customer ) {
			return new WP_Error( 'customer_not_found', __( 'Customer not found.', 'ajcore-ra' ) );
		}

		$filed_filenames  = array();
		$filed_categories = array();
		$errors           = array();
		foreach ( $attachments as $attachment ) {
			$attachment_id = isset( $attachment['attachment_id'] ) ? (string) $attachment['attachment_id'] : '';
			$filename      = isset( $attachment['filename'] ) ? sanitize_file_name( (string) $attachment['filename'] ) : '';
			$category      = isset( $attachment['category'] ) ? sanitize_text_field( (string) $attachment['category'] ) : '';
			if ( '' === $attachment_id || '' === $filename ) {
				continue;
			}
			if ( ! preg_match( '/\.pdf$/i', $filename ) ) {
				$filename .= '.pdf';
			}

			// Flyers are one shared file for every customer: share the existing copy instead of importing another.
			if ( 'sosnc flyers' === strtolower( $category ) ) {
				$flyer_key     = $this->flyer_key( $filename );
				$flyer_file_id = '' !== $flyer_key ? $this->flyer_canonical_id( $flyer_key ) : 0;
				if ( $flyer_file_id && $this->flyer_share_file( $flyer_file_id, (string) $customer->email, $this->flyer_customer_user_id( $customer->stripe_customer_id ) ) ) {
					$filed_filenames[]             = $filename;
					$filed_categories[ $filename ] = $category;
					continue;
				}
			}

			$fetched = $this->gmail_intake_api_request( 'GET', '/messages/' . rawurlencode( $row->gmail_message_id ) . '/attachments/' . rawurlencode( $attachment_id ) );
			if ( is_wp_error( $fetched ) || empty( $fetched['data'] ) ) {
				$errors[] = is_wp_error( $fetched ) ? $fetched->get_error_message() : __( 'Attachment had no data.', 'ajcore-ra' );
				continue;
			}
			$binary = base64_decode( strtr( $fetched['data'], '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $binary ) {
				continue;
			}

			$tmp_path = wp_tempnam( $filename );
			file_put_contents( $tmp_path, $binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$files = array(
				'name'     => $filename,
				'type'     => 'application/pdf',
				'tmp_name' => $tmp_path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $tmp_path ),
			);
			$result = self::admin()->upload_files_for_portal_customer( $customer->stripe_customer_id, $files, $category, $tag );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$filed_filenames[]            = $filename;
				$filed_categories[ $filename ] = $category;
			}
			if ( file_exists( $tmp_path ) ) {
				unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		if ( empty( $filed_filenames ) ) {
			return new WP_Error( 'file_failed', ! empty( $errors ) ? implode( ' | ', $errors ) : __( 'No attachments were filed.', 'ajcore-ra' ) );
		}

		$pdb->update(
			$table,
			array(
				'status'             => 'filed',
				'stripe_customer_id' => $customer->stripe_customer_id,
				'customer_name'      => $customer->name,
				'filed_filenames'    => wp_json_encode( $filed_filenames ),
				'error_message'      => implode( ' | ', $errors ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => (int) $row->id )
		);

		$notified = false;
		if ( $notify && ! empty( $customer->email ) ) {
			$notified = $this->send_gmail_intake_filed_notification( $customer->stripe_customer_id, $customer->name, $customer->email, $filed_filenames, $filed_categories );
		}

		return array( 'notified' => $notified );
	}

	/**
	 * Notifies a customer that document(s) from the Gmail Intake mailbox were just filed to
	 * their portal Files — short and to the point (modeled after, but much shorter than, the real
	 * NC SOS approval email): what was filed and a
	 * getting-started checklist. Subject/heading/body are settings-overridable like every other
	 * branded email (see resolve_email_copy()); the checklist itself is not (yet) editable.
	 */
	public function send_gmail_intake_filed_notification( $stripe_customer_id, $customer_name, $customer_email, $filed_filenames, $filed_categories = array() ) {
		$customer_email = sanitize_email( (string) $customer_email );
		if ( ! is_email( $customer_email ) ) {
			return false;
		}

		$kit       = $this->email_kit();
		if ( ! $kit ) {
			return false;
		}
		$settings  = $this->get_plugin_settings();
		$brand     = $kit['brand']( $stripe_customer_id, $customer_email );
		$sender    = $kit['sender']( $settings, 'wp_gmail_intake_from_email', 'wp_gmail_intake_from_name' );
		$site_name = $brand['site_name'];

		$file_list = implode( ', ', array_map( 'sanitize_text_field', (array) $filed_filenames ) );

		$email_tokens = array(
			'{name}'  => '' !== trim( (string) $customer_name ) ? $customer_name : $customer_email,
			'{files}' => '' !== $file_list ? $file_list : __( 'your documents', 'ajcore-ra' ),
		);

		$template = self::templates()['gmail_intake'];
		$default_subject = $template['default_subject'];
		$default_heading = $template['default_heading'];
		$default_body    = $template['default_body'];

		$subject_template = ! empty( $settings['wp_gmail_intake_subject'] ) ? sanitize_text_field( (string) $settings['wp_gmail_intake_subject'] ) : $default_subject;
		$subject          = $kit['brand_subject']( strtr( $subject_template, $email_tokens ), $brand );

		$copy = $kit['copy_raw']( $settings, 'wp_gmail_intake_heading', 'wp_gmail_intake_body', $default_heading, $default_body, $email_tokens );

		// Group filed files as Important / Extra (File Settings); only when categories are known.
		$paragraphs = $copy['paragraphs'];
		if ( ! empty( $filed_categories ) ) {
			$file_settings = ajcore_get_portal_file_settings();
			$grouped       = array( 'important' => array(), 'extra' => array(), 'other' => array() );
			foreach ( (array) $filed_filenames as $filed_name ) {
				$grouped[ ajcore_portal_file_category_group( $filed_categories[ $filed_name ] ?? '', $file_settings ) ][] = sanitize_text_field( $filed_name );
			}
			$group_labels = array(
				'important' => __( 'Important', 'ajcore-ra' ),
				'extra'     => __( 'Extra', 'ajcore-ra' ),
				'other'     => __( 'Other', 'ajcore-ra' ),
			);
			foreach ( $grouped as $group => $names ) {
				if ( $names ) {
					$paragraphs[] = $group_labels[ $group ] . ': ' . implode( ', ', $names );
				}
			}
		}

		$portal_page_id = absint( get_option( 'ajcore_customer_portal_page_id', 0 ) );
		$portal_url     = $portal_page_id ? get_permalink( $portal_page_id ) : home_url( '/client-portal/' );

		$message = $kit['render'](
			array(
				'kicker'          => $site_name,
				'heading'         => $copy['heading'],
				'paragraphs'      => $paragraphs,
				'checklist_title' => __( 'Your next steps:', 'ajcore-ra' ),
				'checklist_items' => array(
					__( 'Get your EIN from the IRS at irs.gov', 'ajcore-ra' ),
					__( 'Open a business bank account', 'ajcore-ra' ),
					__( 'Get liability insurance for your business', 'ajcore-ra' ),
				),
				'cta_text'        => __( 'View Your Files', 'ajcore-ra' ),
				'cta_url'         => $portal_url,
				'footer_note'     => __( 'Log in to your client portal to view and download these files any time.', 'ajcore-ra' ),
			)
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $sender['from_email'] ) ) {
			$headers[] = 'From: ' . $sender['from_name'] . ' <' . $sender['from_email'] . '>';
			$headers[] = 'Reply-To: ' . $sender['from_email'];
		}

		return (bool) $kit['send']( $customer_email, $subject, $message, $headers, $sender['from_email'], $sender['from_name'], isset( $sender['profile'] ) ? $sender['profile'] : '' );
	}

	// ── REST handlers ─────────────────────────────────────────────────────────

	/**
	 * Handles the browser redirect Google sends back after the admin approves (or denies) the
	 * "Connect Gmail" consent screen — same state-token pattern and non-JSON redirect response as
	 * zoho_mail_oauth_callback() above, for the same reason (an external OAuth redirect carries no
	 * X-WP-Nonce, so this route can't be capability-gated).
	 */
	public function gmail_intake_oauth_callback( WP_REST_Request $request ) {
		$settings_page_url = self::tab_url();

		$error = sanitize_text_field( (string) $request->get_param( 'error' ) );
		if ( '' !== $error ) {
			wp_safe_redirect( add_query_arg( 'gmail-intake-error', $error, $settings_page_url ) );
			exit;
		}

		$state          = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$expected_state = get_transient( 'ajcore_gmail_intake_oauth_state' );
		delete_transient( 'ajcore_gmail_intake_oauth_state' );
		if ( '' === $state || ! $expected_state || ! hash_equals( (string) $expected_state, $state ) ) {
			wp_safe_redirect( add_query_arg( 'gmail-intake-error', __( 'Could not verify this request (expired link) — click Connect Gmail again.', 'ajcore-ra' ), $settings_page_url ) );
			exit;
		}

		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		if ( '' === $code ) {
			wp_safe_redirect( add_query_arg( 'gmail-intake-error', __( 'Google did not return an authorization code.', 'ajcore-ra' ), $settings_page_url ) );
			exit;
		}

		$admin = $this;
		$result = $admin->complete_gmail_intake_oauth_connection( $code );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'gmail-intake-error', $result->get_error_message(), $settings_page_url ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'gmail-intake-connected', 'true', $settings_page_url ) );
		exit;
	}

	/** "Test Connection" button on the Gmail Intake settings page. */
	public function gmail_intake_test_connection( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$admin = $this;

		$access_token = $admin->get_valid_gmail_intake_access_token();
		if ( is_wp_error( $access_token ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $access_token->get_error_message() ) );
		}

		$response = wp_remote_get(
			'https://gmail.googleapis.com/gmail/v1/users/me/profile',
			array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $access_token ) )
		);
		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $response->get_error_message() ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['emailAddress'] ) ) {
			$message = ! empty( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Gmail did not return a profile.', 'ajcore-ra' );
			return rest_ensure_response( array( 'success' => false, 'message' => $message ) );
		}

		return rest_ensure_response(
			array(
				'success'        => true,
				'email_address'  => (string) $data['emailAddress'],
				'messages_total' => isset( $data['messagesTotal'] ) ? (int) $data['messagesTotal'] : 0,
			)
		);
	}

	/** "Process Now" button — runs one pass of AJForms_Admin::process_gmail_intake_inbox() on demand. */
	public function gmail_intake_process_now( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$admin = $this;
		$show_all = rest_sanitize_boolean( $request->get_param( 'show_all' ) );
		$result   = $admin->process_gmail_intake_inbox( 20, $show_all );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $result->get_error_message() ) );
		}

		return rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
	}

	private function format_gmail_intake_log_row( $row ) {
		$row = (array) $row;
		return array(
			'id'                     => (int) $row['id'],
			'gmail_message_id'       => (string) $row['gmail_message_id'],
			'subject'                => (string) $row['subject'],
			'sender'                 => (string) $row['sender'],
			'snippet'                => (string) $row['snippet'],
			'company_name_extracted' => (string) $row['company_name_extracted'],
			'status'                 => (string) $row['status'],
			'stripe_customer_id'     => (string) $row['stripe_customer_id'],
			'customer_name'          => (string) $row['customer_name'],
			'filed_filenames'        => ! empty( $row['filed_filenames'] ) ? (array) json_decode( (string) $row['filed_filenames'], true ) : array(),
			'error_message'          => (string) $row['error_message'],
			'resolved_at'            => (string) $row['resolved_at'],
			'resolved_by'            => (int) $row['resolved_by'],
			'received_at'            => (string) $row['received_at'],
			'created_at'             => (string) $row['created_at'],
			'updated_at'             => (string) $row['updated_at'],
		);
	}

	private function fetch_gmail_intake_log_row( $id ) {
		$pdb   = $this->get_portal_db();
		$table = $this->get_gmail_intake_log_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", absint( $id ) ), ARRAY_A );
	}

	public function get_ops_gmail_intake_items( WP_REST_Request $request ) {
		$pdb   = $this->get_portal_db();
		$table = $this->get_gmail_intake_log_table();
		if ( ! $this->table_exists( $pdb, $table ) ) {
			return rest_ensure_response( array( 'items' => array(), 'stats' => array( 'total' => 0, 'filed' => 0, 'matched_pending_file' => 0, 'needs_review' => 0, 'resolved' => 0, 'skipped_no_attachment' => 0 ) ) );
		}

		$where  = array( '1=1' );
		$params = array();

		$search = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?: '' ) );
		if ( '' !== $search ) {
			$like    = '%' . $pdb->esc_like( $search ) . '%';
			$where[] = '( subject LIKE %s OR sender LIKE %s OR company_name_extracted LIKE %s OR customer_name LIKE %s )';
			$params  = array_merge( $params, array( $like, $like, $like, $like ) );
		}

		$status = sanitize_key( (string) ( $request->get_param( 'status' ) ?: '' ) );
		if ( in_array( $status, array( 'filed', 'matched_pending_file', 'skipped_no_attachment', 'needs_review', 'resolved' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$per_page = min( 500, max( 1, absint( $request->get_param( 'per_page' ) ?: 200 ) ) );
		$params[] = $per_page;

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $pdb->get_results( $pdb->prepare( "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY (status = 'needs_review') DESC, created_at DESC, id DESC LIMIT %d", $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$stats = $pdb->get_row(
			"SELECT COUNT(*) AS total,
				SUM(status = 'filed') AS filed,
				SUM(status = 'matched_pending_file') AS matched_pending_file,
				SUM(status = 'needs_review') AS needs_review,
				SUM(status = 'resolved') AS resolved,
				SUM(status = 'skipped_no_attachment') AS skipped_no_attachment
			FROM `{$table}`",
			ARRAY_A
		);

		return rest_ensure_response( array(
			'items' => array_map( array( $this, 'format_gmail_intake_log_row' ), $rows ),
			'stats' => array(
				'total'                 => (int) ( $stats['total'] ?? 0 ),
				'filed'                 => (int) ( $stats['filed'] ?? 0 ),
				'matched_pending_file'  => (int) ( $stats['matched_pending_file'] ?? 0 ),
				'needs_review'          => (int) ( $stats['needs_review'] ?? 0 ),
				'resolved'              => (int) ( $stats['resolved'] ?? 0 ),
				'skipped_no_attachment' => (int) ( $stats['skipped_no_attachment'] ?? 0 ),
			),
		) );
	}

	public function get_ops_gmail_intake_item( WP_REST_Request $request ) {
		$row = $this->fetch_gmail_intake_log_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Gmail Intake log entry not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->format_gmail_intake_log_row( $row ) );
	}

	/** "Refresh" — runs one pass of AJForms_Admin::process_gmail_intake_inbox() on demand, same as the CP Settings "Process Now" button. */
	public function process_ops_gmail_intake( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', __( 'AJForms admin class not available.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$admin = $this;
		$show_all = rest_sanitize_boolean( $request->get_param( 'show_all' ) );
		$result   = $admin->process_gmail_intake_inbox( 20, $show_all );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
	}

	public function resolve_ops_gmail_intake_item( WP_REST_Request $request ) {
		$row = $this->fetch_gmail_intake_log_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Gmail Intake log entry not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		if ( 'needs_review' !== $row['status'] ) {
			return new WP_Error( 'already_resolved', __( 'This entry is not awaiting review.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}

		$stripe_customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		if ( '' === $stripe_customer_id ) {
			return new WP_Error( 'missing_customer', __( 'stripe_customer_id is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', __( 'AJForms admin class not available.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$admin = $this;
		$result = $admin->resolve_gmail_intake_needs_review( (int) $row['id'], $stripe_customer_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->format_gmail_intake_log_row( $this->fetch_gmail_intake_log_row( $row['id'] ) ) );
	}

	/** Marks a pending entry as filed without uploading/attaching anything — handled elsewhere. */
	public function mark_ops_gmail_intake_item_filed( WP_REST_Request $request ) {
		$admin = $this->get_gmail_intake_admin();
		if ( is_wp_error( $admin ) ) {
			return $admin;
		}
		$result = $admin->mark_gmail_intake_item_filed( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $this->format_gmail_intake_log_row( $this->fetch_gmail_intake_log_row( $request['id'] ) ) );
	}

	private function get_gmail_intake_admin() {
		return $this;
	}

	/** In-app email preview (subject/sender/date/body + attachment list) — replaces linking out to Gmail's own web UI. */
	public function get_ops_gmail_intake_preview( WP_REST_Request $request ) {
		$admin = $this->get_gmail_intake_admin();
		if ( is_wp_error( $admin ) ) {
			return $admin;
		}
		$result = $admin->get_gmail_intake_message_preview( (int) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** Fetches one PDF attachment's bytes on demand (never stored server-side) for in-app preview. */
	public function get_ops_gmail_intake_attachment( WP_REST_Request $request ) {
		$admin = $this->get_gmail_intake_admin();
		if ( is_wp_error( $admin ) ) {
			return $admin;
		}
		$attachment_id = sanitize_text_field( (string) $request->get_param( 'attachment_id' ) );
		if ( '' === $attachment_id ) {
			return new WP_Error( 'missing_attachment_id', __( 'attachment_id is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$result = $admin->get_gmail_intake_attachment_data( (int) $request['id'], $attachment_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** Files the given (possibly renamed) attachments to a customer with an explicit category + tag. */
	public function file_ops_gmail_intake_item( WP_REST_Request $request ) {
		$admin = $this->get_gmail_intake_admin();
		if ( is_wp_error( $admin ) ) {
			return $admin;
		}
		$stripe_customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$tag                = sanitize_key( (string) $request->get_param( 'tag' ) );
		$attachments        = (array) $request->get_param( 'attachments' );
		$notify             = ! empty( $request->get_param( 'notify' ) );
		if ( '' === $stripe_customer_id ) {
			return new WP_Error( 'missing_customer', __( 'stripe_customer_id is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$result = $admin->file_gmail_intake_attachments( (int) $request['id'], $stripe_customer_id, $tag, $attachments, $notify );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = $this->format_gmail_intake_log_row( $this->fetch_gmail_intake_log_row( $request['id'] ) );
		$response['notified'] = ! empty( $result['notified'] );
		return rest_ensure_response( $response );
	}

	/** "Reset" — clears the whole Gmail Intake activity log (local-only; doesn't touch the real mailbox). */
	public function reset_ops_gmail_intake_log( WP_REST_Request $request ) {
		$admin = $this->get_gmail_intake_admin();
		if ( is_wp_error( $admin ) ) {
			return $admin;
		}
		$result = $admin->reset_gmail_intake_log();
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'success' => true ) );
	}

	/** Bulk-selection "Delete" action for the Gmail Intake queue. */
	public function bulk_delete_ops_gmail_intake_items( WP_REST_Request $request ) {
		$admin = $this->get_gmail_intake_admin();
		if ( is_wp_error( $admin ) ) {
			return $admin;
		}
		$ids    = (array) $request->get_param( 'ids' );
		$result = $admin->bulk_delete_gmail_intake_log_rows( $ids );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => $result ) );
	}

	// ── Connection settings UI ────────────────────────────────────────────────

	public function render_settings() {
		$settings     = $this->get_plugin_settings();
		$redirect_uri = $this->get_gmail_intake_redirect_uri();
		$action_url   = admin_url( 'admin-post.php' );

		$is_connected            = ! empty( $settings['gmail_intake_access_token'] ) || ! empty( $settings['gmail_intake_refresh_token'] );
		$can_connect              = ! empty( $settings['gmail_intake_client_id'] ) && ! empty( $settings['gmail_intake_client_secret'] );
		$connect_disabled_reason = ! $can_connect ? __( 'Save a Client ID and Client Secret below first.', 'ajcore-ra' ) : '';

		if ( isset( $_GET['gmail-intake-connected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Gmail Intake connected.', 'ajcore-ra' ) . '</p></div>';
		} elseif ( isset( $_GET['gmail-intake-disconnected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Gmail Intake disconnected.', 'ajcore-ra' ) . '</p></div>';
		} elseif ( isset( $_GET['gmail-intake-error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['gmail-intake-error'] ) ) ) . '</p></div>';
		}

		$authorize_state = wp_generate_password( 32, false );
		set_transient( 'ajcore_gmail_intake_oauth_state', $authorize_state, 10 * MINUTE_IN_SECONDS );
		$authorize_url = add_query_arg(
			array(
				'client_id'     => $settings['gmail_intake_client_id'],
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code',
				'scope'         => 'https://www.googleapis.com/auth/gmail.modify',
				'access_type'   => 'offline',
				// select_account: always show Google's account chooser, so the intake mailbox can be picked
				// instead of whichever account the browser is already signed in to.
				'prompt'        => 'select_account consent',
				'state'         => $authorize_state,
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);
		$disconnect_url = wp_nonce_url( admin_url( 'admin-post.php?action=ajcore_ra_gmail_disconnect' ), 'ajcore_gmail_intake_disconnect' );
		$test_url    = rest_url( 'ajcore/v1/gmail-intake/test' );
		$process_url = rest_url( 'ajcore/v1/gmail-intake/process' );
		?>
		<style>
			#ajforms-gmail-intake-section .ajforms-settings-field input[type="text"],
			#ajforms-gmail-intake-section .ajforms-settings-field input[type="email"] { width: 100%; box-sizing: border-box; min-height: 44px; border: 1px solid #d1d5db; border-radius: 12px; padding: 10px 13px; font-family: inherit; font-size: 14px; }
			#ajforms-gmail-intake-section .ajforms-settings-field label { display: block; margin-bottom: 6px; font-weight: 600; color: #111827; }
			#ajforms-gmail-intake-section .ajforms-settings-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
			#ajforms-gmail-intake-section .ajforms-settings-help { margin-top: 6px; color: #6b7280; font-size: 12px; }
			#ajforms-gmail-intake-instructions { display: none; margin: 10px 0 18px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 13px; line-height: 1.6; }
			#ajforms-gmail-intake-instructions.is-open { display: block; }
			#ajforms-gmail-intake-section .ajforms-zoho-help-btn { border: 1px solid #d1d5db; background: #fff; border-radius: 999px; width: 22px; height: 22px; line-height: 20px; text-align: center; padding: 0; font-weight: 700; cursor: pointer; color: #475569; }
			#ajforms-gmail-intake-section .ajforms-zoho-status { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 10px; background: #f8fafc; border: 1px solid #e2e8f0; margin: 14px 0; font-size: 13px; }
			#ajforms-gmail-intake-section .ajforms-zoho-test-result { margin-top: 14px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 13px; display: none; }
			#ajforms-gmail-intake-section .ajforms-zoho-test-result.is-visible { display: block; }
		</style>
		<div class="ajforms-settings-card" id="ajforms-gmail-intake-section">
			<div class="ajforms-settings-head" style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
				<div>
					<span class="ajcore-modern-admin ajforms-settings-pill" style="display:inline-flex;"><?php esc_html_e( 'Gmail Intake', 'ajcore-ra' ); ?></span>
					<h3 style="display:inline-flex;align-items:center;gap:8px;margin:10px 0 4px;"><?php esc_html_e( 'Connect the intake mailbox', 'ajcore-ra' ); ?>
						<button type="button" class="ajforms-zoho-help-btn" id="ajforms-gmail-intake-help-toggle" title="<?php esc_attr_e( 'Setup instructions', 'ajcore-ra' ); ?>">?</button>
					</h3>
					<p style="margin:0;color:#6b7280;font-size:13px;max-width:640px;"><?php esc_html_e( 'A dedicated Gmail mailbox your team forwards state-filing notification emails into. AJCore reads it, matches each email to a customer by the company name in its subject, and files any real filing-document PDF attachments into that customer\'s Files.', 'ajcore-ra' ); ?></p>
				</div>
			</div>

			<div id="ajforms-gmail-intake-instructions">
				<p style="margin:0 0 10px;padding:10px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;"><?php esc_html_e( 'Already have a Client ID and Client Secret saved below? Skip everything here — just click Connect (or Reconnect) at the bottom of this page and sign in as the intake mailbox. Only redo the steps below if those fields are empty, the OAuth client was deleted in Google Cloud, or you\'re setting this up in a brand-new Google Cloud project.', 'ajcore-ra' ); ?></p>
				<strong><?php esc_html_e( 'Google Cloud setup — everything starts from one link, use the search bar at the top of the console for every step after that. Each step already tells you what to do if it turns out to be already done:', 'ajcore-ra' ); ?></strong>
				<div style="margin:6px 0 10px;"><a class="button button-small" href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">https://console.cloud.google.com/</a></div>
				<ol style="margin:8px 0 0 18px;padding:0;">
					<li><?php esc_html_e( 'At the top left, next to the "Google Cloud" logo, click the project switcher and create (or select) a project.', 'ajcore-ra' ); ?></li>
					<li><?php esc_html_e( 'Click the search bar at the top of the page, type "Gmail API", press Enter, open the result. If it says "Enable", click it; if it already says "Manage" / shows usage graphs, it\'s already enabled — move on.', 'ajcore-ra' ); ?></li>
					<li><?php esc_html_e( 'Search "Google Auth Platform" and open it. If the Overview page shows a "Get started" button, click it and fill in: an app name and your own email as support email → Next. Audience: choose External → Next. Contact email: your own email again → Next. Check the agreement box → Create. If Overview instead already shows "Metrics" / "Project Checkup" panels (no "Get started" button anywhere on the page), this is already configured — click "Audience" in the left-hand menu (the next step below) and keep going.', 'ajcore-ra' ); ?></li>
					<li><?php esc_html_e( 'Click "Audience" in the left-hand menu, scroll to "Test users". If the intake Gmail address is already listed, skip to the next step; otherwise click "Add users", enter it, and Save.', 'ajcore-ra' ); ?></li>
					<li><?php esc_html_e( 'Click "Clients" in the same left-hand menu. If an OAuth client already exists there (e.g. one named for this integration), open it and confirm the Authorized redirect URI below is listed — add it if not, then Save, and skip to the last step. Otherwise click "+ Create client": leave Application type as "Web application", type any Name, leave "Authorized JavaScript origins" empty, and under "Authorized redirect URIs" click "+ Add URI" and paste this exact URL, then click Create:', 'ajcore-ra' ); ?>
						<div style="display:flex;align-items:center;gap:8px;margin-top:6px;">
							<code id="ajforms-gmail-intake-redirect-uri" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;font-size:12px;word-break:break-all;"><?php echo esc_html( $redirect_uri ); ?></code>
							<button type="button" class="button button-small" id="ajforms-gmail-intake-copy-redirect"><?php esc_html_e( 'Copy', 'ajcore-ra' ); ?></button>
						</div>
					</li>
					<li><?php esc_html_e( 'That last step shows a Client ID and Client Secret — copy both into the fields below → Save Settings → Connect, then sign in as the intake mailbox itself (not a delegate).', 'ajcore-ra' ); ?></li>
				</ol>
				<p style="margin:8px 0 0;color:#6b7280;font-size:13px;"><?php esc_html_e( 'Free at this scale — no billing account needed.', 'ajcore-ra' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<?php wp_nonce_field( 'ajcore_ra_save_gmail_intake' ); ?>
				<input type="hidden" name="action" value="ajcore_ra_save_gmail_intake">
				<div class="ajforms-settings-grid">
					<div class="ajforms-settings-field">
						<label for="gmail_intake_client_id"><?php esc_html_e( 'Client ID', 'ajcore-ra' ); ?></label>
						<input type="text" name="gmail_intake_client_id" id="gmail_intake_client_id" value="<?php echo esc_attr( $settings['gmail_intake_client_id'] ); ?>" autocomplete="off">
					</div>
					<div class="ajforms-settings-field">
						<label for="gmail_intake_client_secret"><?php esc_html_e( 'Client Secret', 'ajcore-ra' ); ?></label>
						<input type="text" name="gmail_intake_client_secret" id="gmail_intake_client_secret" value="" placeholder="<?php echo ! empty( $settings['gmail_intake_client_secret'] ) ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'ajcore-ra' ) : ''; ?>" autocomplete="off">
					</div>
					<div class="ajforms-settings-field">
						<label for="gmail_intake_address"><?php esc_html_e( 'Intake Mailbox Address', 'ajcore-ra' ); ?></label>
						<input type="email" name="gmail_intake_address" id="gmail_intake_address" value="<?php echo esc_attr( $settings['gmail_intake_address'] ); ?>">
						<div class="ajforms-settings-help"><?php esc_html_e( 'Reference only — the actual account read is whichever Google login completes Connect below.', 'ajcore-ra' ); ?></div>
					</div>
				</div>
				<p class="submit" style="margin:14px 0 0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'ajcore-ra' ); ?></button></p>
			</form>

			<div class="ajforms-zoho-status">
				<?php if ( $is_connected ) : ?>
					<strong>✓ <?php esc_html_e( 'Connected', 'ajcore-ra' ); ?></strong>
					<span>
						<?php
						echo esc_html(
							! empty( $settings['gmail_intake_connected_email'] )
								? sprintf(
									/* translators: 1: connected mailbox address, 2: date connected */
									__( 'as %1$s%2$s', 'ajcore-ra' ),
									$settings['gmail_intake_connected_email'],
									! empty( $settings['gmail_intake_connected_at'] ) ? sprintf( __( ' — connected %s', 'ajcore-ra' ), $settings['gmail_intake_connected_at'] ) : ''
								)
								: __( 'Connected — mailbox address not yet confirmed.', 'ajcore-ra' )
						);
						?>
					</span>
				<?php else : ?>
					<span><?php esc_html_e( 'Not connected yet.', 'ajcore-ra' ); ?></span>
				<?php endif; ?>
			</div>

			<div class="ajforms-settings-inline-actions">
				<?php if ( $can_connect ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $authorize_url ); ?>"><?php echo esc_html( $is_connected ? __( 'Reconnect Gmail', 'ajcore-ra' ) : __( 'Connect Gmail', 'ajcore-ra' ) ); ?></a>
				<?php else : ?>
					<button type="button" class="button" disabled title="<?php echo esc_attr( $connect_disabled_reason ); ?>"><?php esc_html_e( 'Connect Gmail', 'ajcore-ra' ); ?></button>
					<span style="color:#6b7280;font-size:13px;"><?php echo esc_html( $connect_disabled_reason ); ?></span>
				<?php endif; ?>
				<?php if ( $is_connected ) : ?>
					<button type="button" class="button" id="ajforms-gmail-intake-test-btn"><?php esc_html_e( 'Test Connection', 'ajcore-ra' ); ?></button>
					<button type="button" class="button" id="ajforms-gmail-intake-process-btn"><?php esc_html_e( 'Process Now', 'ajcore-ra' ); ?></button>
					<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#374151;margin-left:4px;">
						<input type="checkbox" id="ajforms-gmail-intake-show-all">
						<?php esc_html_e( 'Show all mailbox messages (ignore filters)', 'ajcore-ra' ); ?>
					</label>
					<a class="button" href="<?php echo esc_url( $disconnect_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Disconnect Gmail Intake? Scheduled processing stops until reconnected.', 'ajcore-ra' ) ); ?>');"><?php esc_html_e( 'Disconnect', 'ajcore-ra' ); ?></a>
				<?php endif; ?>
			</div>
			<p class="ajforms-settings-help" style="margin:2px 0 0;"><?php esc_html_e( 'By default, Process Now only matches messages with "Secretary of State" in the subject (a real NC Secretary of State filing notification) or "sosnc.gov" anywhere in the message. No sender is excluded — real filing forwards come from the same address as other mail in this box. Check the box to process and log every message in the mailbox instead, with a table below showing exactly what was found.', 'ajcore-ra' ); ?></p>

			<div class="ajforms-zoho-test-result" id="ajforms-gmail-intake-test-result"></div>
			<div class="ajforms-zoho-test-result" id="ajforms-gmail-intake-process-result"></div>

			<?php
			$gmail_debug = get_transient( 'ajcore_gmail_intake_api_debug' );
			if ( ! empty( $gmail_debug ) && is_array( $gmail_debug ) ) :
				$pretty_body = $gmail_debug['body'];
				$decoded     = json_decode( $gmail_debug['body'], true );
				if ( null !== $decoded ) {
					$pretty_body = wp_json_encode( $decoded, JSON_PRETTY_PRINT );
				}
				?>
				<div style="margin-top:18px;padding-top:16px;border-top:1px dashed #e2e8f0;">
					<h4 style="margin:0 0 4px;font-size:13px;"><?php esc_html_e( 'Last raw Gmail API response (debug)', 'ajcore-ra' ); ?></h4>
					<p style="margin:0 0 10px;color:#6b7280;font-size:13px;">
						<?php
						printf(
							/* translators: 1: request URL, 2: HTTP status code, 3: date/time */
							esc_html__( '%1$s — HTTP %2$s — %3$s (auto-clears after 15 minutes)', 'ajcore-ra' ),
							esc_html( $gmail_debug['url'] ),
							esc_html( $gmail_debug['status'] ),
							esc_html( $gmail_debug['at'] )
						);
						?>
					</p>
					<pre style="margin:0;padding:14px 16px;background:#0f172a;color:#e2e8f0;border-radius:12px;font-size:12px;line-height:1.5;overflow:auto;max-height:360px;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html( $pretty_body ); ?></pre>
				</div>
			<?php endif; ?>
		</div>
		<script>
		(function () {
			var helpBtn = document.getElementById( 'ajforms-gmail-intake-help-toggle' );
			var instructions = document.getElementById( 'ajforms-gmail-intake-instructions' );
			if ( helpBtn && instructions ) {
				helpBtn.addEventListener( 'click', function () {
					instructions.classList.toggle( 'is-open' );
				} );
			}

			var copyBtn = document.getElementById( 'ajforms-gmail-intake-copy-redirect' );
			var redirectCode = document.getElementById( 'ajforms-gmail-intake-redirect-uri' );
			if ( copyBtn && redirectCode && navigator.clipboard ) {
				copyBtn.addEventListener( 'click', function () {
					navigator.clipboard.writeText( redirectCode.textContent || '' );
					copyBtn.textContent = '<?php echo esc_js( __( 'Copied', 'ajcore-ra' ) ); ?>';
					setTimeout( function () { copyBtn.textContent = '<?php echo esc_js( __( 'Copy', 'ajcore-ra' ) ); ?>'; }, 1500 );
				} );
			}

			var testBtn = document.getElementById( 'ajforms-gmail-intake-test-btn' );
			var testResultBox = document.getElementById( 'ajforms-gmail-intake-test-result' );
			if ( testBtn && testResultBox ) {
				testBtn.addEventListener( 'click', function () {
					testBtn.disabled = true;
					testBtn.textContent = '<?php echo esc_js( __( 'Testing…', 'ajcore-ra' ) ); ?>';
					testResultBox.classList.remove( 'is-visible' );
					fetch( '<?php echo esc_url_raw( $test_url ); ?>', {
						headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							var html = '';
							if ( data && data.success ) {
								html += '<h4 style="color:#166534;">✓ <?php echo esc_js( __( 'Connection works', 'ajcore-ra' ) ); ?></h4>';
								html += '<p style="margin:0;"><?php echo esc_js( __( 'Mailbox:', 'ajcore-ra' ) ); ?> ' + ( data.email_address || '' ) + ' — <?php echo esc_js( __( 'total messages:', 'ajcore-ra' ) ); ?> ' + ( data.messages_total || 0 ) + '</p>';
							} else {
								html += '<h4 style="color:#b91c1c;">✗ <?php echo esc_js( __( 'Test failed', 'ajcore-ra' ) ); ?></h4>';
								html += '<p style="margin:0;">' + ( ( data && data.message ) || '<?php echo esc_js( __( 'Unknown error.', 'ajcore-ra' ) ); ?>' ) + '</p>';
							}
							testResultBox.innerHTML = html;
							testResultBox.classList.add( 'is-visible' );
						} )
						.catch( function () {
							testResultBox.innerHTML = '<h4 style="color:#b91c1c;">✗ <?php echo esc_js( __( 'Test failed', 'ajcore-ra' ) ); ?></h4><p style="margin:0;"><?php echo esc_js( __( 'Request to the site failed.', 'ajcore-ra' ) ); ?></p>';
							testResultBox.classList.add( 'is-visible' );
						} )
						.finally( function () {
							testBtn.disabled = false;
							testBtn.textContent = '<?php echo esc_js( __( 'Test Connection', 'ajcore-ra' ) ); ?>';
						} );
				} );
			}

			var processBtn = document.getElementById( 'ajforms-gmail-intake-process-btn' );
			var processResultBox = document.getElementById( 'ajforms-gmail-intake-process-result' );
			var showAllCheckbox = document.getElementById( 'ajforms-gmail-intake-show-all' );
			if ( processBtn && processResultBox ) {
				processBtn.addEventListener( 'click', function () {
					processBtn.disabled = true;
					processBtn.textContent = '<?php echo esc_js( __( 'Processing…', 'ajcore-ra' ) ); ?>';
					processResultBox.classList.remove( 'is-visible' );
					var showAll = !! ( showAllCheckbox && showAllCheckbox.checked );
					// show_all as a POST JSON body field, not a query string appended with a bare
					// "?" — rest_url() already returns a URL with its own "?rest_route=..." query
					// string on any site without pretty permalinks, and blindly concatenating a
					// second "?" there produces a malformed URL that silently fails (looked like
					// the checkbox "did nothing" — it never reached the server).
					fetch( '<?php echo esc_url_raw( $process_url ); ?>', {
						method: 'POST',
						headers: {
							'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
							'Content-Type': 'application/json'
						},
						body: JSON.stringify( { show_all: showAll } )
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							var html = '';
							if ( data && data.success ) {
								html += '<h4 style="color:#166534;">✓ <?php echo esc_js( __( 'Processed', 'ajcore-ra' ) ); ?>' + ( showAll ? ' <span style="font-weight:400;color:#6b7280;">(<?php echo esc_js( __( 'all mailbox messages, filters ignored', 'ajcore-ra' ) ); ?>)</span>' : '' ) + '</h4>';
								html += '<p style="margin:0;"><?php echo esc_js( __( 'Checked:', 'ajcore-ra' ) ); ?> ' + data.checked + ' — <?php echo esc_js( __( 'Matched (pending file):', 'ajcore-ra' ) ); ?> ' + data.matched_pending_file + ' — <?php echo esc_js( __( 'Needs review:', 'ajcore-ra' ) ); ?> ' + data.needs_review + '</p>';
								html += '<p style="margin:6px 0 0;color:#6b7280;font-size:12px;"><?php echo esc_js( __( 'Gmail query used:', 'ajcore-ra' ) ); ?> <code>' + ( data.query || '' ) + '</code> — <?php echo esc_js( __( 'result size estimate:', 'ajcore-ra' ) ); ?> ' + ( data.result_size_estimate != null ? data.result_size_estimate : '?' ) + '</p>';
								if ( data.errors && data.errors.length ) {
									html += '<p style="margin:8px 0 0;color:#b91c1c;">' + data.errors.join( '<br>' ) + '</p>';
								}
								if ( data.messages && data.messages.length ) {
									// Subject/From come from arbitrary external email content — escape before
									// innerHTML so a crafted Subject line can't inject markup into this admin page.
									var escapeHtml = function ( s ) {
										return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
											return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
										} );
									};
									html += '<table style="width:100%;margin-top:10px;border-collapse:collapse;font-size:12px;">';
									html += '<thead><tr style="text-align:left;color:#6b7280;"><th style="padding:4px 6px;">Status</th><th style="padding:4px 6px;">From</th><th style="padding:4px 6px;">Subject</th></tr></thead><tbody>';
									data.messages.forEach( function ( m ) {
										html += '<tr style="border-top:1px solid #e2e8f0;">'
											+ '<td style="padding:4px 6px;white-space:nowrap;">' + escapeHtml( m.status ) + '</td>'
											+ '<td style="padding:4px 6px;white-space:nowrap;">' + escapeHtml( m.sender ) + '</td>'
											+ '<td style="padding:4px 6px;">' + ( m.subject ? escapeHtml( m.subject ) : '(no subject)' ) + '</td>'
											+ '</tr>';
									} );
									html += '</tbody></table>';
								} else {
									html += '<p style="margin:8px 0 0;color:#6b7280;">' + '<?php echo esc_js( __( 'No messages matched this query — try the "Show all mailbox messages" checkbox to rule out the content filter.', 'ajcore-ra' ) ); ?>' + '</p>';
								}
							} else {
								html += '<h4 style="color:#b91c1c;">✗ <?php echo esc_js( __( 'Processing failed', 'ajcore-ra' ) ); ?></h4>';
								html += '<p style="margin:0;">' + ( ( data && data.message ) || '<?php echo esc_js( __( 'Unknown error.', 'ajcore-ra' ) ); ?>' ) + '</p>';
							}
							processResultBox.innerHTML = html;
							processResultBox.classList.add( 'is-visible' );
						} )
						.catch( function () {
							processResultBox.innerHTML = '<h4 style="color:#b91c1c;">✗ <?php echo esc_js( __( 'Processing failed', 'ajcore-ra' ) ); ?></h4><p style="margin:0;"><?php echo esc_js( __( 'Request to the site failed.', 'ajcore-ra' ) ); ?></p>';
							processResultBox.classList.add( 'is-visible' );
						} )
						.finally( function () {
							processBtn.disabled = false;
							processBtn.textContent = '<?php echo esc_js( __( 'Process Now', 'ajcore-ra' ) ); ?>';
						} );
				} );
			}
		})();
		</script>
		<div class="ajcore-gi-flyers" style="margin:16px 0;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px;max-width:720px;background:#fff;">
			<strong><?php esc_html_e( 'Shared flyers', 'ajcore-ra' ); ?></strong>
			<span style="color:#64748b;font-size:12px;"> — <?php esc_html_e( 'one file per flyer, shared with every customer', 'ajcore-ra' ); ?></span>
			<p style="margin:8px 0;">
				<button type="button" class="button" id="ajcore-gi-flyers-preview"><?php esc_html_e( 'Preview merge', 'ajcore-ra' ); ?></button>
				<button type="button" class="button button-primary" id="ajcore-gi-flyers-apply" disabled><?php esc_html_e( 'Apply merge', 'ajcore-ra' ); ?></button>
			</p>
			<div id="ajcore-gi-flyers-out" style="font-size:13px;"></div>
		</div>
		<script>
		(function () {
			var url = <?php echo wp_json_encode( rest_url( 'ajcore/v1/gmail-intake/flyers/merge' ) ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var pv = document.getElementById( 'ajcore-gi-flyers-preview' ), ap = document.getElementById( 'ajcore-gi-flyers-apply' ), out = document.getElementById( 'ajcore-gi-flyers-out' );
			function run( apply ) {
				out.textContent = '…';
				fetch( url + ( apply ? '?apply=1' : '' ), { method: 'POST', headers: { 'X-WP-Nonce': nonce } } ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
					if ( ! d.flyers ) { out.textContent = d.message || 'Failed.'; return; }
					if ( ! d.flyers.length ) { out.textContent = <?php echo wp_json_encode( __( 'No flyer files found.', 'ajcore-ra' ) ); ?>; ap.disabled = true; return; }
					var dupes = 0;
					out.innerHTML = d.flyers.map( function ( f ) { dupes += f.duplicates; return '<div>' + ( apply ? '✓ ' : '' ) + f.keep_title + ': ' + f.duplicates + ' duplicate(s) ' + ( apply ? 'archived' : 'to archive' ) + ', ' + f.new_shares + ' customer(s) added to the shared copy</div>'; } ).join( '' );
					ap.disabled = apply || ! dupes;
				} ).catch( function () { out.textContent = 'Request failed.'; } );
			}
			pv.addEventListener( 'click', function () { run( false ); } );
			ap.addEventListener( 'click', function () { if ( window.confirm( <?php echo wp_json_encode( __( 'Archive the duplicate flyer files and share the kept copy with those customers?', 'ajcore-ra' ) ); ?> ) ) { run( true ); } } );
		})();
		</script>
		<?php
	}

	// ── Shared flyers: one file per flyer, shared with every customer ─────────────

	/** 'rural-rise' etc. for the known SOSNC flyers, '' for anything else. */
	private function flyer_key( $text ) {
		$t = strtolower( preg_replace( '/[^a-z0-9]+/i', ' ', (string) $text ) );
		foreach ( array( 'rural rise' => 'rural-rise', 'misleading mailings' => 'misleading-mailings', 'notice to employers' => 'notice-to-employers' ) as $needle => $key ) {
			if ( false !== strpos( $t, $needle ) ) {
				return $key;
			}
		}
		return '';
	}

	/** Active (not archived) SOSNC Flyers files grouped by flyer key, oldest first. */
	private function flyer_groups() {
		global $wpdb;
		$files  = $wpdb->prefix . 'aj_portal_files';
		$rows   = $wpdb->get_results( "SELECT id, attachment_id, title, created_at FROM `{$files}` WHERE LOWER(category) = 'sosnc flyers' AND ( status <> 'archived' OR status IS NULL ) ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$groups = array();
		foreach ( (array) $rows as $row ) {
			$key = $this->flyer_key( $row->title );
			if ( '' !== $key ) {
				$groups[ $key ][] = $row;
			}
		}
		return $groups;
	}

	/** The one shared copy for a flyer (the oldest active file), or 0 if none exists yet. */
	private function flyer_canonical_id( $key ) {
		$groups = $this->flyer_groups();
		return isset( $groups[ $key ][0] ) ? (int) $groups[ $key ][0]->id : 0;
	}

	/** Adds a share row for the customer on a file; no-op if they already have it. */
	private function flyer_share_file( $file_id, $email, $user_id ) {
		global $wpdb;
		$users = $wpdb->prefix . 'aj_portal_file_users';
		$email = strtolower( sanitize_email( (string) $email ) );
		if ( '' === $email ) {
			return false;
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$users}` WHERE file_id = %d AND ( LOWER(user_email) = %s OR ( %d > 0 AND user_id = %d ) ) LIMIT 1", (int) $file_id, $email, (int) $user_id, (int) $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $exists ) {
			return true;
		}
		return false !== $wpdb->insert( $users, array( 'file_id' => (int) $file_id, 'user_id' => (int) $user_id, 'user_email' => $email, 'created_at' => current_time( 'mysql' ) ), array( '%d', '%d', '%s', '%s' ) );
	}

	private function flyer_customer_user_id( $stripe_customer_id ) {
		global $wpdb;
		$map = $wpdb->prefix . 'aj_auth_user_mappings';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM `{$map}` WHERE stripe_customer_id = %s LIMIT 1", (string) $stripe_customer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Merge duplicate flyer files: per flyer, keep the oldest as the single shared copy, share it with
	 * everyone who had a duplicate, and archive the duplicates (never deleted; the Archived tab keeps them).
	 * $apply false = plan only.
	 */
	private function flyers_merge( $apply ) {
		global $wpdb;
		$files = $wpdb->prefix . 'aj_portal_files';
		$users = $wpdb->prefix . 'aj_portal_file_users';
		$out   = array();
		foreach ( $this->flyer_groups() as $key => $group ) {
			$keep  = $group[0];
			$dupes = array_slice( $group, 1 );
			$have  = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, LOWER(user_email) AS email FROM `{$users}` WHERE file_id = %d", (int) $keep->id ) ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$have[ $u->email ] = true;
			}
			$to_share = array();
			foreach ( $dupes as $d ) {
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, LOWER(user_email) AS email FROM `{$users}` WHERE file_id = %d", (int) $d->id ) ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( '' !== $u->email && empty( $have[ $u->email ] ) ) {
						$have[ $u->email ]     = true;
						$to_share[ $u->email ] = (int) $u->user_id;
					}
				}
			}
			if ( $apply ) {
				foreach ( $to_share as $email => $uid ) {
					$this->flyer_share_file( $keep->id, $email, $uid );
				}
				foreach ( $dupes as $d ) {
					$wpdb->update( $files, array( 'status' => 'archived', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $d->id ), array( '%s', '%s' ), array( '%d' ) );
				}
			}
			$out[] = array(
				'flyer'      => $key,
				'keep_id'    => (int) $keep->id,
				'keep_title' => (string) $keep->title,
				'duplicates' => count( $dupes ),
				'new_shares' => count( $to_share ),
			);
		}
		return $out;
	}

	public function flyers_merge_route( WP_REST_Request $request ) {
		$apply = rest_sanitize_boolean( $request->get_param( 'apply' ) );
		return rest_ensure_response( array( 'applied' => $apply, 'flyers' => $this->flyers_merge( $apply ) ) );
	}

	// ── Activity tab ──────────────────────────────────────────────────────────

	/**
	 * WP-admin fallback for the Gmail Intake activity log/needs-review queue, so staff can
	 * resolve unmatched emails even if AJOps is down — same data and same resolve action
	 * (resolve_gmail_intake_needs_review()) as the AJOps "Gmail Intake" screen.
	 */
	private function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}

		if ( ! $this->ensure_gmail_intake_log_table() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The Gmail Intake log table is not available yet.', 'ajcore-ra' ) . '</p></div>';
			return;
		}

		$pdb   = $this->get_pdb();
		$table = $this->get_gmail_intake_log_table();

		$status = isset( $_GET['gi_status'] ) ? sanitize_key( wp_unslash( $_GET['gi_status'] ) ) : 'pending';
		$status = in_array( $status, array( 'pending', 'filed' ), true ) ? $status : 'pending';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$status_groups = array(
			'pending' => array( 'needs_review', 'matched_pending_file', 'skipped_no_attachment' ),
			'filed'   => array( 'filed', 'resolved' ),
		);

		$where  = array( '1=1' );
		$params = array();
		$placeholders = implode( ', ', array_fill( 0, count( $status_groups[ $status ] ), '%s' ) );
		$where[]  = "status IN ({$placeholders})";
		$params   = array_merge( $params, $status_groups[ $status ] );
		if ( '' !== $search ) {
			$like    = '%' . $pdb->esc_like( $search ) . '%';
			$where[] = '(subject LIKE %s OR sender LIKE %s OR company_name_extracted LIKE %s OR customer_name LIKE %s)';
			$params  = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY (status = 'needs_review') DESC, created_at DESC, id DESC LIMIT 300";
		$rows = $pdb->get_results( $pdb->prepare( $sql, $params ) );

		$stats = $pdb->get_row(
			"SELECT COUNT(*) AS total,
				SUM(status = 'filed') AS filed,
				SUM(status = 'matched_pending_file') AS matched_pending_file,
				SUM(status = 'needs_review') AS needs_review,
				SUM(status = 'resolved') AS resolved,
				SUM(status = 'skipped_no_attachment') AS skipped_no_attachment
			FROM `{$table}`"
		);

		// Customer picker options, shared by every row's inline resolve form.
		$local_customers_table = $pdb->prefix . 'aj_portal_local_customers';
		$customers              = array_merge(
			(array) $pdb->get_results( "SELECT stripe_customer_id, name, email FROM {$this->get_portal_stripe_customers_table()} ORDER BY name ASC" ),
			(array) $pdb->get_results( "SELECT local_customer_id AS stripe_customer_id, name, email FROM `{$local_customers_table}` ORDER BY name ASC" )
		);

		$file_settings = function_exists( 'ajcore_get_portal_file_settings' ) ? ajcore_get_portal_file_settings() : array( 'categories' => array(), 'tags' => array() );

		$base_url = add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'gmail-intake' ), admin_url( 'admin.php' ) );
		?>
		<div id="ajcore-gi-list-section" class="ajforms-settings-card">
			<div class="ajcore-section-head">
				<div>
					<h2><?php esc_html_e( 'Gmail Intake', 'ajcore-ra' ); ?></h2>
					<p><?php esc_html_e( "State-filing notification emails read from the intake mailbox, matched to a customer by company name. Filing to a customer's Files is paused for now — matched emails just show which documents would be filed.", 'ajcore-ra' ); ?></p>
				</div>
			</div>

			<?php if ( isset( $_GET['gmail-intake-resolved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Filed to the selected customer.', 'ajcore-ra' ); ?></p></div>
			<?php endif; ?>

			<div class="ajcore-kpi-grid" style="grid-template-columns:repeat(2,minmax(160px,220px));">
				<?php
				$pending_count = (int) $stats->needs_review + (int) $stats->matched_pending_file + (int) $stats->skipped_no_attachment;
				$filed_count   = (int) $stats->filed + (int) $stats->resolved;
				?>
				<a class="ajcore-kpi-card" href="<?php echo esc_url( add_query_arg( 'gi_status', 'pending', $base_url ) ); ?>" style="text-decoration:none;<?php echo 'pending' === $status ? 'border-color:#3157ff;box-shadow:0 0 0 2px rgba(49,87,255,.15);' : ''; ?>">
					<span><?php esc_html_e( 'Pending File', 'ajcore-ra' ); ?></span><strong<?php echo $pending_count > 0 ? ' style="color:#dc2626;"' : ''; ?>><?php echo esc_html( $pending_count ); ?></strong>
				</a>
				<a class="ajcore-kpi-card" href="<?php echo esc_url( add_query_arg( 'gi_status', 'filed', $base_url ) ); ?>" style="text-decoration:none;<?php echo 'filed' === $status ? 'border-color:#3157ff;box-shadow:0 0 0 2px rgba(49,87,255,.15);' : ''; ?>">
					<span><?php esc_html_e( 'Filed', 'ajcore-ra' ); ?></span><strong><?php echo esc_html( $filed_count ); ?></strong>
				</a>
			</div>

			<form method="get" class="ajcore-filter-bar" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 16px;">
				<input type="hidden" name="page" value="ajforms-client-portal">
				<input type="hidden" name="tab" value="gmail-intake">
				<select name="gi_status">
					<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending File', 'ajcore-ra' ); ?></option>
					<option value="filed" <?php selected( $status, 'filed' ); ?>><?php esc_html_e( 'Filed', 'ajcore-ra' ); ?></option>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search subject, sender, company, customer…', 'ajcore-ra' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ajcore-ra' ); ?></button>
				<a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Clear Filters', 'ajcore-ra' ); ?></a>
				<button type="button" id="ajcore-gmail-intake-reset-log" class="button" style="color:#dc2626;"><?php esc_html_e( 'Reset Log', 'ajcore-ra' ); ?></button>
				<button type="button" id="ajcore-gmail-intake-refresh" class="button" style="margin-left:auto;"><?php esc_html_e( 'Refresh', 'ajcore-ra' ); ?></button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Received', 'ajcore-ra' ); ?></th>
						<th><?php esc_html_e( 'Email', 'ajcore-ra' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'ajcore-ra' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ajcore-ra' ); ?></th>
						<th style="width:24px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Nothing here.', 'ajcore-ra' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $filed_filenames = ! empty( $row->filed_filenames ) ? (array) json_decode( (string) $row->filed_filenames, true ) : array(); ?>
							<tr class="ajcore-gi-row" style="cursor:pointer;" data-log-id="<?php echo esc_attr( $row->id ); ?>" data-customer-id="<?php echo esc_attr( $row->stripe_customer_id ); ?>" data-status="<?php echo esc_attr( $row->status ); ?>">
								<td><?php echo esc_html( $row->received_at ); ?></td>
								<td>
									<strong><?php echo esc_html( $row->subject ); ?></strong><br>
									<span class="description"><?php echo esc_html( sprintf( __( 'From %s', 'ajcore-ra' ), $row->sender ) ); ?></span>
									<?php if ( ! empty( $filed_filenames ) && 'matched_pending_file' === $row->status ) : ?>
										<br><span class="description"><?php echo esc_html( sprintf( __( 'Would file: %s', 'ajcore-ra' ), implode( ', ', $filed_filenames ) ) ); ?></span>
									<?php elseif ( ! empty( $filed_filenames ) ) : ?>
										<br><span class="description"><?php echo esc_html( sprintf( __( 'Filed: %s', 'ajcore-ra' ), implode( ', ', $filed_filenames ) ) ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $row->error_message ) ) : ?>
										<br><span style="color:#dc2626;"><?php echo esc_html( $row->error_message ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $row->customer_name ) ) : ?>
										<?php echo esc_html( $row->customer_name ); ?>
									<?php elseif ( ! empty( $row->company_name_extracted ) ) : ?>
										<span class="description"><?php echo esc_html( sprintf( __( '"%s" (no match)', 'ajcore-ra' ), $row->company_name_extracted ) ); ?></span>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td>
									<span class="ajcore-status-pill <?php echo esc_attr( 'needs_review' === $row->status ? 'disabled' : ( in_array( $row->status, array( 'filed', 'resolved' ), true ) ? 'active' : '' ) ); ?>">
										<?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $row->status ) ) ); ?>
									</span>
								</td>
								<td style="color:#94a3b8;">&rsaquo;</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div id="ajcore-gi-preview-section" style="display:none;">
			<button type="button" id="ajcore-gi-preview-back" class="button" style="margin-bottom:14px;">&larr; <?php esc_html_e( 'Back to list', 'ajcore-ra' ); ?></button>
			<div id="ajcore-gi-preview-body"></div>
		</div>

		<script>
		( function () {
			var restBase   = <?php echo wp_json_encode( rest_url( 'ajcore/v1' ) ); ?>;
			var nonce       = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var customers   = <?php echo wp_json_encode( array_map( function ( $c ) { return array( 'id' => $c->stripe_customer_id, 'name' => $c->name, 'email' => $c->email ); }, $customers ) ); ?>;
			var categories  = <?php echo wp_json_encode( array_values( (array) $file_settings['categories'] ) ); ?>;
			var tags        = <?php echo wp_json_encode( (object) (array) $file_settings['tags'] ); ?>;

			function apiFetch( path, opts ) {
				opts = opts || {};
				opts.headers = Object.assign( { 'X-WP-Nonce': nonce }, opts.headers || {} );
				return fetch( restBase + path, opts ).then( function ( r ) {
					return r.json().then( function ( data ) {
						if ( ! r.ok ) { throw new Error( data.message || 'Request failed' ); }
						return data;
					} );
				} );
			}

			// ── Refresh / process-now (on load + button + 5min interval) ──────────────
			var refreshBtn = document.getElementById( 'ajcore-gmail-intake-refresh' );
			function processThenReload( onDone ) {
				if ( refreshBtn ) { refreshBtn.disabled = true; refreshBtn.textContent = <?php echo wp_json_encode( __( 'Checking mailbox…', 'ajcore-ra' ) ); ?>; }
				fetch( restBase + '/gmail-intake/process', { method: 'POST', headers: { 'X-WP-Nonce': nonce } } )
					.catch( function () {} )
					.then( function () { onDone(); } );
			}
			if ( refreshBtn ) {
				refreshBtn.addEventListener( 'click', function () { processThenReload( function () { window.location.reload(); } ); } );
			}
			if ( -1 === window.location.search.indexOf( 'gi_processed=1' ) ) {
				processThenReload( function () {
					var url = new URL( window.location.href );
					url.searchParams.set( 'gi_processed', '1' );
					window.location.replace( url.toString() );
				} );
			}
			setInterval( function () { processThenReload( function () { window.location.reload(); } ); }, 5 * 60 * 1000 );

			// ── Reset log ──────────────────────────────────────────────────────────
			var resetBtn = document.getElementById( 'ajcore-gmail-intake-reset-log' );
			if ( resetBtn ) {
				resetBtn.addEventListener( 'click', function () {
					if ( ! window.confirm( <?php echo wp_json_encode( __( 'Clear pending Gmail Intake entries? Already-filed items are kept. This only clears our own tracking table — it does not touch the real mailbox.', 'ajcore-ra' ) ); ?> ) ) { return; }
					resetBtn.disabled = true;
					apiFetch( '/gmail-intake/reset', { method: 'POST' } ).then( function () { window.location.reload(); } ).catch( function ( e ) { alert( e.message ); resetBtn.disabled = false; } );
				} );
			}

			// ── Preview (full-width, in place of the list) ─────────────────────────
			var listSection    = document.getElementById( 'ajcore-gi-list-section' );
			var previewSection = document.getElementById( 'ajcore-gi-preview-section' );
			var backBtn        = document.getElementById( 'ajcore-gi-preview-back' );
			var body           = document.getElementById( 'ajcore-gi-preview-body' );

			function showList() {
				previewSection.style.display = 'none';
				listSection.style.display = '';
				body.innerHTML = '';
			}
			backBtn.addEventListener( 'click', showList );

			function escapeHtml( s ) {
				return String( s || '' ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[ c ]; } );
			}
			function stripPdfExt( s ) { return String( s || '' ).replace( /\.pdf$/i, '' ); }

			document.querySelectorAll( '.ajcore-gi-row' ).forEach( function ( row ) {
				row.addEventListener( 'click', function () {
					var logId = row.getAttribute( 'data-log-id' );
					var presetCustomerId = row.getAttribute( 'data-customer-id' ) || '';
					var rowStatus = row.getAttribute( 'data-status' ) || '';
					listSection.style.display = 'none';
					previewSection.style.display = '';
					body.innerHTML = '<p>' + <?php echo wp_json_encode( __( 'Loading…', 'ajcore-ra' ) ); ?> + '</p>';

					apiFetch( '/gmail-intake/' + logId + '/preview' ).then( function ( data ) {
						function categoryOptionsFor( selectedCategory ) {
							return '<option value="">— category —</option>' + categories.map( function ( c ) {
								return '<option value="' + escapeHtml( c ) + '"' + ( c === selectedCategory ? ' selected' : '' ) + '>' + escapeHtml( c ) + '</option>';
							} ).join( '' );
						}
						var defaultTagSlug = Object.keys( tags ).indexOf( 'registeredagent' ) !== -1 ? 'registeredagent' : '';
						var tagOptions = '<option value="">— none —</option>' + Object.keys( tags ).map( function ( slug ) {
							return '<option value="' + escapeHtml( slug ) + '"' + ( slug === defaultTagSlug ? ' selected' : '' ) + '>' + escapeHtml( tags[ slug ] ) + '</option>';
						} ).join( '' );
						var customerOptions = customers.map( function ( c ) {
							return '<option value="' + escapeHtml( c.id ) + '"' + ( c.id === presetCustomerId ? ' selected' : '' ) + '>' + escapeHtml( c.name ) + ' (' + escapeHtml( c.email ) + ')</option>';
						} ).join( '' );

						var attachmentsHtml = ( data.attachments || [] ).map( function ( a, idx ) {
							var suggested = stripPdfExt( a.suggested_filename || a.filename );
							var note = a.looks_like_document ? '' : ' &middot; <span style="color:#b45309;">' + <?php echo wp_json_encode( __( "didn't look like a filing document by filename — check before filing", 'ajcore-ra' ) ); ?> + '</span>';
							var linkHtml = a.related_link ? '<a href="' + escapeHtml( a.related_link ) + '" target="_blank" rel="noopener noreferrer" style="font-size:11px;font-weight:700;display:inline-block;margin-top:4px;">' + <?php echo wp_json_encode( __( 'Open filing site →', 'ajcore-ra' ) ); ?> + '</a>' : '';
							return '' +
								'<div class="ajcore-gi-attachment" data-attachment-id="' + escapeHtml( a.attachment_id ) + '" style="border:1px solid #e2e8f0;border-radius:8px;padding:10px;margin-bottom:8px;">' +
									'<div style="display:flex;align-items:flex-start;gap:8px;">' +
										'<input type="checkbox" class="ajcore-gi-att-check" ' + ( a.looks_like_document ? 'checked' : '' ) + ' style="margin-top:8px;">' +
										'<div style="flex:1;min-width:0;">' +
											'<div style="display:flex;align-items:center;gap:4px;">' +
												'<input type="text" class="ajcore-gi-att-filename" value="' + escapeHtml( suggested ) + '" style="flex:1;min-width:0;">' +
												'<span style="color:#94a3b8;font-weight:600;">.pdf</span>' +
											'</div>' +
											'<select class="ajcore-gi-att-category" style="width:100%;margin-top:6px;">' + categoryOptionsFor( a.suggested_category || '' ) + '</select>' +
											'<p class="description" style="margin:4px 0 0;font-size:11px;">' + Math.round( a.size / 1024 ) + ' KB' + note + '</p>' +
											linkHtml +
										'</div>' +
										'<button type="button" class="button button-small ajcore-gi-att-preview-btn">' + <?php echo wp_json_encode( __( 'Preview', 'ajcore-ra' ) ); ?> + '</button>' +
									'</div>' +
									'<div class="ajcore-gi-att-preview-holder"></div>' +
								'</div>';
						} ).join( '' ) || '<p class="description">' + <?php echo wp_json_encode( __( 'No PDF attachments on this email.', 'ajcore-ra' ) ); ?> + '</p>';

						body.innerHTML = '' +
							'<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">' +
								'<div>' +
									'<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:16px;">' +
										'<p style="margin:0;font-weight:700;">' + escapeHtml( data.subject || '(no subject)' ) + '</p>' +
										'<p style="margin:4px 0 0;color:#64748b;font-size:12px;">' + <?php echo wp_json_encode( __( 'From', 'ajcore-ra' ) ); ?> + ' ' + escapeHtml( data.sender ) + ' &middot; ' + escapeHtml( data.date ) + '</p>' +
										'<div style="margin-top:8px;padding-top:8px;border-top:1px solid #e2e8f0;max-height:260px;overflow-y:auto;font-size:12px;color:#64748b;white-space:pre-wrap;">' + escapeHtml( data.body_text || '(no body content)' ) + '</div>' +
									'</div>' +
									'<label style="display:block;font-weight:700;margin-bottom:6px;">' + <?php echo wp_json_encode( __( 'Customer', 'ajcore-ra' ) ); ?> + '</label>' +
									'<select id="ajcore-gi-file-customer" style="width:100%;"><option value="">' + <?php echo wp_json_encode( __( '— pick a customer —', 'ajcore-ra' ) ); ?> + '</option>' + customerOptions + '</select>' +
									'<label style="display:block;font-weight:700;margin:14px 0 6px;">' + <?php echo wp_json_encode( __( 'Tag (applies to all filed attachments)', 'ajcore-ra' ) ); ?> + '</label>' +
									'<select id="ajcore-gi-file-tag" style="width:100%;">' + tagOptions + '</select>' +
									'<label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-weight:600;">' +
										'<input type="checkbox" id="ajcore-gi-file-notify" checked> ' + <?php echo wp_json_encode( __( 'Email the customer about these files', 'ajcore-ra' ) ); ?> +
									'</label>' +
									'<div id="ajcore-gi-file-error" style="display:none;margin-top:14px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;"></div>' +
									'<div style="margin-top:16px;display:flex;gap:8px;">' +
										'<button type="button" class="button button-primary" id="ajcore-gi-file-submit">' + <?php echo wp_json_encode( __( 'File Selected to Customer', 'ajcore-ra' ) ); ?> + '</button>' +
										( ( 'filed' !== rowStatus && 'resolved' !== rowStatus ) ? '<button type="button" class="button" id="ajcore-gi-mark-filed">' + <?php echo wp_json_encode( __( 'Mark as Already Filed', 'ajcore-ra' ) ); ?> + '</button>' : '' ) +
									'</div>' +
								'</div>' +
								'<div>' +
									'<label style="display:block;font-weight:700;margin-bottom:8px;">' + <?php echo wp_json_encode( __( 'Attachments', 'ajcore-ra' ) ); ?> + '</label>' +
									attachmentsHtml +
								'</div>' +
							'</div>';

						body.querySelectorAll( '.ajcore-gi-att-preview-btn' ).forEach( function ( pbtn ) {
							pbtn.addEventListener( 'click', function () {
								var wrap   = pbtn.closest( '.ajcore-gi-attachment' );
								var holder = wrap.querySelector( '.ajcore-gi-att-preview-holder' );
								var attId  = wrap.getAttribute( 'data-attachment-id' );
								if ( holder.dataset.loaded === '1' ) {
									holder.style.display = holder.style.display === 'none' ? 'block' : 'none';
									return;
								}
								pbtn.disabled = true;
								pbtn.textContent = <?php echo wp_json_encode( __( 'Loading…', 'ajcore-ra' ) ); ?>;
								apiFetch( '/gmail-intake/' + logId + '/attachment?attachment_id=' + encodeURIComponent( attId ) ).then( function ( d ) {
									holder.innerHTML = '<embed src="data:' + d.mime_type + ';base64,' + d.data_base64 + '" type="application/pdf" style="width:100%;height:32rem;margin-top:8px;border:1px solid #e2e8f0;border-radius:8px;">';
									holder.dataset.loaded = '1';
									pbtn.disabled = false;
									pbtn.textContent = <?php echo wp_json_encode( __( 'Hide', 'ajcore-ra' ) ); ?>;
								} ).catch( function ( e ) {
									holder.innerHTML = '<p style="color:#dc2626;font-size:12px;">' + escapeHtml( e.message ) + '</p>';
									pbtn.disabled = false;
									pbtn.textContent = <?php echo wp_json_encode( __( 'Preview', 'ajcore-ra' ) ); ?>;
								} );
							} );
						} );

						document.getElementById( 'ajcore-gi-file-submit' ).addEventListener( 'click', function () {
							var errorBox = document.getElementById( 'ajcore-gi-file-error' );
							var customerId = document.getElementById( 'ajcore-gi-file-customer' ).value;
							if ( ! customerId ) {
								errorBox.style.display = 'block';
								errorBox.textContent = <?php echo wp_json_encode( __( 'Pick a customer first.', 'ajcore-ra' ) ); ?>;
								return;
							}
							var selectedAttachments = [];
							body.querySelectorAll( '.ajcore-gi-attachment' ).forEach( function ( wrap ) {
								var check = wrap.querySelector( '.ajcore-gi-att-check' );
								if ( check && check.checked ) {
									selectedAttachments.push( {
										attachment_id: wrap.getAttribute( 'data-attachment-id' ),
										filename: wrap.querySelector( '.ajcore-gi-att-filename' ).value.trim() + '.pdf',
										category: wrap.querySelector( '.ajcore-gi-att-category' ).value,
									} );
								}
							} );
							if ( selectedAttachments.length === 0 ) {
								errorBox.style.display = 'block';
								errorBox.textContent = <?php echo wp_json_encode( __( 'Select at least one attachment to file.', 'ajcore-ra' ) ); ?>;
								return;
							}
							var submitBtn = document.getElementById( 'ajcore-gi-file-submit' );
							submitBtn.disabled = true;
							submitBtn.textContent = <?php echo wp_json_encode( __( 'Filing…', 'ajcore-ra' ) ); ?>;
							apiFetch( '/gmail-intake/' + logId + '/file', {
								method: 'POST',
								headers: { 'Content-Type': 'application/json' },
								body: JSON.stringify( {
									stripe_customer_id: customerId,
									tag: document.getElementById( 'ajcore-gi-file-tag' ).value,
									notify: document.getElementById( 'ajcore-gi-file-notify' ).checked,
									attachments: selectedAttachments,
								} ),
							} ).then( function () {
								window.location.reload();
							} ).catch( function ( e ) {
								errorBox.style.display = 'block';
								errorBox.textContent = e.message;
								submitBtn.disabled = false;
								submitBtn.textContent = <?php echo wp_json_encode( __( 'File Selected to Customer', 'ajcore-ra' ) ); ?>;
							} );
						} );

						var markFiledBtn = document.getElementById( 'ajcore-gi-mark-filed' );
						if ( markFiledBtn ) {
							markFiledBtn.addEventListener( 'click', function () {
								if ( ! window.confirm( <?php echo wp_json_encode( __( 'Mark this as already filed? This just clears it from Pending â it does not upload or attach anything.', 'ajcore-ra' ) ); ?> ) ) { return; }
								markFiledBtn.disabled = true;
								markFiledBtn.textContent = <?php echo wp_json_encode( __( 'Markingâ¦', 'ajcore-ra' ) ); ?>;
								apiFetch( '/gmail-intake/' + logId + '/mark-filed', { method: 'POST' } ).then( function () {
									window.location.reload();
								} ).catch( function ( e ) {
									markFiledBtn.disabled = false;
									markFiledBtn.textContent = <?php echo wp_json_encode( __( 'Mark as Already Filed', 'ajcore-ra' ) ); ?>;
									window.alert( e.message );
								} );
							} );
						}
					} ).catch( function ( e ) {
						body.innerHTML = '<p style="color:#dc2626;">' + escapeHtml( e.message ) + '</p>';
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
