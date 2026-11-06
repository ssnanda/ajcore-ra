<?php
/**
 * Zoho Mail shared inbox (OAuth connection, live read-only inbox), moved out of AJCore.
 *
 * Same /ajcore/v1 URLs as before (/zoho-mail/oauth/callback, /zoho-mail/test, /zoho-mail/diagnose, /ops/inbox), the
 * same transients and the same API calls, so the Zoho API Console client and its redirect URI keep working. The
 * Client Portal admin tab "Zoho Mail" replaces AJCore's "Zoho Shared Inbox" settings tab and the Inbox viewer.
 *
 * Settings: option `ajcore_ra_zoho_mail` (client secret and tokens encrypted). Values previously kept in AJCore's
 * `ajforms_settings` (zoho_mail_*) are copied into this option once on first load; the old keys are left in place
 * and no longer used.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Zoho_Mail {

	const OPTION = 'ajcore_ra_zoho_mail';
	const TAB    = 'zoho-mail';

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
		add_action( 'admin_post_ajcore_ra_save_zoho_mail', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ajcore_ra_zoho_mail_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public static function handle_save() {
		self::instance()->save_from_post();
	}

	public static function handle_disconnect() {
		self::instance()->disconnect();
	}

	public static function tab_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB ), $args ), admin_url( 'admin.php' ) );
	}

	// ── settings ────────────────────────────────────────────────────────────────

	private static function defaults() {
		return array(
			'zoho_mail_client_id'        => '',
			'zoho_mail_client_secret'    => '',
			'zoho_mail_account_email'    => '',
			'zoho_mail_org_id'           => '',
			'zoho_mail_group_id'         => '',
			'zoho_mail_data_center'      => 'com',
			'zoho_mail_access_token'     => '',
			'zoho_mail_refresh_token'    => '',
			'zoho_mail_token_expires_at' => 0,
			'zoho_mail_account_id'       => '',
			'zoho_mail_connected_email'  => '',
			'zoho_mail_connected_at'     => '',
		);
	}

	private static function secret_keys() {
		return array( 'zoho_mail_client_secret', 'zoho_mail_access_token', 'zoho_mail_refresh_token' );
	}

	private static function encrypt( $value ) {
		return function_exists( 'ajcore_encrypt_setting_value' ) ? ajcore_encrypt_setting_value( (string) $value ) : (string) $value;
	}

	private static function decrypt( $value ) {
		return function_exists( 'ajcore_decrypt_setting_value' ) ? (string) ajcore_decrypt_setting_value( (string) $value ) : (string) $value;
	}

	public function get_settings() {
		$opt      = get_option( self::OPTION, array() );
		$opt      = is_array( $opt ) ? $opt : array();
		$settings = array();
		foreach ( self::defaults() as $key => $default ) {
			$value            = isset( $opt[ $key ] ) ? $opt[ $key ] : $default;
			$settings[ $key ] = in_array( $key, self::secret_keys(), true ) ? trim( self::decrypt( (string) $value ) ) : $value;
		}
		return $settings;
	}

	private function store( array $settings ) {
		$opt = array( 'seeded' => 1 );
		foreach ( self::defaults() as $key => $default ) {
			$value       = isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
			$opt[ $key ] = in_array( $key, self::secret_keys(), true ) ? self::encrypt( (string) $value ) : $value;
		}
		update_option( self::OPTION, $opt, false );
	}

	/** One-time copy of AJCore's old zoho_mail_* keys (client id/secret, tokens, mailbox). */
	private static function seed_settings() {
		$opt = get_option( self::OPTION, null );
		if ( is_array( $opt ) && ! empty( $opt['seeded'] ) ) {
			return;
		}
		$found = array();
		if ( function_exists( 'ajforms_get_settings' ) ) {
			$legacy = ajforms_get_settings();
			foreach ( self::defaults() as $key => $default ) {
				if ( isset( $legacy[ $key ] ) && '' !== (string) $legacy[ $key ] && (string) $legacy[ $key ] !== (string) $default ) {
					$found[ $key ] = $legacy[ $key ];
				}
			}
		}
		self::instance()->store( array_merge( self::defaults(), $found ) );
	}

	private function save_from_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_save_zoho_mail' );
		$settings = $this->get_settings();
		foreach ( array( 'zoho_mail_client_id', 'zoho_mail_org_id', 'zoho_mail_group_id' ) as $field ) {
			$settings[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		$settings['zoho_mail_account_email'] = isset( $_POST['zoho_mail_account_email'] ) ? sanitize_email( wp_unslash( $_POST['zoho_mail_account_email'] ) ) : '';
		$dc                                  = isset( $_POST['zoho_mail_data_center'] ) ? sanitize_key( wp_unslash( $_POST['zoho_mail_data_center'] ) ) : 'com';
		$settings['zoho_mail_data_center']   = in_array( $dc, array( 'com', 'eu', 'in', 'com.au', 'jp' ), true ) ? $dc : 'com';
		$secret                              = isset( $_POST['zoho_mail_client_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['zoho_mail_client_secret'] ) ) ) : '';
		if ( '' !== $secret ) { // blank keeps the saved secret
			$settings['zoho_mail_client_secret'] = $secret;
		}
		$this->store( $settings );
		wp_safe_redirect( self::tab_url( array( 'saved' => 1 ) ) );
		exit;
	}

	private function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_zoho_mail_disconnect' );
		$settings = $this->get_settings();
		foreach ( array( 'zoho_mail_access_token', 'zoho_mail_refresh_token', 'zoho_mail_account_id', 'zoho_mail_connected_email', 'zoho_mail_connected_at' ) as $field ) {
			$settings[ $field ] = '';
		}
		$settings['zoho_mail_token_expires_at'] = 0;
		$this->store( $settings );
		wp_safe_redirect( self::tab_url( array( 'zoho-mail-disconnected' => 'true' ) ) );
		exit;
	}

	// ── REST ────────────────────────────────────────────────────────────────────

	public function register_routes() {
		$ns = AJCore_Extensions::rest_namespace();
		// Zoho's redirect back carries none of WordPress's REST auth headers, so WP treats it as logged out;
		// security is the handler's own state-token check (a random value stashed in a transient on Connect).
		register_rest_route( $ns, '/zoho-mail/oauth/callback', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'oauth_callback' ), 'permission_callback' => '__return_true' ) );
		// Called with fetch() from the tab itself (real X-WP-Nonce), so cookie auth works.
		register_rest_route( $ns, '/zoho-mail/test', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'test_connection' ), 'permission_callback' => function () { return current_user_can( 'manage_options' ); } ) );
		register_rest_route( $ns, '/zoho-mail/diagnose', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'diagnose_route' ), 'permission_callback' => function () { return current_user_can( 'manage_options' ); } ) );
		register_rest_route( $ns, '/ops/inbox', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_ops_inbox' ), 'permission_callback' => array( 'AJCore_Extensions', 'can_manage_ops' ) ) );
	}

	public static function catalog( $catalog ) {
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/inbox', 'auth' => 'Admin', 'purpose' => 'Shared Zoho Mail inbox, read live (folder_id, limit). Not connected returns empty lists.', 'app' => 'OPS inbox' );
		$catalog[] = array( 'surface' => 'System', 'method' => 'GET', 'path' => '/zoho-mail/oauth/callback', 'auth' => 'State token', 'purpose' => 'Browser redirect target after the Zoho Mail consent screen.', 'app' => 'WP admin' );
		$catalog[] = array( 'surface' => 'System', 'method' => 'GET', 'path' => '/zoho-mail/test', 'auth' => 'Admin', 'purpose' => 'Connection test for the Zoho Mail tab.', 'app' => 'WP admin' );
		$catalog[] = array( 'surface' => 'System', 'method' => 'GET', 'path' => '/zoho-mail/diagnose', 'auth' => 'Admin', 'purpose' => 'Diagnostic search across the mailboxes the connected login can see.', 'app' => 'WP admin' );
		return $catalog;
	}

	public function get_ops_inbox( WP_REST_Request $request ) {
		$settings = $this->get_settings();
		if ( empty( $settings['zoho_mail_refresh_token'] ) ) {
			return rest_ensure_response( array( 'connected' => false, 'folders' => array(), 'messages' => array() ) );
		}
		$folders = $this->get_folders();
		if ( is_wp_error( $folders ) ) {
			return new WP_Error( $folders->get_error_code(), $folders->get_error_message(), array( 'status' => 502 ) );
		}
		$folder_id = sanitize_text_field( (string) $request->get_param( 'folder_id' ) );
		$limit     = min( 100, max( 1, absint( $request->get_param( 'limit' ) ?: 50 ) ) );
		$messages  = $this->get_recent_messages( $limit, $folder_id );
		if ( is_wp_error( $messages ) ) {
			return new WP_Error( $messages->get_error_code(), $messages->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'connected' => true, 'connected_email' => (string) $settings['zoho_mail_connected_email'], 'folders' => $folders, 'messages' => $messages ) );
	}

	/** Browser redirect from Zoho after the admin approves (or denies) the consent screen. */
	public function oauth_callback( WP_REST_Request $request ) {
		$error = sanitize_text_field( (string) $request->get_param( 'error' ) );
		if ( '' !== $error ) {
			wp_safe_redirect( self::tab_url( array( 'zoho-mail-error' => $error ) ) );
			exit;
		}
		$state          = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$expected_state = get_transient( 'ajcore_zoho_mail_oauth_state' );
		delete_transient( 'ajcore_zoho_mail_oauth_state' );
		if ( '' === $state || ! $expected_state || ! hash_equals( (string) $expected_state, $state ) ) {
			wp_safe_redirect( self::tab_url( array( 'zoho-mail-error' => __( 'Could not verify this request (expired link) — click Connect Zoho Mail again.', 'ajcore-ra' ) ) ) );
			exit;
		}
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		if ( '' === $code ) {
			wp_safe_redirect( self::tab_url( array( 'zoho-mail-error' => __( 'Zoho did not return an authorization code.', 'ajcore-ra' ) ) ) );
			exit;
		}
		$result = $this->complete_oauth_connection( $code );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::tab_url( array( 'zoho-mail-error' => $result->get_error_message() ) ) );
			exit;
		}
		wp_safe_redirect( self::tab_url( array( 'zoho-mail-connected' => 'true' ) ) );
		exit;
	}

	public function test_connection( WP_REST_Request $request ) {
		$folders = $this->get_folders();
		if ( is_wp_error( $folders ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $folders->get_error_message() ) );
		}
		$messages = $this->get_recent_messages( 5 );
		if ( is_wp_error( $messages ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $messages->get_error_message() ) );
		}
		return rest_ensure_response( array(
			'success'         => true,
			'folders'         => wp_list_pluck( $folders, 'name' ),
			'recent_messages' => array_map( function ( $m ) {
				$from = '' !== $m['from_name'] ? $m['from_name'] : $m['from_email'];
				return trim( $m['subject'] . ( '' !== $from ? ' — ' . $from : '' ) );
			}, $messages ),
		) );
	}

	public function diagnose_route( WP_REST_Request $request ) {
		$search_text = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( '' === $search_text ) {
			$search_text = 'E-Notification Filing';
		}
		$result = $this->diagnose_shared_access( $search_text );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $result->get_error_message() ) );
		}
		return rest_ensure_response( array_merge( array( 'success' => true ), $result ) );
	}

	// ── Zoho API (moved unchanged from AJCore, reading this class's settings) ───

	/** The data-center-specific accounts host Zoho token/authorize endpoints live on. */
	private function accounts_host( $data_center ) {
		$dc = in_array( $data_center, array( 'com', 'eu', 'in', 'com.au', 'jp' ), true ) ? $data_center : 'com';
		return 'accounts.zoho.' . $dc;
	}

	/** The data-center-specific Mail API host (only com/eu/in currently have mail.zoho.{dc}). */
	private function api_host( $data_center ) {
		$dc = in_array( $data_center, array( 'com', 'eu', 'in' ), true ) ? $data_center : 'com';
		return 'mail.zoho.' . $dc;
	}

	/**
	 * Exchanges an authorization code for access + refresh tokens, finds the Shared Mailbox (a Zoho organization
	 * group with its own mailboxId, not a personal account) and stores everything.
	 */
	private function complete_oauth_connection( $code ) {
		$settings      = $this->get_settings();
		$client_id     = trim( (string) $settings['zoho_mail_client_id'] );
		$client_secret = trim( (string) $settings['zoho_mail_client_secret'] );
		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'zoho_mail_not_configured', __( 'Zoho Mail Client ID/Secret are not saved.', 'ajcore-ra' ) );
		}
		$token_response = wp_remote_post( 'https://' . $this->accounts_host( $settings['zoho_mail_data_center'] ) . '/oauth/v2/token', array(
			'timeout' => 20,
			'body'    => array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => rest_url( 'ajcore/v1/zoho-mail/oauth/callback' ),
				'code'          => sanitize_text_field( (string) $code ),
			),
		) );
		if ( is_wp_error( $token_response ) ) {
			return $token_response;
		}
		$token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );
		if ( empty( $token_data['access_token'] ) ) {
			return new WP_Error( 'zoho_mail_token_exchange_failed', ! empty( $token_data['error'] ) ? (string) $token_data['error'] : __( 'Zoho did not return an access token.', 'ajcore-ra' ) );
		}
		$access_token  = sanitize_text_field( (string) $token_data['access_token'] );
		$refresh_token = ! empty( $token_data['refresh_token'] ) ? sanitize_text_field( (string) $token_data['refresh_token'] ) : trim( (string) $settings['zoho_mail_refresh_token'] );
		$expires_in    = ! empty( $token_data['expires_in'] ) ? absint( $token_data['expires_in'] ) : 3600;

		$api_host     = $this->api_host( $settings['zoho_mail_data_center'] );
		$auth_headers = array( 'Authorization' => 'Zoho-oauthtoken ' . $access_token );
		$wanted_email = strtolower( trim( (string) $settings['zoho_mail_account_email'] ) );
		$wanted_local = false !== strpos( $wanted_email, '@' ) ? strtolower( substr( $wanted_email, 0, strpos( $wanted_email, '@' ) ) ) : $wanted_email;

		// zoid has no reliable non-partner discovery API, so it is entered in settings (Admin Console: Organization > Profile).
		$zoid = sanitize_text_field( trim( (string) $settings['zoho_mail_org_id'] ) );
		if ( '' === $zoid ) {
			return new WP_Error( 'zoho_mail_no_org_id', __( 'Zoho Organization ID is not saved — copy it from the Zoho Mail Admin Console (Organization → Profile) into the settings, then reconnect.', 'ajcore-ra' ) );
		}

		$mailbox_id       = '';
		$connected_email  = '';
		$available_groups = array();
		$zgid_override    = sanitize_text_field( trim( (string) $settings['zoho_mail_group_id'] ) );

		if ( '' !== $zgid_override ) {
			// Manual override: fetch this one group directly (the org-wide list has been seen returning an empty list).
			$group_url      = "https://{$api_host}/api/organization/{$zoid}/groups/{$zgid_override}";
			$group_response = wp_remote_get( $group_url, array( 'timeout' => 20, 'headers' => $auth_headers ) );
			if ( is_wp_error( $group_response ) ) {
				return $group_response;
			}
			$group_status = (int) wp_remote_retrieve_response_code( $group_response );
			$group_body   = wp_remote_retrieve_body( $group_response );
			$group_data   = json_decode( $group_body, true );
			set_transient( 'ajcore_zoho_mail_groups_debug', array( 'url' => $group_url, 'status' => $group_status, 'body' => $group_body, 'at' => current_time( 'mysql' ) ), 15 * MINUTE_IN_SECONDS );
			$group = isset( $group_data['data'] ) && is_array( $group_data['data'] ) ? $group_data['data'] : array();
			if ( $group_status >= 300 || empty( $group['mailboxId'] ) ) {
				return new WP_Error( 'zoho_mail_group_lookup_failed', sprintf( __( 'Could not fetch Zoho Group ID %1$s directly — GET /api/organization/{zoid}/groups/{zgid} returned HTTP %2$d: %3$s', 'ajcore-ra' ), $zgid_override, $group_status, $group_body ) );
			}
			$mailbox_id      = sanitize_text_field( (string) $group['mailboxId'] );
			$group_email     = isset( $group['emailId'] ) ? (string) $group['emailId'] : ( isset( $group['email'] ) ? (string) $group['email'] : '' );
			$connected_email = '' !== $group_email ? sanitize_email( $group_email ) : trim( (string) $settings['zoho_mail_account_email'] );
		} else {
			$groups_url      = "https://{$api_host}/api/organization/{$zoid}/groups";
			$groups_response = wp_remote_get( $groups_url, array( 'timeout' => 20, 'headers' => $auth_headers ) );
			if ( is_wp_error( $groups_response ) ) {
				return $groups_response;
			}
			$groups_status = (int) wp_remote_retrieve_response_code( $groups_response );
			$groups_body   = wp_remote_retrieve_body( $groups_response );
			$groups_data   = json_decode( $groups_body, true );
			set_transient( 'ajcore_zoho_mail_groups_debug', array( 'url' => $groups_url, 'status' => $groups_status, 'body' => $groups_body, 'at' => current_time( 'mysql' ) ), 15 * MINUTE_IN_SECONDS );
			if ( $groups_status >= 300 || ! isset( $groups_data['data']['groups'] ) ) {
				return new WP_Error( 'zoho_mail_groups_lookup_failed', sprintf( __( 'Could not list Shared Mailbox groups for this organization — GET /api/organization/{zoid}/groups returned HTTP %1$d: %2$s', 'ajcore-ra' ), $groups_status, $groups_body ) );
			}
			foreach ( (array) $groups_data['data']['groups'] as $group ) {
				if ( ! is_array( $group ) || empty( $group['mailboxId'] ) ) {
					continue; // Plain distribution lists have no mailboxId; only Shared Mailbox groups do.
				}
				$group_name         = isset( $group['name'] ) ? (string) $group['name'] : '';
				$group_email        = isset( $group['emailId'] ) ? (string) $group['emailId'] : ( isset( $group['email'] ) ? (string) $group['email'] : '' );
				$available_groups[] = '' !== $group_email ? $group_email : $group_name;
				if ( strtolower( trim( $group_email ) ) === $wanted_email || strtolower( trim( $group_name ) ) === $wanted_local ) {
					$mailbox_id      = sanitize_text_field( (string) $group['mailboxId'] );
					$connected_email = '' !== $group_email ? sanitize_email( $group_email ) : trim( (string) $settings['zoho_mail_account_email'] );
					break;
				}
			}
		}

		if ( '' === $mailbox_id ) {
			return new WP_Error( 'zoho_mail_shared_mailbox_not_found', sprintf(
				__( 'No Shared Mailbox group matching %1$s was found for this Zoho organization. Shared Mailbox groups visible: %2$s. Confirm the signed-in Zoho user is a member/moderator of it, or fix the Shared Mailbox Address in settings.', 'ajcore-ra' ),
				$wanted_email,
				! empty( $available_groups ) ? implode( ', ', array_unique( $available_groups ) ) : __( 'none — this login may not be an org admin, which the Groups API requires', 'ajcore-ra' )
			) );
		}

		$settings['zoho_mail_access_token']     = $access_token;
		$settings['zoho_mail_refresh_token']    = $refresh_token;
		$settings['zoho_mail_token_expires_at'] = time() + $expires_in;
		$settings['zoho_mail_account_id']       = $mailbox_id;
		$settings['zoho_mail_connected_email']  = $connected_email;
		$settings['zoho_mail_connected_at']     = current_time( 'mysql' );
		$this->store( $settings );
		return true;
	}

	/** A usable access token, refreshed through the stored refresh token when expired (or about to be). */
	private function get_valid_access_token() {
		$settings      = $this->get_settings();
		$access_token  = trim( (string) $settings['zoho_mail_access_token'] );
		$refresh_token = trim( (string) $settings['zoho_mail_refresh_token'] );
		$expires_at    = absint( $settings['zoho_mail_token_expires_at'] );
		if ( '' === $refresh_token ) {
			return new WP_Error( 'zoho_mail_not_connected', __( 'Zoho Mail is not connected.', 'ajcore-ra' ) );
		}
		if ( '' !== $access_token && $expires_at > time() + 60 ) {
			return $access_token;
		}
		$client_id     = trim( (string) $settings['zoho_mail_client_id'] );
		$client_secret = trim( (string) $settings['zoho_mail_client_secret'] );
		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'zoho_mail_not_configured', __( 'Zoho Mail Client ID/Secret are not saved.', 'ajcore-ra' ) );
		}
		$response = wp_remote_post( 'https://' . $this->accounts_host( $settings['zoho_mail_data_center'] ) . '/oauth/v2/token', array(
			'timeout' => 20,
			'body'    => array( 'grant_type' => 'refresh_token', 'client_id' => $client_id, 'client_secret' => $client_secret, 'refresh_token' => $refresh_token ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'zoho_mail_refresh_failed', ! empty( $data['error'] ) ? (string) $data['error'] : __( 'Zoho did not return a refreshed access token.', 'ajcore-ra' ) );
		}
		$settings['zoho_mail_access_token']     = sanitize_text_field( (string) $data['access_token'] );
		$settings['zoho_mail_token_expires_at'] = time() + ( ! empty( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600 );
		$this->store( $settings );
		return $settings['zoho_mail_access_token'];
	}

	/** Diagnostic: searches every mailbox the authorized login can personally see for a known piece of text. */
	private function diagnose_shared_access( $search_text ) {
		$access_token = $this->get_valid_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}
		$settings     = $this->get_settings();
		$api_host     = $this->api_host( $settings['zoho_mail_data_center'] );
		$auth_headers = array( 'Authorization' => 'Zoho-oauthtoken ' . $access_token );

		$accounts_response = wp_remote_get( "https://{$api_host}/api/accounts", array( 'timeout' => 20, 'headers' => $auth_headers ) );
		if ( is_wp_error( $accounts_response ) ) {
			return $accounts_response;
		}
		$accounts_data  = json_decode( wp_remote_retrieve_body( $accounts_response ), true );
		$accounts_tried = array();
		$matches        = array();
		foreach ( (array) ( isset( $accounts_data['data'] ) ? $accounts_data['data'] : array() ) as $account ) {
			if ( ! is_array( $account ) || empty( $account['accountId'] ) ) {
				continue;
			}
			$account_id       = (string) $account['accountId'];
			$account_email    = isset( $account['primaryEmailAddress'] ) ? (string) $account['primaryEmailAddress'] : '';
			$accounts_tried[] = array( 'account_id' => $account_id, 'email' => $account_email );
			$search_response  = wp_remote_get( add_query_arg( array( 'searchKey' => $search_text, 'limit' => 5 ), "https://{$api_host}/api/accounts/{$account_id}/messages/search" ), array( 'timeout' => 20, 'headers' => $auth_headers ) );
			if ( is_wp_error( $search_response ) ) {
				continue;
			}
			$search_data = json_decode( wp_remote_retrieve_body( $search_response ), true );
			foreach ( (array) ( isset( $search_data['data'] ) ? $search_data['data'] : array() ) as $row ) {
				if ( empty( $row['messageId'] ) ) {
					continue;
				}
				$matches[] = array( 'account_id' => $account_id, 'account_email' => $account_email, 'folder_id' => isset( $row['folderId'] ) ? (string) $row['folderId'] : '', 'subject' => isset( $row['subject'] ) ? (string) $row['subject'] : '' );
			}
		}
		return array( 'search_text' => $search_text, 'accounts_tried' => $accounts_tried, 'found' => ! empty( $matches ), 'matches' => $matches );
	}

	/** Authenticated GET against the connected mailbox; the last response is kept 15 minutes for the debug panel. */
	private function api_get( $path, $query = array() ) {
		$settings   = $this->get_settings();
		$account_id = trim( (string) $settings['zoho_mail_account_id'] );
		if ( '' === $account_id ) {
			return new WP_Error( 'zoho_mail_not_connected', __( 'Zoho Mail is not connected.', 'ajcore-ra' ) );
		}
		$access_token = $this->get_valid_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}
		$url = 'https://' . $this->api_host( $settings['zoho_mail_data_center'] ) . '/api/accounts/' . rawurlencode( $account_id ) . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}
		$response = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Zoho-oauthtoken ' . $access_token ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $raw_body, true );
		set_transient( 'ajcore_zoho_mail_api_debug', array( 'url' => $url, 'status' => $status_code, 'body' => $raw_body, 'at' => current_time( 'mysql' ) ), 15 * MINUTE_IN_SECONDS );
		if ( $status_code >= 400 ) {
			return new WP_Error( 'zoho_mail_api_error', ! empty( $data['status']['description'] ) ? (string) $data['status']['description'] : sprintf( __( 'Zoho Mail API returned an error (%d).', 'ajcore-ra' ), $status_code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	public function get_folders() {
		$data = $this->api_get( '/folders' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$folders = array();
		foreach ( (array) ( isset( $data['data'] ) ? $data['data'] : array() ) as $row ) {
			if ( empty( $row['folderId'] ) ) {
				continue;
			}
			$folders[] = array(
				'folder_id'    => (string) $row['folderId'],
				'name'         => isset( $row['folderName'] ) ? (string) $row['folderName'] : ( isset( $row['path'] ) ? (string) $row['path'] : __( '(unnamed folder)', 'ajcore-ra' ) ),
				'unread_count' => isset( $row['unreadCount'] ) ? absint( $row['unreadCount'] ) : 0,
			);
		}
		return $folders;
	}

	public function get_recent_messages( $limit = 10, $folder_id = '' ) {
		$query = array( 'limit' => max( 1, absint( $limit ) ) );
		if ( '' !== $folder_id ) {
			$query['folderId'] = $folder_id;
		}
		$data = $this->api_get( '/messages/view', $query );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$messages = array();
		foreach ( (array) ( isset( $data['data'] ) ? $data['data'] : array() ) as $row ) {
			if ( empty( $row['messageId'] ) ) {
				continue;
			}
			$messages[] = array(
				'message_id'     => (string) $row['messageId'],
				'folder_id'      => isset( $row['folderId'] ) ? (string) $row['folderId'] : $folder_id,
				'subject'        => isset( $row['subject'] ) && '' !== $row['subject'] ? (string) $row['subject'] : __( '(no subject)', 'ajcore-ra' ),
				'from_name'      => isset( $row['sender'] ) ? (string) $row['sender'] : '',
				'from_email'     => isset( $row['fromAddress'] ) ? (string) $row['fromAddress'] : '',
				'summary'        => isset( $row['summary'] ) ? (string) $row['summary'] : '',
				'received_at'    => isset( $row['receivedTime'] ) ? $this->timestamp_to_mysql( $row['receivedTime'] ) : '',
				'is_unread'      => isset( $row['status'] ) && '0' === (string) $row['status'],
				'has_attachment' => ! empty( $row['hasAttachment'] ) && '1' === (string) $row['hasAttachment'],
			);
		}
		return $messages;
	}

	/** Zoho Mail timestamps are milliseconds since epoch. */
	private function timestamp_to_mysql( $ms_timestamp ) {
		$ms = (float) $ms_timestamp;
		return $ms <= 0 ? '' : gmdate( 'Y-m-d H:i:s', (int) round( $ms / 1000 ) );
	}

	// ── Admin tab ───────────────────────────────────────────────────────────────

	public static function admin_tab( $tabs ) {
		$tabs[ self::TAB ] = array( 'label' => __( 'Zoho Mail', 'ajcore-ra' ) );
		return $tabs;
	}

	public static function render_admin_tab( $tab ) {
		if ( self::TAB !== $tab || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::instance()->render_tab();
	}

	private function render_tab() {
		$settings     = $this->get_settings();
		$is_connected = ! empty( $settings['zoho_mail_refresh_token'] );
		$can_connect  = ! empty( $settings['zoho_mail_client_id'] ) && ! empty( $settings['zoho_mail_client_secret'] );
		$redirect_uri = rest_url( 'ajcore/v1/zoho-mail/oauth/callback' );

		if ( isset( $_GET['zoho-mail-connected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Zoho Mail connected.', 'ajcore-ra' ) . '</p></div>';
		} elseif ( isset( $_GET['zoho-mail-disconnected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Zoho Mail disconnected.', 'ajcore-ra' ) . '</p></div>';
		} elseif ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'ajcore-ra' ) . '</p></div>';
		} elseif ( isset( $_GET['zoho-mail-error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['zoho-mail-error'] ) ) ) . '</p></div>';
		}

		$this->render_inbox( $settings, $is_connected );

		// A random token in a transient (not a user-tied nonce) survives Zoho's redirect back, which carries no WP auth.
		$state = wp_generate_password( 32, false );
		set_transient( 'ajcore_zoho_mail_oauth_state', $state, 10 * MINUTE_IN_SECONDS );
		$authorize_url = add_query_arg( array(
			// organization.groups.READ is what finds the Shared Mailbox itself (it is an org Group with its own mailboxId).
			'scope'         => 'ZohoMail.accounts.READ,ZohoMail.messages.READ,ZohoMail.folders.READ,ZohoMail.organization.groups.READ',
			'client_id'     => (string) $settings['zoho_mail_client_id'],
			'response_type' => 'code',
			'access_type'   => 'offline',
			'redirect_uri'  => $redirect_uri,
			'prompt'        => 'consent',
			'state'         => $state,
		), 'https://' . $this->accounts_host( $settings['zoho_mail_data_center'] ) . '/oauth/v2/auth' );
		$disconnect_url = wp_nonce_url( admin_url( 'admin-post.php?action=ajcore_ra_zoho_mail_disconnect' ), 'ajcore_zoho_mail_disconnect' );
		?>
		<details class="ajforms-settings-card" style="margin:14px 0"<?php echo ! $is_connected || isset( $_GET['saved'] ) || isset( $_GET['zoho-mail-error'] ) ? ' open' : ''; ?>>
			<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'Connection', 'ajcore-ra' ); ?> — <?php echo $is_connected ? esc_html( sprintf( __( 'connected as %s', 'ajcore-ra' ), $settings['zoho_mail_connected_email'] ?: __( '(mailbox address not confirmed)', 'ajcore-ra' ) ) ) : esc_html__( 'not connected', 'ajcore-ra' ); ?></summary>
			<details style="margin:10px 0">
				<summary style="cursor:pointer"><?php esc_html_e( 'Setup steps', 'ajcore-ra' ); ?></summary>
				<ol style="line-height:1.7">
					<li><?php esc_html_e( 'Open api-console.zoho.com (the Zoho API Console, not the Mail Admin Console) and sign in with the admin who can see the shared mailbox.', 'ajcore-ra' ); ?></li>
					<li><?php esc_html_e( 'Add Client, choose Server-based Applications.', 'ajcore-ra' ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Client Name: AJCore Zoho Mail. Homepage URL: %s', 'ajcore-ra' ), home_url( '/' ) ) ); ?></li>
					<li><?php esc_html_e( 'Authorized Redirect URI (must match exactly):', 'ajcore-ra' ); ?> <code><?php echo esc_html( $redirect_uri ); ?></code></li>
					<li><?php esc_html_e( 'Copy the Client ID and Client Secret below, plus the Organization ID (Mail Admin Console: Organization, Profile).', 'ajcore-ra' ); ?></li>
					<li><?php esc_html_e( 'In the Mail Admin Console, Groups, Shared Mailbox, Members: the Zoho user you sign in with must be a member or moderator and an org admin (Zoho treats a Shared Mailbox as an organization group).', 'ajcore-ra' ); ?></li>
				</ol>
			</details>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px">
				<input type="hidden" name="action" value="ajcore_ra_save_zoho_mail">
				<?php wp_nonce_field( 'ajcore_ra_save_zoho_mail' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="zoho_mail_client_id"><?php esc_html_e( 'Client ID', 'ajcore-ra' ); ?></label></th><td><input type="text" class="regular-text" name="zoho_mail_client_id" id="zoho_mail_client_id" value="<?php echo esc_attr( $settings['zoho_mail_client_id'] ); ?>" autocomplete="off"></td></tr>
					<tr><th><label for="zoho_mail_client_secret"><?php esc_html_e( 'Client Secret', 'ajcore-ra' ); ?></label></th><td><input type="text" class="regular-text" name="zoho_mail_client_secret" id="zoho_mail_client_secret" value="" placeholder="<?php echo ! empty( $settings['zoho_mail_client_secret'] ) ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'ajcore-ra' ) : ''; ?>" autocomplete="off"></td></tr>
					<tr><th><label for="zoho_mail_account_email"><?php esc_html_e( 'Shared Mailbox Address', 'ajcore-ra' ); ?></label></th><td><input type="email" class="regular-text" name="zoho_mail_account_email" id="zoho_mail_account_email" value="<?php echo esc_attr( $settings['zoho_mail_account_email'] ); ?>"></td></tr>
					<tr><th><label for="zoho_mail_org_id"><?php esc_html_e( 'Organization ID', 'ajcore-ra' ); ?></label></th><td><input type="text" class="regular-text" name="zoho_mail_org_id" id="zoho_mail_org_id" value="<?php echo esc_attr( $settings['zoho_mail_org_id'] ); ?>" autocomplete="off"></td></tr>
					<tr><th><label for="zoho_mail_group_id"><?php esc_html_e( 'Group ID (zgid), optional', 'ajcore-ra' ); ?></label></th><td><input type="text" class="regular-text" name="zoho_mail_group_id" id="zoho_mail_group_id" value="<?php echo esc_attr( $settings['zoho_mail_group_id'] ); ?>" autocomplete="off"><p class="description"><?php esc_html_e( 'Only if the groups list comes back empty. Copy it from the group URL in the Admin Console (after "grouplist/" or "/groups/").', 'ajcore-ra' ); ?></p></td></tr>
					<tr><th><label for="zoho_mail_data_center"><?php esc_html_e( 'Data center', 'ajcore-ra' ); ?></label></th><td><select name="zoho_mail_data_center" id="zoho_mail_data_center"><?php foreach ( array( 'com' => 'zoho.com (US)', 'eu' => 'zoho.eu', 'in' => 'zoho.in', 'com.au' => 'zoho.com.au', 'jp' => 'zoho.jp' ) as $dc_key => $dc_label ) : ?><option value="<?php echo esc_attr( $dc_key ); ?>" <?php selected( $settings['zoho_mail_data_center'], $dc_key ); ?>><?php echo esc_html( $dc_label ); ?></option><?php endforeach; ?></select></td></tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ajcore-ra' ); ?></button></p>
			</form>
			<p>
				<?php if ( $can_connect ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $authorize_url ); ?>"><?php echo esc_html( $is_connected ? __( 'Reconnect Zoho Mail', 'ajcore-ra' ) : __( 'Connect Zoho Mail', 'ajcore-ra' ) ); ?></a>
				<?php else : ?>
					<button type="button" class="button" disabled><?php esc_html_e( 'Connect Zoho Mail', 'ajcore-ra' ); ?></button> <span class="description"><?php esc_html_e( 'Save a Client ID and Client Secret first.', 'ajcore-ra' ); ?></span>
				<?php endif; ?>
				<?php if ( $is_connected ) : ?>
					<button type="button" class="button" id="ajra-zm-test"><?php esc_html_e( 'Test Connection', 'ajcore-ra' ); ?></button>
					<a class="button" href="<?php echo esc_url( $disconnect_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Disconnect Zoho Mail?', 'ajcore-ra' ) ); ?>');"><?php esc_html_e( 'Disconnect', 'ajcore-ra' ); ?></a>
				<?php endif; ?>
			</p>
			<div id="ajra-zm-result" style="display:none;margin:10px 0;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px"></div>
			<?php if ( $is_connected ) : ?>
				<h4 style="margin-bottom:4px"><?php esc_html_e( 'Diagnose Shared Mailbox access', 'ajcore-ra' ); ?></h4>
				<p class="description"><?php esc_html_e( 'Searches every mailbox this Zoho login can personally see for a known piece of text.', 'ajcore-ra' ); ?></p>
				<p><input type="text" id="ajra-zm-q" value="E-Notification Filing" style="min-width:260px"> <button type="button" class="button" id="ajra-zm-diag"><?php esc_html_e( 'Run diagnostic', 'ajcore-ra' ); ?></button></p>
				<div id="ajra-zm-diag-result" style="display:none;margin:10px 0;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px"></div>
			<?php endif; ?>
			<?php
			foreach ( array( 'ajcore_zoho_mail_groups_debug' => __( 'Last raw Shared Mailbox groups response', 'ajcore-ra' ), 'ajcore_zoho_mail_api_debug' => __( 'Last raw Zoho Mail API response', 'ajcore-ra' ) ) as $debug_key => $debug_label ) {
				$debug = get_transient( $debug_key );
				if ( empty( $debug ) || ! is_array( $debug ) ) {
					continue;
				}
				$decoded = json_decode( $debug['body'], true );
				$pretty  = null !== $decoded ? wp_json_encode( $decoded, JSON_PRETTY_PRINT ) : $debug['body'];
				echo '<details style="margin-top:10px"><summary>' . esc_html( $debug_label ) . ' — ' . esc_html( $debug['url'] ) . ' — HTTP ' . esc_html( $debug['status'] ) . ' — ' . esc_html( $debug['at'] ) . '</summary><pre style="max-height:320px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font-size:12px;white-space:pre-wrap">' . esc_html( $pretty ) . '</pre></details>';
			}
			?>
		</details>
		<script>
		(function () {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var testUrl = <?php echo wp_json_encode( rest_url( 'ajcore/v1/zoho-mail/test' ) ); ?>;
			var diagUrl = <?php echo wp_json_encode( rest_url( 'ajcore/v1/zoho-mail/diagnose' ) ); ?>;
			function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
			function call(url, btn, box, render) {
				btn.disabled = true; box.style.display = 'none';
				fetch(url, { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (d) { box.innerHTML = render(d || {}); box.style.display = 'block'; })
					.catch(function () { box.innerHTML = 'Request to the site failed.'; box.style.display = 'block'; })
					.then(function () { btn.disabled = false; });
			}
			var t = document.getElementById('ajra-zm-test');
			if (t) { t.addEventListener('click', function () {
				call(testUrl, t, document.getElementById('ajra-zm-result'), function (d) {
					if (!d.success) { return '<strong style="color:#b32d2e">Test failed</strong><br>' + esc(d.message || 'Unknown error.'); }
					return '<strong style="color:#007017">Connection works</strong><br>Folders: ' + esc((d.folders || []).join(', ')) + '<br>Recent: ' + ((d.recent_messages || []).length ? '<ul>' + d.recent_messages.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('') + '</ul>' : 'none');
				});
			}); }
			var g = document.getElementById('ajra-zm-diag');
			if (g) { g.addEventListener('click', function () {
				call(diagUrl + (diagUrl.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(document.getElementById('ajra-zm-q').value), g, document.getElementById('ajra-zm-diag-result'), function (d) {
					if (!d.success) { return '<strong style="color:#b32d2e">Diagnostic failed</strong><br>' + esc(d.message || 'Unknown error.'); }
					var mailboxes = (d.accounts_tried || []).map(function (a) { return a.email || a.account_id; }).join(', ') || '(none)';
					var head = 'Searched for "' + esc(d.search_text) + '" in: ' + esc(mailboxes) + '<br>';
					if (d.found) { return head + '<strong style="color:#007017">Found it</strong> — the personal Message API can reach this content.<ul>' + d.matches.map(function (m) { return '<li>' + esc(m.account_email || m.account_id) + ' — folder ' + esc(m.folder_id) + ' — "' + esc(m.subject) + '"</li>'; }).join('') + '</ul>'; }
					return head + '<strong style="color:#b32d2e">Not found</strong> in any personally visible mailbox; the Groups and Organization ID setup is required.';
				});
			}); }
		})();
		</script>
		<?php
	}

	/** The shared inbox, read live from Zoho on every load (no local copy of message content). */
	private function render_inbox( array $settings, $is_connected ) {
		echo '<div class="ajforms-settings-card" style="margin-bottom:14px"><h2 style="margin-top:0">' . esc_html__( 'Inbox', 'ajcore-ra' ) . '</h2>';
		if ( ! $is_connected ) {
			echo '<p>' . esc_html__( 'Not connected yet. Open Connection below.', 'ajcore-ra' ) . '</p></div>';
			return;
		}
		$folders = $this->get_folders();
		if ( is_wp_error( $folders ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $folders->get_error_message() ) . '</p></div></div>';
			return;
		}
		$folder_id = isset( $_GET['zoho_folder'] ) ? sanitize_text_field( wp_unslash( $_GET['zoho_folder'] ) ) : '';
		$messages  = $this->get_recent_messages( 50, $folder_id );
		if ( is_wp_error( $messages ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $messages->get_error_message() ) . '</p></div></div>';
			return;
		}
		$mailbox = $settings['zoho_mail_connected_email'] ? $settings['zoho_mail_connected_email'] : $settings['zoho_mail_account_email'];
		echo '<p class="description">' . esc_html( sprintf( __( 'Shared mailbox: %s. Read-only view; reply, assign and archive still happen in Zoho Mail.', 'ajcore-ra' ), $mailbox ) ) . ' <a href="' . esc_url( 'https://' . $this->api_host( $settings['zoho_mail_data_center'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in Zoho Mail ↗', 'ajcore-ra' ) . '</a></p>';
		?>
		<form method="get" style="margin:8px 0">
			<input type="hidden" name="page" value="ajforms-client-portal"><input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB ); ?>">
			<select name="zoho_folder" onchange="this.form.submit();">
				<option value=""><?php esc_html_e( 'All folders', 'ajcore-ra' ); ?></option>
				<?php foreach ( $folders as $folder ) : ?>
					<option value="<?php echo esc_attr( $folder['folder_id'] ); ?>" <?php selected( $folder_id, $folder['folder_id'] ); ?>><?php echo esc_html( $folder['name'] . ( $folder['unread_count'] > 0 ? ' (' . $folder['unread_count'] . ')' : '' ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>
		<table class="widefat striped">
			<thead><tr><th style="width:170px"><?php esc_html_e( 'Date', 'ajcore-ra' ); ?></th><th style="width:220px"><?php esc_html_e( 'From', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Subject', 'ajcore-ra' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $messages ) ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No messages in this view.', 'ajcore-ra' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $messages as $message ) : ?>
					<tr style="<?php echo $message['is_unread'] ? 'font-weight:700;' : ''; ?>">
						<td><?php echo esc_html( $message['received_at'] ? get_date_from_gmt( $message['received_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—' ); ?></td>
						<td><?php echo esc_html( $message['from_name'] ? $message['from_name'] : $message['from_email'] ); ?></td>
						<td><?php echo esc_html( $message['subject'] ); ?><?php echo $message['has_attachment'] ? ' <span title="' . esc_attr__( 'Has attachment', 'ajcore-ra' ) . '">📎</span>' : ''; ?><?php if ( ! empty( $message['summary'] ) ) : ?><br><span class="description"><?php echo esc_html( wp_trim_words( $message['summary'], 20 ) ); ?></span><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		</div>
		<?php
	}
}
