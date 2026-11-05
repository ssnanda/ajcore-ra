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
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog_writes' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'ajcore_ra_rentec_sync', array( __CLASS__, 'run_scheduled_sync' ) );
		add_action( 'init', array( __CLASS__, 'ensure_sync_scheduled' ) );
	}

	public static function boot_routes() {
		self::instance()->register_routes();
		self::instance()->register_write_routes();
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
		$rentec->render_diagnostics();
		$rentec->render_workspace();
	}

	/** Collapsed "Diagnostics" card: pulls a few raw Rentec rows with every field and offers copy buttons. */
	private function render_diagnostics() {
		$accounts = $this->get_rentec_accounts_for_ops();
		$labels   = array( 'work_orders' => 'Work Orders', 'tenants' => 'Tenants', 'leads' => 'Leads', 'applications' => 'Applications', 'vendors' => 'Vendors', 'properties' => 'Properties', 'owners' => 'Owners', 'leases' => 'Leases', 'transactions' => 'Transactions', 'files' => 'Files', 'messages' => 'Messages', 'accounts' => 'Accounts', 'products' => 'Products', 'banks' => 'Banks' );
		?>
		<details class="ajforms-settings-card" style="margin-bottom:14px" id="ajra-diag">
			<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'Diagnostics', 'ajcore-ra' ); ?></summary>
			<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:10px 0">
				<select id="ajra-diag-resource"><?php foreach ( $labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
				<select id="ajra-diag-account"><?php foreach ( $accounts as $id => $account ) : ?><option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $account['label'] ); ?></option><?php endforeach; ?></select>
				<select id="ajra-diag-limit"><?php foreach ( array( 1, 2, 3, 5 ) as $n ) : ?><option value="<?php echo (int) $n; ?>"<?php selected( 3, $n ); ?>><?php echo (int) $n; ?> <?php esc_html_e( 'rows', 'ajcore-ra' ); ?></option><?php endforeach; ?></select>
				<button type="button" class="button button-primary" id="ajra-diag-run"><?php esc_html_e( 'Pull rows', 'ajcore-ra' ); ?></button>
				<button type="button" class="button" id="ajra-diag-copy-rows" hidden><?php esc_html_e( 'Copy full rows (JSON)', 'ajcore-ra' ); ?></button>
				<button type="button" class="button" id="ajra-diag-copy-fields" hidden><?php esc_html_e( 'Copy field list', 'ajcore-ra' ); ?></button>
				<button type="button" class="button" id="ajra-diag-copy-record" hidden><?php esc_html_e( 'Copy full record (JSON)', 'ajcore-ra' ); ?></button>
				<span id="ajra-diag-status" style="color:#50575e"></span>
			</div>
			<pre id="ajra-diag-out" style="display:none;max-height:420px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font-size:12px"></pre>
		</details>
		<script>
		(function () {
			var $ = function (id) { return document.getElementById(id); };
			var last = null;
			function copy(text, label) {
				var done = function () { $('ajra-diag-status').textContent = 'Copied ' + label; };
				if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done); return; }
				var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done();
			}
			function fieldsText(fields) { return fields.map(function (f) { return f.name + '\t' + f.type + '\t' + f.example; }).join('\n'); }
			$('ajra-diag-run').addEventListener('click', function () {
				var url = <?php echo wp_json_encode( rest_url( 'ajcore/v1/ops/rentec/diagnostics' ) ); ?>;
				url += (url.indexOf('?') > -1 ? '&' : '?') + 'resource=' + encodeURIComponent($('ajra-diag-resource').value) + '&account=' + encodeURIComponent($('ajra-diag-account').value) + '&limit=' + encodeURIComponent($('ajra-diag-limit').value);
				$('ajra-diag-status').textContent = 'Pulling…';
				fetch(url, { headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> }, credentials: 'same-origin' })
					.then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
					.then(function (res) {
						if (!res.ok) { $('ajra-diag-status').textContent = res.b.message || 'Failed'; return; }
						last = res.b;
						var out = $('ajra-diag-out');
						out.style.display = 'block';
						out.textContent = JSON.stringify(res.b.rows, null, 2) + (res.b.detail ? '\n\n// single record #' + res.b.detail_id + (res.b.only_in_detail.length ? ' (only here: ' + res.b.only_in_detail.join(', ') + ')' : '') + '\n' + JSON.stringify(res.b.detail, null, 2) : '');
						$('ajra-diag-copy-rows').hidden = false;
						$('ajra-diag-copy-fields').hidden = false;
						$('ajra-diag-copy-record').hidden = !res.b.detail;
						$('ajra-diag-status').textContent = res.b.total + ' records · ' + res.b.fields.length + ' list fields' + (res.b.detail ? ' · ' + res.b.detail_fields.length + ' record fields' : '') + ' · ' + res.b.calls + ' call(s)';
					})
					.catch(function () { $('ajra-diag-status').textContent = 'Request failed'; });
			});
			$('ajra-diag-copy-rows').addEventListener('click', function () { if (last) { copy(JSON.stringify(last.rows, null, 2), 'rows'); } });
			$('ajra-diag-copy-record').addEventListener('click', function () { if (last && last.detail) { copy(JSON.stringify(last.detail, null, 2), 'record'); } });
			$('ajra-diag-copy-fields').addEventListener('click', function () { if (last) { copy(fieldsText(last.fields) + (last.detail ? '\n\n--- single record ---\n' + fieldsText(last.detail_fields) : ''), 'field list'); } });
		})();
		</script>
		<?php
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
		if ( 'GET' !== strtoupper( (string) $method ) ) {
			$this->refresh_rentec_after_write( $url, $api_key );
		}
		return $body;
	}

	// ── Local copy of Rentec data ───────────────────────────────────────────────
	// Rentec's v3 list endpoints cannot be paged and every call costs ~1-3 s (60 calls/min per key), so each list
	// is stored in a local table (aj_rentec_cache) and screens read from it. It is refreshed by: a background job
	// every 10 minutes, the "Sync now" button, the per-screen Refresh button, and right after any create, note or
	// upload (only the affected lists). Rentec's API cannot edit existing records, so nothing else needs pushing.

	private $rentec_bypass_cache = false;

	/** A stored copy older than this is treated as missing and re-fetched live (the background job normally keeps it far fresher). */
	private function rentec_cache_max_age() {
		return (int) apply_filters( 'ajcore_ra_rentec_cache_max_age', 30 * MINUTE_IN_SECONDS );
	}

	private function rentec_cache_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_rentec_cache';
	}

	private function ensure_rentec_cache_table() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		if ( (int) get_option( 'ajcore_ra_rentec_cache_table', 0 ) >= 1 ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $this->rentec_cache_table();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_hash char(32) NOT NULL,
			cache_key char(32) NOT NULL,
			resource varchar(40) NOT NULL DEFAULT '',
			url text NOT NULL,
			body longtext NOT NULL,
			records int(11) NOT NULL DEFAULT 0,
			synced_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entry (key_hash,cache_key),
			KEY resource (resource)
		) {$wpdb->get_charset_collate()};" );
		update_option( 'ajcore_ra_rentec_cache_table', 1, false );
	}

	/** Same URL with its query sorted, so equal requests share one stored copy. */
	private function normalize_rentec_url( $url ) {
		$parts = wp_parse_url( $url );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			ksort( $query );
		}
		return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( $parts['path'] ?? '' ) . ( $query ? '?' . http_build_query( $query ) : '' );
	}

	/** First path segment after /api/v3/ ("transactions/expense" -> "transactions"). */
	private function rentec_resource_of( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = preg_replace( '#^.*/api/v3/#', '', $path );
		$seg  = explode( '/', trim( $path, '/' ) );
		return sanitize_key( (string) $seg[0] );
	}

	private function store_rentec_cache( $url, $api_key, array $body ) {
		global $wpdb;
		$this->ensure_rentec_cache_table();
		$url = $this->normalize_rentec_url( $url );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT INTO `{$this->rentec_cache_table()}` (key_hash,cache_key,resource,url,body,records,synced_at) VALUES (%s,%s,%s,%s,%s,%d,%s)
			 ON DUPLICATE KEY UPDATE body=VALUES(body),records=VALUES(records),synced_at=VALUES(synced_at),url=VALUES(url),resource=VALUES(resource)",
			md5( $api_key ),
			md5( $url ),
			$this->rentec_resource_of( $url ),
			$url,
			wp_json_encode( $body ),
			isset( $body['data'] ) && is_array( $body['data'] ) ? count( $body['data'] ) : 0,
			gmdate( 'Y-m-d H:i:s' )
		) );
	}

	private function read_rentec_cache( $url, $api_key ) {
		global $wpdb;
		$this->ensure_rentec_cache_table();
		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT body, synced_at FROM `{$this->rentec_cache_table()}` WHERE key_hash=%s AND cache_key=%s",
			md5( $api_key ),
			md5( $this->normalize_rentec_url( $url ) )
		) );
	}

	/** GET through the local copy. Falls back to a live call (and stores it) when missing, too old, or bypassed; serves the old copy if Rentec is unreachable. */
	private function cached_rentec_for_ops( $url, $api_key, $ttl = 0 ) {
		$row = $this->read_rentec_cache( $url, $api_key );
		if ( $row && ! $this->rentec_bypass_cache && ( time() - strtotime( $row->synced_at . ' UTC' ) ) <= $this->rentec_cache_max_age() ) {
			$hit = json_decode( $row->body, true );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}
		$body = $this->request_rentec_for_ops( $url, $api_key );
		if ( ! is_wp_error( $body ) ) {
			$this->store_rentec_cache( $url, $api_key, $body );
			return $body;
		}
		if ( $row ) {
			$stale = json_decode( $row->body, true );
			if ( is_array( $stale ) ) {
				return $stale;
			}
		}
		return $body;
	}

	/** After a successful create/note/upload, re-fetch the stored lists it can change so the screens show it straight away. */
	private function refresh_rentec_after_write( $url, $api_key ) {
		$map = array(
			'work_orders'  => array( 'work_orders' ),
			'leads'        => array( 'leads' ),
			'vendors'      => array( 'vendors' ),
			'properties'   => array( 'properties' ),
			'tenants'      => array( 'tenants' ),
			'leases'       => array( 'leases', 'tenants' ),
			'transactions' => array( 'transactions' ),
			'messages'     => array( 'messages' ),
			'files'        => array( 'files' ),
		);
		$resource = $this->rentec_resource_of( $url );
		if ( isset( $map[ $resource ] ) ) {
			$this->refresh_rentec_cache_resources( $map[ $resource ], $api_key );
		}
	}

	private function refresh_rentec_cache_resources( array $resources, $api_key ) {
		global $wpdb;
		$this->ensure_rentec_cache_table();
		$place = implode( ',', array_fill( 0, count( $resources ), '%s' ) );
		$urls  = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT url FROM `{$this->rentec_cache_table()}` WHERE key_hash=%s AND resource IN ({$place})",
			array_merge( array( md5( $api_key ) ), $resources )
		) );
		foreach ( (array) $urls as $stored_url ) {
			$body = $this->request_rentec_for_ops( $stored_url, $api_key );
			if ( ! is_wp_error( $body ) ) {
				$this->store_rentec_cache( $stored_url, $api_key, $body );
			}
		}
	}

	/** The Rentec lists to keep local, in sync order. resource => list of exact URLs the screens and counts request. */
	private function rentec_sync_plan() {
		$plan = array();
		foreach ( array( 'work_orders', 'tenants', 'leads', 'applications', 'vendors', 'properties', 'owners', 'leases', 'transactions', 'files', 'messages', 'accounts', 'products' ) as $resource ) {
			$plan[ $resource ] = array( $this->rentec_list_url( $resource ) );
		}
		$base                   = 'https://secure.rentecdirect.com/api/v3/';
		$plan['applications'][] = add_query_arg( 'status', 'Unread', $base . 'applications' );
		$plan['messages'][]     = add_query_arg( array( 'unread_only' => 'true', 'page_size' => 100 ), $base . 'messages' );
		return $plan;
	}

	/** The URL a screen requests for a resource's list (also what the counts and the sync use, so they share one stored copy). */
	private function rentec_list_url( $resource ) {
		$url = 'https://secure.rentecdirect.com/api/v3/' . $resource;
		if ( 'transactions' === $resource ) {
			$url = add_query_arg( 'page', 1, $url );
		} elseif ( 'properties' === $resource ) {
			$url = add_query_arg( 'include_subunits', 'true', $url );
		} elseif ( 'messages' === $resource ) {
			$url = add_query_arg( 'page_size', 100, $url );
		} elseif ( 'work_orders' === $resource ) {
			$url = add_query_arg( 'age', '3650d', $url );
		}
		return $url;
	}

	/** Syncs one resource for one account key live; returns records stored or a WP_Error. */
	private function sync_rentec_resource( $resource, $api_key ) {
		$plan = $this->rentec_sync_plan();
		if ( ! isset( $plan[ $resource ] ) ) {
			return new WP_Error( 'ajcore_rentec_kind', __( 'Unknown Rentec resource.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$records = 0;
		foreach ( $plan[ $resource ] as $url ) {
			$body = $this->request_rentec_for_ops( $url, $api_key );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$this->store_rentec_cache( $url, $api_key, $body );
			$records = max( $records, isset( $body['data'] ) && is_array( $body['data'] ) ? count( $body['data'] ) : 0 );
		}
		update_option( 'ajcore_ra_rentec_last_sync', time(), false );
		return $records;
	}

	/** Background job: sync every resource for every configured account. */
	public static function run_scheduled_sync() {
		$rentec   = self::instance();
		$settings = $rentec->get_settings();
		if ( '1' !== (string) ( $settings['rentec_enabled'] ?? '0' ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		foreach ( $rentec->get_rentec_accounts_for_ops() as $account ) {
			if ( empty( $account['key'] ) ) {
				continue;
			}
			foreach ( array_keys( $rentec->rentec_sync_plan() ) as $resource ) {
				$rentec->sync_rentec_resource( $resource, $account['key'] );
			}
		}
	}

	public static function cron_schedules( $schedules ) {
		$schedules['ajra_ten_minutes'] = array( 'interval' => 10 * MINUTE_IN_SECONDS, 'display' => __( 'Every 10 minutes', 'ajcore-ra' ) );
		return $schedules;
	}

	public static function ensure_sync_scheduled() {
		if ( ! wp_next_scheduled( 'ajcore_ra_rentec_sync' ) ) {
			wp_schedule_event( time() + 120, 'ajra_ten_minutes', 'ajcore_ra_rentec_sync' );
		}
	}

	/** What is stored and how old, plus the steps the Sync now button walks through. */
	public function get_ops_rentec_sync_status( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		global $wpdb;
		$this->ensure_rentec_cache_table();
		$steps   = array();
		$entries = array();
		foreach ( $selected['accounts'] as $id => $account ) {
			if ( empty( $account['key'] ) ) {
				continue;
			}
			foreach ( array_keys( $this->rentec_sync_plan() ) as $resource ) {
				$steps[] = array( 'account' => (string) $id, 'resource' => $resource );
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT resource, MAX(synced_at) AS synced_at, MAX(records) AS records FROM `{$this->rentec_cache_table()}` WHERE key_hash=%s GROUP BY resource", md5( $account['key'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $row ) {
				$entries[] = array( 'account' => (string) $id, 'resource' => $row['resource'], 'synced_at' => strtotime( $row['synced_at'] . ' UTC' ), 'records' => (int) $row['records'] );
			}
		}
		return rest_ensure_response( array(
			'steps'            => $steps,
			'entries'          => $entries,
			'last_synced'      => $entries ? max( wp_list_pluck( $entries, 'synced_at' ) ) : 0,
			'oldest'           => $entries ? min( wp_list_pluck( $entries, 'synced_at' ) ) : 0,
			'interval_minutes' => 10,
			'next_scheduled'   => (int) wp_next_scheduled( 'ajcore_ra_rentec_sync' ),
			'now'              => time(),
		) );
	}

	/** Sync one resource for one account (the Sync now button calls this once per step). */
	public function run_ops_rentec_sync( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$account  = (string) $request->get_param( 'account' );
		$resource = sanitize_key( (string) $request->get_param( 'resource' ) );
		if ( empty( $selected['accounts'][ $account ]['key'] ) ) {
			return new WP_Error( 'ajcore_rentec_not_configured', __( 'That Rentec account does not have an API key.', 'ajcore-ra' ), array( 'status' => 409 ) );
		}
		$records = $this->sync_rentec_resource( $resource, $selected['accounts'][ $account ]['key'] );
		if ( is_wp_error( $records ) ) {
			return $records;
		}
		return rest_ensure_response( array( 'account' => $account, 'resource' => $resource, 'records' => $records ) );
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

	// ── Extra Rentec resources: messages, files, properties, tenants, leases, expenses, notes ──
	// Rentec's v3 "write" is create-only (no update/delete) apart from sticky notes; these routes mirror that.

	/** Field whitelist per creatable record: name => type (str|email|text|int|num|bool|date|enum:a,b). */
	private function rentec_record_specs() {
		return array(
			'properties' => array(
				'path'     => 'properties',
				'required' => array(),
				'fields'   => array( 'nickname' => 'str', 'address' => 'str', 'city' => 'str', 'state' => 'str', 'zip' => 'str', 'monthly_rent' => 'num', 'security_dep' => 'num', 'bedrooms' => 'int', 'bathrooms' => 'num', 'sqft' => 'int', 'notes' => 'text', 'multiplex' => 'bool', 'multiunits' => 'int', 'owner_id' => 'int', 'default_bank' => 'int', 'default_bank_expense' => 'int' ),
			),
			'tenants'    => array(
				'path'     => 'tenants',
				'required' => array(),
				'fields'   => array( 'f_name' => 'str', 'l_name' => 'str', 'm_int' => 'str', 'company' => 'str', 'email' => 'email', 'phone' => 'str', 'mphone' => 'str', 'employer_phone' => 'str', 'dob' => 'date', 'notes' => 'text' ),
			),
			'leases'     => array(
				'path'     => 'leases',
				'required' => array( 'renter_id', 'property_id', 'move_in_date' ),
				'fields'   => array( 'renter_id' => 'int', 'property_id' => 'int', 'move_in_date' => 'date', 'begin_charges_date' => 'date', 'lease_begin_date' => 'date', 'lease_end_date' => 'date', 'rent_amount' => 'num', 'rent_period' => 'enum:daily,weekly,monthly,yearly,biweekly,quarterly', 'product_id' => 'int', 'prorate' => 'bool' ),
			),
			'expenses'   => array(
				'path'     => 'transactions/expense',
				'required' => array( 'category_id', 'amount', 'pmt_type' ),
				'fields'   => array( 'property_id' => 'int', 'bank_id' => 'int', 'renter_id' => 'int', 'vendor_id' => 'int', 'category_id' => 'int', 'amount' => 'num', 'transaction_time' => 'str', 'memo' => 'str', 'check_num' => 'str', 'pmt_type' => 'enum:CH,PCH,CA,CR,O,ACH' ),
			),
			'messages'   => array(
				'path'     => 'messages',
				'required' => array( 'body' ),
				'fields'   => array( 'subject' => 'str', 'body' => 'text', 'renter_id' => 'int', 'prospect_id' => 'int', 'owner_id' => 'int', 'vendor_id' => 'int', 'date' => 'date' ),
			),
		);
	}

	private function clean_rentec_value( $value, $type ) {
		if ( 'int' === $type ) {
			return absint( $value );
		}
		if ( 'num' === $type ) {
			return round( max( 0, (float) $value ), 2 );
		}
		if ( 'bool' === $type ) {
			return rest_sanitize_boolean( $value );
		}
		if ( 'email' === $type ) {
			return sanitize_email( (string) $value );
		}
		if ( 'text' === $type ) {
			return sanitize_textarea_field( (string) $value );
		}
		if ( 'date' === $type ) {
			$value = sanitize_text_field( (string) $value );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		}
		if ( 0 === strpos( $type, 'enum:' ) ) {
			$value = sanitize_text_field( (string) $value );
			return in_array( $value, explode( ',', substr( $type, 5 ) ), true ) ? $value : '';
		}
		return sanitize_text_field( (string) $value );
	}

	public function register_write_routes() {
		$ops = array( 'AJCore_Extensions', 'can_manage_ops' );
		$ns  = AJCore_Extensions::rest_namespace();
		register_rest_route( $ns, '/ops/rentec/records/(?P<kind>properties|tenants|leases|expenses|messages)', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create_ops_rentec_record' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/rentec/(?P<kind>properties|tenants)/(?P<id>\d+)/notes', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'add_ops_rentec_entity_note' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/rentec/files', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'upload_ops_rentec_file' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/rentec/counts', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_ops_rentec_counts' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/rentec/sync-status', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_ops_rentec_sync_status' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/rentec/sync', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'run_ops_rentec_sync' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/rentec/schema', array( 'methods' => WP_REST_Server::READABLE, 'callback' => function () { return rest_ensure_response( AJCore_RA_Rentec_Screens::definition() ); }, 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/rentec/diagnostics', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_ops_rentec_diagnostics' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/rentec/lookup', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_ops_rentec_lookup' ), 'permission_callback' => $ops ) );
	}

	public static function catalog_writes( $catalog ) {
		$e = static function ( $method, $path, $purpose ) {
			return array( 'surface' => 'OPS', 'method' => $method, 'path' => $path, 'auth' => 'Admin', 'purpose' => $purpose, 'app' => 'OPS rentec' );
		};
		$catalog[] = $e( 'POST', '/ops/rentec/records/{kind}', 'Creates a Rentec record. kind: properties | tenants | leases | expenses | messages (create-only; Rentec has no update/delete).' );
		$catalog[] = $e( 'POST', '/ops/rentec/{properties|tenants}/{id}/notes', 'Adds a note to a Rentec property or tenant.' );
		$catalog[] = $e( 'POST', '/ops/rentec/files', 'Uploads a file to Rentec (multipart: file, optional property_id, renter_id, owner_id, vendor_id, workorder_id, transaction_id, share_owner).' );
		$catalog[] = $e( 'GET', '/ops/rentec/counts', 'Badge counts for the Rentec sidebar (open work orders, tenants owing, leads, unread applications and messages, totals), cached.' );
		$catalog[] = $e( 'GET', '/ops/rentec/sync-status', 'What is stored locally per Rentec resource and how old it is, plus the steps the Sync now button walks through.' );
		$catalog[] = $e( 'POST', '/ops/rentec/sync', 'Re-fetches one Rentec resource (account, resource) into the local copy. The background job does the same every 10 minutes.' );
		$catalog[] = $e( 'GET', '/ops/rentec/schema', 'The shared Rentec screen definition (resources, columns, badges, create forms) used by the WP-admin workspace and AJOps.' );
		$catalog[] = $e( 'GET', '/ops/rentec/diagnostics', 'Raw Rentec rows with every field (resource, account, limit<=5): unmodified list rows, the first record fetched individually, and a field list. Bypasses the cache; 1-2 Rentec calls.' );
		$catalog[] = $e( 'GET', '/ops/rentec/lookup', 'Finds a tenant, owner, vendor or prospect by exact name, email or phone (type, q) for the message recipient picker.' );
		return $catalog;
	}

	public function create_ops_rentec_record( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$specs = $this->rentec_record_specs();
		$kind  = sanitize_key( (string) $request->get_param( 'kind' ) );
		if ( ! isset( $specs[ $kind ] ) ) {
			return new WP_Error( 'ajcore_rentec_kind', __( 'Unknown Rentec record type.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$spec    = $specs[ $kind ];
		$payload = array();
		foreach ( $spec['fields'] as $field => $type ) {
			$raw = $request->get_param( $field );
			if ( null === $raw || '' === $raw ) {
				continue;
			}
			$value = $this->clean_rentec_value( $raw, $type );
			if ( '' === $value || ( in_array( $type, array( 'int' ), true ) && 0 === $value ) ) {
				continue;
			}
			$payload[ $field ] = $value;
		}
		// Rentec wants "yyyy-mm-dd hh:ii:ss" for the expense time; the form sends just the date.
		if ( ! empty( $payload['transaction_time'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $payload['transaction_time'] ) ) {
			$payload['transaction_time'] .= ' 12:00:00';
		}
		foreach ( $spec['required'] as $field ) {
			if ( ! isset( $payload[ $field ] ) || '' === $payload[ $field ] || 0 === $payload[ $field ] ) {
				return new WP_Error( 'ajcore_rentec_required', sprintf( __( '%s is required.', 'ajcore-ra' ), $field ), array( 'status' => 400 ) );
			}
		}
		if ( 'tenants' === $kind && empty( $payload['company'] ) && ( empty( $payload['f_name'] ) || empty( $payload['l_name'] ) ) ) {
			return new WP_Error( 'ajcore_rentec_required', __( 'First and last name, or a company, is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( 'properties' === $kind && empty( $payload['address'] ) ) {
			return new WP_Error( 'ajcore_rentec_required', __( 'Address is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( 'expenses' === $kind && empty( $payload['property_id'] ) && empty( $payload['bank_id'] ) ) {
			return new WP_Error( 'ajcore_rentec_required', __( 'A property or a bank account is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( 'messages' === $kind && empty( $payload['renter_id'] ) && empty( $payload['prospect_id'] ) && empty( $payload['owner_id'] ) && empty( $payload['vendor_id'] ) ) {
			return new WP_Error( 'ajcore_rentec_required', __( 'Choose who the message is for.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$body = $this->request_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/' . $spec['path'], $selected['account']['key'], 'POST', $payload );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return rest_ensure_response( array( 'success' => true, 'account' => $selected['id'], 'record' => $body['data'] ?? $body ) );
	}

	public function add_ops_rentec_entity_note( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$note = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		if ( '' === $note ) {
			return new WP_Error( 'ajcore_rentec_note_required', __( 'Enter a note.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$kind = 'tenants' === $request->get_param( 'kind' ) ? 'tenants' : 'properties';
		$body = $this->request_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/' . $kind . '/' . absint( $request->get_param( 'id' ) ) . '/notes', $selected['account']['key'], 'POST', array( 'note' => $note ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $body['data'] ?? $body ) );
	}

	/** Uploads any document (max 20 MB) to Rentec, optionally attached to a property, tenant, owner, vendor, work order or transaction. */
	public function upload_ops_rentec_file( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'ajcore_rentec_file_required', __( 'Choose a file to upload.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > 20 * MB_IN_BYTES ) {
			return new WP_Error( 'ajcore_rentec_file_too_large', __( 'Files must be 20 MB or smaller.', 'ajcore-ra' ), array( 'status' => 413 ) );
		}
		$contents = file_get_contents( $file['tmp_name'] );
		if ( false === $contents ) {
			return new WP_Error( 'ajcore_rentec_file_read_failed', __( 'The uploaded file could not be read.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$type     = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$mime     = (string) ( $type['type'] ?? '' );
		$mime     = '' !== $mime ? $mime : 'application/octet-stream';
		$boundary = 'ajcore-' . wp_generate_password( 24, false, false );
		$eol      = "\r\n";
		$body     = '';
		foreach ( array( 'property_id', 'renter_id', 'owner_id', 'vendor_id', 'workorder_id', 'transaction_id' ) as $field ) {
			$id = absint( $request->get_param( $field ) );
			if ( $id ) {
				$body .= '--' . $boundary . $eol . 'Content-Disposition: form-data; name="' . $field . '"' . $eol . $eol . $id . $eol;
			}
		}
		if ( rest_sanitize_boolean( $request->get_param( 'share_owner' ) ) ) {
			$body .= '--' . $boundary . $eol . 'Content-Disposition: form-data; name="share_owner"' . $eol . $eol . 'true' . $eol;
		}
		$body .= '--' . $boundary . $eol . 'Content-Disposition: form-data; name="file"; filename="' . sanitize_file_name( $file['name'] ) . '"' . $eol . 'Content-Type: ' . $mime . $eol . $eol . $contents . $eol . '--' . $boundary . '--' . $eol;
		$response = wp_remote_post( 'https://secure.rentecdirect.com/api/v3/files', array(
			'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'multipart/form-data; boundary=' . $boundary, 'X-API-Key' => $selected['account']['key'] ),
			'body'    => $body,
			'timeout' => 45,
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ajcore_rentec_upload_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status        = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $response_body['message'] ) ? sanitize_text_field( (string) $response_body['message'] ) : sprintf( __( 'Rentec returned HTTP status %d.', 'ajcore-ra' ), $status );
			return new WP_Error( 'ajcore_rentec_upload_failed', $message, array( 'status' => 502 ) );
		}
		$this->refresh_rentec_cache_resources( array( 'files', 'work_orders' ), $selected['account']['key'] );
		return rest_ensure_response( is_array( $response_body ) ? $response_body : array( 'success' => true ) );
	}

	/** Sidebar badge counts, summed over the configured accounts. Cached; refresh=1 bypasses. */
	public function get_ops_rentec_counts( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$this->rentec_bypass_cache = rest_sanitize_boolean( $request->get_param( 'refresh' ) );
		$base   = 'https://secure.rentecdirect.com/api/v3/';
		$counts = array( 'work_orders' => 0, 'tenants' => 0, 'leads' => 0, 'applications' => 0, 'messages' => 0, 'properties' => 0, 'vendors' => 0, 'owners' => 0 );
		$errors = 0;
		foreach ( $selected['accounts'] as $account ) {
			if ( empty( $account['key'] ) ) {
				continue;
			}
			$key  = $account['key'];
			$get  = function ( $url, $ttl = 300 ) use ( $key ) {
				$body = $this->cached_rentec_for_ops( $url, $key, $ttl );
				return is_wp_error( $body ) ? null : (array) ( $body['data'] ?? array() );
			};
			$rows = $get( add_query_arg( 'age', '3650d', $base . 'work_orders' ) );
			if ( null === $rows ) {
				$errors++;
			} else {
				foreach ( $rows as $row ) {
					if ( in_array( (string) ( $row['status'] ?? '' ), array( 'OpenU', 'OpenA', 'Parts', 'Work', 'Unassigned', 'Assigned' ), true ) ) {
						$counts['work_orders']++;
					}
				}
			}
			$rows = $get( $base . 'tenants', 600 );
			if ( null !== $rows ) {
				foreach ( $rows as $row ) {
					if ( (float) ( $row['balance'] ?? 0 ) < 0 ) {
						$counts['tenants']++;
					}
				}
			}
			$rows = $get( $base . 'leads', 600 );
			$counts['leads'] += null === $rows ? 0 : count( $rows );
			$rows = $get( add_query_arg( 'status', 'Unread', $base . 'applications' ) );
			$counts['applications'] += null === $rows ? 0 : count( $rows );
			$rows = $get( add_query_arg( array( 'unread_only' => 'true', 'page_size' => 100 ), $base . 'messages' ) );
			$counts['messages'] += null === $rows ? 0 : count( $rows );
			$rows = $get( add_query_arg( 'include_subunits', 'true', $base . 'properties' ), 600 );
			$counts['properties'] += null === $rows ? 0 : count( $this->flatten_rentec_properties_for_ops( $rows ) );
			$rows = $get( $base . 'vendors', 600 );
			$counts['vendors'] += null === $rows ? 0 : count( $rows );
			$rows = $get( $base . 'owners', 600 );
			$counts['owners'] += null === $rows ? 0 : count( $rows );
		}
		return rest_ensure_response( array( 'counts' => $counts, 'partial' => $errors > 0 ) );
	}

	/** Raw rows exactly as Rentec returns them, so every available field is visible. No enrichment, no cache. */
	public function get_ops_rentec_diagnostics( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		// resource => id field of a row (for the single-record call GET /{resource}/{id}), '' = none.
		$resources = array(
			'work_orders'  => 'workorder_id',
			'tenants'      => 'renter_id',
			'leads'        => 'prospect_id',
			'vendors'      => 'vendor_id',
			'transactions' => '',
			'files'        => 'file_id',
			'messages'     => '',
			'leases'       => 'lease_id',
			'properties'   => 'property_id',
			'owners'       => 'owner_id',
			'applications' => 'application_id',
			'accounts'     => 'account_id',
			'products'     => 'product_id',
			'banks'        => 'bank_id',
		);
		$resource = sanitize_key( (string) $request->get_param( 'resource' ) );
		if ( ! isset( $resources[ $resource ] ) ) {
			return new WP_Error( 'ajcore_rentec_kind', __( 'Unknown Rentec resource.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$limit = max( 1, min( 5, absint( $request->get_param( 'limit' ) ) ?: 3 ) );
		$key   = $selected['account']['key'];
		$url   = 'https://secure.rentecdirect.com/api/v3/' . $resource;
		if ( 'work_orders' === $resource ) {
			$url = add_query_arg( 'age', '3650d', $url );
		} elseif ( 'messages' === $resource ) {
			$url = add_query_arg( 'page_size', $limit, $url );
		}
		$list = $this->request_rentec_for_ops( $url, $key );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$all  = (array) ( $list['data'] ?? array() );
		$rows = array_slice( $all, 0, $limit );

		$detail    = null;
		$detail_id = null;
		$detail_error = '';
		$id_field  = $resources[ $resource ];
		if ( '' !== $id_field && ! empty( $rows[0][ $id_field ] ) ) {
			$detail_id = absint( $rows[0][ $id_field ] );
		} elseif ( '' !== $id_field && ! empty( $rows[0]['id'] ) && preg_match( '/(\d+)$/', (string) $rows[0]['id'], $m ) ) {
			$detail_id = absint( $m[1] );
		}
		if ( $detail_id ) {
			$one = $this->request_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/' . $resource . '/' . $detail_id, $key );
			if ( is_wp_error( $one ) ) {
				$detail_error = $one->get_error_message();
			} else {
				$detail = $one['data'] ?? $one;
			}
		}

		$describe = static function ( $records ) {
			$fields = array();
			foreach ( (array) $records as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}
				foreach ( $record as $name => $value ) {
					$type = null === $value ? 'null' : ( is_array( $value ) ? ( array_keys( $value ) === range( 0, count( $value ) - 1 ) && $value ? 'list' : ( $value ? 'object' : 'empty list' ) ) : gettype( $value ) );
					if ( ! isset( $fields[ $name ] ) ) {
						$fields[ $name ] = array( 'name' => (string) $name, 'type' => $type, 'example' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
					} elseif ( 'null' === $fields[ $name ]['type'] && 'null' !== $type ) {
						$fields[ $name ]['type']    = $type;
						$fields[ $name ]['example'] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
					}
				}
			}
			return array_values( $fields );
		};

		$fields        = $describe( $rows );
		$detail_fields = is_array( $detail ) ? $describe( array( $detail ) ) : array();
		$list_names    = wp_list_pluck( $fields, 'name' );
		$only_detail   = array_values( array_diff( wp_list_pluck( $detail_fields, 'name' ), $list_names ) );

		return rest_ensure_response( array(
			'account'      => $selected['id'],
			'resource'     => $resource,
			'url'          => $url,
			'total'        => isset( $list['summary']['records'] ) ? absint( $list['summary']['records'] ) : count( $all ),
			'rows'         => $rows,
			'fields'       => $fields,
			'detail_id'    => $detail_id,
			'detail'       => $detail,
			'detail_fields' => $detail_fields,
			'only_in_detail' => $only_detail,
			'detail_error' => $detail_error,
			'calls'        => 1 + ( $detail_id ? 1 : 0 ),
		) );
	}

	/** Name search across tenants / owners / vendors / prospects for the message recipient picker. */
	public function get_ops_rentec_lookup( WP_REST_Request $request ) {
		$selected = $this->get_rentec_account_for_ops_request( $request );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$map  = array( 'tenant' => 'tenant', 'owner' => 'owner', 'vendor' => 'vendor', 'prospect' => 'prospect' );
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( ! isset( $map[ $type ] ) ) {
			return new WP_Error( 'ajcore_rentec_kind', __( 'Unknown lookup type.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		// Rentec's lookup takes exact fields, not free text: email, phone, or first/last name.
		$q    = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$args = array();
		if ( false !== strpos( $q, '@' ) ) {
			$args['email'] = $q;
		} elseif ( strlen( preg_replace( '/\D+/', '', $q ) ) >= 7 ) {
			$args['phone'] = preg_replace( '/\D+/', '', $q );
		} else {
			$parts = preg_split( '/\s+/', trim( $q ) );
			if ( count( $parts ) > 1 ) {
				$args['f_name'] = array_shift( $parts );
				$args['l_name'] = implode( ' ', $parts );
			} elseif ( '' !== $q ) {
				$args['l_name'] = $q;
			}
		}
		if ( ! $args ) {
			return new WP_Error( 'ajcore_rentec_lookup_required', __( 'Enter a name, email or phone.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$url  = add_query_arg( array_map( 'rawurlencode', $args ), 'https://secure.rentecdirect.com/api/v3/lookup/' . $map[ $type ] );
		$body = $this->request_rentec_for_ops( $url, $selected['account']['key'] );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return rest_ensure_response( array( 'account' => $selected['id'], 'rows' => array_slice( (array) ( $body['data'] ?? array() ), 0, 25 ) ) );
	}

	/** Adds _party ("Tenant: Jane Doe"), _party_type and _preview to message rows (one bulk call per party type present). */
	private function label_rentec_messages_for_ops( array $rows, $key ) {
		$sources = array(
			'renter_id'   => array( 'Tenant', 'tenants', 'renter_id' ),
			'vendor_id'   => array( 'Vendor', 'vendors', 'vendor_id' ),
			'owner_id'    => array( 'Owner', 'owners', 'owner_id' ),
			'prospect_id' => array( 'Lead', 'leads', 'prospect_id' ),
		);
		$names = array();
		foreach ( $sources as $field => $src ) {
			$needed = false;
			foreach ( $rows as $row ) {
				if ( ! empty( $row[ $field ] ) ) {
					$needed = true;
					break;
				}
			}
			if ( ! $needed ) {
				continue;
			}
			$body = $this->cached_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/' . $src[1], $key, 600 );
			if ( is_wp_error( $body ) ) {
				continue;
			}
			foreach ( (array) ( $body['data'] ?? array() ) as $person ) {
				if ( ! is_array( $person ) || empty( $person[ $src[2] ] ) ) {
					continue;
				}
				$name = trim( (string) ( $person['f_name'] ?? '' ) . ' ' . (string) ( $person['l_name'] ?? '' ) );
				$name = '' !== $name ? $name : (string) ( $person['name'] ?? $person['company'] ?? $person['contact'] ?? '' );
				$names[ $field ][ absint( $person[ $src[2] ] ) ] = sanitize_text_field( $name );
			}
		}
		foreach ( $rows as &$row ) {
			$row['_party'] = '';
			$row['_party_type'] = '';
			foreach ( $sources as $field => $src ) {
				if ( ! empty( $row[ $field ] ) ) {
					$row['_party_type'] = $src[0];
					$row['_party']      = $names[ $field ][ absint( $row[ $field ] ) ] ?? ( $src[0] . ' #' . absint( $row[ $field ] ) );
					break;
				}
			}
			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $row['body'] ?? '' ) ) ) );
			$row['_preview'] = mb_substr( $text, 0, 160 );
			$row['_direction'] = 'OUT' === ( $row['direction'] ?? '' ) ? 'Sent' : ( 'IN' === ( $row['direction'] ?? '' ) ? 'Received' : (string) ( $row['direction'] ?? '' ) );
		}
		unset( $row );
		usort( $rows, function ( $a, $b ) {
			return strcmp( (string) ( $b['email_time'] ?? '' ), (string) ( $a['email_time'] ?? '' ) );
		} );
		return $rows;
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
		$allowed_resources = array( 'tenants', 'leads', 'vendors', 'transactions', 'work_orders', 'files', 'messages', 'leases', 'properties', 'owners', 'accounts', 'products', 'applications' );
		$resource          = sanitize_key( (string) $request->get_param( 'resource' ) );
		if ( ! in_array( $resource, $allowed_resources, true ) ) {
			$resource = 'work_orders';
		}
		$account  = $selected['id'];
		$accounts = $selected['accounts'];

		$url = $this->rentec_list_url( $resource );
		if ( 'messages' === $resource && rest_sanitize_boolean( $request->get_param( 'unread_only' ) ) ) {
			$url = add_query_arg( 'unread_only', 'true', $url );
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

		$this->rentec_bypass_cache = rest_sanitize_boolean( $request->get_param( 'refresh' ) );
		$body = $this->cached_rentec_for_ops( $url, $accounts[ $account ]['key'], 120 );
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
			$property_body = $this->cached_rentec_for_ops( add_query_arg( 'include_subunits', 'true', 'https://secure.rentecdirect.com/api/v3/properties' ), $accounts[ $account ]['key'], 600 );
			$vendor_body   = $this->cached_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/vendors', $accounts[ $account ]['key'], 600 );
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
		if ( 'properties' === $resource ) {
			$rows = $this->flatten_rentec_properties_for_ops( $rows );
			foreach ( $rows as &$row ) {
				$city = implode( ', ', array_filter( array_map( 'strval', array( $row['city'] ?? '', $row['state'] ?? '', $row['zip'] ?? '' ) ) ) );
				$row['_address'] = implode( ', ', array_filter( array_map( 'strval', array( $row['address'] ?? '', $city ) ) ) );
			}
			unset( $row );
		}
		if ( 'messages' === $resource ) {
			$rows = $this->label_rentec_messages_for_ops( $rows, $accounts[ $account ]['key'] );
		}
		if ( 'leases' === $resource ) {
			// Lease rows carry only renter_id/property_id. Resolve both in two bulk calls (same
			// approach as work_orders above) so the tab can show who and where without an N+1.
			$tenant_body   = $this->cached_rentec_for_ops( 'https://secure.rentecdirect.com/api/v3/tenants', $accounts[ $account ]['key'], 600 );
			$property_body = $this->cached_rentec_for_ops( add_query_arg( 'include_subunits', 'true', 'https://secure.rentecdirect.com/api/v3/properties' ), $accounts[ $account ]['key'], 600 );
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
		// Rentec cannot page its lists, so the full list is fetched once (and cached) and paged here.
		$per_page = min( 200, absint( $request->get_param( 'per_page' ) ) );
		$page_no  = max( 1, absint( $request->get_param( 'page' ) ) );
		if ( 'messages' === $resource ) {
			$total = count( $rows );
		}

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
				'rows'     => $per_page > 0 ? array_slice( $rows, ( $page_no - 1 ) * $per_page, $per_page ) : array_slice( $rows, 0, 1000 ),
				'limit'    => $per_page > 0 ? $per_page : 1000,
				'page'     => $page_no,
				'per_page' => $per_page,
				'pages'    => $per_page > 0 ? max( 1, (int) ceil( count( $rows ) / $per_page ) ) : 1,
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
		$this->refresh_rentec_cache_resources( array( 'files', 'work_orders' ), $selected['account']['key'] );
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
	/**
	 * The Rentec workspace. Built in the browser from the shared screen definition (GET /ops/rentec/schema)
	 * by assets/rentec-workspace.js, so WP admin shows the same screens as AJOps.
	 */
	public function render_workspace() {
		$settings = $this->get_settings();
		if ( '1' !== (string) ( $settings['rentec_enabled'] ?? '0' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Rentec Direct integration is disabled. Enable it and save the API keys above.', 'ajcore-ra' ) . '</p></div>';
			return;
		}
		$file = dirname( __DIR__ ) . '/assets/rentec-workspace.js';
		?>
		<style>
			#ajra-rentec-app{margin-top:6px}
			.ajra-sync{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 10px;padding:6px 12px;background:#fff;border:1px solid #dcdcde;border-radius:6px;color:#50575e}
			.ajra-nav{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}
			.ajra-tab{border:1px solid #c3c4c7;background:#fff;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:13px}
			.ajra-tab.is-active{background:#2271b1;border-color:#2271b1;color:#fff}
			.ajra-badge{display:inline-block;margin-left:6px;background:#dcdcde;color:#1d2327;border-radius:10px;padding:0 7px;font-size:11px;font-weight:600}
			.ajra-tab.is-active .ajra-badge{background:rgba(255,255,255,.28);color:#fff}
			.ajra-filters{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center;margin:0 0 12px;padding:8px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px}
			.ajra-label{font-weight:600}
			.ajra-head{display:flex;gap:10px;align-items:center;margin:8px 0}
			.ajra-head h2{margin:0}
			.ajra-head .button-primary{margin-left:auto}
			.ajra-table th{cursor:pointer;white-space:nowrap}
			.ajra-table td{max-width:320px;vertical-align:top}
			.ajra-click{cursor:pointer}
			.ajra-expanded td{white-space:pre-wrap;background:#f6f7f7}
			.ajra-pager{display:flex;gap:8px;align-items:center;justify-content:space-between;margin-top:10px}
			.ajra-dialog{width:min(760px,calc(100vw - 32px));border:0;border-radius:12px;padding:0;box-shadow:0 24px 70px rgba(15,23,42,.3)}
			.ajra-dialog::backdrop{background:rgba(0,0,0,.4)}
			.ajra-dialog-in{padding:20px 24px;max-height:85vh;overflow:auto}
			.ajra-dialog-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}
			.ajra-dialog-head h2{margin:0}
			.ajra-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px}
			.ajra-grid label{display:flex;flex-direction:column;gap:3px;font-size:12px;font-weight:600;text-transform:uppercase;color:#50575e}
			.ajra-grid label.check{flex-direction:row;align-items:center;text-transform:none;font-weight:400;font-size:13px;padding-top:18px}
			.ajra-grid .wide{grid-column:1 / -1}
			.ajra-grid input,.ajra-grid select,.ajra-grid textarea{width:100%;text-transform:none;font-weight:400}
			.ajra-actions{text-align:right;margin-top:14px}
			.ajra-dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin:10px 0}
			.ajra-dl dt{font-size:11px;font-weight:600;text-transform:uppercase;color:#787c82}
			.ajra-dl dd{margin:2px 0 0;word-break:break-word}
			.ajra-note{border-left:3px solid #dcdcde;padding:2px 10px;margin:6px 0}
			.ajra-file{display:flex;justify-content:space-between;align-items:center;border:1px solid #dcdcde;border-radius:6px;padding:6px 10px;margin:4px 0}
			@media (max-width:700px){.ajra-grid{grid-template-columns:1fr}}
		</style>
		<div id="ajra-rentec-app"><p><?php esc_html_e( 'Loading…', 'ajcore-ra' ); ?></p></div>
		<script>window.AJRA_RENTEC = <?php echo wp_json_encode( array( 'base' => rest_url( AJCore_Extensions::rest_namespace() . '/ops/rentec' ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
		<script src="<?php echo esc_url( plugin_dir_url( dirname( __DIR__ ) . '/ajcore-ra.php' ) . 'assets/rentec-workspace.js?ver=' . (int) @filemtime( $file ) ); ?>"></script>
		<?php
	}
}
