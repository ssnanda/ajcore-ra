<?php
/**
 * Rentec Direct (API v3) integration, moved out of AJCore.
 *
 * Same /ajcore/v1/ops/rentec/* routes, parameters, permissions and responses AJOps already uses;
 * the handler code below is unchanged apart from where settings are read. Also provides the
 * Client Portal admin tab "Rentec" (workspace + connection settings) and the connection test.
 *
 * Settings: option `ajcore_ra_rentec` (API keys encrypted). With the shared database on, the master
 * site publishes them to the same shared row as before (`ajcore_rentec_settings`) and other sites
 * read from it. Values previously kept in AJCore's `ajforms_settings` (or that shared row) are copied
 * into this option once on first load.
 *
 * AJCore's expense-invoice options endpoint gets its Rentec units/vendors from the
 * 'ajcore_expense_invoice_options' filter, supplied here.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Rentec {

	const OPTION     = 'ajcore_ra_rentec';
	const SHARED_ROW = 'ajcore_rentec_settings';
	const TAB        = 'rentec';

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
		add_action( 'admin_post_ajcore_ra_save_rentec', array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_ajax_ajcore_test_rentec_connection', array( __CLASS__, 'ajax_test' ) );
		add_filter( 'ajcore_expense_invoice_options', array( __CLASS__, 'expense_invoice_options' ) );
	}

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public static function ajax_test() {
		self::instance()->ajax_test_rentec_connection();
	}

	public static function handle_save() {
		self::instance()->save_from_post();
	}

	// ── settings ────────────────────────────────────────────────────────────────

	private static function keys() {
		return array( 'rentec_enabled', 'rentec_api_key', 'rentec_account_label_1', 'rentec_api_key_2', 'rentec_account_label_2' );
	}

	private static function defaults() {
		return array(
			'rentec_enabled'         => '0',
			'rentec_api_key'         => '',
			'rentec_account_label_1' => 'Rentec Account 1',
			'rentec_api_key_2'       => '',
			'rentec_account_label_2' => 'Rentec Account 2',
		);
	}

	private static function secret_keys() {
		return array( 'rentec_api_key', 'rentec_api_key_2' );
	}

	private static function is_shared() {
		return function_exists( 'ajcore_is_shared_db_enabled' ) && ajcore_is_shared_db_enabled();
	}

	private static function is_master() {
		return ! function_exists( 'ajcore_is_stripe_sync_owner' ) || ajcore_is_stripe_sync_owner();
	}

	private static function decrypt( $value ) {
		return function_exists( 'ajcore_decrypt_setting_value' ) ? (string) ajcore_decrypt_setting_value( (string) $value ) : (string) $value;
	}

	private static function encrypt( $value ) {
		return function_exists( 'ajcore_encrypt_setting_value' ) ? ajcore_encrypt_setting_value( (string) $value ) : (string) $value;
	}

	private static function read_shared() {
		if ( ! function_exists( 'ajcore_get_shared_db' ) ) {
			return array();
		}
		$db = ajcore_get_shared_db();
		if ( ! $db ) {
			return array();
		}
		$table = $db->prefix . 'aj_shared_settings';
		if ( $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$value   = $db->get_var( $db->prepare( "SELECT setting_value FROM `{$table}` WHERE setting_name = %s LIMIT 1", self::SHARED_ROW ) );
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Publishes the (decrypted) settings to the shared row; master only, shared DB only. */
	private static function write_shared( array $settings ) {
		if ( ! self::is_shared() || ! self::is_master() || ! function_exists( 'ajcore_get_shared_db' ) ) {
			return false;
		}
		$db = ajcore_get_shared_db();
		if ( ! $db ) {
			return false;
		}
		$table = $db->prefix . 'aj_shared_settings';
		if ( $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
		$data = array();
		foreach ( self::keys() as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$data[ $key ] = $settings[ $key ];
			}
		}
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

	/**
	 * Settings with the same `rentec_*` keys AJCore used to expose (keys decrypted). On a non-master
	 * site with the shared DB on, the master's published values win, as before.
	 */
	public function get_settings() {
		$opt      = get_option( self::OPTION, array() );
		$opt      = is_array( $opt ) ? $opt : array();
		$settings = array();
		foreach ( self::defaults() as $key => $default ) {
			$value            = isset( $opt[ $key ] ) ? (string) $opt[ $key ] : $default;
			$settings[ $key ] = in_array( $key, self::secret_keys(), true ) ? trim( self::decrypt( $value ) ) : $value;
		}
		if ( self::is_shared() && ! self::is_master() ) {
			$shared = self::read_shared();
			if ( ! empty( $shared ) ) {
				$settings = array_merge( $settings, array_intersect_key( $shared, $settings ) );
			}
		}
		return $settings;
	}

	private function store( array $settings ) {
		$opt = array( 'seeded' => 1 );
		foreach ( self::keys() as $key ) {
			$value       = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : self::defaults()[ $key ];
			$opt[ $key ] = in_array( $key, self::secret_keys(), true ) ? self::encrypt( $value ) : $value;
		}
		update_option( self::OPTION, $opt, false );
		self::write_shared( $settings );
	}

	/**
	 * One-time copy of the settings AJCore used to hold: its `ajforms_settings` rentec_* keys, or, on a
	 * site that never had them locally, the shared row. A master also (re)publishes to the shared row
	 * when it does not exist yet, so other sites keep inheriting the configuration.
	 */
	private static function seed_settings() {
		$opt = get_option( self::OPTION, null );
		if ( is_array( $opt ) && ! empty( $opt['seeded'] ) ) {
			return;
		}
		$found = array();
		if ( function_exists( 'ajforms_get_settings' ) ) {
			$legacy = ajforms_get_settings();
			foreach ( self::keys() as $key ) {
				if ( isset( $legacy[ $key ] ) && '' !== (string) $legacy[ $key ] && ( ! isset( self::defaults()[ $key ] ) || (string) $legacy[ $key ] !== self::defaults()[ $key ] ) ) {
					$found[ $key ] = in_array( $key, self::secret_keys(), true ) ? self::decrypt( $legacy[ $key ] ) : (string) $legacy[ $key ];
				}
			}
		}
		if ( empty( $found ) ) {
			$found = array_intersect_key( self::read_shared(), self::defaults() );
		}
		$settings = array_merge( self::defaults(), $found );
		$store    = array( 'seeded' => 1 );
		foreach ( self::keys() as $key ) {
			$store[ $key ] = in_array( $key, self::secret_keys(), true ) ? self::encrypt( $settings[ $key ] ) : $settings[ $key ];
		}
		update_option( self::OPTION, $store, false );
		if ( self::is_shared() && self::is_master() && empty( self::read_shared() ) && ! empty( $found ) ) {
			self::write_shared( $settings );
		}
	}

	private function save_from_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_save_rentec' );
		if ( self::is_shared() && ! self::is_master() ) {
			wp_die( esc_html__( 'Rentec settings are managed by the AJCore master site.', 'ajcore-ra' ) );
		}

		$settings                           = $this->get_settings();
		$settings['rentec_enabled']         = isset( $_POST['rentec_enabled'] ) ? '1' : '0';
		$settings['rentec_account_label_1'] = isset( $_POST['rentec_account_label_1'] ) ? sanitize_text_field( wp_unslash( $_POST['rentec_account_label_1'] ) ) : 'Rentec Account 1';
		$settings['rentec_account_label_2'] = isset( $_POST['rentec_account_label_2'] ) ? sanitize_text_field( wp_unslash( $_POST['rentec_account_label_2'] ) ) : 'Rentec Account 2';
		foreach ( self::secret_keys() as $field ) {
			$new = isset( $_POST[ $field ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ) : '';
			if ( '' !== $new ) { // blank keeps the saved key
				$settings[ $field ] = $new;
			}
		}
		$this->store( $settings );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Client Portal admin tab ─────────────────────────────────────────────────

	public static function admin_tab( $tabs ) {
		$tabs[ self::TAB ] = array( 'label' => __( 'Rentec', 'ajcore-ra' ) );
		return $tabs;
	}

	public static function render_admin_tab( $tab ) {
		if ( self::TAB !== $tab || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rentec = self::instance();
		echo '<details class="ajforms-settings-card" style="margin-bottom:14px"' . ( isset( $_GET['saved'] ) ? ' open' : '' ) . '><summary style="cursor:pointer;font-weight:600">' . esc_html__( 'Connection & API keys', 'ajcore-ra' ) . '</summary>';
		$rentec->render_settings();
		echo '</details>';
		$rentec->render_workspace();
	}

	// ── REST routes (same URLs as before) ───────────────────────────────────────

	public function register_routes() {
		$ns  = AJCore_Extensions::rest_namespace();
		$ops = array( 'AJCore_Extensions', 'can_manage_ops' );

		register_rest_route(
			$ns,
			'/ops/rentec',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_rentec_resource' ),
				'permission_callback' => $ops,
				'args'                => array(
					'account'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'resource' => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
					'statuses' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ops/rentec/work-orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_rentec_work_order' ),
				'permission_callback' => $ops,
			)
		);
		register_rest_route(
			$ns,
			'/ops/rentec/tenants/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_rentec_tenant' ),
				'permission_callback' => $ops,
			)
		);
		register_rest_route(
			$ns,
			'/ops/rentec/tenants/overdue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_ops_rentec_tenants_overdue' ),
				'permission_callback' => $ops,
			)
		);
		register_rest_route(
			$ns,
			'/ops/rentec/work-orders',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_ops_rentec_work_order' ),
				'permission_callback' => $ops,
			)
		);
		register_rest_route(
			$ns,
			'/ops/rentec/work-order-options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_rentec_work_order_options' ),
				'permission_callback' => $ops,
			)
		);
		register_rest_route(
			$ns,
			'/ops/rentec/leads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_ops_rentec_lead' ),
				'permission_callback' => $ops,
			)
		);
		register_rest_route(
			$ns,
			'/ops/rentec/vendors',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_ops_rentec_vendor' ),
				'permission_callback' => $ops,
			)
		);

		register_rest_route(
			$ns,
			'/ops/rentec/work-orders/(?P<id>\d+)/notes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_ops_rentec_work_order_note' ),
				'permission_callback' => $ops,
				'args'                => array(
					'id'      => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'account' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'note'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
					'private' => array( 'required' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/ops/rentec/work-orders/(?P<id>\d+)/attachments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_ops_rentec_work_order_attachment' ),
				'permission_callback' => $ops,
				'args'                => array(
					'id'      => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'account' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	public static function catalog( $catalog ) {
		$e = static function ( $method, $path, $purpose ) {
			return array( 'surface' => 'OPS', 'method' => $method, 'path' => $path, 'auth' => 'Admin', 'purpose' => $purpose, 'app' => 'OPS rentec' );
		};
		$catalog[] = $e( 'GET', '/ops/rentec', 'Rentec Direct resource list (resource, account, statuses) proxied live from Rentec API v3.' );
		$catalog[] = $e( 'GET', '/ops/rentec/work-orders/{id}', 'One Rentec work order with notes and files.' );
		$catalog[] = $e( 'GET', '/ops/rentec/tenants/{id}', 'One Rentec tenant.' );
		$catalog[] = $e( 'POST', '/ops/rentec/tenants/overdue', 'Overdue-balance calculation for the supplied Rentec tenants.' );
		$catalog[] = $e( 'POST', '/ops/rentec/work-orders', 'Creates a Rentec work order.' );
		$catalog[] = $e( 'GET', '/ops/rentec/work-order-options', 'Properties, vendors and categories for the work-order form.' );
		$catalog[] = $e( 'POST', '/ops/rentec/leads', 'Creates a Rentec lead (the API has no update/delete for leads).' );
		$catalog[] = $e( 'POST', '/ops/rentec/vendors', 'Creates a Rentec vendor.' );
		$catalog[] = $e( 'POST', '/ops/rentec/work-orders/{id}/notes', 'Adds a note to a Rentec work order.' );
		$catalog[] = $e( 'POST', '/ops/rentec/work-orders/{id}/attachments', 'Uploads an attachment to a Rentec work order.' );
		return $catalog;
	}

	/** Supplies Rentec units + vendors to AJCore's expense-invoice options endpoint. */
	public static function expense_invoice_options( $options ) {
		return self::instance()->build_expense_invoice_options( is_array( $options ) ? $options : array() );
	}

	private function build_expense_invoice_options( array $options ) {
		$units   = (array) ( $options['units'] ?? array() );
		$vendors = (array) ( $options['vendors'] ?? array() );
		foreach ( $this->get_rentec_accounts_for_ops() as $account_id => $account ) {
			if ( empty( $account['key'] ) ) {
				continue;
			}
			foreach ( array( 'properties', 'vendors' ) as $resource ) {
				$url = 'https://secure.rentecdirect.com/api/v3/' . $resource;
				if ( 'properties' === $resource ) {
					$url = add_query_arg( 'include_subunits', 'true', $url );
				}
				$body = $this->request_rentec_for_ops( $url, $account['key'] );
				if ( is_wp_error( $body ) ) {
					continue;
				}
				$rows = 'properties' === $resource ? $this->flatten_rentec_properties_for_ops( $body['data'] ?? array() ) : (array) ( $body['data'] ?? array() );
				foreach ( $rows as $row ) {
					$row['_rentec_account']       = (string) $account_id;
					$row['_rentec_account_label'] = $account['label'];
					if ( 'properties' === $resource ) {
						$units[] = $row;
					} else {
						$vendors[] = $row;
					}
				}
			}
		}
		return array( 'units' => $units, 'vendors' => $vendors );
	}

	// ── Rentec API handlers (moved unchanged from AJCore) ──────────────────────

	private function get_rentec_accounts_for_ops() {
		$settings = function_exists( 'ajforms_get_settings' ) ? $this->get_settings() : get_option( 'ajforms_settings', array() );
		return array(
			'1' => array(
				'label' => sanitize_text_field( (string) ( $settings['rentec_account_label_1'] ?? 'Rentec Account 1' ) ),
				'key'   => (string) ( $settings['rentec_api_key'] ?? '' ),
			),
			'2' => array(
				'label' => sanitize_text_field( (string) ( $settings['rentec_account_label_2'] ?? 'Rentec Account 2' ) ),
				'key'   => (string) ( $settings['rentec_api_key_2'] ?? '' ),
			),
		);
	}

	private function get_rentec_account_for_ops_request( WP_REST_Request $request ) {
		$settings = function_exists( 'ajforms_get_settings' ) ? $this->get_settings() : get_option( 'ajforms_settings', array() );
		if ( '1' !== (string) ( $settings['rentec_enabled'] ?? '0' ) ) {
			return new WP_Error( 'ajcore_rentec_disabled', __( 'The Rentec integration is disabled.', 'ajcore-ra' ), array( 'status' => 409 ) );
		}
		$accounts = $this->get_rentec_accounts_for_ops();
		$account  = '2' === (string) $request->get_param( 'account' ) ? '2' : '1';
		if ( empty( $accounts[ $account ]['key'] ) && ! empty( $accounts[ '1' === $account ? '2' : '1' ]['key'] ) ) {
			$account = '1' === $account ? '2' : '1';
		}
		if ( empty( $accounts[ $account ]['key'] ) ) {
			return new WP_Error( 'ajcore_rentec_not_configured', __( 'The selected Rentec account does not have an API key.', 'ajcore-ra' ), array( 'status' => 409 ) );
		}
		return array( 'id' => $account, 'account' => $accounts[ $account ], 'accounts' => $accounts );
	}

	private function request_rentec_for_ops( $url, $api_key, $method = 'GET', $payload = null ) {
		$args = array(
			'method'      => $method,
			'headers'     => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'X-API-Key'    => $api_key,
			),
			'timeout'     => 15,
			'redirection' => 0,
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ajcore_rentec_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			$message = isset( $body['message'] ) ? sanitize_text_field( (string) $body['message'] ) : sprintf( __( 'Rentec returned HTTP status %d.', 'ajcore-ra' ), $status );
			return new WP_Error( 'ajcore_rentec_response_failed', $message, array( 'status' => 502 ) );
		}
		return $body;
	}

	/** Flatten Rentec multi-unit properties and inherit blank sub-unit addresses from the parent. */
	private function flatten_rentec_properties_for_ops( $rows ) {
		$flattened = array();
		$collect = function ( $properties, $parent_id = 0 ) use ( &$collect, &$flattened ) {
			foreach ( (array) $properties as $property ) {
				if ( ! is_array( $property ) ) continue;
				$subunits = isset( $property['subunits'] ) && is_array( $property['subunits'] ) ? $property['subunits'] : array();
				unset( $property['subunits'] );
				if ( $parent_id && empty( $property['sub_of'] ) ) $property['sub_of'] = $parent_id;
				$flattened[] = $property;
				$collect( $subunits, absint( $property['property_id'] ?? 0 ) );
			}
		};
		$collect( $rows );
		$by_id = array();
		foreach ( $flattened as $property ) {
			if ( ! empty( $property['property_id'] ) ) $by_id[ absint( $property['property_id'] ) ] = $property;
		}
		foreach ( $flattened as &$property ) {
			$parent_id = absint( $property['sub_of'] ?? 0 );
			$parent = $parent_id ? ( $by_id[ $parent_id ] ?? array() ) : array();
			if ( ! $parent ) continue;
			foreach ( array( 'address', 'city', 'state', 'zip' ) as $address_field ) {
				if ( empty( $property[ $address_field ] ) && ! empty( $parent[ $address_field ] ) ) $property[ $address_field ] = $parent[ $address_field ];
			}
			$property['_parent_property_id'] = $parent_id;
			$property['_parent_nickname'] = sanitize_text_field( (string) ( $parent['nickname'] ?? '' ) );
		}
		unset( $property );
		return $flattened;
	}

	public function get_ops_rentec_resource( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		// 'leases' backs AJOps' Insurance tab. Rentec's v3 API exposes NO insurance data of its own
		// — there is no /insurance endpoint and no policy/carrier/coverage field on any schema
		// (confirmed against the published OpenAPI spec) — so the closest real thing the API can
		// give that screen is the lease roster: who currently occupies which property, and for how
		// long. That is the list renters insurance is tracked against.
		$allowed_resources = array( 'tenants', 'leads', 'vendors', 'transactions', 'work_orders', 'files', 'messages', 'leases' );
		$resource          = sanitize_key( (string) $request->get_param( 'resource' ) );
		if ( ! in_array( $resource, $allowed_resources, true ) ) {
			$resource = 'work_orders';
		}
		$account  = $selected['id'];
		$accounts = $selected['accounts'];

		$url = 'https://secure.rentecdirect.com/api/v3/' . $resource;
		if ( 'transactions' === $resource ) {
			$url = add_query_arg( 'page', 1, $url );
		}

		$statuses = array();
		if ( 'work_orders' === $resource ) {
			$allowed_statuses = array( 'OpenU', 'OpenA', 'Parts', 'Work', 'Finalized', 'Closed' );
			$statuses         = array_values( array_intersect( $allowed_statuses, array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'statuses' ) ) ) ) ) );
			if ( empty( $statuses ) ) {
				$statuses = array( 'OpenU', 'OpenA', 'Parts', 'Work', 'Finalized' );
			}
			$url = add_query_arg( 'age', '3650d', $url );
		}

		$body = $this->request_rentec_for_ops( $url, $accounts[ $account ]['key'] );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$rows = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
		if ( 'work_orders' === $resource ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $statuses ) {
						$status = (string) ( $row['status'] ?? '' );
						if ( 'Unassigned' === $status ) {
							$status = 'OpenU';
						} elseif ( 'Assigned' === $status ) {
							$status = 'OpenA';
						}
						return in_array( $status, $statuses, true );
					}
				)
			);

			// Work-order list rows only contain property/vendor IDs. Resolve both resources in
			// two bulk calls so AJOps can show the address and assignee without an N+1 request.
			$property_body = $this->request_rentec_for_ops( add_query_arg( 'include_subunits', 'true', 'https://secure.rentecdirect.com/api/v3/properties' ), $accounts[ $account ]['key'] );
			$vendor_body   = $this->request_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/vendors', $accounts[ $account ]['key'] );
			$properties    = array();
			$vendors       = array();
			if ( ! is_wp_error( $property_body ) ) {
				foreach ( $this->flatten_rentec_properties_for_ops( $property_body['data'] ?? array() ) as $property ) {
					if ( is_array( $property ) && ! empty( $property['property_id'] ) ) $properties[ absint( $property['property_id'] ) ] = $property;
				}
			}
			if ( ! is_wp_error( $vendor_body ) ) {
				foreach ( (array) ( $vendor_body['data'] ?? array() ) as $vendor ) {
					if ( is_array( $vendor ) && ! empty( $vendor['vendor_id'] ) ) $vendors[ absint( $vendor['vendor_id'] ) ] = $vendor;
				}
			}
			foreach ( $rows as &$row ) {
				$property = $properties[ absint( $row['property_id'] ?? 0 ) ] ?? array();
				$vendor   = $vendors[ absint( $row['vendor_id'] ?? 0 ) ] ?? array();
				$city     = implode( ', ', array_filter( array_map( 'strval', array( $property['city'] ?? '', $property['state'] ?? '', $property['zip'] ?? '' ) ) ) );
				$row['_address'] = implode( ', ', array_filter( array_map( 'strval', array( $property['address'] ?? '', $city ) ) ) );
				$assigned = ! empty( $row['assigned'] ) ? $row['assigned'] : ( $vendor['name'] ?? $vendor['company'] ?? $vendor['contact'] ?? '' );
				$row['_assigned_to'] = sanitize_text_field( (string) $assigned );
				$row['_description'] = sanitize_text_field( (string) ( $row['short_desc'] ?? '' ) );
				$row['_details']     = sanitize_textarea_field( (string) ( $row['description'] ?? '' ) );
			}
			unset( $row );
		}
		if ( 'leases' === $resource ) {
			// Lease rows carry only renter_id/property_id. Resolve both in two bulk calls (same
			// approach as work_orders above) so the tab can show who and where without an N+1.
			$tenant_body   = $this->request_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/tenants', $accounts[ $account ]['key'] );
			$property_body = $this->request_rentec_for_ops( add_query_arg( 'include_subunits', 'true', 'https://secure.rentecdirect.com/api/v3/properties' ), $accounts[ $account ]['key'] );
			$tenants       = array();
			$properties    = array();
			if ( ! is_wp_error( $tenant_body ) ) {
				foreach ( (array) ( $tenant_body['data'] ?? array() ) as $tenant ) {
					if ( is_array( $tenant ) && ! empty( $tenant['renter_id'] ) ) {
						$tenants[ absint( $tenant['renter_id'] ) ] = $tenant;
					}
				}
			}
			if ( ! is_wp_error( $property_body ) ) {
				foreach ( $this->flatten_rentec_properties_for_ops( $property_body['data'] ?? array() ) as $property ) {
					if ( is_array( $property ) && ! empty( $property['property_id'] ) ) {
						$properties[ absint( $property['property_id'] ) ] = $property;
					}
				}
			}

			$today = current_time( 'Y-m-d' );
			foreach ( $rows as &$row ) {
				$tenant   = $tenants[ absint( $row['renter_id'] ?? 0 ) ] ?? array();
				$property = $properties[ absint( $row['property_id'] ?? 0 ) ] ?? array();
				$name     = trim( (string) ( $tenant['f_name'] ?? '' ) . ' ' . (string) ( $tenant['l_name'] ?? '' ) );
				if ( '' === $name ) {
					$name = (string) ( $tenant['company'] ?? '' );
				}
				$city = implode( ', ', array_filter( array_map( 'strval', array( $property['city'] ?? '', $property['state'] ?? '', $property['zip'] ?? '' ) ) ) );

				$row['_tenant_name']  = sanitize_text_field( $name );
				$row['_tenant_email'] = sanitize_text_field( (string) ( $tenant['email'] ?? '' ) );
				$row['_tenant_phone'] = sanitize_text_field( (string) ( $tenant['phone'] ?? $tenant['mphone'] ?? '' ) );
				$row['_address']      = implode( ', ', array_filter( array_map( 'strval', array( $property['address'] ?? '', $city ) ) ) );

				// A lease with no end date is open-ended, not expired.
				$lease_end          = substr( (string) ( $row['lease_end'] ?? '' ), 0, 10 );
				$row['_lease_state'] = '' === $lease_end ? 'Open-ended' : ( $lease_end < $today ? 'Expired' : 'Active' );
			}
			unset( $row );
		}

		$total = 'work_orders' === $resource
			? count( $rows )
			: ( isset( $body['summary']['records'] ) ? absint( $body['summary']['records'] ) : count( $rows ) );

		$account_summaries = array();
		foreach ( $accounts as $index => $configured_account ) {
			$account_summaries[] = array(
				'id'         => $index,
				'label'      => $configured_account['label'],
				'configured' => ! empty( $configured_account['key'] ),
			);
		}

		return rest_ensure_response(
			array(
				'account'  => $account,
				'accounts' => $account_summaries,
				'resource' => $resource,
				'total'    => $total,
				'rows'     => array_slice( $rows, 0, 100 ),
				'limit'    => 100,
			)
		);
	}

	public function get_ops_rentec_tenant( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_Error( 'ajcore_rentec_tenant_required', __( 'A valid tenant ID is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$body = $this->request_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/tenants/' . $id, $selected['account']['key'] );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return rest_ensure_response(
			array(
				'account' => $selected['id'],
				'tenant'  => $body['data'] ?? $body,
			)
		);
	}

	/**
	 * Rentec's tenant list only exposes an aggregate `balance`, which goes negative the moment
	 * next month's rent charge posts (often a few days before it's actually due). This checks the
	 * dated per-tenant transaction ledger and only counts charges/payments dated on or before today,
	 * so a tenant billed in advance isn't reported as overdue.
	 *
	 * Rentec hard-caps the API key at 60 requests/minute (verified: it 429s past that, shared with
	 * any other concurrent Rentec traffic on the account). Rather than sleeping/retrying inside a
	 * single request — which risks tripping a reverse-proxy timeout in production — this endpoint
	 * only ever fetches one small batch per call and reports rate-limited ids back as `retryable`,
	 * leaving pacing across batches to the caller (AJOps runs the batches on a client-side timer).
	 */
	public function get_ops_rentec_tenants_overdue( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		$renter_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'renter_ids' ) ) ) ) );
		if ( empty( $renter_ids ) ) {
			return new WP_Error( 'ajcore_rentec_overdue_ids_required', __( 'At least one tenant ID is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		// Keep a single call comfortably under the 60/min ceiling even if it lands mid-window
		// alongside other Rentec traffic from this account.
		$renter_ids = array_slice( $renter_ids, 0, 30 );

		$api_key       = $selected['account']['key'];
		$now           = time();
		$fetch_results = $this->fetch_rentec_tenant_transactions_concurrent( $renter_ids, $api_key );

		$results   = array();
		$retryable = array();
		$errors    = 0;
		foreach ( $renter_ids as $renter_id ) {
			$outcome = $fetch_results[ $renter_id ] ?? array( 'status' => 0, 'entries' => null );
			if ( 429 === $outcome['status'] ) {
				$retryable[] = $renter_id;
				continue;
			}
			if ( null === $outcome['entries'] ) {
				$errors++;
				continue;
			}
			$true_balance = 0.0;
			foreach ( $outcome['entries'] as $entry ) {
				$ts = isset( $entry['transaction_time'] ) ? strtotime( (string) $entry['transaction_time'] ) : false;
				if ( false === $ts || $ts > $now ) {
					continue;
				}
				$true_balance += (float) ( $entry['amount'] ?? 0 );
			}
			$results[] = array(
				'renter_id'    => $renter_id,
				'true_balance' => round( $true_balance, 2 ),
				'overdue'      => $true_balance < -0.01,
			);
		}

		return rest_ensure_response(
			array(
				'account'   => $selected['id'],
				'as_of'     => gmdate( 'c', $now ),
				'results'   => $results,
				'retryable' => $retryable,
				'errors'    => $errors,
			)
		);
	}

	/**
	 * Fetches page 1 of each tenant's transaction ledger concurrently via curl_multi (a sequential
	 * loop would take ~0.5s per tenant). Tenants with more than one page of history (300+
	 * transactions, ~12+ years of monthly activity) only get their first page counted — acceptable
	 * for the near-term "is this actually overdue" question. Returns each renter_id's HTTP status
	 * alongside its entries so the caller can tell "rate limited, retry" apart from "really failed".
	 */
	private function fetch_rentec_tenant_transactions_concurrent( array $renter_ids, $api_key ) {
		$multi   = curl_multi_init();
		$handles = array();
		foreach ( $renter_ids as $renter_id ) {
			$url = 'https://secure.rentecdirect.com/api/v3/transactions?' . http_build_query( array( 'page' => 1, 'renter_id' => $renter_id ) );
			$ch  = curl_init( $url );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER     => array( 'Accept: application/json', 'X-API-Key: ' . $api_key ),
					CURLOPT_TIMEOUT        => 15,
				)
			);
			curl_multi_add_handle( $multi, $ch );
			$handles[ $renter_id ] = $ch;
		}

		$running = null;
		do {
			curl_multi_exec( $multi, $running );
			curl_multi_select( $multi );
		} while ( $running > 0 );

		$results = array();
		foreach ( $handles as $renter_id => $ch ) {
			$body    = curl_multi_getcontent( $ch );
			$code    = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$decoded = json_decode( $body, true );
			$results[ $renter_id ] = array(
				'status'  => $code,
				'entries' => ( 200 === $code && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) ? $decoded['data'] : null,
			);
			curl_multi_remove_handle( $multi, $ch );
			curl_close( $ch );
		}
		curl_multi_close( $multi );
		return $results;
	}

	public function get_ops_rentec_work_order( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$id   = absint( $request->get_param( 'id' ) );
		$body = $this->request_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/work_orders/' . $id, $selected['account']['key'] );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$work_order = $body['data'] ?? $body;
		if ( ! isset( $work_order['notes'] ) && isset( $body['notes'] ) && is_array( $body['notes'] ) ) {
			$work_order['notes'] = $body['notes'];
		}
		$property   = array();
		$renter     = array();
		$contact    = array();
		$vendor     = array();
		$lookups    = array(
			'property'       => array( 'resource' => 'properties', 'id' => absint( $work_order['property_id'] ?? 0 ) ),
			'renter'         => array( 'resource' => 'tenants', 'id' => absint( $work_order['renter_id'] ?? 0 ) ),
			'contact_renter' => array( 'resource' => 'tenants', 'id' => absint( $work_order['contact_renter_id'] ?? 0 ) ),
			'vendor'         => array( 'resource' => 'vendors', 'id' => absint( $work_order['vendor_id'] ?? 0 ) ),
		);
		foreach ( $lookups as $lookup_name => $lookup ) {
			if ( ! $lookup['id'] ) {
				continue;
			}
			$lookup_body = $this->request_rentec_for_ops(
				'https://secure.rentecdirect.com/api/v3/' . $lookup['resource'] . '/' . $lookup['id'],
				$selected['account']['key']
			);
			if ( is_wp_error( $lookup_body ) ) {
				continue;
			}
			$lookup_value = isset( $lookup_body['data'] ) && is_array( $lookup_body['data'] ) ? $lookup_body['data'] : $lookup_body;
			if ( 'property' === $lookup_name ) {
				$property = $lookup_value;
			} elseif ( 'renter' === $lookup_name ) {
				$renter = $lookup_value;
			} elseif ( 'contact_renter' === $lookup_name ) {
				$contact = $lookup_value;
			} else {
				$vendor = $lookup_value;
			}
		}
		if ( $property && empty( $property['address'] ) && ! empty( $property['sub_of'] ) ) {
			$parent_body = $this->request_rentec_for_ops(
				'https://secure.rentecdirect.com/api/v3/properties/' . absint( $property['sub_of'] ),
				$selected['account']['key']
			);
			if ( ! is_wp_error( $parent_body ) ) {
				$parent = isset( $parent_body['data'] ) && is_array( $parent_body['data'] ) ? $parent_body['data'] : $parent_body;
				foreach ( array( 'address', 'city', 'state', 'zip' ) as $address_field ) {
					if ( empty( $property[ $address_field ] ) && ! empty( $parent[ $address_field ] ) ) $property[ $address_field ] = $parent[ $address_field ];
				}
				$property['_parent_property_id'] = absint( $property['sub_of'] );
				$property['_parent_nickname'] = sanitize_text_field( (string) ( $parent['nickname'] ?? '' ) );
			}
		}

		$files       = array();
		$files_error = '';
		$files_body  = $this->request_rentec_for_ops(
			add_query_arg( 'workorder_id', $id, 'https://secure.rentecdirect.com/api/v3/files' ),
			$selected['account']['key']
		);
		if ( is_wp_error( $files_body ) ) {
			$files_error = $files_body->get_error_message();
		} else {
			$file_rows = isset( $files_body['data'] ) && is_array( $files_body['data'] )
				? $files_body['data']
				: ( isset( $files_body['files'] ) && is_array( $files_body['files'] ) ? $files_body['files'] : array() );
			$files     = array_values(
				array_filter(
					$file_rows,
					static function ( $file ) use ( $id ) {
						if ( ! is_array( $file ) ) {
							return false;
						}
						// The request itself is scoped by workorder_id. Retain rows from API
						// versions that omit the association field in their list response.
						return ! array_key_exists( 'workorder_id', $file ) || $id === absint( $file['workorder_id'] );
					}
				)
			);
		}

		return rest_ensure_response(
			array(
				'account'        => $selected['id'],
				'work_order'     => $work_order,
				'property'       => $property,
				'renter'         => $renter,
				'contact_renter' => $contact,
				'vendor'         => $vendor,
				'files'          => $files,
				'files_error'    => $files_error,
			)
		);
	}

	public function get_ops_rentec_work_order_options( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$result = array(
			'account'    => $selected['id'],
			'properties' => array(),
			'tenants'    => array(),
			'vendors'    => array(),
		);
		foreach ( array( 'properties', 'tenants', 'vendors' ) as $resource ) {
			$url = 'https://secure.rentecdirect.com/api/v3/' . $resource;
			if ( 'properties' === $resource ) $url = add_query_arg( 'include_subunits', 'true', $url );
			$body = $this->request_rentec_for_ops(
				$url,
				$selected['account']['key']
			);
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$resource_rows = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
			$result[ $resource ] = 'properties' === $resource ? $this->flatten_rentec_properties_for_ops( $resource_rows ) : $resource_rows;
		}
		return rest_ensure_response( $result );
	}

	public function create_ops_rentec_work_order( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$property_id = absint( $request->get_param( 'property_id' ) );
		$short_desc  = sanitize_text_field( (string) $request->get_param( 'short_desc' ) );
		if ( ! $property_id || '' === $short_desc ) {
			return new WP_Error( 'ajcore_rentec_work_order_required', __( 'Property and short description are required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$payload = array(
			'property_id' => $property_id,
			'short_desc'  => function_exists( 'mb_substr' ) ? mb_substr( $short_desc, 0, 100 ) : substr( $short_desc, 0, 100 ),
			'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			'priority'    => min( 5, max( 1, absint( $request->get_param( 'priority' ) ) ?: 1 ) ),
		);
		foreach ( array( 'renter_id', 'contact_renter_id', 'vendor_id' ) as $optional_id ) {
			$value = absint( $request->get_param( $optional_id ) );
			if ( $value ) {
				$payload[ $optional_id ] = $value;
			}
		}
		$body = $this->request_rentec_for_ops(
			'https://secure.rentecdirect.com/api/v3/work_orders',
			$selected['account']['key'],
			'POST',
			$payload
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return rest_ensure_response(
			array(
				'success'    => true,
				'account'    => $selected['id'],
				'work_order' => $body['data'] ?? $body,
			)
		);
	}

	public function create_ops_rentec_lead( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		$payload = array(
			'f_name'           => sanitize_text_field( (string) $request->get_param( 'f_name' ) ),
			'l_name'           => sanitize_text_field( (string) $request->get_param( 'l_name' ) ),
			'email'            => sanitize_email( (string) $request->get_param( 'email' ) ),
			'phone'            => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
			'mphone'           => sanitize_text_field( (string) $request->get_param( 'mphone' ) ),
			'monthly_rent_max' => (float) $request->get_param( 'monthly_rent_max' ),
			'search_city'      => sanitize_text_field( (string) $request->get_param( 'search_city' ) ),
			'search_state'     => strtoupper( sanitize_text_field( (string) $request->get_param( 'search_state' ) ) ),
		);
		if ( '' === $payload['f_name'] || '' === $payload['l_name'] || $payload['monthly_rent_max'] <= 0 || '' === $payload['search_city'] || '' === $payload['search_state'] || ( '' === $payload['phone'] && '' === $payload['mphone'] ) ) {
			return new WP_Error( 'ajcore_rentec_lead_required', __( 'First name, last name, phone or mobile, maximum rent, city, and state are required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'monthly_income', 'monthly_rent_min' ) as $money_field ) {
			$value = (float) $request->get_param( $money_field );
			if ( $value > 0 ) {
				$payload[ $money_field ] = $value;
			}
		}
		foreach ( array( 'occupants', 'bedrooms_min', 'bathrooms_min' ) as $number_field ) {
			$value = absint( $request->get_param( $number_field ) );
			if ( $value ) {
				$payload[ $number_field ] = $value;
			}
		}
		$move_in = sanitize_text_field( (string) $request->get_param( 'move_in' ) );
		if ( '' !== $move_in ) {
			$payload['move_in'] = $move_in;
		}
		$notes = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		if ( '' !== $notes ) {
			$payload['notes'] = $notes;
		}

		$body = $this->request_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/leads', $selected['account']['key'], 'POST', $payload );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return rest_ensure_response( array( 'success' => true, 'account' => $selected['id'], 'lead' => $body['data'] ?? $body ) );
	}

	public function create_ops_rentec_vendor( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		$payload = array();
		foreach ( array( 'name', 'industry', 'contact', 'address', 'address2', 'city', 'state', 'zip', 'phone', 'mphone', 'rate' ) as $field ) {
			$value = sanitize_text_field( (string) $request->get_param( $field ) );
			if ( '' !== $value ) {
				$payload[ $field ] = $value;
			}
		}
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( '' !== $email ) {
			$payload['email'] = $email;
		}
		$notes = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		if ( '' !== $notes ) {
			$payload['notes'] = $notes;
		}
		if ( empty( $payload['name'] ) ) {
			return new WP_Error( 'ajcore_rentec_vendor_required', __( 'Vendor name is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}

		$body = $this->request_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/vendors', $selected['account']['key'], 'POST', $payload );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return rest_ensure_response( array( 'success' => true, 'account' => $selected['id'], 'vendor' => $body['data'] ?? $body ) );
	}

	public function upload_ops_rentec_work_order_attachment( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'ajcore_rentec_file_required', __( 'Choose an image to upload.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'ajcore_rentec_file_upload_failed', __( 'The image upload did not complete.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > 10 * MB_IN_BYTES ) {
			return new WP_Error( 'ajcore_rentec_file_too_large', __( 'Images must be 10 MB or smaller.', 'ajcore-ra' ), array( 'status' => 413 ) );
		}
		$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		$file_type     = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$mime_type     = (string) ( $file_type['type'] ?? $file['type'] ?? '' );
		if ( ! in_array( $mime_type, $allowed_types, true ) ) {
			return new WP_Error( 'ajcore_rentec_file_type', __( 'Upload a JPG, PNG, GIF, or WebP image.', 'ajcore-ra' ), array( 'status' => 415 ) );
		}
		$file_contents = file_get_contents( $file['tmp_name'] );
		if ( false === $file_contents ) {
			return new WP_Error( 'ajcore_rentec_file_read_failed', __( 'The uploaded image could not be read.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}

		$boundary = 'ajcore-' . wp_generate_password( 24, false, false );
		$eol      = "\r\n";
		$body     = '--' . $boundary . $eol;
		$body    .= 'Content-Disposition: form-data; name="workorder_id"' . $eol . $eol;
		$body    .= absint( $request->get_param( 'id' ) ) . $eol;
		$body    .= '--' . $boundary . $eol;
		$body    .= 'Content-Disposition: form-data; name="file"; filename="' . sanitize_file_name( $file['name'] ) . '"' . $eol;
		$body    .= 'Content-Type: ' . $mime_type . $eol . $eol;
		$body    .= $file_contents . $eol;
		$body    .= '--' . $boundary . '--' . $eol;

		$response = wp_remote_post(
			'https://secure.rentecdirect.com/api/v3/files',
			array(
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
					'X-API-Key'    => $selected['account']['key'],
				),
				'body'    => $body,
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ajcore_rentec_upload_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status   = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $response_body['message'] ) ? sanitize_text_field( (string) $response_body['message'] ) : sprintf( __( 'Rentec returned HTTP status %d.', 'ajcore-ra' ), $status );
			return new WP_Error( 'ajcore_rentec_upload_failed', $message, array( 'status' => 502 ) );
		}
		return rest_ensure_response( is_array( $response_body ) ? $response_body : array( 'success' => true ) );
	}

	public function add_ops_rentec_work_order_note( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$note = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		if ( '' === $note ) {
			return new WP_Error( 'ajcore_rentec_note_required', __( 'Enter a note.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$id   = absint( $request->get_param( 'id' ) );
		$body = $this->request_rentec_for_ops(
			'https://secure.rentecdirect.com/api/v3/work_orders/' . $id . '/notes',
			$selected['account']['key'],
			'POST',
			array( 'note' => $note, 'private' => rest_sanitize_boolean( $request->get_param( 'private' ) ) )
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $body['data'] ?? $body ) );
	}

	// ── Connection test (AJAX) ─────────────────────────────────────────────────

	/**
	 * Test Rentec Direct API v3 access and return aggregate availability only.
	 *
	 * Response bodies are never returned to the browser or persisted. Each resource
	 * response is reduced to the count supplied by Rentec's summary envelope.
	 */
	public function ajax_test_rentec_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ajcore-ra' ), 403 );
		}

		check_ajax_referer( 'ajcore_test_rentec_connection', 'nonce' );

		$settings      = $this->get_settings();
		$account_index = isset( $_POST['account'] ) && '2' === sanitize_text_field( wp_unslash( $_POST['account'] ) ) ? '2' : '1';
		$setting_key   = '2' === $account_index ? 'rentec_api_key_2' : 'rentec_api_key';
		$api_key       = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		if ( '' === $api_key ) {
			$api_key = isset( $settings[ $setting_key ] ) ? trim( (string) $settings[ $setting_key ] ) : '';
		}

		if ( '' === $api_key ) {
			wp_send_json_error( __( 'Enter a Rentec Direct API key or save one first.', 'ajcore-ra' ), 400 );
		}

		$base_url = 'https://secure.rentecdirect.com/api/v3/';
		$headers  = array(
			'Accept'    => 'application/json',
			'X-API-Key' => $api_key,
		);
		$ping     = wp_remote_get(
			$base_url . 'ping',
			array(
				'headers'     => $headers,
				'timeout'     => 12,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $ping ) ) {
			wp_send_json_error( $ping->get_error_message(), 502 );
		}

		$ping_status = (int) wp_remote_retrieve_response_code( $ping );
		if ( 200 !== $ping_status ) {
			$message = 401 === $ping_status
				? __( 'Rentec rejected the API key.', 'ajcore-ra' )
				: sprintf( __( 'Rentec connection failed with HTTP status %d.', 'ajcore-ra' ), $ping_status );
			wp_send_json_error( $message, $ping_status >= 400 && $ping_status < 600 ? $ping_status : 502 );
		}

		$resources = array(
			'properties'   => __( 'Properties', 'ajcore-ra' ),
			'tenants'      => __( 'Tenants', 'ajcore-ra' ),
			'leases'       => __( 'Leases', 'ajcore-ra' ),
			'owners'       => __( 'Owners', 'ajcore-ra' ),
			'vendors'      => __( 'Vendors', 'ajcore-ra' ),
			'work_orders'  => __( 'Work Orders', 'ajcore-ra' ),
			'transactions' => __( 'Transactions', 'ajcore-ra' ),
			'leads'        => __( 'Leads', 'ajcore-ra' ),
			'messages'     => __( 'Messages', 'ajcore-ra' ),
			'files'        => __( 'Files', 'ajcore-ra' ),
		);
		$metrics   = array();

		foreach ( $resources as $endpoint => $label ) {
			$url = $base_url . $endpoint;
			if ( 'work_orders' === $endpoint ) {
				$url = add_query_arg( 'age', '3650d', $url );
			} elseif ( 'transactions' === $endpoint ) {
				$url = add_query_arg( 'page', 1, $url );
			}

			$response = wp_remote_get(
				$url,
				array(
					'headers'     => $headers,
					'timeout'     => 12,
					'redirection' => 0,
				)
			);

			if ( is_wp_error( $response ) ) {
				$metrics[] = array( 'label' => $label, 'available' => false, 'count' => null );
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = json_decode( wp_remote_retrieve_body( $response ), true );
			$count  = is_array( $body ) && isset( $body['summary']['records'] )
				? absint( $body['summary']['records'] )
				: null;

			$metrics[] = array(
				'label'     => $label,
				'available' => 200 === $status,
				'count'     => 200 === $status ? $count : null,
			);
		}

		wp_send_json_success(
			array(
				'connected' => true,
				'metrics'   => $metrics,
				'note'      => __( 'Counts reflect records returned by each Rentec endpoint and may be limited by that endpoint. No record details were stored or displayed.', 'ajcore-ra' ),
			)
		);
	}

	// ── Connection settings UI ─────────────────────────────────────────────────

	/**
	 * Rentec Direct configuration for the Client Portal/AJOps integration.
	 */
	public function render_settings() {
		$settings           = $this->get_settings();
		$rentec_key_1_saved = ! empty( $settings['rentec_api_key'] );
		$rentec_key_2_saved = ! empty( $settings['rentec_api_key_2'] );
		$is_shared_db       = function_exists( 'ajcore_is_shared_db_enabled' ) && ajcore_is_shared_db_enabled();
		$is_master          = ! function_exists( 'ajcore_is_stripe_sync_owner' ) || ajcore_is_stripe_sync_owner();
		$read_only          = $is_shared_db && ! $is_master;
		$form_url            = admin_url( 'admin-post.php' );
		?>
		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rentec Direct settings saved.', 'ajcore-ra' ); ?></p></div>
		<?php endif; ?>
		<div class="ajforms-settings-card" id="ajcore-rentec-settings">
			<div class="ajcore-section-head">
				<div>
					<h2><?php esc_html_e( 'Rentec Direct API v3', 'ajcore-ra' ); ?></h2>
					<p><?php esc_html_e( 'Connect the Client Portal and AJOps to Rentec Direct. This first phase only checks which operational resources are readable and shows aggregate metrics.', 'ajcore-ra' ); ?></p>
				</div>
			</div>
			<?php if ( $read_only ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Rentec integration and API accounts are managed by the AJCore master site. This site automatically uses the shared master configuration.', 'ajcore-ra' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $form_url ); ?>">
				<?php wp_nonce_field( 'ajcore_ra_save_rentec' ); ?>
				<input type="hidden" name="action" value="ajcore_ra_save_rentec">
				<p>
					<label>
						<input name="rentec_enabled" type="checkbox" value="1" <?php checked( '1' === (string) ( $settings['rentec_enabled'] ?? '0' ) ); ?> <?php disabled( $read_only ); ?>>
						<strong><?php esc_html_e( 'Enable Rentec Direct integration', 'ajcore-ra' ); ?></strong>
					</label>
				</p>
				<p class="description"><?php esc_html_e( 'No automatic synchronization is enabled yet. This saves the connection for future Client Portal and AJOps features.', 'ajcore-ra' ); ?></p>
				<div class="ajcore-kpi-grid" style="align-items:start;">
					<?php
					$rentec_accounts = array(
						'1' => array(
							'label'     => $settings['rentec_account_label_1'] ?? 'Rentec Account 1',
							'key_field' => 'rentec_api_key',
							'saved'     => $rentec_key_1_saved,
						),
						'2' => array(
							'label'     => $settings['rentec_account_label_2'] ?? 'Rentec Account 2',
							'key_field' => 'rentec_api_key_2',
							'saved'     => $rentec_key_2_saved,
						),
					);
					foreach ( $rentec_accounts as $account_index => $account ) :
						$label_field = 'rentec_account_label_' . $account_index;
						$key_id      = 'rentec_api_key_' . $account_index;
						?>
						<div class="ajforms-settings-card" style="margin:0;">
							<h3><?php echo esc_html( sprintf( __( 'Account %s', 'ajcore-ra' ), $account_index ) ); ?></h3>
							<p>
								<label for="<?php echo esc_attr( $label_field ); ?>"><strong><?php esc_html_e( 'Account Name', 'ajcore-ra' ); ?></strong></label><br>
								<input name="<?php echo esc_attr( $label_field ); ?>" id="<?php echo esc_attr( $label_field ); ?>" type="text" class="regular-text" value="<?php echo esc_attr( $account['label'] ); ?>" <?php disabled( $read_only ); ?>>
							</p>
							<p>
								<label for="<?php echo esc_attr( $key_id ); ?>"><strong><?php esc_html_e( 'API v3 Key', 'ajcore-ra' ); ?></strong></label><br>
								<input name="<?php echo esc_attr( $account['key_field'] ); ?>" id="<?php echo esc_attr( $key_id ); ?>" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $account['saved'] ? __( 'Saved — enter a new key to replace', 'ajcore-ra' ) : __( 'Paste the API key from Rentec Direct', 'ajcore-ra' ) ); ?>" <?php disabled( $read_only ); ?>>
								<?php if ( $account['saved'] ) : ?>
									<span class="description" style="display:block;"><?php echo esc_html( sprintf( __( 'Current: %s — leave blank to keep.', 'ajcore-ra' ), ajcore_mask_secret_for_display( $settings[ $account['key_field'] ] ) ) ); ?></span>
								<?php endif; ?>
							</p>
							<div class="ajforms-settings-inline-actions">
								<button type="button" class="button ajcore-test-rentec" data-account="<?php echo esc_attr( $account_index ); ?>" <?php disabled( $read_only ); ?>><?php esc_html_e( 'Test This Account', 'ajcore-ra' ); ?></button>
								<span id="ajcore-rentec-test-status-<?php echo esc_attr( $account_index ); ?>"></span>
							</div>
							<div id="ajcore-rentec-metrics-<?php echo esc_attr( $account_index ); ?>" style="display:none;margin-top:18px;"></div>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Create API V3 keys under Settings → Utilities → API Keys in each Rentec account. Start with read-only permissions.', 'ajcore-ra' ); ?></p>
				<?php if ( ! $read_only ) : ?>
					<div class="ajforms-settings-inline-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Rentec Settings', 'ajcore-ra' ); ?></button>
					</div>
				<?php endif; ?>
			</form>
		</div>
		<script>
		(function() {
			const testNonce = '<?php echo esc_js( wp_create_nonce( 'ajcore_test_rentec_connection' ) ); ?>';
			document.querySelectorAll('.ajcore-test-rentec').forEach(function(testButton) {
				testButton.addEventListener('click', function() {
					const account = testButton.dataset.account || '1';
					const keyInput = document.getElementById('rentec_api_key_' + account);
					const statusNode = document.getElementById('ajcore-rentec-test-status-' + account);
					const metricsNode = document.getElementById('ajcore-rentec-metrics-' + account);
					if (!keyInput || !statusNode || !metricsNode) return;

					function setStatus(message, isError) {
						statusNode.textContent = message;
						statusNode.style.color = isError ? '#b32d2e' : '#166534';
					}

					testButton.disabled = true;
					metricsNode.style.display = 'none';
					setStatus('<?php echo esc_js( __( 'Testing Rentec connection and read access...', 'ajcore-ra' ) ); ?>', false);
				const formData = new FormData();
				formData.append('action', 'ajcore_test_rentec_connection');
				formData.append('nonce', testNonce);
				formData.append('api_key', keyInput.value.trim());
					formData.append('account', account);

				fetch(ajaxurl, { method: 'POST', body: formData })
					.then(function(response) { return response.json(); })
					.then(function(response) {
						if (!response.success) {
							setStatus(typeof response.data === 'string' ? response.data : '<?php echo esc_js( __( 'Unable to test Rentec Direct.', 'ajcore-ra' ) ); ?>', true);
							return;
						}
						setStatus('<?php echo esc_js( __( 'Connected to Rentec Direct.', 'ajcore-ra' ) ); ?>', false);
						metricsNode.innerHTML = '';
						const grid = document.createElement('div');
						grid.className = 'ajcore-kpi-grid';
						(response.data.metrics || []).forEach(function(metric) {
							const card = document.createElement('div');
							card.className = 'ajcore-kpi-card';
							const label = document.createElement('span');
							label.textContent = metric.label || '';
							const value = document.createElement('strong');
							value.style.color = metric.available ? '#166534' : '#b32d2e';
							value.textContent = metric.available
								? (metric.count === null ? '<?php echo esc_js( __( 'Available', 'ajcore-ra' ) ); ?>' : String(metric.count))
								: '<?php echo esc_js( __( 'Unavailable', 'ajcore-ra' ) ); ?>';
							card.appendChild(label);
							card.appendChild(value);
							grid.appendChild(card);
						});
						const note = document.createElement('p');
						note.className = 'description';
						note.textContent = response.data.note || '';
						metricsNode.appendChild(grid);
						metricsNode.appendChild(note);
						metricsNode.style.display = 'block';
					})
					.catch(function() {
						setStatus('<?php echo esc_js( __( 'Unable to test Rentec Direct.', 'ajcore-ra' ) ); ?>', true);
					})
					.finally(function() { testButton.disabled = false; });
				});
			});
		})();
		</script>
		<?php
	}

	// ── Workspace tab ──────────────────────────────────────────────────────────

	/**
	 * Rentec workspace inside the Client Portal admin.
	 */
	public function render_workspace() {
		$settings  = $this->get_settings();
		$resources = array(
			'work_orders'  => array(
				'label'   => __( 'Work Orders', 'ajcore-ra' ),
				'columns' => array(
					'wo_number'     => __( 'Number', 'ajcore-ra' ),
					'status'        => __( 'Status', 'ajcore-ra' ),
					'priority'      => __( 'Priority', 'ajcore-ra' ),
					'short_desc'    => __( 'Description', 'ajcore-ra' ),
					'property_id'   => __( 'Property', 'ajcore-ra' ),
					'date_received' => __( 'Received', 'ajcore-ra' ),
				),
			),
			'leads'        => array(
				'label'   => __( 'Leads', 'ajcore-ra' ),
				'columns' => array(
					'prospect_id' => __( 'ID', 'ajcore-ra' ),
					'f_name'     => __( 'First Name', 'ajcore-ra' ),
					'l_name'     => __( 'Last Name', 'ajcore-ra' ),
					'email'      => __( 'Email', 'ajcore-ra' ),
					'phone'      => __( 'Phone', 'ajcore-ra' ),
					'move_in'    => __( 'Move In', 'ajcore-ra' ),
					'search_city' => __( 'Search City', 'ajcore-ra' ),
				),
			),
			'vendors'      => array(
				'label'   => __( 'Vendors', 'ajcore-ra' ),
				'columns' => array(
					'vendor_id' => __( 'ID', 'ajcore-ra' ),
					'name'      => __( 'Name', 'ajcore-ra' ),
					'industry'  => __( 'Industry', 'ajcore-ra' ),
					'contact'   => __( 'Contact', 'ajcore-ra' ),
					'email'     => __( 'Email', 'ajcore-ra' ),
					'phone'     => __( 'Phone', 'ajcore-ra' ),
				),
			),
			'transactions' => array(
				'label'   => __( 'Transactions', 'ajcore-ra' ),
				'columns' => array(
					'transaction_id'   => __( 'ID', 'ajcore-ra' ),
					'transaction_time' => __( 'Date', 'ajcore-ra' ),
					'category_name'    => __( 'Category', 'ajcore-ra' ),
					'description'      => __( 'Description', 'ajcore-ra' ),
					'amount'           => __( 'Amount', 'ajcore-ra' ),
					'property_id'      => __( 'Property', 'ajcore-ra' ),
				),
			),
			'files'        => array(
				'label'   => __( 'Files', 'ajcore-ra' ),
				'columns' => array(
					'file_id'      => __( 'ID', 'ajcore-ra' ),
					'filename'     => __( 'Filename', 'ajcore-ra' ),
					'upload_date'  => __( 'Uploaded', 'ajcore-ra' ),
					'bytes'        => __( 'Size', 'ajcore-ra' ),
					'property_id'  => __( 'Property', 'ajcore-ra' ),
					'workorder_id' => __( 'Work Order', 'ajcore-ra' ),
				),
			),
			'messages'     => array(
				'label'   => __( 'Messages', 'ajcore-ra' ),
				'columns' => array(
					'email_id'   => __( 'ID', 'ajcore-ra' ),
					'email_time' => __( 'Date', 'ajcore-ra' ),
					'direction'  => __( 'Direction', 'ajcore-ra' ),
					'subject'    => __( 'Subject', 'ajcore-ra' ),
					'status'     => __( 'Status', 'ajcore-ra' ),
					'renter_id'  => __( 'Tenant', 'ajcore-ra' ),
				),
			),
		);

		$resource = isset( $_GET['rentec_resource'] ) ? sanitize_key( wp_unslash( $_GET['rentec_resource'] ) ) : 'work_orders';
		if ( ! isset( $resources[ $resource ] ) ) {
			$resource = 'work_orders';
		}
		$accounts = array(
			'1' => array(
				'label' => $settings['rentec_account_label_1'] ?? 'Rentec Account 1',
				'key'   => $settings['rentec_api_key'] ?? '',
			),
			'2' => array(
				'label' => $settings['rentec_account_label_2'] ?? 'Rentec Account 2',
				'key'   => $settings['rentec_api_key_2'] ?? '',
			),
		);
		$filters_set        = isset( $_GET['rentec_filters_set'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['rentec_filters_set'] ) );
		$requested_accounts = isset( $_GET['rentec_accounts'] )
			? (array) wp_unslash( $_GET['rentec_accounts'] )
			: ( $filters_set ? array() : array_map( 'strval', array_keys( $accounts ) ) );
		$selected_accounts  = array_values(
			array_filter(
				array_map( 'sanitize_text_field', $requested_accounts ),
				function ( $index ) use ( $accounts ) {
					return isset( $accounts[ $index ] ) && ! empty( $accounts[ $index ]['key'] );
				}
			)
		);
		if ( empty( $selected_accounts ) && ! $filters_set ) {
			$selected_accounts = array_map(
				'strval',
				array_keys(
				array_filter(
					$accounts,
					function ( $configured_account ) {
						return ! empty( $configured_account['key'] );
					}
				)
				)
			);
		}
		$work_order_statuses = array(
			'OpenU'     => __( 'Unassigned', 'ajcore-ra' ),
			'OpenA'     => __( 'Assigned', 'ajcore-ra' ),
			'Parts'     => __( 'Parts on Order', 'ajcore-ra' ),
			'Work'      => __( 'Work in Progress', 'ajcore-ra' ),
			'Finalized' => __( 'Finalized', 'ajcore-ra' ),
			'Closed'    => __( 'Closed', 'ajcore-ra' ),
		);
		$selected_statuses = isset( $_GET['rentec_statuses'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['rentec_statuses'] ) )
			: ( $filters_set ? array() : array( 'OpenU', 'OpenA', 'Parts', 'Work', 'Finalized' ) );
		$note_result = '';
		if ( isset( $_POST['ajcore_rentec_note_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ajcore_rentec_note_nonce'] ) ), 'ajcore_add_rentec_work_order_note' ) ) {
			$note_account = isset( $_POST['rentec_note_account'] ) && '2' === sanitize_text_field( wp_unslash( $_POST['rentec_note_account'] ) ) ? '2' : '1';
			$note_id      = isset( $_POST['rentec_workorder_id'] ) ? absint( $_POST['rentec_workorder_id'] ) : 0;
			$note_text    = isset( $_POST['rentec_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rentec_note'] ) ) : '';
			if ( $note_id && '' !== trim( $note_text ) && ! empty( $accounts[ $note_account ]['key'] ) ) {
				$note_response = wp_remote_post(
					'https://secure.rentecdirect.com/api/v3/work_orders/' . $note_id . '/notes',
					array(
						'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-API-Key' => $accounts[ $note_account ]['key'] ),
						'body'    => wp_json_encode( array( 'note' => $note_text, 'private' => ! empty( $_POST['rentec_note_private'] ) ) ),
						'timeout' => 15,
					)
				);
				$note_status = is_wp_error( $note_response ) ? 0 : (int) wp_remote_retrieve_response_code( $note_response );
				$note_result = in_array( $note_status, array( 200, 201 ), true )
					? __( 'Work-order note added.', 'ajcore-ra' )
					: ( is_wp_error( $note_response ) ? $note_response->get_error_message() : sprintf( __( 'Rentec returned HTTP status %d while adding the note.', 'ajcore-ra' ), $note_status ) );
			}
		}
		$base_url = add_query_arg(
			array(
				'page' => 'ajforms-client-portal',
				'tab'  => 'rentec',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="ajforms-settings-card">
			<div class="ajcore-section-head">
				<div>
					<h2><?php esc_html_e( 'Rentec Direct', 'ajcore-ra' ); ?></h2>
				</div>
				<div class="ajforms-settings-inline-actions">
					<?php if ( '1' === (string) ( $settings['rentec_enabled'] ?? '0' ) ) : ?>
						<?php if ( 'work_orders' === $resource ) : ?><button type="button" id="ajcore-new-rentec-work-order" class="button button-primary"><?php esc_html_e( 'New Work Order', 'ajcore-ra' ); ?></button><?php endif; ?>
						<?php if ( 'leads' === $resource ) : ?><button type="button" id="ajcore-new-rentec-lead" class="button button-primary"><?php esc_html_e( 'New Lead', 'ajcore-ra' ); ?></button><?php endif; ?>
						<?php if ( 'vendors' === $resource ) : ?><button type="button" id="ajcore-new-rentec-vendor" class="button button-primary"><?php esc_html_e( 'New Vendor', 'ajcore-ra' ); ?></button><?php endif; ?>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-settings', 'section' => 'rentec' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Rentec Settings', 'ajcore-ra' ); ?></a>
				</div>
			</div>

			<?php if ( '1' === (string) ( $settings['rentec_enabled'] ?? '0' ) ) : ?>
				<dialog id="ajcore-rentec-work-order-dialog" style="width:min(680px,calc(100vw - 32px));border:0;border-radius:14px;padding:0;box-shadow:0 24px 70px rgba(15,23,42,.3);">
					<form id="ajcore-rentec-work-order-create-form" style="padding:24px;">
						<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;">
							<div><h2 style="margin:0;"><?php esc_html_e( 'New Work Order', 'ajcore-ra' ); ?></h2><p class="description"><?php esc_html_e( 'Create this work order directly in Rentec Direct.', 'ajcore-ra' ); ?></p></div>
							<button type="button" class="button" id="ajcore-close-rentec-work-order"><?php esc_html_e( 'Close', 'ajcore-ra' ); ?></button>
						</div>
						<div id="ajcore-rentec-work-order-create-error" class="notice notice-error inline" style="display:none;margin:14px 0;"></div>
						<table class="form-table" role="presentation">
							<tr><th><label for="ajcore-create-rentec-account"><?php esc_html_e( 'Rentec Account', 'ajcore-ra' ); ?></label></th><td>
								<select id="ajcore-create-rentec-account" class="regular-text">
									<?php foreach ( $accounts as $account_id => $account_config ) : ?>
										<?php if ( ! empty( $account_config['key'] ) ) : ?><option value="<?php echo esc_attr( $account_id ); ?>"><?php echo esc_html( $account_config['label'] ); ?></option><?php endif; ?>
									<?php endforeach; ?>
								</select>
							</td></tr>
							<tr><th><label for="ajcore-create-rentec-property"><?php esc_html_e( 'Property', 'ajcore-ra' ); ?></label></th><td><select id="ajcore-create-rentec-property" class="regular-text" required><option value=""><?php esc_html_e( 'Select a property', 'ajcore-ra' ); ?></option></select></td></tr>
							<tr><th><label for="ajcore-create-rentec-renter"><?php esc_html_e( 'Renter', 'ajcore-ra' ); ?></label></th><td><select id="ajcore-create-rentec-renter" class="regular-text"><option value=""><?php esc_html_e( 'No renter', 'ajcore-ra' ); ?></option></select></td></tr>
							<tr><th><label for="ajcore-create-rentec-contact"><?php esc_html_e( 'Contact Renter', 'ajcore-ra' ); ?></label></th><td><select id="ajcore-create-rentec-contact" class="regular-text"><option value=""><?php esc_html_e( 'Same/none', 'ajcore-ra' ); ?></option></select></td></tr>
							<tr><th><label for="ajcore-create-rentec-vendor"><?php esc_html_e( 'Vendor', 'ajcore-ra' ); ?></label></th><td><select id="ajcore-create-rentec-vendor" class="regular-text"><option value=""><?php esc_html_e( 'Unassigned', 'ajcore-ra' ); ?></option></select></td></tr>
							<tr><th><label for="ajcore-create-rentec-priority"><?php esc_html_e( 'Priority', 'ajcore-ra' ); ?></label></th><td><select id="ajcore-create-rentec-priority"><?php foreach ( range( 1, 5 ) as $priority_value ) : ?><option value="<?php echo esc_attr( $priority_value ); ?>"><?php echo esc_html( $priority_value ); ?></option><?php endforeach; ?></select></td></tr>
							<tr><th><label for="ajcore-create-rentec-short-desc"><?php esc_html_e( 'Short Description', 'ajcore-ra' ); ?></label></th><td><input id="ajcore-create-rentec-short-desc" type="text" class="large-text" maxlength="100" required></td></tr>
							<tr><th><label for="ajcore-create-rentec-description"><?php esc_html_e( 'Full Description', 'ajcore-ra' ); ?></label></th><td><textarea id="ajcore-create-rentec-description" class="large-text" rows="5"></textarea></td></tr>
						</table>
						<p class="submit" style="display:flex;justify-content:flex-end;gap:8px;">
							<button type="button" class="button" id="ajcore-cancel-rentec-work-order"><?php esc_html_e( 'Cancel', 'ajcore-ra' ); ?></button>
							<button type="submit" class="button button-primary" id="ajcore-submit-rentec-work-order"><?php esc_html_e( 'Create Work Order', 'ajcore-ra' ); ?></button>
						</p>
					</form>
				</dialog>
				<script>
				(function() {
					const openButton = document.getElementById('ajcore-new-rentec-work-order');
					const dialog = document.getElementById('ajcore-rentec-work-order-dialog');
					const form = document.getElementById('ajcore-rentec-work-order-create-form');
					if (!openButton || !dialog || !form) return;
					const account = document.getElementById('ajcore-create-rentec-account');
					const property = document.getElementById('ajcore-create-rentec-property');
					const renter = document.getElementById('ajcore-create-rentec-renter');
					const contact = document.getElementById('ajcore-create-rentec-contact');
					const vendor = document.getElementById('ajcore-create-rentec-vendor');
					const error = document.getElementById('ajcore-rentec-work-order-create-error');
					const submit = document.getElementById('ajcore-submit-rentec-work-order');
					const restRoot = <?php echo wp_json_encode( untrailingslashit( rest_url( 'ajcore/v1/ops/rentec' ) ) ); ?>;
					const nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

					function option(select, value, label) {
						const item = document.createElement('option');
						item.value = value;
						item.textContent = label;
						select.appendChild(item);
					}
					function resetSelect(select, label) {
						select.textContent = '';
						option(select, '', label);
					}
					function tenantLabel(item) {
						return [item.f_name, item.l_name].filter(Boolean).join(' ') || item.company || ('Renter ' + item.renter_id);
					}
					function showError(message) {
						error.textContent = message || '';
						error.style.display = message ? 'block' : 'none';
					}
					function loadOptions() {
						showError('');
						[property, renter, contact, vendor].forEach(function(select) { select.disabled = true; });
						resetSelect(property, '<?php echo esc_js( __( 'Loading properties…', 'ajcore-ra' ) ); ?>');
						resetSelect(renter, '<?php echo esc_js( __( 'No renter', 'ajcore-ra' ) ); ?>');
						resetSelect(contact, '<?php echo esc_js( __( 'Same/none', 'ajcore-ra' ) ); ?>');
						resetSelect(vendor, '<?php echo esc_js( __( 'Unassigned', 'ajcore-ra' ) ); ?>');
						fetch(restRoot + '/work-order-options?account=' + encodeURIComponent(account.value), { cache: 'no-store', headers: { 'X-WP-Nonce': nonce } })
							.then(function(response) { return response.json().then(function(body) { if (!response.ok) throw new Error(body.message || '<?php echo esc_js( __( 'Failed to load Rentec options.', 'ajcore-ra' ) ); ?>'); return body; }); })
							.then(function(body) {
								resetSelect(property, '<?php echo esc_js( __( 'Select a property', 'ajcore-ra' ) ); ?>');
								(body.properties || []).forEach(function(item) {
									option(property, String(item.property_id), [item.nickname, item.address, item.city, item.state].filter(Boolean).join(' · ') || ('Property ' + item.property_id));
								});
								(body.tenants || []).forEach(function(item) {
									option(renter, String(item.renter_id), tenantLabel(item));
									option(contact, String(item.renter_id), tenantLabel(item));
								});
								(body.vendors || []).forEach(function(item) {
									option(vendor, String(item.vendor_id), item.name || item.company || ('Vendor ' + item.vendor_id));
								});
							})
							.catch(function(loadError) { showError(loadError.message); })
							.finally(function() { [property, renter, contact, vendor].forEach(function(select) { select.disabled = false; }); });
					}
					openButton.addEventListener('click', function() { dialog.showModal(); loadOptions(); });
					account.addEventListener('change', loadOptions);
					renter.addEventListener('change', function() { if (!contact.value) contact.value = renter.value; });
					['ajcore-close-rentec-work-order', 'ajcore-cancel-rentec-work-order'].forEach(function(id) {
						document.getElementById(id).addEventListener('click', function() { dialog.close(); });
					});
					form.addEventListener('submit', function(event) {
						event.preventDefault();
						showError('');
						submit.disabled = true;
						submit.textContent = '<?php echo esc_js( __( 'Creating…', 'ajcore-ra' ) ); ?>';
						fetch(restRoot + '/work-orders', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
							body: JSON.stringify({
								account: account.value,
								property_id: Number(property.value),
								renter_id: renter.value ? Number(renter.value) : 0,
								contact_renter_id: contact.value ? Number(contact.value) : 0,
								vendor_id: vendor.value ? Number(vendor.value) : 0,
								priority: Number(document.getElementById('ajcore-create-rentec-priority').value),
								short_desc: document.getElementById('ajcore-create-rentec-short-desc').value.trim(),
								description: document.getElementById('ajcore-create-rentec-description').value.trim()
							})
						})
							.then(function(response) { return response.json().then(function(body) { if (!response.ok) throw new Error(body.message || '<?php echo esc_js( __( 'Failed to create the work order.', 'ajcore-ra' ) ); ?>'); return body; }); })
							.then(function() { location.reload(); })
							.catch(function(saveError) { showError(saveError.message); submit.disabled = false; submit.textContent = '<?php echo esc_js( __( 'Create Work Order', 'ajcore-ra' ) ); ?>'; });
					});
				}());
				</script>
				<?php if ( in_array( $resource, array( 'leads', 'vendors' ), true ) ) : ?>
					<?php
					$create_kind  = 'leads' === $resource ? 'lead' : 'vendor';
					$create_title = 'lead' === $create_kind ? __( 'New Lead', 'ajcore-ra' ) : __( 'New Vendor', 'ajcore-ra' );
					$create_fields = 'lead' === $create_kind
						? array(
							'f_name' => array( __( 'First Name', 'ajcore-ra' ), 'text', true ),
							'l_name' => array( __( 'Last Name', 'ajcore-ra' ), 'text', true ),
							'email' => array( __( 'Email', 'ajcore-ra' ), 'email', false ),
							'phone' => array( __( 'Phone', 'ajcore-ra' ), 'tel', true ),
							'monthly_rent_min' => array( __( 'Minimum Rent', 'ajcore-ra' ), 'number', false ),
							'monthly_rent_max' => array( __( 'Maximum Rent', 'ajcore-ra' ), 'number', true ),
							'search_city' => array( __( 'Search City', 'ajcore-ra' ), 'text', true ),
							'search_state' => array( __( 'Search State', 'ajcore-ra' ), 'text', true ),
							'move_in' => array( __( 'Move-in Date', 'ajcore-ra' ), 'date', false ),
							'occupants' => array( __( 'Occupants', 'ajcore-ra' ), 'number', false ),
						)
						: array(
							'name' => array( __( 'Vendor Name', 'ajcore-ra' ), 'text', true ),
							'industry' => array( __( 'Industry', 'ajcore-ra' ), 'text', false ),
							'contact' => array( __( 'Contact', 'ajcore-ra' ), 'text', false ),
							'email' => array( __( 'Email', 'ajcore-ra' ), 'email', false ),
							'phone' => array( __( 'Phone', 'ajcore-ra' ), 'tel', false ),
							'mphone' => array( __( 'Mobile', 'ajcore-ra' ), 'tel', false ),
							'address' => array( __( 'Address', 'ajcore-ra' ), 'text', false ),
							'address2' => array( __( 'Address 2', 'ajcore-ra' ), 'text', false ),
							'city' => array( __( 'City', 'ajcore-ra' ), 'text', false ),
							'state' => array( __( 'State', 'ajcore-ra' ), 'text', false ),
							'zip' => array( __( 'ZIP', 'ajcore-ra' ), 'text', false ),
							'rate' => array( __( 'Hourly Rate', 'ajcore-ra' ), 'text', false ),
						);
					?>
					<dialog id="ajcore-rentec-record-dialog" style="width:min(680px,calc(100vw - 32px));border:0;border-radius:14px;padding:0;box-shadow:0 24px 70px rgba(15,23,42,.3);">
						<form id="ajcore-rentec-record-create-form" style="padding:24px;">
							<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;">
								<div><h2 style="margin:0;"><?php echo esc_html( $create_title ); ?></h2><p class="description"><?php printf( esc_html__( 'Create this %s directly in Rentec Direct.', 'ajcore-ra' ), esc_html( $create_kind ) ); ?></p></div>
								<button type="button" class="button ajcore-close-rentec-record"><?php esc_html_e( 'Close', 'ajcore-ra' ); ?></button>
							</div>
							<div id="ajcore-rentec-record-create-error" class="notice notice-error inline" style="display:none;margin:14px 0;"></div>
							<table class="form-table" role="presentation">
								<tr><th><label for="ajcore-create-rentec-record-account"><?php esc_html_e( 'Rentec Account', 'ajcore-ra' ); ?></label></th><td>
									<select id="ajcore-create-rentec-record-account" class="regular-text">
										<?php foreach ( $accounts as $account_id => $account_config ) : ?>
											<?php if ( ! empty( $account_config['key'] ) ) : ?><option value="<?php echo esc_attr( $account_id ); ?>"><?php echo esc_html( $account_config['label'] ); ?></option><?php endif; ?>
										<?php endforeach; ?>
									</select>
								</td></tr>
								<?php foreach ( $create_fields as $field_name => $field_config ) : ?>
									<tr><th><label for="ajcore-create-rentec-<?php echo esc_attr( $field_name ); ?>"><?php echo esc_html( $field_config[0] ); ?><?php echo $field_config[2] ? ' *' : ''; ?></label></th><td><input id="ajcore-create-rentec-<?php echo esc_attr( $field_name ); ?>" name="<?php echo esc_attr( $field_name ); ?>" type="<?php echo esc_attr( $field_config[1] ); ?>" class="regular-text" <?php echo $field_config[2] ? 'required' : ''; ?>></td></tr>
								<?php endforeach; ?>
								<tr><th><label for="ajcore-create-rentec-notes"><?php esc_html_e( 'Notes', 'ajcore-ra' ); ?></label></th><td><textarea id="ajcore-create-rentec-notes" name="notes" class="large-text" rows="4"></textarea></td></tr>
							</table>
							<p class="submit" style="display:flex;justify-content:flex-end;gap:8px;">
								<button type="button" class="button ajcore-close-rentec-record"><?php esc_html_e( 'Cancel', 'ajcore-ra' ); ?></button>
								<button type="submit" class="button button-primary" id="ajcore-submit-rentec-record"><?php printf( esc_html__( 'Create %s', 'ajcore-ra' ), esc_html( ucfirst( $create_kind ) ) ); ?></button>
							</p>
						</form>
					</dialog>
					<script>
					(function() {
						const kind = <?php echo wp_json_encode( $create_kind ); ?>;
						const openButton = document.getElementById('ajcore-new-rentec-' + kind);
						const dialog = document.getElementById('ajcore-rentec-record-dialog');
						const form = document.getElementById('ajcore-rentec-record-create-form');
						const error = document.getElementById('ajcore-rentec-record-create-error');
						const submit = document.getElementById('ajcore-submit-rentec-record');
						if (!openButton || !dialog || !form) return;
						const restRoot = <?php echo wp_json_encode( untrailingslashit( rest_url( 'ajcore/v1/ops/rentec' ) ) ); ?>;
						const nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
						openButton.addEventListener('click', function() { dialog.showModal(); });
						dialog.querySelectorAll('.ajcore-close-rentec-record').forEach(function(button) {
							button.addEventListener('click', function() { dialog.close(); });
						});
						form.addEventListener('submit', function(event) {
							event.preventDefault();
							error.style.display = 'none';
							submit.disabled = true;
							const payload = { account: document.getElementById('ajcore-create-rentec-record-account').value };
							new FormData(form).forEach(function(value, key) { payload[key] = String(value).trim(); });
							fetch(restRoot + '/' + (kind === 'lead' ? 'leads' : 'vendors'), {
								method: 'POST',
								headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
								body: JSON.stringify(payload)
							})
								.then(function(response) { return response.json().then(function(body) { if (!response.ok) throw new Error(body.message || '<?php echo esc_js( __( 'Rentec could not create this record.', 'ajcore-ra' ) ); ?>'); return body; }); })
								.then(function() { location.reload(); })
								.catch(function(saveError) { error.textContent = saveError.message; error.style.display = 'block'; submit.disabled = false; });
						});
					}());
					</script>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( '1' !== (string) ( $settings['rentec_enabled'] ?? '0' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The Rentec integration is disabled. Enable it in CP Settings before loading data.', 'ajcore-ra' ); ?></p></div>
			<?php else : ?>
				<form method="get" id="ajcore-rentec-filters" class="ajforms-settings-inline-actions">
					<input type="hidden" name="page" value="ajforms-client-portal">
					<input type="hidden" name="tab" value="rentec">
					<input type="hidden" name="rentec_resource" value="<?php echo esc_attr( $resource ); ?>">
					<input type="hidden" name="rentec_filters_set" value="1">
					<strong><?php esc_html_e( 'Accounts', 'ajcore-ra' ); ?></strong>
					<?php foreach ( $accounts as $index => $configured_account ) : ?>
						<label><input type="checkbox" name="rentec_accounts[]" value="<?php echo esc_attr( $index ); ?>" <?php checked( in_array( (string) $index, $selected_accounts, true ) ); ?> <?php disabled( empty( $configured_account['key'] ) ); ?>> <?php echo esc_html( $configured_account['label'] . ( empty( $configured_account['key'] ) ? ' — not configured' : '' ) ); ?></label>
					<?php endforeach; ?>
					<?php if ( 'work_orders' === $resource ) : ?>
						<strong><?php esc_html_e( 'Status', 'ajcore-ra' ); ?></strong>
						<?php foreach ( $work_order_statuses as $status_key => $status_label ) : ?>
							<label><input type="checkbox" name="rentec_statuses[]" value="<?php echo esc_attr( $status_key ); ?>" <?php checked( in_array( $status_key, $selected_statuses, true ) ); ?>> <?php echo esc_html( $status_label ); ?></label>
						<?php endforeach; ?>
					<?php endif; ?>
					<span id="ajcore-rentec-filter-status" class="description" aria-live="polite"></span>
				</form>

				<nav class="ajcore-tabs-shell" aria-label="<?php esc_attr_e( 'Rentec sections', 'ajcore-ra' ); ?>">
					<select class="ajcore-tab-select" onchange="if(this.value){window.location.href=this.value;}">
						<?php foreach ( $resources as $resource_key => $resource_config ) : ?>
							<option value="<?php echo esc_url( add_query_arg( array( 'rentec_accounts' => $selected_accounts, 'rentec_statuses' => $selected_statuses, 'rentec_filters_set' => '1', 'rentec_resource' => $resource_key ), $base_url ) ); ?>" <?php selected( $resource, $resource_key ); ?>><?php echo esc_html( $resource_config['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php foreach ( $resources as $resource_key => $resource_config ) : ?>
						<a class="ajcore-tab-link <?php echo $resource === $resource_key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'rentec_accounts' => $selected_accounts, 'rentec_statuses' => $selected_statuses, 'rentec_filters_set' => '1', 'rentec_resource' => $resource_key ), $base_url ) ); ?>"><?php echo esc_html( $resource_config['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>
				<script>
				(function() {
					const form = document.getElementById('ajcore-rentec-filters');
					const status = document.getElementById('ajcore-rentec-filter-status');
					if (!form) return;
					form.querySelectorAll('input[type="checkbox"]').forEach(function(input) {
						input.addEventListener('change', function() {
							if (status) status.textContent = '<?php echo esc_js( __( 'Updating…', 'ajcore-ra' ) ); ?>';
							form.requestSubmit();
						});
					});
				}());
				</script>
			<?php endif; ?>
		</div>
		<?php
		if ( '1' !== (string) ( $settings['rentec_enabled'] ?? '0' ) ) {
			return;
		}
		if ( empty( $selected_accounts ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No configured Rentec accounts were selected.', 'ajcore-ra' ) . '</p></div>';
			return;
		}
		$rows  = array();
		$total = 0;
		foreach ( $selected_accounts as $account ) {
			$endpoint_url = 'https://secure.rentecdirect.com/api/v3/' . $resource;
			if ( 'work_orders' === $resource ) {
				$endpoint_url = add_query_arg( 'age', '3650d', $endpoint_url );
			} elseif ( 'transactions' === $resource ) {
				$endpoint_url = add_query_arg( 'page', 1, $endpoint_url );
			}
			$response = wp_remote_get( $endpoint_url, array( 'headers' => array( 'Accept' => 'application/json', 'X-API-Key' => (string) $accounts[ $account ]['key'] ), 'timeout' => 15, 'redirection' => 0 ) );
			$body     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( is_wp_error( $response ) ? $response->get_error_message() : sprintf( __( 'Rentec returned HTTP status %d for %s.', 'ajcore-ra' ), (int) wp_remote_retrieve_response_code( $response ), $accounts[ $account ]['label'] ) ) . '</p></div>';
				continue;
			}
			$account_rows = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
			if ( 'work_orders' === $resource ) {
				$account_rows = array_values(
					array_filter(
						$account_rows,
						function ( $row ) use ( $selected_statuses ) {
							$status = (string) ( $row['status'] ?? '' );
							if ( 'Unassigned' === $status ) {
								$status = 'OpenU';
							} elseif ( 'Assigned' === $status ) {
								$status = 'OpenA';
							}
							return in_array( $status, $selected_statuses, true );
						}
					)
				);
			}
			$total       += count( $account_rows );
			$account_rows = array_slice( $account_rows, 0, 100 );
			foreach ( $account_rows as &$account_row ) {
				$account_row['_rentec_account']       = $account;
				$account_row['_rentec_account_label'] = $accounts[ $account ]['label'];
			}
			unset( $account_row );
			$rows   = array_merge( $rows, $account_rows );
		}
		$columns     = $resources[ $resource ]['columns'];
		?>
		<div class="ajforms-settings-card">
			<div class="ajcore-section-head">
				<div>
					<h2><?php echo esc_html( $resources[ $resource ]['label'] ); ?></h2>
					<p><?php echo esc_html( sprintf( _n( '%1$d account · %2$d records returned.', '%1$d accounts · %2$d records returned.', count( $selected_accounts ), 'ajcore-ra' ), count( $selected_accounts ), $total ) ); ?></p>
				</div>
			</div>
			<?php if ( '' !== $note_result ) : ?><div class="notice notice-info inline"><p><?php echo esc_html( $note_result ); ?></p></div><?php endif; ?>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No records were returned.', 'ajcore-ra' ); ?></p>
			<?php else : ?>
				<div style="overflow-x:auto;">
					<table class="widefat striped">
						<thead><tr><?php foreach ( $columns as $column_label ) : ?><th><?php echo esc_html( $column_label ); ?></th><?php endforeach; ?></tr></thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr
									<?php if ( 'work_orders' === $resource ) : ?>
										class="ajcore-rentec-work-order-row"
										data-workorder-id="<?php echo esc_attr( $row['workorder_id'] ?? 0 ); ?>"
										data-rentec-account="<?php echo esc_attr( $row['_rentec_account'] ?? '1' ); ?>"
										title="<?php esc_attr_e( 'Double-click to view full details', 'ajcore-ra' ); ?>"
										style="cursor:pointer;"
									<?php endif; ?>
								>
									<?php foreach ( $columns as $field => $column_label ) : ?>
										<?php
										$value = isset( $row[ $field ] ) ? $row[ $field ] : '';
										if ( 'bytes' === $field && is_numeric( $value ) ) {
											$value = size_format( (int) $value );
										} elseif ( 'amount' === $field && is_numeric( $value ) ) {
											$value = number_format_i18n( (float) $value, 2 );
										} elseif ( is_array( $value ) || is_object( $value ) ) {
											$value = wp_json_encode( $value );
										}
										$value = wp_html_excerpt( (string) $value, 120, '…' );
										?>
										<td><?php echo esc_html( $value ); ?></td>
									<?php endforeach; ?>
								</tr>
								<?php if ( 'work_orders' === $resource ) : ?>
									<tr><td colspan="<?php echo esc_attr( count( $columns ) ); ?>">
										<details class="ajcore-rentec-work-order-details">
											<summary><strong><?php esc_html_e( 'View details and notes', 'ajcore-ra' ); ?></strong></summary>
											<p class="ajcore-rentec-detail-loading description" hidden><?php esc_html_e( 'Loading full work-order details…', 'ajcore-ra' ); ?></p>
											<div class="ajcore-rentec-detail-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin:14px 0;">
												<?php foreach ( $row as $detail_key => $detail_value ) : ?>
													<?php if ( 0 === strpos( (string) $detail_key, '_' ) || 'notes' === $detail_key ) { continue; } ?>
													<div><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $detail_key ) ) ); ?>:</strong> <?php echo esc_html( is_scalar( $detail_value ) ? (string) $detail_value : wp_json_encode( $detail_value ) ); ?></div>
												<?php endforeach; ?>
											</div>
											<h4>
												<?php esc_html_e( 'Attachments', 'ajcore-ra' ); ?>
												<label class="button button-small" style="margin-left:8px;">
													<?php esc_html_e( 'Attach Image', 'ajcore-ra' ); ?>
													<input type="file" class="ajcore-rentec-image-upload" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
												</label>
											</h4>
											<div class="ajcore-rentec-attachments"><p class="description"><?php esc_html_e( 'Open the details to load attachments.', 'ajcore-ra' ); ?></p></div>
											<h4><?php esc_html_e( 'Notes & History', 'ajcore-ra' ); ?></h4>
											<p class="description"><?php esc_html_e( 'Rentec API V3 currently supports adding notes, but does not expose an endpoint for changing an existing work-order status.', 'ajcore-ra' ); ?></p>
											<?php foreach ( (array) ( $row['notes'] ?? array() ) as $work_order_note ) : ?>
												<p><?php echo esc_html( (string) ( $work_order_note['note'] ?? '' ) ); ?> <small><?php echo esc_html( (string) ( $work_order_note['created'] ?? '' ) ); ?><?php echo ! empty( $work_order_note['private'] ) ? ' · ' . esc_html__( 'Private', 'ajcore-ra' ) : ' · ' . esc_html__( 'Shared', 'ajcore-ra' ); ?></small></p>
											<?php endforeach; ?>
											<form method="post">
												<?php wp_nonce_field( 'ajcore_add_rentec_work_order_note', 'ajcore_rentec_note_nonce' ); ?>
												<input type="hidden" name="rentec_note_account" value="<?php echo esc_attr( $row['_rentec_account'] ); ?>">
												<input type="hidden" name="rentec_workorder_id" value="<?php echo esc_attr( $row['workorder_id'] ?? 0 ); ?>">
												<textarea name="rentec_note" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Add a work-order note…', 'ajcore-ra' ); ?>" required></textarea>
												<p><label><input type="checkbox" name="rentec_note_private" value="1" checked> <?php esc_html_e( 'Private note', 'ajcore-ra' ); ?></label> <button class="button button-primary"><?php esc_html_e( 'Add Note', 'ajcore-ra' ); ?></button></p>
											</form>
										</details>
									</td></tr>
								<?php endif; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php if ( 'work_orders' === $resource ) : ?>
					<script>
					(function() {
						const restBase = <?php echo wp_json_encode( untrailingslashit( rest_url( 'ajcore/v1/ops/rentec/work-orders' ) ) ); ?>;
						const restNonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

						function valueText(value) {
							if (value === null || typeof value === 'undefined') return '—';
							if (typeof value === 'object') {
								try { return JSON.stringify(value); } catch (error) { return String(value); }
							}
							return String(value);
						}

						function renderDetail(details, body) {
							const grid = details.querySelector('.ajcore-rentec-detail-grid');
							const attachments = details.querySelector('.ajcore-rentec-attachments');
							if (grid) {
								grid.textContent = '';
								Object.entries(body.work_order || {}).forEach(function(entry) {
									const key = entry[0];
									if (key.charAt(0) === '_' || key === 'notes') return;
									const item = document.createElement('div');
									const label = document.createElement('strong');
									label.textContent = key.replaceAll('_', ' ').replace(/\b\w/g, function(letter) { return letter.toUpperCase(); }) + ': ';
									item.appendChild(label);
									item.appendChild(document.createTextNode(valueText(entry[1])));
									grid.appendChild(item);
								});
							}
							if (attachments) {
								attachments.textContent = '';
								if (body.files_error) {
									const error = document.createElement('p');
									error.className = 'notice notice-warning inline';
									error.textContent = '<?php echo esc_js( __( 'Attachments could not be loaded: ', 'ajcore-ra' ) ); ?>' + body.files_error;
									attachments.appendChild(error);
									return;
								}
								const files = Array.isArray(body.files) ? body.files : [];
								if (!files.length) {
									const empty = document.createElement('p');
									empty.className = 'description';
									empty.textContent = '<?php echo esc_js( __( 'No attachments are associated with this work order.', 'ajcore-ra' ) ); ?>';
									attachments.appendChild(empty);
									return;
								}
								files.forEach(function(file, index) {
									const card = document.createElement('div');
									card.style.cssText = 'border:1px solid #dcdcde;border-radius:6px;padding:10px;margin:8px 0;';
									const name = document.createElement('strong');
									name.textContent = valueText(file.filename || file.name || ('<?php echo esc_js( __( 'Attachment', 'ajcore-ra' ) ); ?> ' + (index + 1)));
									card.appendChild(name);
									const meta = document.createElement('div');
									meta.className = 'description';
									meta.textContent = [file.upload_date || '', file.bytes ? Number(file.bytes).toLocaleString() + ' bytes' : ''].filter(Boolean).join(' · ');
									card.appendChild(meta);
									const fileUrl = file.download_url || file.file_url || file.url || file.link || '';
									if (fileUrl) {
										const link = document.createElement('a');
										link.href = String(fileUrl);
										link.target = '_blank';
										link.rel = 'noopener noreferrer';
										link.className = 'button button-small';
										link.style.marginTop = '8px';
										link.textContent = '<?php echo esc_js( __( 'Open attachment', 'ajcore-ra' ) ); ?>';
										card.appendChild(link);
									}
									attachments.appendChild(card);
								});
							}
						}

						function loadDetail(row, details) {
							if (!row || !details || details.dataset.loaded === '1' || details.dataset.loading === '1') return;
							const id = row.dataset.workorderId;
							const account = row.dataset.rentecAccount || '1';
							if (!id || id === '0') return;
							const loading = details.querySelector('.ajcore-rentec-detail-loading');
							details.dataset.loading = '1';
							if (loading) loading.hidden = false;
							fetch(restBase + '/' + encodeURIComponent(id) + '?account=' + encodeURIComponent(account), {
								cache: 'no-store',
								headers: { 'X-WP-Nonce': restNonce }
							})
								.then(function(response) {
									return response.json().then(function(body) {
										if (!response.ok) throw new Error(body.message || '<?php echo esc_js( __( 'Failed to load the work order.', 'ajcore-ra' ) ); ?>');
										return body;
									});
								})
								.then(function(body) {
									renderDetail(details, body);
									details.dataset.loaded = '1';
								})
								.catch(function(error) {
									if (loading) {
										loading.hidden = false;
										loading.textContent = error.message;
										loading.style.color = '#b32d2e';
									}
								})
								.finally(function() {
									details.dataset.loading = '0';
									if (loading && details.dataset.loaded === '1') loading.hidden = true;
								});
						}

						document.querySelectorAll('.ajcore-rentec-work-order-row').forEach(function(row) {
							const detailRow = row.nextElementSibling;
							const details = detailRow ? detailRow.querySelector('.ajcore-rentec-work-order-details') : null;
							if (!details) return;
							row.addEventListener('dblclick', function() {
								details.open = true;
								loadDetail(row, details);
								details.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
							});
							details.addEventListener('toggle', function() {
								if (details.open) loadDetail(row, details);
							});
							const upload = details.querySelector('.ajcore-rentec-image-upload');
							if (upload) {
								upload.addEventListener('change', function() {
									const file = upload.files && upload.files[0];
									upload.value = '';
									if (!file) return;
									const formData = new FormData();
									formData.append('account', row.dataset.rentecAccount || '1');
									formData.append('file', file);
									const loading = details.querySelector('.ajcore-rentec-detail-loading');
									if (loading) {
										loading.hidden = false;
										loading.style.color = '';
										loading.textContent = '<?php echo esc_js( __( 'Uploading image…', 'ajcore-ra' ) ); ?>';
									}
									fetch(restBase + '/' + encodeURIComponent(row.dataset.workorderId) + '/attachments', {
										method: 'POST',
										headers: { 'X-WP-Nonce': restNonce },
										body: formData
									})
										.then(function(response) {
											return response.json().then(function(body) {
												if (!response.ok) throw new Error(body.message || '<?php echo esc_js( __( 'Failed to upload the image.', 'ajcore-ra' ) ); ?>');
											});
										})
										.then(function() {
											details.dataset.loaded = '0';
											loadDetail(row, details);
										})
										.catch(function(error) {
											if (loading) {
												loading.hidden = false;
												loading.style.color = '#b32d2e';
												loading.textContent = error.message;
											}
										});
								});
							}
						});
					}());
					</script>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
