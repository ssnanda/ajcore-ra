<?php
/**
 * E-Signatures (BreezeDoc), moved out of AJCore.
 *
 * Same /ajcore/v1 routes AJOps already uses (/ops/esign/*) plus their WP-admin siblings (/esign/*),
 * registered here while AJCore-RA is active. Same log table (aj_esign_documents, defined in AJCore's
 * activator and created on demand), same row shape.
 *
 * Two BreezeDoc credentials (https://breezedoc.com/developer/docs/):
 *  - Team API key (`bd_live_…`) → /api/v1/*: templates, create+send (idempotent), PDF upload with
 *    placed fields, remind, cancel, signed-PDF download, audit events, signed webhooks. Preferred.
 *  - Personal Access Token → legacy /api/*. Kept only for documents sent before the Team key existed.
 * Credentials are stored encrypted in the `ajcore_ra_esign` option. A Personal token previously saved
 * in AJCore's settings (`ajforms_settings[breezedoc_api_token]`) is copied here once on first load.
 *
 * UI: Client Portal admin tab "E-Signatures" (replaces AJCore's old tab + Settings section).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Esign {

	const OPTION     = 'ajcore_ra_esign';
	const TAB        = 'ra-esign';
	const WEBHOOK_OPTION = 'ajcore_breezedoc_webhook';
	const SHARED_ROW     = 'ajcore_breezedoc_settings'; // shared-DB row: master publishes, other sites read
	const IGNORED_ROW    = 'ajcore_breezedoc_ignored';  // BreezeDoc ids deleted from the local log (imports skip them)
	const CRON_HOOK      = 'ajcore_ra_esign_auto_import';
	const TPL_OPTION     = 'ajcore_ra_esign_templates'; // AJCore-native templates (PDF + signer roles + signature page)
	const SIGNED_DONE_ROW = 'ajcore_breezedoc_signed_done'; // documents whose signed copy was already emailed / filed

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
		self::migrate_legacy_token();
		add_action( 'rest_api_init', array( __CLASS__, 'boot_routes' ) );
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog' ) );
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ), 9 );
		add_action( 'ajcore_admin_portal_tab_render', array( __CLASS__, 'render_admin_tab' ) );
		add_action( 'ajcore_portal_dashboard_metrics', array( __CLASS__, 'dashboard_metric' ) );
		add_action( 'admin_post_ajcore_ra_save_esign', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_publish_shared' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_auto_import' ) );
		add_action( 'init', array( __CLASS__, 'sync_schedule' ) );
		add_filter( 'ajcore_additional_branded_email_templates', array( __CLASS__, 'add_templates' ) );
		add_filter( 'ajcore_preserved_setting_prefixes', array( __CLASS__, 'preserved_prefixes' ) );
	}

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public function __construct() {
		$this->kit = class_exists( 'AJCore_Extensions' ) ? AJCore_Extensions::rest_toolkit() : array();
	}

	// ── settings ────────────────────────────────────────────────────────────────

	private static function encrypt( $value ) {
		return function_exists( 'ajcore_encrypt_setting_value' ) ? ajcore_encrypt_setting_value( (string) $value ) : (string) $value;
	}

	private static function is_shared() {
		return function_exists( 'ajcore_is_shared_db_enabled' ) && ajcore_is_shared_db_enabled();
	}

	private static function is_master() {
		return ! function_exists( 'ajcore_is_stripe_sync_owner' ) || ajcore_is_stripe_sync_owner();
	}

	/** True on a secondary site of a shared-DB network: credentials are managed by the master. */
	private function read_only() {
		return self::is_shared() && ! self::is_master();
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

	/** @return array<string,string> decrypted team_api_key / api_token published by the master. */
	private static function read_shared() {
		list( $db, $table ) = self::shared_db_and_table();
		if ( ! $db ) {
			return array();
		}
		$value   = $db->get_var( $db->prepare( "SELECT setting_value FROM `{$table}` WHERE setting_name = %s LIMIT 1", self::SHARED_ROW ) );
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Master only: publishes the plain credentials (same convention as the shared Rentec row). */
	private function write_shared() {
		if ( ! self::is_shared() || ! self::is_master() ) {
			return false;
		}
		list( $db, $table ) = self::shared_db_and_table();
		if ( ! $db ) {
			return false;
		}
		$encoded = wp_json_encode( array( 'team_api_key' => $this->local_setting( 'team_api_key' ), 'api_token' => $this->local_setting( 'api_token' ) ) );
		if ( false === $encoded ) {
			return false;
		}
		$exists = $db->get_var( $db->prepare( "SELECT setting_name FROM `{$table}` WHERE setting_name = %s LIMIT 1", self::SHARED_ROW ) );
		if ( $exists ) {
			return false !== $db->update( $table, array( 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ), array( 'setting_name' => self::SHARED_ROW ), array( '%s', '%s' ), array( '%s' ) );
		}
		return false !== $db->insert( $table, array( 'setting_name' => self::SHARED_ROW, 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ), array( '%s', '%s', '%s' ) );
	}

	private function local_setting( $key ) {
		$opt   = get_option( self::OPTION, array() );
		$value = is_array( $opt ) && isset( $opt[ $key ] ) ? (string) $opt[ $key ] : '';
		return trim( function_exists( 'ajcore_decrypt_setting_value' ) ? (string) ajcore_decrypt_setting_value( $value ) : $value );
	}

	/** Credential value; on a secondary shared-DB site the master's published value wins. */
	private function setting( $key ) {
		if ( $this->read_only() ) {
			$shared = self::read_shared();
			return isset( $shared[ $key ] ) ? trim( (string) $shared[ $key ] ) : '';
		}
		return $this->local_setting( $key );
	}

	/** One-time copy of the Personal token AJCore used to keep in ajforms_settings. */
	private static function migrate_legacy_token() {
		$opt = get_option( self::OPTION, null );
		if ( is_array( $opt ) && ! empty( $opt['migrated'] ) ) {
			return;
		}
		$opt = is_array( $opt ) ? $opt : array();
		if ( empty( $opt['api_token'] ) && function_exists( 'ajforms_get_settings' ) ) {
			$legacy = ajforms_get_settings();
			$token  = trim( (string) ( $legacy['breezedoc_api_token'] ?? '' ) );
			if ( '' !== $token ) {
				$opt['api_token'] = self::encrypt( $token );
			}
		}
		$opt['migrated'] = 1;
		update_option( self::OPTION, $opt, false );
		self::instance()->write_shared();
	}

	/** Master with the shared DB on: publish credentials saved before sharing existed, so other sites see them. */
	public static function maybe_publish_shared() {
		if ( ! self::is_shared() || ! self::is_master() ) {
			return;
		}
		$esign = self::instance();
		if ( ( '' !== $esign->local_setting( 'team_api_key' ) || '' !== $esign->local_setting( 'api_token' ) ) && empty( self::read_shared() ) ) {
			$esign->write_shared();
		}
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_save_esign' );
		if ( self::instance()->read_only() ) {
			wp_die( esc_html__( 'E-Signature credentials are managed by the AJCore master site.', 'ajcore-ra' ) );
		}
		$opt = get_option( self::OPTION, array() );
		$opt = is_array( $opt ) ? $opt : array();
		// Secret inputs are never re-rendered: blank keeps the saved value.
		foreach ( array( 'team_api_key' => 'breezedoc_team_api_key', 'api_token' => 'breezedoc_api_token' ) as $key => $field ) {
			$posted = isset( $_POST[ $field ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ) : '';
			if ( '' !== $posted ) {
				$opt[ $key ] = self::encrypt( $posted );
			}
		}
		if ( ! empty( $_POST['clear_api_token'] ) ) {
			unset( $opt['api_token'] );
		}
		if ( isset( $_POST['esign_email_form'] ) ) { // checkboxes are absent when unticked
			$opt['notify_on_send'] = ! empty( $_POST['esign_notify_on_send'] ) ? 1 : 0;
			$opt['file_signed']    = ! empty( $_POST['esign_file_signed'] ) ? 1 : 0;
			$mode                  = isset( $_POST['esign_signed_copy'] ) ? sanitize_key( wp_unslash( $_POST['esign_signed_copy'] ) ) : 'off';
			$opt['signed_copy']    = in_array( $mode, array( 'off', 'customer', 'all' ), true ) ? $mode : 'off';
		}
		if ( isset( $_POST['esign_auto_import'] ) ) {
			$freq             = sanitize_key( wp_unslash( $_POST['esign_auto_import'] ) );
			$new_freq         = in_array( $freq, array( 'off', 'hourly', 'twicedaily', 'daily' ), true ) ? $freq : 'off';
			if ( 'off' !== $new_freq && 'off' === ( $opt['auto_import'] ?? 'off' ) ) {
				$opt['auto_since'] = time() - 2 * DAY_IN_SECONDS;
			}
			$opt['auto_import'] = $new_freq;
		}
		$opt['migrated'] = 1;
		update_option( self::OPTION, $opt, false );
		self::instance()->write_shared();
		self::sync_schedule();
		wp_safe_redirect( self::tab_url( array( 'saved' => 1 ) ) );
		exit;
	}

	public static function tab_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB ), $args ), admin_url( 'admin.php' ) );
	}

	// ── portal DB helpers ───────────────────────────────────────────────────────

	private function get_pdb() {
		return $this->kit['portal_db']();
	}

	private function get_esign_documents_table() {
		return $this->kit['portal_table']( 'aj_esign_documents' );
	}

	private function ensure_esign_documents_table() {
		$db    = $this->get_pdb();
		$table = $this->get_esign_documents_table();
		if ( $this->kit['table_exists']( $db, $table ) ) {
			return true;
		}
		if ( ! class_exists( 'AJForms_Activator' ) && defined( 'AJFORMS_PLUGIN_DIR' ) ) {
			require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
		}
		if ( class_exists( 'AJForms_Activator' ) && method_exists( 'AJForms_Activator', 'create_esign_documents_table_in_portal_db' ) ) {
			AJForms_Activator::create_esign_documents_table_in_portal_db( $db->prefix, $db->get_charset_collate(), $db );
		}
		return $this->kit['table_exists']( $db, $table );
	}

	private function find_portal_customer_by_stripe_id( $stripe_customer_id ) {
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$pdb                = $this->get_pdb();
		if ( 0 === strpos( $stripe_customer_id, 'local_' ) ) {
			$local_table = $this->kit['portal_table']( 'aj_portal_local_customers' );
			return $pdb->get_row( $pdb->prepare( "SELECT local_customer_id AS stripe_customer_id, name, customer_number, email FROM `{$local_table}` WHERE local_customer_id = %s", $stripe_customer_id ) );
		}
		$table = $this->kit['portal_table']( 'aj_portal_stripe_customers' );
		return $pdb->get_row( $pdb->prepare( "SELECT stripe_customer_id, name, customer_number, email FROM `{$table}` WHERE stripe_customer_id = %s", $stripe_customer_id ) );
	}

	private function customers() {
		$pdb   = $this->get_pdb();
		$out   = array();
		$stripe = $this->kit['portal_table']( 'aj_portal_stripe_customers' );
		$local  = $this->kit['portal_table']( 'aj_portal_local_customers' );
		if ( $this->kit['table_exists']( $pdb, $stripe ) ) {
			$out = array_merge( $out, (array) $pdb->get_results( "SELECT stripe_customer_id AS id, name, email FROM `{$stripe}` ORDER BY name ASC" ) );
		}
		if ( $this->kit['table_exists']( $pdb, $local ) ) {
			$out = array_merge( $out, (array) $pdb->get_results( "SELECT local_customer_id AS id, name, email FROM `{$local}` ORDER BY name ASC" ) );
		}
		return array_map( static function ( $c ) {
			return array( 'id' => (string) $c->id, 'name' => (string) $c->name, 'email' => (string) $c->email );
		}, $out );
	}

	private function format_esign_document_row( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'                   => (int) $row->id,
			'breezedocDocumentId'  => (string) $row->breezedoc_document_id,
			'breezedocTemplateId'  => (string) $row->breezedoc_template_id,
			'templateTitle'        => (string) $row->template_title,
			'stripeCustomerId'     => (string) $row->stripe_customer_id,
			'customerName'         => (string) $row->customer_name,
			'status'               => (string) $row->status,
			'recipients'           => $row->recipients ? json_decode( (string) $row->recipients, true ) : array(),
			'documentUrl'          => (string) $row->document_url,
			'sentAt'               => (string) $row->sent_at,
			'completedAt'          => (string) $row->completed_at,
			'lastCheckedAt'        => (string) $row->last_checked_at,
			'createdAt'            => (string) $row->created_at,
			'updatedAt'            => (string) $row->updated_at,
		);
	}

	public function get_esign_documents_list() {
		if ( ! $this->ensure_esign_documents_table() ) {
			return array( 'documents' => array(), 'deleted' => array(), 'stats' => array( 'total' => 0, 'sent' => 0, 'completed' => 0 ) );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_esign_documents_table();
		// One-time removal of the earlier hidden "skip list" — Delete now just moves rows to Deleted.
		if ( ! $this->opt( 'skiplist_cleared', 0 ) ) {
			$this->clear_ids( self::IGNORED_ROW );
			delete_option( self::IGNORED_ROW );
			$o                     = get_option( self::OPTION, array() );
			$o                     = is_array( $o ) ? $o : array();
			$o['skiplist_cleared'] = 1;
			update_option( self::OPTION, $o, false );
		}
		$rows = $pdb->get_results( "SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT 800" );

		$documents = array();
		$deleted   = array();
		$stats     = array( 'total' => 0, 'sent' => 0, 'completed' => 0 );
		foreach ( $rows as $row ) {
			if ( 0 === strpos( (string) $row->status, 'deleted:' ) ) {
				$deleted[] = $this->format_esign_document_row( $row );
				continue;
			}
			$documents[] = $this->format_esign_document_row( $row );
			$stats['total']++;
			'completed' === $row->status ? $stats['completed']++ : $stats['sent']++;
		}
		return array( 'documents' => $documents, 'deleted' => $deleted, 'stats' => $stats );
	}

	// ── BreezeDoc API ───────────────────────────────────────────────────────────

	/**
	 * BreezeDoc has two credential types (https://breezedoc.com/developer/docs/):
	 *  - Team API key (`bd_live_…`, team-owned, read/write scopes) → /api/v1/* — preferred. Prefixed
	 *    string IDs (tpl_/doc_/rcp_/evt_), idempotency keys, remind/cancel/download/events, webhooks,
	 *    PDF upload.
	 *  - Personal Access Token → legacy /api/* (integer IDs, no webhooks). Kept only so documents
	 *    sent before the Team key was configured can still be refreshed.
	 * Any path starting with /v1 uses the Team key; every other path uses the Personal token.
	 * Base URL is https://breezedoc.com/api. Both are limited to 60 requests/minute.
	 */
	private function breezedoc_credential_for_path( $path ) {
		if ( 0 === strpos( $path, '/v1' ) ) {
			return array( 'v1', $this->setting( 'team_api_key' ) );
		}
		return array( 'legacy', $this->setting( 'api_token' ) );
	}

	/** True when a Team API key is saved (the v1 code paths are used for everything new). */
	public function breezedoc_is_v1() {
		return '' !== $this->setting( 'team_api_key' );
	}

	public function breezedoc_is_connected() {
		return '' !== $this->setting( 'team_api_key' ) || '' !== $this->setting( 'api_token' );
	}

	/** Flattens nested arrays into Laravel-style bracket fields (recipients[0][name]) for multipart bodies. */
	private function breezedoc_flatten_fields( $data, $prefix = '' ) {
		$out = array();
		foreach ( (array) $data as $key => $value ) {
			$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';
			if ( is_array( $value ) ) {
				$out = array_merge( $out, $this->breezedoc_flatten_fields( $value, $name ) );
			} else {
				$out[ $name ] = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
			}
		}
		return $out;
	}

	/**
	 * $args: query (array), body (array → JSON), multipart (array( 'fields' => nested array,
	 * 'file' => array( 'name', 'filename', 'content', 'type' ) )), idempotency_key (string),
	 * timeout (int).
	 */
	private function breezedoc_api_request( $method, $path, $args = array() ) {
		list( $mode, $token ) = $this->breezedoc_credential_for_path( $path );
		if ( '' === $token ) {
			return new WP_Error(
				'breezedoc_not_configured',
				'v1' === $mode
					? __( 'BreezeDoc is not connected. Add a Team API key in E-Signatures settings.', 'ajcore-ra' )
					: __( 'This signature request was sent with a Personal Access Token, which is no longer saved. Add it back in E-Signatures settings to refresh it.', 'ajcore-ra' )
			);
		}

		$url = 'https://breezedoc.com/api' . $path;
		if ( ! empty( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}
		$request_args = array(
			'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : 20,
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( ! empty( $args['idempotency_key'] ) ) {
			$request_args['headers']['Idempotency-Key'] = substr( (string) $args['idempotency_key'], 0, 255 );
		}
		if ( isset( $args['multipart'] ) ) {
			$boundary = 'ajcore' . wp_generate_password( 24, false );
			$body     = '';
			foreach ( $this->breezedoc_flatten_fields( $args['multipart']['fields'] ?? array() ) as $name => $value ) {
				$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . str_replace( array( '"', "\r", "\n" ), '', $name ) . "\"\r\n\r\n{$value}\r\n";
			}
			if ( ! empty( $args['multipart']['file'] ) ) {
				$file  = $args['multipart']['file'];
				$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . $file['name'] . '"; filename="' . str_replace( array( '"', "\r", "\n" ), '', (string) $file['filename'] ) . "\"\r\nContent-Type: " . $file['type'] . "\r\n\r\n" . $file['content'] . "\r\n";
			}
			$body .= "--{$boundary}--\r\n";
			$request_args['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			$request_args['body']                    = $body;
		} elseif ( isset( $args['body'] ) ) {
			$request_args['headers']['Content-Type'] = 'application/json';
			$request_args['body']                    = wp_json_encode( $args['body'] );
		}

		$response = wp_remote_request( $url, $request_args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code >= 400 ) {
			// v1 errors are {error:{code,message}}; legacy errors are {message}.
			$api_code = is_array( $data ) && ! empty( $data['error']['code'] ) ? (string) $data['error']['code'] : '';
			$message  = '';
			if ( is_array( $data ) && ! empty( $data['error']['message'] ) ) {
				$message = (string) $data['error']['message'];
			} elseif ( is_array( $data ) && ! empty( $data['message'] ) ) {
				$message = (string) $data['message'];
			}
			if ( 429 === $status_code ) {
				$message = __( 'BreezeDoc rate limit reached (60 requests/minute). Try again in a minute.', 'ajcore-ra' );
			} elseif ( '' === $message ) {
				/* translators: %d: HTTP status code */
				$message = sprintf( __( 'BreezeDoc API returned an error (%d).', 'ajcore-ra' ), $status_code );
			}
			return new WP_Error( 'breezedoc_api_error', $message, array( 'status' => $status_code, 'api_code' => $api_code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	public function breezedoc_test_connection() {
		if ( $this->breezedoc_is_v1() ) {
			$me = $this->breezedoc_api_request( 'GET', '/v1/me' );
			if ( is_wp_error( $me ) ) {
				return $me;
			}
			$label = '';
			foreach ( array( array( 'team', 'name' ), array( 'owner', 'email' ), array( 'email' ) ) as $path ) {
				$value = $me;
				foreach ( $path as $key ) {
					$value = is_array( $value ) && isset( $value[ $key ] ) ? $value[ $key ] : null;
				}
				if ( is_string( $value ) && '' !== $value ) {
					$label = $value;
					break;
				}
			}
			return array( 'connected' => true, 'email' => $label ?: __( 'Team API key', 'ajcore-ra' ), 'mode' => 'team', 'details' => $me );
		}
		$me = $this->breezedoc_api_request( 'GET', '/me' );
		if ( is_wp_error( $me ) ) {
			return $me;
		}
		return array( 'connected' => true, 'email' => isset( $me['email'] ) ? (string) $me['email'] : '', 'mode' => 'personal' );
	}

	/** Normalizes a BreezeDoc Recipient array into the shape both frontends expect. */
	private function breezedoc_map_recipients( $raw_recipients ) {
		$recipients = array();
		foreach ( (array) $raw_recipients as $i => $r ) {
			if ( ! is_array( $r ) ) {
				$recipients[] = array( 'party' => (int) $r, 'name' => '', 'email' => '', 'completedAt' => '', 'viewedAt' => '' );
				continue;
			}
			$recipients[] = array(
				'party'       => (int) ( $r['party'] ?? $r['number'] ?? $r['index'] ?? ( $i + 1 ) ),
				'name'        => isset( $r['name'] ) ? (string) $r['name'] : '',
				'email'       => isset( $r['email'] ) ? (string) $r['email'] : '',
				'completedAt' => (string) ( $r['completed_at'] ?? $r['signed_at'] ?? '' ),
				'viewedAt'    => (string) ( $r['opened_at'] ?? $r['viewed_at'] ?? '' ),
			);
		}
		usort( $recipients, static function ( $a, $b ) {
			return $a['party'] <=> $b['party'];
		} );
		return $recipients;
	}

	/** Compact field definitions (type, party, page, label) from a template's raw `fields`. */
	private function breezedoc_map_fields( $raw_fields ) {
		$out = array();
		foreach ( (array) $raw_fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$out[] = array(
				'type'  => (string) ( $f['type'] ?? $f['field_type'] ?? '' ),
				'party' => (int) ( $f['party'] ?? $f['recipient_party'] ?? ( $f['recipient_field']['party'] ?? 0 ) ),
				'page'  => (int) ( $f['page'] ?? $f['page_number'] ?? 0 ),
				'label' => (string) ( $f['label'] ?? $f['name'] ?? $f['placeholder'] ?? '' ),
			);
		}
		return $out;
	}

	private function breezedoc_map_template( $raw ) {
		return array(
			'fields'     => $this->breezedoc_map_fields( $raw['fields'] ?? array() ),
			'id'         => isset( $raw['id'] ) ? (string) $raw['id'] : '',
			'title'      => isset( $raw['title'] ) ? (string) $raw['title'] : ( isset( $raw['name'] ) ? (string) $raw['name'] : '' ),
			'recipients' => $this->breezedoc_map_recipients( $raw['parties'] ?? $raw['recipients'] ?? array() ),
		);
	}

	private function list_templates_source( $v1 ) {
		if ( ! $v1 ) {
			$result = $this->breezedoc_api_request( 'GET', '/templates' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array_map( array( $this, 'breezedoc_map_template' ), (array) ( $result['data'] ?? array() ) );
		}

		$templates = array();
		$cursor    = '';
		for ( $page = 0; $page < 5; $page++ ) {
			$query  = array( 'limit' => 100 );
			if ( '' !== $cursor ) {
				$query['cursor'] = $cursor;
			}
			$result = $this->breezedoc_api_request( 'GET', '/v1/templates', array( 'query' => $query ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( (array) ( $result['data'] ?? array() ) as $raw ) {
				$templates[] = $this->breezedoc_map_template( $raw );
			}
			$cursor = isset( $result['next_cursor'] ) ? (string) $result['next_cursor'] : '';
			if ( '' === $cursor ) {
				break;
			}
		}

		// The list may omit numbered parties; fetch them per template (cached, capped for the
		// 60 req/min limit) so the send form can render one row per signer.
		$detail_calls = 0;
		foreach ( $templates as $i => $template ) {
			if ( ! empty( $template['recipients'] ) || '' === $template['id'] ) {
				continue;
			}
			$cache_key = 'ajcore_bd_tpl_' . md5( $template['id'] );
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				$templates[ $i ] = $cached;
				continue;
			}
			if ( $detail_calls >= 25 ) {
				continue;
			}
			$detail_calls++;
			$detail = $this->breezedoc_get_template( $template['id'] );
			if ( ! is_wp_error( $detail ) ) {
				$templates[ $i ] = $detail;
			}
		}
		return $templates;
	}

	/** Templates from every saved credential: team (tpl_ ids) and personal (numeric ids), labelled when both exist. */
	/** Templates are AJCore's own; BreezeDoc is only the signing engine (its templates can't be previewed or edited through the API). */
	public function breezedoc_list_templates() {
		$all = array();
		foreach ( $this->local_templates() as $meta ) {
			$all[] = $this->local_template_shape( $meta );
		}
		return $all;
	}

	public function breezedoc_get_template( $template_id ) {
		$template_id = sanitize_text_field( (string) $template_id );
		if ( 0 === strpos( $template_id, 'ajt_' ) ) {
			$all = $this->local_templates();
			return isset( $all[ $template_id ] ) ? $this->local_template_shape( $all[ $template_id ] ) : new WP_Error( 'not_found', __( 'Form not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		if ( 0 === strpos( $template_id, 'tpl_' ) ) {
			$result = $this->breezedoc_api_request( 'GET', '/v1/templates/' . rawurlencode( $template_id ) );
		} else {
			$result = $this->breezedoc_api_request( 'GET', '/templates/' . absint( $template_id ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$template = $this->breezedoc_map_template( $result );
		if ( '' === $template['id'] ) {
			$template['id'] = $template_id;
		}
		set_transient( 'ajcore_bd_tpl_' . md5( $template_id ), $template, 10 * MINUTE_IN_SECONDS );
		return $template;
	}

	/** Sanitizes + validates the recipient list shared by every send path; returns array|WP_Error. */
	private function breezedoc_clean_recipients( $recipients ) {
		$clean = array();
		foreach ( array_values( (array) $recipients ) as $i => $r ) {
			$clean[] = array(
				'name'  => sanitize_text_field( (string) ( $r['name'] ?? '' ) ),
				'email' => sanitize_email( (string) ( $r['email'] ?? '' ) ),
				'party' => ! empty( $r['party'] ) ? (int) $r['party'] : $i + 1,
			);
		}
		usort( $clean, static function ( $a, $b ) {
			return $a['party'] <=> $b['party'];
		} );
		if ( empty( $clean ) ) {
			return new WP_Error( 'invalid_recipient', __( 'Add at least one recipient.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		foreach ( $clean as $r ) {
			if ( '' === $r['name'] || ! is_email( $r['email'] ) ) {
				return new WP_Error( 'invalid_recipient', __( 'Every recipient needs a name and a valid email address.', 'ajcore-ra' ), array( 'status' => 400 ) );
			}
		}
		return $clean;
	}

	/** Extracts the fields we store from a v1 document response (tolerant of key naming). */
	private function breezedoc_v1_doc_state( $doc ) {
		$status = strtolower( (string) ( $doc['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'draft', 'sent', 'completed', 'cancelled', 'expired' ), true ) ) {
			$status = ! empty( $doc['completed_at'] ) ? 'completed' : 'sent';
		}
		$completed = ! empty( $doc['completed_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $doc['completed_at'] ) ) : '';
		$url       = (string) ( $doc['url'] ?? $doc['document_url'] ?? '' );
		if ( '' === $url && ! empty( $doc['slug'] ) ) {
			$url = 'https://breezedoc.com/documents/' . $doc['slug'];
		}
		return array(
			'status'       => $status,
			'completed_at' => $completed,
			'url'          => $url,
			'recipients'   => $this->breezedoc_map_recipients( $doc['recipients'] ?? array() ),
			'title'        => (string) ( $doc['title'] ?? '' ),
		);
	}

	/** Same shape as breezedoc_v1_doc_state() for a legacy (Personal token) document. */
	private function breezedoc_legacy_doc_state( $doc ) {
		$completed = ! empty( $doc['completed_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $doc['completed_at'] ) ) : '';
		$was_sent  = false;
		foreach ( (array) ( $doc['recipients'] ?? array() ) as $r ) {
			if ( ! empty( $r['sent_at'] ) ) {
				$was_sent = true;
				break;
			}
		}
		return array(
			'status'       => '' !== $completed ? 'completed' : ( $was_sent ? 'sent' : 'draft' ),
			'completed_at' => $completed,
			'url'          => ! empty( $doc['slug'] ) ? 'https://breezedoc.com/documents/' . $doc['slug'] : '',
			'recipients'   => $this->breezedoc_map_recipients( $doc['recipients'] ?? array() ),
			'title'        => (string) ( $doc['title'] ?? '' ),
		);
	}

	private function esign_log_insert( $document_id, $template_id, $title, $stripe_customer_id, $status, $recipients, $url ) {
		if ( ! $this->ensure_esign_documents_table() ) {
			return new WP_Error( 'no_table', __( 'E-Signatures log table could not be created.', 'ajcore-ra' ) );
		}
		$customer      = $stripe_customer_id ? $this->find_portal_customer_by_stripe_id( $stripe_customer_id ) : null;
		$customer_name = $customer ? (string) $customer->name : '';
		$pdb           = $this->get_pdb();
		$table         = $this->get_esign_documents_table();
		// An idempotent replay (double click) returns the same BreezeDoc id: hand back the existing row.
		$existing = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE breezedoc_document_id = %s", (string) $document_id ) );
		if ( $existing ) {
			return $this->format_esign_document_row( $existing );
		}
		$pdb->insert(
			$table,
			array(
				'breezedoc_document_id' => (string) $document_id,
				'breezedoc_template_id' => (string) $template_id,
				'template_title'        => (string) $title,
				'stripe_customer_id'    => sanitize_text_field( (string) $stripe_customer_id ),
				'customer_name'         => $customer_name,
				'status'                => (string) $status,
				'recipients'            => wp_json_encode( $recipients ),
				'document_url'          => (string) $url,
				'sent_at'               => current_time( 'mysql' ),
				'created_by'            => get_current_user_id(),
				'created_at'            => current_time( 'mysql' ),
				'updated_at'            => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$logged = $this->format_esign_document_row( $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $pdb->insert_id ) ) );
		$this->maybe_notify_signers( $logged );
		return $logged;
	}

	/**
	 * Creates a document from a template and sends it. Team API key + `tpl_…` id → v1 (idempotent,
	 * optional signing_order / message / expires_in_days); numeric id → legacy Personal-token flow.
	 * BreezeDoc can't pre-fill a template's own fields — only who signs.
	 * $options: signing_order (bool), message (string, needs custom-email plan), expires_in_days (1-365), title.
	 */
	public function send_esign_document_from_template( $template_id, $stripe_customer_id, $recipients, $options = array() ) {
		$template_id = sanitize_text_field( (string) $template_id );
		$recipients  = $this->breezedoc_clean_recipients( $recipients );
		if ( is_wp_error( $recipients ) ) {
			return $recipients;
		}

		if ( 0 === strpos( $template_id, 'ajt_' ) ) {
			return $this->send_local_template( $template_id, $stripe_customer_id, $recipients, $options );
		}
		if ( 0 !== strpos( $template_id, 'tpl_' ) ) {
			return $this->send_esign_document_legacy( absint( $template_id ), $stripe_customer_id, $recipients );
		}
		if ( ! $this->breezedoc_is_v1() ) {
			return new WP_Error( 'breezedoc_not_configured', __( 'BreezeDoc is not connected. Add a Team API key in E-Signatures settings.', 'ajcore-ra' ) );
		}

		// Same payload within a 5-minute window replays the first response (double-click safe).
		$idem  = 'ajcore-' . sha1( wp_json_encode( array( $template_id, $stripe_customer_id, $recipients ) ) ) . '-' . floor( time() / 300 );
		$draft = array( 'template_id' => $template_id, 'recipients' => $recipients );
		if ( ! empty( $options['title'] ) ) {
			$draft['title'] = mb_substr( sanitize_text_field( (string) $options['title'] ), 0, 191 );
		}
		$created = $this->breezedoc_api_request( 'POST', '/v1/documents', array( 'body' => $draft, 'idempotency_key' => $idem ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return $this->breezedoc_v1_send_and_log( $created, $template_id, $stripe_customer_id, $options, $idem );
	}

	/** Shared tail of the v1 template and PDF flows: send the draft, re-read it, log it locally. */
	private function breezedoc_v1_send_and_log( $created, $template_id, $stripe_customer_id, $options, $idem ) {
		$document_id = isset( $created['id'] ) ? (string) $created['id'] : '';
		if ( '' === $document_id ) {
			return new WP_Error( 'breezedoc_create_failed', __( 'BreezeDoc did not return a document id.', 'ajcore-ra' ) );
		}

		$send_body = array( 'signing_order' => ! isset( $options['signing_order'] ) || (bool) $options['signing_order'] );
		if ( ! empty( $options['message'] ) ) {
			$send_body['message'] = sanitize_textarea_field( (string) $options['message'] );
		}
		if ( ! empty( $options['expires_in_days'] ) ) {
			$send_body['expires_in_days'] = max( 1, min( 365, (int) $options['expires_in_days'] ) );
		}
		$sent = $this->breezedoc_api_request( 'POST', '/v1/documents/' . rawurlencode( $document_id ) . '/send', array( 'body' => $send_body, 'idempotency_key' => $idem . '-send' ) );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		$fresh = $this->breezedoc_api_request( 'GET', '/v1/documents/' . rawurlencode( $document_id ) );
		$state = $this->breezedoc_v1_doc_state( is_wp_error( $fresh ) ? array_merge( $created, $sent ) : $fresh );
		if ( 'draft' === $state['status'] ) {
			$state['status'] = 'sent'; // /send succeeded; a stale read must not show it as a draft.
		}

		$title = $state['title'];
		if ( '' === $title && $template_id ) {
			$tpl   = $this->breezedoc_get_template( $template_id );
			$title = is_wp_error( $tpl ) ? '' : $tpl['title'];
		}
		return $this->esign_log_insert( $document_id, $template_id, $title, $stripe_customer_id, $state['status'], $state['recipients'], $state['url'] );
	}

	/**
	 * Uploads a PDF, auto-places a signature (+ date) field per signer, sends it. BreezeDoc limits:
	 * real PDF, 25 MB, 100 pages. $file is a $_FILES-style array. $page 0 = last page (best-effort
	 * page count from the PDF bytes; falls back to page 1).
	 */
	public function send_esign_pdf( $file, $title, $stripe_customer_id, $recipients, $page = 0, $options = array() ) {
		if ( ! $this->breezedoc_is_v1() ) {
			return new WP_Error( 'breezedoc_not_configured', __( 'PDF upload needs a BreezeDoc Team API key. Add one in E-Signatures settings.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$recipients = $this->breezedoc_clean_recipients( $recipients );
		if ( is_wp_error( $recipients ) ) {
			return $recipients;
		}
		$tmp = is_array( $file ) ? (string) ( $file['tmp_name'] ?? '' ) : '';
		if ( '' === $tmp || ! is_readable( $tmp ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'no_file', __( 'Choose a PDF to upload.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( filesize( $tmp ) > 25 * MB_IN_BYTES ) {
			return new WP_Error( 'file_too_large', __( 'PDF must be 25 MB or smaller.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$content = (string) file_get_contents( $tmp );
		if ( 0 !== strpos( ltrim( $content ), '%PDF-' ) ) {
			return new WP_Error( 'not_pdf', __( 'That file is not a PDF.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}

		$page = (int) $page;
		if ( $page < 1 ) {
			$count = preg_match_all( '/\/Type\s*\/Page(?![A-Za-z])/', $content );
			$page  = max( 1, (int) $count );
		}

		// Placed fields (from the field editor): filled ones are stamped on the PDF, the rest go to BreezeDoc.
		$fields = array();
		$have   = array();
		$layout = ! empty( $options['layout'] ) ? self::clean_layout( $options['layout'] ) : array();
		if ( $layout ) {
			$values = $this->merge_values( $stripe_customer_id, $recipients, isset( $options['vars'] ) && is_array( $options['vars'] ) ? $options['vars'] : array() );
			$stamps = array();
			foreach ( $layout as $f ) {
				if ( $f['party'] > count( $recipients ) ) {
					continue;
				}
				if ( '' !== $f['fill'] && in_array( $f['type'], array( 'text', 'email', 'date', 'checkbox' ), true ) ) {
					$val = trim( $this->merge_text( $f['fill'], $values ) );
					if ( '' !== $val ) {
						$stamps[] = $f + array( 'value' => $val );
						continue;
					}
				}
				$fields[] = array( 'party' => $recipients[ $f['party'] - 1 ]['party'], 'page' => $f['page'], 'type' => $f['type'], 'properties' => array( 'x' => $f['x'], 'y' => $f['y'], 'w' => $f['w'], 'h' => $f['h'] ) );
				if ( 'signature' === $f['type'] ) {
					$have[ $recipients[ $f['party'] - 1 ]['party'] ] = true;
				}
			}
			if ( $stamps ) {
				$stamped = $this->stamp_pdf( $content, $stamps );
				if ( is_wp_error( $stamped ) ) {
					return $stamped;
				}
				$content = $stamped;
			}
		}
		// A default signature + date box for every signer that has none placed (page-fraction coords).
		$base_x = isset( $options['sig_x'] ) ? max( 0.0, min( 0.6, (float) $options['sig_x'] ) ) : 0.08;
		$base_y = isset( $options['sig_y'] ) ? max( 0.05, min( 0.9, (float) $options['sig_y'] ) ) : 0.78;
		foreach ( $recipients as $i => $r ) {
			if ( isset( $have[ $r['party'] ] ) ) {
				continue;
			}
			$col = $i % 2;
			$row = intdiv( $i, 2 );
			$x   = min( 0.62, $base_x + 0.48 * $col );
			$y   = max( 0.05, $base_y - 0.14 * $row );
			$fields[] = array( 'party' => $r['party'], 'page' => $page, 'type' => 'signature', 'properties' => array( 'x' => $x, 'y' => $y, 'w' => 0.36, 'h' => 0.06 ) );
			$fields[] = array( 'party' => $r['party'], 'page' => $page, 'type' => 'date', 'properties' => array( 'x' => $x, 'y' => $y + 0.07, 'w' => 0.2, 'h' => 0.03 ) );
		}

		if ( ! empty( $options['preview_only'] ) ) { // nothing is sent to BreezeDoc: return the filled PDF with the signer boxes outlined
			$outs = array();
			foreach ( $fields as $bf ) {
				$pr     = $bf['properties'];
				$outs[] = array( 'page' => $bf['page'], 'x' => $pr['x'], 'y' => $pr['y'], 'w' => $pr['w'], 'h' => $pr['h'], 'outline' => ucfirst( (string) $bf['type'] ) . ' (' . ( $bf['party'] ?? 1 ) . ')' );
			}
			$bytes = $outs ? $this->stamp_pdf( $content, $outs ) : $content;
			return is_wp_error( $bytes ) ? $bytes : array( 'preview_pdf' => $bytes );
		}
		$title    = mb_substr( sanitize_text_field( (string) $title ), 0, 191 );
		$filename = sanitize_file_name( (string) ( $file['name'] ?? 'document.pdf' ) );
		if ( '' === $title ) {
			$title = preg_replace( '/\.pdf$/i', '', $filename );
		}
		$idem    = 'ajcore-pdf-' . sha1( md5( $content ) . wp_json_encode( array( $stripe_customer_id, $recipients, $page ) ) ) . '-' . floor( time() / 300 );
		$created = $this->breezedoc_api_request(
			'POST',
			'/v1/documents',
			array(
				'timeout'         => 60,
				'idempotency_key' => $idem,
				'multipart'       => array(
					'fields' => array( 'title' => $title, 'recipients' => $recipients, 'fields' => $fields ),
					'file'   => array( 'name' => 'pdf', 'filename' => $filename, 'content' => $content, 'type' => 'application/pdf' ),
				),
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( empty( $created['title'] ) ) {
			$created['title'] = $title;
		}
		$logged = $this->breezedoc_v1_send_and_log( $created, '', $stripe_customer_id, $options, $idem );
		if ( ! is_wp_error( $logged ) && ! empty( $logged['breezedocDocumentId'] ) ) {
			$keep = $this->sent_path( $logged['breezedocDocumentId'] );
			if ( '' !== $keep ) {
				file_put_contents( $keep, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return $logged;
	}

	/** Pre-Team-key flow (Personal Access Token, integer template ids). */
	private function send_esign_document_legacy( $template_id, $stripe_customer_id, $recipients ) {
		$document = $this->breezedoc_api_request( 'POST', '/templates/' . $template_id . '/create-document' );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		$document_id = isset( $document['id'] ) ? (int) $document['id'] : 0;
		if ( ! $document_id ) {
			return new WP_Error( 'breezedoc_create_failed', __( 'BreezeDoc did not return a document id.', 'ajcore-ra' ) );
		}
		$send_body = array( 'recipients' => array_map( static function ( $r ) {
			return array( 'name' => $r['name'], 'email' => $r['email'] );
		}, $recipients ) );
		$sent = $this->breezedoc_api_request( 'POST', '/documents/' . $document_id . '/send', array( 'body' => $send_body ) );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		$slug = isset( $sent['slug'] ) ? (string) $sent['slug'] : '';
		return $this->esign_log_insert(
			$document_id,
			$template_id,
			isset( $sent['title'] ) ? (string) $sent['title'] : '',
			$stripe_customer_id,
			'sent',
			$this->breezedoc_map_recipients( $sent['recipients'] ?? $recipients ),
			$slug ? 'https://breezedoc.com/documents/' . $slug : ''
		);
	}

	private function get_esign_row( $local_id ) {
		if ( ! $this->ensure_esign_documents_table() ) {
			return new WP_Error( 'no_table', __( 'E-Signatures log table could not be created.', 'ajcore-ra' ) );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_esign_documents_table();
		$row   = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", absint( $local_id ) ) );
		return $row ? $row : new WP_Error( 'not_found', __( 'Signature request not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
	}

	/** Polls BreezeDoc for a logged document's current state (webhooks call this too — we never trust webhook payload data). */
	public function refresh_esign_document_status( $local_id ) {
		$row = $this->get_esign_row( $local_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return $this->refresh_esign_row( $row );
	}

	private function refresh_esign_row( $row ) {
		$pdb   = $this->get_pdb();
		$table = $this->get_esign_documents_table();

		if ( 0 === strpos( (string) $row->breezedoc_document_id, 'doc_' ) ) {
			$document = $this->breezedoc_api_request( 'GET', '/v1/documents/' . rawurlencode( (string) $row->breezedoc_document_id ) );
			if ( is_wp_error( $document ) ) {
				return $document;
			}
			$state        = $this->breezedoc_v1_doc_state( $document );
			$status       = $state['status'];
			$completed_at = $state['completed_at'];
			$recipients   = $state['recipients'];
			$url          = $state['url'];
		} else {
			$document = $this->breezedoc_api_request( 'GET', '/documents/' . absint( $row->breezedoc_document_id ) );
			if ( is_wp_error( $document ) ) {
				return $document;
			}
			$completed_at = ! empty( $document['completed_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $document['completed_at'] ) ) : '';
			$status       = '' !== $completed_at ? 'completed' : 'sent';
			$recipients   = $this->breezedoc_map_recipients( $document['recipients'] ?? array() );
			$url          = '';
		}

		$update_data   = array(
			'status'          => $status,
			'recipients'      => wp_json_encode( $recipients ),
			'last_checked_at' => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		);
		$update_format = array( '%s', '%s', '%s', '%s' );
		if ( '' !== $completed_at ) {
			$update_data['completed_at'] = $completed_at;
			$update_format[]             = '%s';
		}
		if ( '' !== $url && '' === (string) $row->document_url ) {
			$update_data['document_url'] = $url;
			$update_format[]             = '%s';
		}
		$pdb->update( $table, $update_data, array( 'id' => $row->id ), $update_format, array( '%d' ) );
		if ( 'completed' === $status && 'completed' !== (string) $row->status ) {
			$this->on_completed( $row, $recipients );
		}

		return $this->format_esign_document_row( $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $row->id ) ) );
	}

	/**
	 * v1-only per-document actions on a logged row.
	 * remind → nudges unsigned invitees (BreezeDoc allows once per 24h); cancel → stops signing and
	 * refreshes the row; download → 15-minute signed URL(s) for the completed PDF + certificate;
	 * events → audit trail.
	 */
	public function esign_document_action( $local_id, $action ) {
		$row = $this->get_esign_row( $local_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( 'preview' === $action ) {
			return $this->esign_preview( $row );
		}
		$doc_id = (string) $row->breezedoc_document_id;
		if ( 0 !== strpos( $doc_id, 'doc_' ) ) {
			return new WP_Error( 'legacy_document', __( 'This request was sent before the Team API key was added; remind, cancel, download and history are only available for newer requests.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$base = '/v1/documents/' . rawurlencode( $doc_id );
		$idem = 'ajcore-' . $action . '-' . sha1( $doc_id ) . '-' . floor( time() / 60 );

		switch ( $action ) {
			case 'remind':
				$result = $this->breezedoc_api_request( 'POST', $base . '/remind', array( 'idempotency_key' => $idem ) );
				return is_wp_error( $result ) ? $result : array( 'ok' => true, 'result' => $result );
			case 'cancel':
				$result = $this->breezedoc_api_request( 'POST', $base . '/cancel', array( 'idempotency_key' => $idem ) );
				return is_wp_error( $result ) ? $result : $this->refresh_esign_row( $row );
			case 'download':
				if ( 'completed' !== (string) $row->status ) {
					$keep = $this->sent_path( $doc_id );
					if ( '' !== $keep && is_readable( $keep ) ) {
						return array( 'sent' => true, 'url' => '', 'certificateUrl' => '' );
					}
					return new WP_Error( 'not_signed_yet', __( 'BreezeDoc only provides the PDF once the document is fully signed, and AJCore has no copy of this one.', 'ajcore-ra' ), array( 'status' => 404 ) );
				}
				$result = $this->breezedoc_api_request( 'GET', $base . '/download' );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$url = (string) ( $result['url'] ?? $result['pdf_url'] ?? $result['download_url'] ?? '' );
				$cert = (string) ( $result['certificate_url'] ?? $result['certificate'] ?? '' );
				return array( 'url' => $url, 'certificateUrl' => $cert, 'raw' => $result );
			case 'events':
				$result = $this->breezedoc_api_request( 'GET', $base . '/events', array( 'query' => array( 'limit' => 100 ) ) );
				return is_wp_error( $result ) ? $result : array( 'events' => (array) ( $result['data'] ?? array() ) );
		}
		return new WP_Error( 'bad_action', __( 'Unknown action.', 'ajcore-ra' ), array( 'status' => 400 ) );
	}

	// ── AJCore templates (our own; BreezeDoc templates cannot be created through its API) ──

	private function tpl_dir() {
		$u   = wp_upload_dir();
		$dir = trailingslashit( $u['basedir'] ) . 'ajcore-ra-esign-templates';
		if ( ! is_dir( $dir ) && wp_mkdir_p( $dir ) ) {
			file_put_contents( $dir . '/.htaccess', "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/** The exact PDF AJCore sent to BreezeDoc, kept so a request can be previewed and downloaded while it is still waiting for signatures. */
	private function sent_path( $breezedoc_document_id ) {
		$name = sanitize_file_name( (string) $breezedoc_document_id );
		if ( '' === $name ) {
			return '';
		}
		$dir = $this->tpl_dir() . '/sent';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir . '/' . $name . '.pdf';
	}

	/** @return array<string,array> id => meta (title, file, filename, signers[], page, created) */
	private function local_templates() {
		$list = get_option( self::TPL_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	private function local_template_shape( array $meta ) {
		$recipients = array();
		foreach ( array_values( (array) $meta['signers'] ) as $i => $role ) {
			$recipients[] = array( 'party' => $i + 1, 'name' => '', 'email' => '', 'completedAt' => '', 'viewedAt' => '', 'role' => (string) $role );
		}
		return array(
			'id'         => (string) $meta['id'],
			'title'      => (string) $meta['title'],
			'rawTitle'   => (string) $meta['title'],
			'source'     => 'ajcore',
			'page'       => (int) ( $meta['page'] ?? 0 ),
			'layout'     => isset( $meta['layout'] ) ? $meta['layout'] : array(),
			'sigX'       => (float) ( $meta['sig_x'] ?? 8 ),
			'sigY'       => (float) ( $meta['sig_y'] ?? 78 ),
			'kind'       => ( $meta['kind'] ?? 'pdf' ) === 'doc' ? 'doc' : 'pdf',
			'body'       => (string) ( $meta['body'] ?? '' ),
			'recipients' => $recipients,
			'fields'     => array(),
		);
	}

	/**
	 * Creates or updates an AJCore template. $file is a $_FILES-style array (required when creating).
	 * $signers: role labels in signing order (Party 1, 2, …). $page: 0 = last page.
	 */
	public function save_local_template( $id, $title, $signers, $page, $file = null,$kind = 'pdf', $body = '', $sig_x = null, $sig_y = null, $layout = null ) {
		$title   = mb_substr( sanitize_text_field( (string) $title ), 0, 191 );
		$signers = array_values( array_filter( array_map( static function ( $r ) {
			return mb_substr( sanitize_text_field( (string) $r ), 0, 80 );
		}, (array) $signers ), static function ( $r ) {
			return '' !== $r;
		} ) );
		if ( '' === $title ) {
			return new WP_Error( 'no_title', __( 'Give the form a name.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( empty( $signers ) ) {
			$signers = array( __( 'Signer', 'ajcore-ra' ) );
		}
		$all      = $this->local_templates();
		$id       = (string) $id;
		$existing = isset( $all[ $id ] ) ? $all[ $id ] : null;
		if ( '' !== $id && ! $existing ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$has_file = is_array( $file ) && ! empty( $file['tmp_name'] ) && empty( $file['error'] );
		$kind     = 'doc' === $kind ? 'doc' : 'pdf';
		if ( $existing && ( $existing['kind'] ?? 'pdf' ) === 'doc' ) {
			$kind = 'doc'; // a template never changes kind after creation
		}
		$body = trim( str_replace( "\r\n", "\n", wp_kses_no_null( (string) $body ) ) );
		if ( 'doc' === $kind && '' !== $body ) {
			$body = self::clean_doc_html( $body );
		}
		if ( 'doc' === $kind && '' === $body ) {
			return new WP_Error( 'no_body', __( 'Write the document text.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( 'pdf' === $kind && ! $existing && ! $has_file ) {
			return new WP_Error( 'no_file', __( 'Choose the form PDF.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$meta = $existing ? $existing : array( 'id' => 'ajt_' . strtolower( wp_generate_password( 10, false ) ), 'created' => current_time( 'mysql' ) );
		if ( 'pdf' === $kind && $has_file ) {
			if ( filesize( $file['tmp_name'] ) > 25 * MB_IN_BYTES ) {
				return new WP_Error( 'file_too_large', __( 'PDF must be 25 MB or smaller.', 'ajcore-ra' ), array( 'status' => 400 ) );
			}
			$head = (string) file_get_contents( $file['tmp_name'], false, null, 0, 1024 );
			if ( 0 !== strpos( ltrim( $head ), '%PDF-' ) ) {
				return new WP_Error( 'not_pdf', __( 'That file is not a PDF.', 'ajcore-ra' ), array( 'status' => 400 ) );
			}
			$dir = $this->tpl_dir();
			if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
				return new WP_Error( 'no_dir', __( 'Could not store the form file (uploads folder is not writable).', 'ajcore-ra' ), array( 'status' => 500 ) );
			}
			$stored = $meta['id'] . '.pdf';
			if ( ! ( is_uploaded_file( $file['tmp_name'] ) ? move_uploaded_file( $file['tmp_name'], $dir . '/' . $stored ) : copy( $file['tmp_name'], $dir . '/' . $stored ) ) ) {
				return new WP_Error( 'store_failed', __( 'Could not store the form file.', 'ajcore-ra' ), array( 'status' => 500 ) );
			}
			$meta['file']     = $stored;
			$meta['filename'] = sanitize_file_name( (string) ( $file['name'] ?? $stored ) );
		}
		$meta['kind']    = $kind;
		if ( null !== $layout ) {
			$meta['layout'] = self::clean_layout( $layout );
		}
		foreach ( array( 'sig_x' => $sig_x, 'sig_y' => $sig_y ) as $k => $val ) {
			if ( null !== $val && '' !== $val ) {
				$meta[ $k ] = max( 0, min( 90, (float) $val ) ); // percent from the left / top
			}
		}
		if ( 'doc' === $kind ) {
			if ( strlen( $body ) > 6000000 ) {
				return new WP_Error( 'too_big', __( 'The document is too large (images?). Use smaller images.', 'ajcore-ra' ), array( 'status' => 400 ) );
			}
			$meta['body']     = $body;
			$meta['format']   = 'html';
			$meta['file']     = '';
			$meta['filename'] = sanitize_file_name( $title ) . '.pdf';
		}
		$meta['title']   = $title;
		$meta['signers'] = $signers;
		$meta['page']    = max( 0, (int) $page );
		$all[ $meta['id'] ] = $meta;
		update_option( self::TPL_OPTION, $all, false );
		return $this->local_template_shape( $meta );
	}

	/** Copies a template (and its stored PDF) under a new id. */
	public function duplicate_local_template( $id ) {
		$all = $this->local_templates();
		$id  = (string) $id;
		if ( ! isset( $all[ $id ] ) ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$meta          = $all[ $id ];
		$meta['id']    = 'ajt_' . strtolower( wp_generate_password( 10, false ) );
		$meta['title'] = mb_substr( sprintf( /* translators: %s: template name */ __( '%s (copy)', 'ajcore-ra' ), (string) $meta['title'] ), 0, 191 );
		$meta['created'] = current_time( 'mysql' );
		if ( '' !== (string) ( $meta['file'] ?? '' ) ) {
			$src = $this->tpl_dir() . '/' . basename( (string) $meta['file'] );
			$new = $meta['id'] . '.pdf';
			if ( ! is_readable( $src ) || ! copy( $src, $this->tpl_dir() . '/' . $new ) ) {
				return new WP_Error( 'copy_failed', __( 'Could not copy the form file.', 'ajcore-ra' ), array( 'status' => 500 ) );
			}
			$meta['file'] = $new;
		}
		$all[ $meta['id'] ] = $meta;
		update_option( self::TPL_OPTION, $all, false );
		return $this->local_template_shape( $meta );
	}

	/** Really deletes an AJCore template and its stored PDF. */
	public function delete_local_template( $id ) {
		$all = $this->local_templates();
		$id  = (string) $id;
		if ( ! isset( $all[ $id ] ) ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$path = '' !== (string) $all[ $id ]['file'] ? $this->tpl_dir() . '/' . basename( (string) $all[ $id ]['file'] ) : '';
		if ( '' !== $path && is_file( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		unset( $all[ $id ] );
		update_option( self::TPL_OPTION, $all, false );
		return array( 'deleted' => 1 );
	}

	/** Sends an AJCore template: its stored PDF goes through the Team API upload with a signature + date box per signer. */
	private function send_local_template( $template_id, $stripe_customer_id, array $recipients, array $options ) {
		$all = $this->local_templates();
		if ( ! isset( $all[ $template_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$meta = $all[ $template_id ];
		if ( ( $meta['kind'] ?? 'pdf' ) === 'doc' ) {
			$pdf = $this->render_doc_template( $meta, $stripe_customer_id, $recipients, false, isset( $options['vars'] ) && is_array( $options['vars'] ) ? $options['vars'] : array() );
			if ( is_wp_error( $pdf ) ) {
				return $pdf;
			}
			if ( ! empty( $options['preview_only'] ) ) {
				return array( 'preview_pdf' => $pdf );
			}
			$tmp = wp_tempnam( 'ajesign.pdf' );
			if ( ! $tmp || false === file_put_contents( $tmp, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return new WP_Error( 'tmp_failed', __( 'Could not write the generated PDF.', 'ajcore-ra' ), array( 'status' => 500 ) );
			}
			$options['sig_x'] = 0.08;
			$options['sig_y'] = 0.30; // generated signature page: boxes in the upper-middle, clear of text
			$result = $this->send_esign_pdf( array( 'tmp_name' => $tmp, 'name' => (string) $meta['filename'], 'error' => 0 ), (string) $meta['title'], $stripe_customer_id, $recipients, 0, $options );
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return $result;
		}
		$path = $this->tpl_dir() . '/' . basename( (string) $meta['file'] );
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'file_missing', __( 'The form PDF is missing — edit the form and upload it again.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $meta['layout'] ) && empty( $options['layout'] ) ) {
			$options['layout'] = $meta['layout']; // a layout adjusted at send time wins
		}
		if ( isset( $meta['sig_x'] ) ) {
			$options['sig_x'] = (float) $meta['sig_x'] / 100;
		}
		if ( isset( $meta['sig_y'] ) ) {
			$options['sig_y'] = (float) $meta['sig_y'] / 100;
		}
		return $this->send_esign_pdf( array( 'tmp_name' => $path, 'name' => (string) $meta['filename'], 'error' => 0 ), (string) $meta['title'], $stripe_customer_id, $recipients, (int) $meta['page'], $options );
	}

	// ── Document templates: merge fields + built-in PDF writer ──────────────────

	/** Merge fields offered in the editor: token => label. */
	public static function merge_field_catalog() {
		$c = array(
			'customer.name'          => 'Customer — name',
			'customer.business_name' => 'Customer — business name',
			'customer.individual_name' => 'Customer — individual name',
			'customer.email'         => 'Customer — email',
			'customer.phone'         => 'Customer — phone',
			'customer.address'       => 'Customer — full address',
			'customer.address.line1' => 'Customer — street',
			'customer.address.line2' => 'Customer — street line 2',
			'customer.address.city'  => 'Customer — city',
			'customer.address.state' => 'Customer — state',
			'customer.address.zip'   => 'Customer — ZIP',
			'customer.address.country' => 'Customer — country',
			'customer.number'        => 'Customer — number',
			'customer.description'   => 'Customer — description',
		);
		if ( class_exists( 'AJCore_RA_Business_Profile' ) ) {
			foreach ( AJCore_RA_Business_Profile::fields() as $k => $def ) {
				$c[ self::business_token( $k ) ] = 'Business — ' . wp_strip_all_tags( (string) $def['label'] );
			}
			$c['business.address'] = 'Business — full address';
		}
		foreach ( self::our_fields() as $k => $label ) {
			$c[ 'our.' . $k ] = 'Our company — ' . $label;
		}
		$c += array(
			'mailbox.pmb'            => 'Mailbox — PMB number',
			'mailbox.start_date'     => 'Mailbox — start date',
			'mailbox.id_type'        => 'Mailbox — ID type',
			'mailbox.id_issuer'      => 'Mailbox — ID issuer',
			'signer.name'            => 'Signer 1 — name',
			'signer.email'           => 'Signer 1 — email',
			'date'                   => 'Today (long date)',
			'date.short'             => 'Today (numeric)',
			'date.day'               => 'Today — day',
			'date.month'             => 'Today — month name',
			'date.year'              => 'Today — year (4 digits)',
			'date.year2'             => 'Today — year (2 digits)',
			'company'                => 'This site\'s business name',
		);
		return $c;
	}

	private static function business_token( $key ) {
		return 'business_name' === $key ? 'business.name' : 'business.' . $key;
	}

	/** The company's own details (registered agent), used to fill forms. @return array<string,string> key => label */
	public static function our_fields() {
		return array(
			'name'   => 'name',
			'street' => 'street',
			'city'   => 'city',
			'state'  => 'state',
			'zip'    => 'ZIP',
			'county' => 'county',
			'email'  => 'email',
			'phone'  => 'phone',
		);
	}

	const OUR_OPTION = 'ajcore_ra_esign_our';

	private static function our_defaults() {
		return array(
			'name'   => 'NC LLC Agents (2356362)',
			'street' => '1914 J N PEASE PL',
			'city'   => 'CHARLOTTE',
			'state'  => 'NC',
			'zip'    => '28262',
			'county' => 'Mecklenburg',
			'email'  => 'agent@ncllcagents.com',
			'phone'  => '(704) 307-2135',
		);
	}

	/** Saved company details over the defaults. @return array<string,string> */
	public static function our_details() {
		$saved = get_option( self::OUR_OPTION, array() );
		$out   = self::our_defaults();
		if ( is_array( $saved ) ) {
			foreach ( $out as $k => $v ) {
				if ( isset( $saved[ $k ] ) ) {
					$out[ $k ] = (string) $saved[ $k ];
				}
			}
		}
		return $out;
	}

	/** Values for the merge fields for one customer (blank when unknown). */
	private function merge_values( $stripe_customer_id, array $recipients, array $vars = array() ) {
		$v = array_fill_keys( array_keys( self::merge_field_catalog() ), '' );
		$pdb = $this->get_pdb();
		$id  = (string) $stripe_customer_id;
		if ( '' !== $id ) {
			foreach ( array( 'aj_portal_local_customers' => 'local_customer_id', 'aj_portal_stripe_customers' => 'stripe_customer_id' ) as $suffix => $col ) {
				$tbl = $this->kit['portal_table']( $suffix );
				if ( ! $this->kit['table_exists']( $pdb, $tbl ) ) {
					continue;
				}
				$row = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$tbl}` WHERE `{$col}` = %s LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $row ) {
					continue;
				}
				$v['customer.name']   = (string) ( $row['name'] ?? '' );
				$v['customer.email']  = (string) ( $row['email'] ?? '' );
				$v['customer.phone']  = (string) ( $row['phone'] ?? '' );
				$v['customer.number'] = (string) ( $row['customer_number'] ?? '' );
				$v['customer.description'] = (string) ( $row['description'] ?? '' );
				// Business / individual name sit in the metadata (or the raw Stripe record); any other metadata key is {{meta.key}}.
				$meta = is_string( $row['metadata'] ?? null ) ? json_decode( (string) $row['metadata'], true ) : null;
				$meta = is_array( $meta ) ? $meta : array();
				$raw  = is_string( $row['raw_data'] ?? null ) ? json_decode( (string) $row['raw_data'], true ) : null;
				if ( is_array( $raw ) ) {
					foreach ( array( 'business_name', 'individual_name' ) as $mk ) {
						if ( empty( $meta[ $mk ] ) && ! empty( $raw[ $mk ] ) ) {
							$meta[ $mk ] = $raw[ $mk ];
						}
					}
				}
				$v['customer.business_name']   = isset( $meta['business_name'] ) && is_scalar( $meta['business_name'] ) ? (string) $meta['business_name'] : '';
				$v['customer.individual_name'] = isset( $meta['individual_name'] ) && is_scalar( $meta['individual_name'] ) ? (string) $meta['individual_name'] : '';
				foreach ( $meta as $mk => $mv ) {
					if ( is_scalar( $mv ) ) {
						$v[ 'meta.' . strtolower( preg_replace( '/[^a-z0-9_]+/i', '_', (string) $mk ) ) ] = (string) $mv;
					}
				}
				$addr                 = $row['address'] ?? '';
				$decoded              = is_string( $addr ) ? json_decode( $addr, true ) : null;
				if ( is_array( $decoded ) ) {
					$parts = array();
					foreach ( array( 'line1', 'line2' ) as $k ) {
						if ( ! empty( $decoded[ $k ] ) ) {
							$parts[] = (string) $decoded[ $k ];
						}
					}
					$city = trim( implode( ' ', array_filter( array( (string) ( $decoded['city'] ?? '' ) . ( ! empty( $decoded['city'] ) && ! empty( $decoded['state'] ) ? ',' : '' ), (string) ( $decoded['state'] ?? '' ), (string) ( $decoded['postal_code'] ?? '' ) ) ) ) );
					if ( '' !== $city ) {
						$parts[] = $city;
					}
					$addr = implode( ', ', $parts );
				}
				if ( is_array( $decoded ) ) {
					$v['customer.address.line1']   = (string) ( $decoded['line1'] ?? '' );
					$v['customer.address.line2']   = (string) ( $decoded['line2'] ?? '' );
					$v['customer.address.city']    = (string) ( $decoded['city'] ?? '' );
					$v['customer.address.state']   = (string) ( $decoded['state'] ?? '' );
					$v['customer.address.zip']     = (string) ( $decoded['postal_code'] ?? $decoded['zip'] ?? '' );
					$v['customer.address.country'] = (string) ( $decoded['country'] ?? '' );
				}
				$v['customer.address'] = (string) $addr;
				break;
			}
		}
		// Business profile (name, street, city, phone, …).
		if ( '' !== $id && class_exists( 'AJCore_RA_Business_Profile' ) ) {
			$bp = AJCore_RA_Business_Profile::load( $id );
			$bv = isset( $bp['values'] ) && is_array( $bp['values'] ) ? $bp['values'] : array();
			foreach ( array_keys( AJCore_RA_Business_Profile::fields() ) as $k ) {
				$v[ self::business_token( $k ) ] = isset( $bv[ $k ] ) && is_scalar( $bv[ $k ] ) ? (string) $bv[ $k ] : '';
			}
			$csz = trim( implode( ' ', array_filter( array( ( $bv['city'] ?? '' ) . ( ! empty( $bv['city'] ) && ! empty( $bv['state'] ) ? ',' : '' ), (string) ( $bv['state'] ?? '' ), (string) ( $bv['zip'] ?? '' ) ) ) ) );
			$v['business.address'] = implode( ', ', array_filter( array( (string) ( $bv['street'] ?? '' ), $csz ) ) );
		}
		// Mailbox profile.
		if ( '' !== $id ) {
			$ptbl = $this->kit['portal_table']( 'aj_portal_customer_profiles' );
			if ( $this->kit['table_exists']( $pdb, $ptbl ) ) {
				$prow = $pdb->get_row( $pdb->prepare( "SELECT pmb_number, mailbox_start_date, id_type, id_issuer FROM `{$ptbl}` WHERE customer_id = %s LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $prow ) {
					$v['mailbox.pmb']        = (string) $prow['pmb_number'];
					$v['mailbox.start_date'] = (string) $prow['mailbox_start_date'];
					$v['mailbox.id_type']    = (string) $prow['id_type'];
					$v['mailbox.id_issuer']  = (string) $prow['id_issuer'];
				}
			}
		}
		foreach ( self::our_details() as $k => $val ) {
			$v[ 'our.' . $k ] = $val;
		}
		if ( ! empty( $recipients[0] ) ) {
			$v['signer.name']  = (string) ( $recipients[0]['name'] ?? '' );
			$v['signer.email'] = (string) ( $recipients[0]['email'] ?? '' );
		}
		$v['date']       = wp_date( 'F j, Y' );
		$v['date.short'] = wp_date( 'm/d/Y' );
		$v['date.day']   = wp_date( 'j' );
		$v['date.month'] = wp_date( 'F' );
		$v['date.year']  = wp_date( 'Y' );
		$v['date.year2'] = wp_date( 'y' );
		// Values asked for at send time: {{ask.llc_name}} etc.
		foreach ( $vars as $k => $val ) {
			$v[ 'ask.' . sanitize_key( (string) $k ) ] = sanitize_text_field( (string) $val );
		}
		$v['company']    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return $v;
	}

	/** Fills {{tokens}} in $text; $preview keeps unreplaced tokens visible. */
	private function merge_text( $text, array $values, $preview = false ) {
		return preg_replace_callback( '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', static function ( $m ) use ( $values, $preview ) {
			$key = strtolower( $m[1] );
			if ( $preview ) {
				return $m[0];
			}
			return isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
		}, $text );
	}

	/** Merged PDF bytes for a document template. Last page is always a signature page. */
	private function render_doc_template( array $meta, $stripe_customer_id, array $recipients, $preview = false, array $vars = array() ) {
		$values = $this->merge_values( $stripe_customer_id, $recipients, $vars );
		if ( ( $meta['format'] ?? 'text' ) === 'html' ) {
			return $this->build_html_pdf( (string) $meta['title'], $this->merge_html( (string) $meta['body'], $values, $preview ) );
		}
		$text = $this->merge_text( (string) $meta['body'], $values, $preview );
		return self::build_text_pdf( (string) $meta['title'], $text, array() );
	}

	/** Sanitizes a field layout (array or JSON): type, signer party, page, fractional box, optional fill text. */
	private static function clean_layout( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$types = array( 'signature', 'initials', 'text', 'email', 'date', 'checkbox' );
		$out   = array();
		foreach ( $raw as $f ) {
			if ( ! is_array( $f ) || ! in_array( (string) ( $f['type'] ?? '' ), $types, true ) ) {
				continue;
			}
			$w     = min( 1, max( 0.005, (float) ( $f['w'] ?? 0 ) ) );
			$h     = min( 1, max( 0.004, (float) ( $f['h'] ?? 0 ) ) );
			$out[] = array(
				'type'  => (string) $f['type'],
				'party' => max( 1, (int) ( $f['party'] ?? 1 ) ),
				'page'  => max( 1, (int) ( $f['page'] ?? 1 ) ),
				'x'     => max( 0, min( 1 - $w, (float) ( $f['x'] ?? 0 ) ) ),
				'y'     => max( 0, min( 1 - $h, (float) ( $f['y'] ?? 0 ) ) ),
				'w'     => $w,
				'h'     => $h,
				'fill'  => mb_substr( sanitize_text_field( (string) ( $f['fill'] ?? '' ) ), 0, 300 ),
			);
			if ( count( $out ) >= 400 ) {
				break;
			}
		}
		return $out;
	}

	/** Prints each stamp's value onto its box in the PDF (FPDF + FPDI). Returns the new PDF bytes. */
	private function stamp_pdf( $content, array $stamps ) {
		$fpdf = __DIR__ . '/lib/fpdf/fpdf.php';
		$fpdi = __DIR__ . '/lib/fpdi/src/autoload.php';
		if ( ! is_readable( $fpdf ) || ! is_readable( $fpdi ) ) {
			return new WP_Error( 'no_stamp_engine', __( 'The PDF stamping library is missing from the AJCore-RA plugin.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		require_once $fpdf;
		require_once $fpdi;
		$tmp = wp_tempnam( 'ajstamp.pdf' );
		if ( ! $tmp || false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'tmp_failed', __( 'Could not write a temporary file.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$enc = static function ( $s ) {
			$c = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s ) : false;
			return false === $c ? preg_replace( '/[^\x20-\x7e]/', '?', $s ) : $c;
		};
		try {
			$pdf = new \setasign\Fpdi\Fpdi( 'P', 'pt' );
			$pdf->SetAutoPageBreak( false );
			$pdf->SetMargins( 0, 0, 0 );
			$n = $pdf->setSourceFile( $tmp );
			for ( $i = 1; $i <= $n; $i++ ) {
				$tpl  = $pdf->importPage( $i );
				$size = $pdf->getTemplateSize( $tpl );
				$pdf->AddPage( $size['orientation'], array( $size['width'], $size['height'] ) );
				$pdf->useTemplate( $tpl );
				foreach ( $stamps as $f ) {
					if ( (int) $f['page'] !== $i ) {
						continue;
					}
					$x = $f['x'] * $size['width'];
					$y = $f['y'] * $size['height'];
					$w = $f['w'] * $size['width'];
					$h = $f['h'] * $size['height'];
					if ( ! empty( $f['outline'] ) ) { // preview only: where the signer will sign / fill in
						$pdf->SetDrawColor( 79, 70, 229 );
						$pdf->SetLineWidth( 0.8 );
						$pdf->Rect( $x, $y, $w, $h );
						$pdf->SetFont( 'Helvetica', '', 6.5 );
						$pdf->SetTextColor( 79, 70, 229 );
						$pdf->Text( $x + 1.5, $y + 7, (string) $f['outline'] );
						continue;
					}
					$pdf->SetTextColor( 0, 0, 0 );
					if ( 'checkbox' === $f['type'] ) {
						$fs = max( 6, min( 12, $h * 0.95 ) );
						$pdf->SetFont( 'Helvetica', 'B', $fs );
						$pdf->Text( $x + max( 0.5, ( $w - $pdf->GetStringWidth( 'X' ) ) / 2 ), $y + $h * 0.5 + $fs * 0.35, 'X' );
						continue;
					}
					$text = $enc( (string) $f['value'] );
					$fs   = max( 6, min( 11, $h * 0.75 ) );
					$pdf->SetFont( 'Helvetica', '', $fs );
					while ( $fs > 5 && $pdf->GetStringWidth( $text ) > $w - 2 ) {
						$fs -= 0.5;
						$pdf->SetFont( 'Helvetica', '', $fs );
					}
					$base = $h <= 18 ? $y + $h - 3 : $y + $h * 0.5 + $fs * 0.35;
					$pdf->Text( $x + 1.5, $base, $text );
				}
			}
			$out = $pdf->Output( 'S' );
		} catch ( \Throwable $e ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			$hint = preg_match( '/compress|xref|cross-reference|encrypt|filter/i', $e->getMessage() ) ? ' ' . __( '(Newer PDFs with compressed internals are not supported — re-save the form as PDF 1.4.)', 'ajcore-ra' ) : '';
			return new WP_Error( 'stamp_failed', sprintf( /* translators: %s: error */ __( 'Could not fill in the PDF: %s.', 'ajcore-ra' ), $e->getMessage() ) . $hint, array( 'status' => 422 ) );
		}
		@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return $out;
	}

	/** Allowed rich-text markup for Build document templates (everything else is stripped). */
	private static function clean_doc_html( $html ) {
		$g     = array( 'style' => true, 'class' => true, 'align' => true );
		$cell  = $g + array( 'colspan' => true, 'rowspan' => true, 'width' => true, 'height' => true, 'valign' => true );
		$allow = array(
			'p' => $g, 'div' => $g, 'span' => $g, 'br' => array(), 'hr' => $g, 'h1' => $g, 'h2' => $g, 'h3' => $g, 'h4' => $g,
			'b' => $g, 'strong' => $g, 'i' => $g, 'em' => $g, 'u' => $g, 's' => $g, 'sub' => $g, 'sup' => $g, 'blockquote' => $g,
			'ul' => $g, 'ol' => $g, 'li' => $g, 'a' => $g + array( 'href' => true ),
			'table' => $g + array( 'border' => true, 'cellpadding' => true, 'cellspacing' => true, 'width' => true ),
			'thead' => $g, 'tbody' => $g, 'tfoot' => $g, 'tr' => $g, 'td' => $cell, 'th' => $cell, 'colgroup' => array(), 'col' => array( 'width' => true, 'style' => true ),
			'img' => $g + array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true ),
		);
		$styles = static function ( $css ) {
			return array_merge( (array) $css, array( 'text-align', 'width', 'height', 'min-height', 'border', 'border-collapse', 'border-color', 'border-width', 'border-style', 'border-top', 'border-bottom', 'border-left', 'border-right', 'padding', 'margin', 'margin-top', 'margin-bottom', 'background-color', 'color', 'font-size', 'font-weight', 'font-style', 'font-family', 'text-decoration', 'vertical-align', 'line-height', 'page-break-after', 'page-break-before', 'page-break-inside', 'list-style-type' ) );
		};
		add_filter( 'safe_style_css', $styles );
		$out = wp_kses( $html, $allow, array_merge( wp_allowed_protocols(), array( 'data' ) ) );
		remove_filter( 'safe_style_css', $styles );
		$out = preg_replace( '/href\s*=\s*([\'"])\s*data:[^\'"]*\1/i', '', (string) $out ); // data: only for images
		$out = preg_replace( '/src\s*=\s*([\'"])\s*data:(?!image\/(png|jpe?g|gif|webp);base64,)[^\'"]*\1/i', 'src=""', (string) $out );
		return (string) $out;
	}

	/** Fills {{tokens}} in rich HTML; values are HTML-escaped. */
	private function merge_html( $html, array $values, $preview = false ) {
		return preg_replace_callback( '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', static function ( $m ) use ( $values, $preview ) {
			if ( $preview ) {
				return $m[0];
			}
			$key = strtolower( $m[1] );
			return isset( $values[ $key ] ) ? esc_html( (string) $values[ $key ] ) : '';
		}, $html );
	}

	/** Rich HTML → PDF with the bundled dompdf (no remote fetches, no inline PHP); ends with a signature page. */
	private function build_html_pdf( $title, $html ) {
		$autoload = __DIR__ . '/lib/dompdf/autoload.inc.php';
		if ( ! is_readable( $autoload ) ) {
			return new WP_Error( 'no_pdf_engine', __( 'The PDF engine (dompdf) is missing from the AJCore-RA plugin.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		require_once $autoload;
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$cache = $this->tpl_dir() . '/cache';
		wp_mkdir_p( $cache );
		try {
			$options = new \Dompdf\Options();
			$options->set( 'isRemoteEnabled', false );
			$options->set( 'isPhpEnabled', false );
			$options->set( 'isJavascriptEnabled', false );
			$options->set( 'chroot', $this->tpl_dir() );
			$options->set( 'tempDir', $cache );
			$options->set( 'fontDir', $cache );
			$options->set( 'fontCache', $cache );
			$options->set( 'defaultFont', 'Helvetica' );
			$css = '@page{margin:0.8in}body{font-family:Helvetica,Arial,sans-serif;font-size:11pt;line-height:1.4;color:#111}'
				. 'h1{font-size:20pt;margin:0 0 8pt}h2{font-size:15pt;margin:10pt 0 6pt}h3{font-size:12.5pt;margin:8pt 0 4pt}p{margin:0 0 7pt}'
				. 'table{border-collapse:collapse;width:100%;margin:0 0 8pt}td,th{border:1px solid #444;padding:4pt 6pt;vertical-align:top}'
				. 'img{max-width:100%}hr.pb{border:0;height:0;margin:0;page-break-after:always}';
			$sig  = '<hr class="pb"><h2>Signatures</h2><p>' . esc_html( $title ) . '</p><p style="font-size:9pt;color:#555">' . esc_html__( 'Please sign and date in the boxes provided below.', 'ajcore-ra' ) . '</p>';
			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( '<!doctype html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . $html . $sig . '</body></html>', 'UTF-8' );
			$dompdf->setPaper( 'letter' );
			$dompdf->render();
			return $dompdf->output();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'pdf_failed', sprintf( /* translators: %s: error */ __( 'Could not build the PDF: %s', 'ajcore-ra' ), $e->getMessage() ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Minimal dependency-free PDF writer: US Letter, Helvetica, word-wrapped. "# " = heading, "## " =
	 * subheading, "- " = bullet, blank line = paragraph gap, "---pagebreak---" = new page. Always
	 * appends a final page listing the signer roles so signature boxes never land on text.
	 */
	private static function build_text_pdf( $title, $text, array $roles ) {
		$W = 612; $H = 792; $M = 64;
		$enc = static function ( $s ) {
			$s = str_replace( array( "\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x93", "\xe2\x80\x94", "\xe2\x80\xa2", "\xc2\xa0" ), array( "'", "'", '"', '"', '-', '-', '*', ' ' ), $s );
			$c = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s ) : false;
			return false === $c ? preg_replace( '/[^\x20-\x7e]/', '?', $s ) : $c;
		};
		$esc = static function ( $s ) {
			return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $s );
		};
		// Approximate Helvetica advance width (1000 units/em): narrow, wide and average classes.
		$width = static function ( $s, $size, $bold ) {
			$w = 0;
			foreach ( str_split( $s ) as $ch ) {
				if ( false !== strpos( 'iljtfI.,:;\'|!', $ch ) ) { $w += 270; }
				elseif ( false !== strpos( 'mwMW@%', $ch ) ) { $w += 830; }
				elseif ( ' ' === $ch ) { $w += 278; }
				elseif ( ctype_upper( $ch ) ) { $w += 680; }
				else { $w += 540; }
			}
			return $w * $size / 1000 * ( $bold ? 1.06 : 1 );
		};
		$pages = array( array() ); // each: list of array(x,y,size,bold,text)
		$y     = $H - $M;
		$pi    = 0;
		$newpage = function () use ( &$pages, &$pi, &$y, $H, $M ) {
			$pages[] = array();
			$pi++;
			$y = $H - $M;
		};
		$put = function ( $line, $size, $bold, $indent = 0, $lead = 1.35 ) use ( &$pages, &$pi, &$y, $M, $newpage ) {
			if ( $y - $size * $lead < $M ) {
				$newpage();
			}
			$pages[ $pi ][] = array( $M + $indent, $y - $size, $size, $bold, $line );
			$y -= $size * $lead;
		};
		$wrap = function ( $para, $size, $bold, $indent ) use ( $width, $W, $M ) {
			$max   = $W - 2 * $M - $indent;
			$words = preg_split( '/\s+/', trim( $para ) );
			$lines = array();
			$cur   = '';
			foreach ( $words as $w ) {
				$try = '' === $cur ? $w : $cur . ' ' . $w;
				if ( '' !== $cur && $width( $try, $size, $bold ) > $max ) {
					$lines[] = $cur;
					$cur     = $w;
				} else {
					$cur = $try;
				}
			}
			if ( '' !== $cur ) {
				$lines[] = $cur;
			}
			return $lines;
		};
		foreach ( explode( "\n", $enc( $text ) ) as $raw ) {
			$line = rtrim( $raw );
			if ( '---pagebreak---' === trim( $line ) ) {
				$newpage();
				continue;
			}
			if ( '' === trim( $line ) ) {
				$y -= 7;
				continue;
			}
			if ( 0 === strpos( $line, '## ' ) ) {
				$y -= 4;
				foreach ( $wrap( substr( $line, 3 ), 13, true, 0 ) as $l ) { $put( $l, 13, true ); }
			} elseif ( 0 === strpos( $line, '# ' ) ) {
				$y -= 6;
				foreach ( $wrap( substr( $line, 2 ), 18, true, 0 ) as $l ) { $put( $l, 18, true, 0, 1.3 ); }
				$y -= 4;
			} elseif ( 0 === strpos( $line, '- ' ) ) {
				$first = true;
				foreach ( $wrap( substr( $line, 2 ), 10.5, false, 14 ) as $l ) {
					if ( $first ) { $pages[ $pi ][] = array( $M + 2, max( $M, $y - 10.5 ), 10.5, false, '*' ); }
					$put( $l, 10.5, false, 14 );
					$first = false;
				}
			} else {
				foreach ( $wrap( $line, 10.5, false, 0 ) as $l ) { $put( $l, 10.5, false ); }
			}
		}
		// Signature page (always last).
		$newpage();
		$put( 'Signatures', 16, true );
		$y -= 6;
		$put( $enc( $title ), 10.5, false );
		$put( 'Please sign and date in the boxes provided below.', 9, false );
		unset( $roles );

		// Serialize.
		$objs   = array();
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids   = array();
		$n      = 5; // 3 = Helvetica, 4 = Helvetica-Bold
		$page_objs = array();
		foreach ( $pages as $items ) {
			$stream = '';
			foreach ( $items as $it ) {
				$stream .= sprintf( "BT /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $it[3] ? 'F2' : 'F1', $it[2], $it[0], $it[1], $esc( $it[4] ) );
			}
			$content_no = $n++;
			$page_no    = $n++;
			$objs[ $content_no ] = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . 'endstream';
			$objs[ $page_no ]    = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $W $H] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $content_no 0 R >>";
			$kids[]              = $page_no . ' 0 R';
		}
		$objs[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		$objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		ksort( $objs );
		$out     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objs as $no => $body ) {
			$offsets[ $no ] = strlen( $out );
			$out           .= $no . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $out );
		$max  = max( array_keys( $objs ) );
		$out .= "xref\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";
		for ( $i = 1; $i <= $max; $i++ ) {
			$out .= sprintf( "%010d 00000 n \n", $offsets[ $i ] ?? 0 );
		}
		$out .= "trailer\n<< /Size " . ( $max + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $out;
	}

	/** Streams a template PDF (admin only). */
	public function stream_local_template( $id ) {
		$all = $this->local_templates();
		$id  = (string) $id;
		if ( ! isset( $all[ $id ] ) ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		if ( ( $all[ $id ]['kind'] ?? 'pdf' ) === 'doc' ) {
			$bytes = $this->render_doc_template( $all[ $id ], '', array(), true );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
			nocache_headers();
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: inline; filename="preview.pdf"' );
			header( 'Content-Length: ' . strlen( $bytes ) );
			echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}
		$path = $this->tpl_dir() . '/' . basename( (string) $all[ $id ]['file'] );
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'file_missing', __( 'The form PDF is missing.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . str_replace( '"', '', (string) $all[ $id ]['filename'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/** Every http(s) URL found anywhere in a decoded API value. @return string[] */
	private static function collect_urls( $value ) {
		$urls = array();
		if ( is_string( $value ) ) {
			if ( preg_match( '#^https?://#i', $value ) ) {
				$urls[] = $value;
			}
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$urls = array_merge( $urls, self::collect_urls( $item ) );
			}
		}
		return $urls;
	}

	/**
	 * What can be shown for a logged document. Team documents: the signed PDF (+ certificate) once
	 * completed, via a 15-minute signed link. Personal-token documents: BreezeDoc's legacy API does not
	 * document a file URL, so any page images it returns are shown and otherwise `openUrl` (BreezeDoc's
	 * own viewer, needs a BreezeDoc login) is the fallback.
	 * Returns: mode (pdf|images|none), url, certificateUrl, pages[], openUrl, message.
	 */
	private function esign_preview( $row ) {
		$doc_id = (string) $row->breezedoc_document_id;
		$base   = array( 'mode' => 'none', 'url' => '', 'certificateUrl' => '', 'pages' => array(), 'openUrl' => (string) $row->document_url, 'message' => '' );
		if ( 0 === strpos( $doc_id, 'doc_' ) ) {
			if ( 'completed' !== (string) $row->status ) {
				$keep = $this->sent_path( $doc_id );
				if ( '' !== $keep && is_readable( $keep ) ) {
					$base['mode'] = 'sent'; // the copy AJCore sent; the client builds the URL from the row id
					return $base;
				}
				$base['message'] = __( 'BreezeDoc only provides the PDF once the document is fully signed, and AJCore keeps a copy only of documents it sent itself (this one was sent before that, or from BreezeDoc).', 'ajcore-ra' );
				return $base;
			}
			$result = $this->breezedoc_api_request( 'GET', '/v1/documents/' . rawurlencode( $doc_id ) . '/download' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$base['url']            = (string) ( $result['url'] ?? $result['pdf_url'] ?? $result['download_url'] ?? '' );
			$base['certificateUrl'] = (string) ( $result['certificate_url'] ?? $result['certificate'] ?? '' );
			$base['mode']           = '' !== $base['url'] ? 'pdf' : 'none';
			if ( 'none' === $base['mode'] ) {
				$base['message'] = __( 'BreezeDoc did not return a download link.', 'ajcore-ra' );
			}
			return $base;
		}
		$doc = $this->breezedoc_api_request( 'GET', '/documents/' . absint( $doc_id ) );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$pages = array_values( array_unique( array_filter( self::collect_urls( $doc['document_files'] ?? array() ), static function ( $u ) {
			return (bool) preg_match( '#\.(png|jpe?g|webp|gif|pdf)(\?|$)#i', $u );
		} ) ) );
		if ( $pages ) {
			$base['mode']  = 'images';
			$base['pages'] = $pages;
			return $base;
		}
		$base['message'] = __( 'BreezeDoc\'s API does not provide a file for this document. Use "Open" to view it in BreezeDoc.', 'ajcore-ra' );
		return $base;
	}

	// -----------------------------------------------------------------
	// BreezeDoc webhooks (Team API) — replace manual status polling.
	// -----------------------------------------------------------------

	private function breezedoc_webhook_state() {
		$state = get_option( self::WEBHOOK_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/** Public view of webhook state (never includes the signing secret). */
	public function breezedoc_webhook_status() {
		$state = $this->breezedoc_webhook_state();
		return array(
			'registered'  => ! empty( $state['id'] ) && ( $state['url'] ?? '' ) === rest_url( AJCore_Extensions::rest_namespace() . '/esign/webhook' ),
			'url'         => (string) ( $state['url'] ?? '' ),
			'endpointUrl' => rest_url( AJCore_Extensions::rest_namespace() . '/esign/webhook' ),
			'lastEventAt' => (string) ( $state['last_event_at'] ?? '' ),
			'lastResult'  => (string) ( $state['last_result'] ?? '' ),
		);
	}

	public function breezedoc_webhook_register() {
		if ( $this->read_only() ) {
			return new WP_Error( 'webhook_master_only', __( 'The BreezeDoc webhook is registered from the master site only.', 'ajcore-ra' ), array( 'status' => 403 ) );
		}
		if ( ! $this->breezedoc_is_v1() ) {
			return new WP_Error( 'breezedoc_not_configured', __( 'Webhooks need a Team API key.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$url = rest_url( AJCore_Extensions::rest_namespace() . '/esign/webhook' );
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'https_required', __( 'BreezeDoc webhooks need a public HTTPS URL (port 443); this site is not on HTTPS.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$existing = $this->breezedoc_webhook_state();
		// Only replace an endpoint this site registered: if the option was copied from another site
		// (dev/prod sync) its url differs, and deleting it would break that site's webhook.
		if ( ! empty( $existing['id'] ) && ( $existing['url'] ?? '' ) === $url ) {
			$this->breezedoc_api_request( 'DELETE', '/v1/webhooks/' . rawurlencode( (string) $existing['id'] ) );
		}
		$result = $this->breezedoc_api_request(
			'POST',
			'/v1/webhooks',
			array(
				'body'            => array( 'url' => $url, 'events' => array( 'document.sent', 'recipient.viewed', 'recipient.signed', 'document.completed', 'document.cancelled', 'document.expired' ) ),
				'idempotency_key' => 'ajcore-webhook-' . wp_generate_password( 16, false ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$secret = (string) ( $result['secret'] ?? $result['signing_secret'] ?? '' );
		if ( empty( $result['id'] ) || '' === $secret ) {
			return new WP_Error( 'webhook_failed', __( 'BreezeDoc did not return a webhook id and secret.', 'ajcore-ra' ) );
		}
		update_option( self::WEBHOOK_OPTION, array( 'id' => (string) $result['id'], 'secret' => $secret, 'url' => $url, 'registered_at' => current_time( 'mysql' ) ), false );
		return $this->breezedoc_webhook_status();
	}

	public function breezedoc_webhook_remove() {
		if ( $this->read_only() ) {
			return new WP_Error( 'webhook_master_only', __( 'The BreezeDoc webhook is managed from the master site only.', 'ajcore-ra' ), array( 'status' => 403 ) );
		}
		$state = $this->breezedoc_webhook_state();
		if ( ! empty( $state['id'] ) && ( $state['url'] ?? '' ) === rest_url( AJCore_Extensions::rest_namespace() . '/esign/webhook' ) ) {
			$result = $this->breezedoc_api_request( 'DELETE', '/v1/webhooks/' . rawurlencode( (string) $state['id'] ) );
			if ( is_wp_error( $result ) && 404 !== (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
				return $result;
			}
		}
		delete_option( self::WEBHOOK_OPTION );
		return $this->breezedoc_webhook_status();
	}

	public function breezedoc_webhook_send_test() {
		$state = $this->breezedoc_webhook_state();
		if ( empty( $state['id'] ) ) {
			return new WP_Error( 'no_webhook', __( 'Register the webhook first.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$result = $this->breezedoc_api_request( 'POST', '/v1/webhooks/' . rawurlencode( (string) $state['id'] ) . '/test', array( 'idempotency_key' => 'ajcore-webhook-test-' . wp_generate_password( 12, false ) ) );
		return is_wp_error( $result ) ? $result : array( 'queued' => true );
	}

	public function breezedoc_webhook_deliveries() {
		$state = $this->breezedoc_webhook_state();
		if ( empty( $state['id'] ) ) {
			return array( 'deliveries' => array() );
		}
		$result = $this->breezedoc_api_request( 'GET', '/v1/webhooks/' . rawurlencode( (string) $state['id'] ) . '/deliveries' );
		return is_wp_error( $result ) ? $result : array( 'deliveries' => (array) ( $result['data'] ?? $result ) );
	}

	/**
	 * Public receiver (REST route /ajcore/v1/esign/webhook). BreezeDoc signs the raw body with
	 * HMAC-SHA256 using the endpoint secret; the docs don't name the header, so every common
	 * signature header is accepted, in hex / base64 / `sha256=` / `t=…,v1=…` forms, with or without
	 * a `timestamp.` prefix. The payload is only used to find `doc_…` ids — status always comes
	 * from a fresh authenticated API read.
	 */
	public function handle_esign_webhook( WP_REST_Request $request ) {
		$state  = $this->breezedoc_webhook_state();
		$secret = (string) ( $state['secret'] ?? '' );
		$body   = (string) $request->get_body();
		if ( '' === $secret ) {
			return new WP_Error( 'no_webhook', 'Webhook not registered.', array( 'status' => 404 ) );
		}

		$header = '';
		foreach ( array( 'x_breezedoc_signature', 'x_breezedoc_signature_256', 'x_webhook_signature', 'x_signature', 'x_hub_signature_256', 'signature' ) as $name ) {
			$value = (string) $request->get_header( $name );
			if ( '' !== $value ) {
				$header = $value;
				break;
			}
		}
		$timestamp = '';
		foreach ( array( 'x_breezedoc_timestamp', 'x_webhook_timestamp', 'x_timestamp' ) as $name ) {
			$value = (string) $request->get_header( $name );
			if ( '' !== $value ) {
				$timestamp = $value;
				break;
			}
		}
		if ( '' === $timestamp && preg_match( '/\bt=(\d+)/', $header, $m ) ) {
			$timestamp = $m[1];
		}

		$expected = array( hash_hmac( 'sha256', $body, $secret ), base64_encode( hash_hmac( 'sha256', $body, $secret, true ) ) );
		if ( '' !== $timestamp ) {
			$expected[] = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
			$expected[] = base64_encode( hash_hmac( 'sha256', $timestamp . '.' . $body, $secret, true ) );
		}
		$candidates = array( $header );
		if ( preg_match_all( '/[A-Za-z0-9+\/=]{40,}/', $header, $matches ) ) {
			$candidates = array_merge( $candidates, $matches[0] );
		}
		$candidates[] = preg_replace( '/^sha256=/i', '', $header );
		$valid        = false;
		foreach ( $candidates as $candidate ) {
			foreach ( $expected as $exp ) {
				if ( '' !== $candidate && hash_equals( $exp, (string) $candidate ) ) {
					$valid = true;
					break 2;
				}
			}
		}

		$state['last_event_at'] = current_time( 'mysql' );
		if ( ! $valid ) {
			$state['last_result'] = '' === $header ? 'Rejected: no signature header' : 'Rejected: signature mismatch';
			update_option( self::WEBHOOK_OPTION, $state, false );
			return new WP_Error( 'bad_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}

		$refreshed = 0;
		if ( preg_match_all( '/doc_[A-Za-z0-9]+/', $body, $ids ) && $this->ensure_esign_documents_table() ) {
			$pdb   = $this->get_pdb();
			$table = $this->get_esign_documents_table();
			foreach ( array_slice( array_unique( $ids[0] ), 0, 5 ) as $doc_id ) {
				$row = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE breezedoc_document_id = %s", $doc_id ) );
				if ( $row && ! is_wp_error( $this->refresh_esign_row( $row ) ) ) {
					$refreshed++;
				}
			}
		}
		$state['last_result'] = sprintf( 'OK — %d document(s) refreshed', $refreshed );
		update_option( self::WEBHOOK_OPTION, $state, false );
		return rest_ensure_response( array( 'received' => true, 'refreshed' => $refreshed ) );
	}

	/** Raw samples from each saved credential (team info, first templates, first documents) so a mismatch with what we expect is visible. */
	public function esign_diagnose() {
		if ( ! $this->breezedoc_is_connected() ) {
			return new WP_Error( 'breezedoc_not_configured', __( 'Diagnose needs a Team API key or Personal token.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$calls = array();
		if ( $this->breezedoc_is_v1() ) {
			$calls['team_me']        = array( '/v1/me', array() );
			$calls['team_templates'] = array( '/v1/templates', array( 'limit' => 2 ) );
			$calls['team_documents'] = array( '/v1/documents', array( 'limit' => 2 ) );
		}
		if ( '' !== $this->setting( 'api_token' ) ) {
			$calls['personal_me']        = array( '/me', array() );
			$calls['personal_templates'] = array( '/templates', array() );
			$calls['personal_documents'] = array( '/documents', array() );
		}
		$out = array();
		foreach ( $calls as $key => $call ) {
			$r           = $this->breezedoc_api_request( 'GET', $call[0], $call[1] ? array( 'query' => $call[1] ) : array() );
			$out[ $key ] = is_wp_error( $r ) ? 'ERROR (' . ( $r->get_error_data()['status'] ?? '?' ) . '): ' . $r->get_error_message() : $r;
		}
		return $out;
	}

	/** lower-cased email => array( stripe_customer_id, name ) across Stripe and local portal customers. */
	private function customer_email_map() {
		$map = array();
		foreach ( $this->customers() as $c ) {
			$email = strtolower( trim( $c['email'] ) );
			if ( '' !== $email && ! isset( $map[ $email ] ) ) {
				$map[ $email ] = array( $c['id'], $c['name'] );
			}
		}
		return $map;
	}

	// ── automatic import ────────────────────────────────────────────────────────

	/** Keeps the WP-Cron event in line with the saved frequency; runs on the master site only. */
	public static function sync_schedule() {
		$opt   = get_option( self::OPTION, array() );
		$freq  = is_array( $opt ) && isset( $opt['auto_import'] ) ? (string) $opt['auto_import'] : 'off';
		$next  = wp_next_scheduled( self::CRON_HOOK );
		if ( ! in_array( $freq, array( 'hourly', 'twicedaily', 'daily' ), true ) || ! self::is_master() ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}
		if ( $next && wp_get_schedule( self::CRON_HOOK ) === $freq ) {
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( time() + 300, $freq, self::CRON_HOOK );
	}

	/**
	 * One scheduled pass: import (documents matching a portal customer only), then link a batch of
	 * unlinked rows. Resumes where it stopped if BreezeDoc's 60/min limit is hit; the outcome is shown
	 * in the Connection box.
	 */
	public static function run_auto_import() {
		$esign = self::instance();
		if ( ! $esign->breezedoc_is_connected() || ! self::is_master() ) {
			return;
		}
		$opt      = get_option( self::OPTION, array() );
		$opt      = is_array( $opt ) ? $opt : array();
		$cursor   = (string) ( $opt['auto_cursor'] ?? '' );
		$summary  = array( 'seen' => 0, 'imported' => 0, 'linked' => 0 );
		$deadline = time() + 40;
		$run_at   = time();
		$since    = (int) ( $opt['auto_since'] ?? 0 );
		if ( ! $since ) {
			$since = $run_at - 2 * DAY_IN_SECONDS;
		}
		for ( $i = 0; $i < 6 && time() < $deadline; $i++ ) {
			$r = $esign->import_documents( $cursor, true, $since );
			if ( is_wp_error( $r ) ) {
				$summary['error'] = $r->get_error_message();
				break;
			}
			$summary['seen']     += (int) $r['seen'];
			$summary['imported'] += (int) $r['imported'];
			$cursor               = (string) $r['next_cursor'];
			if ( ! empty( $r['wait'] ) || '' === $cursor ) {
				break;
			}
		}
		$link_after = (int) ( $opt['auto_link_after'] ?? 0 );
		if ( empty( $summary['error'] ) ) {
			$l = $esign->link_documents( $link_after );
			if ( ! is_wp_error( $l ) ) {
				$summary['linked'] = (int) $l['linked'];
				$link_after        = ! empty( $l['done'] ) ? 0 : (int) $l['next_after'];
			}
		}
		$opt                    = get_option( self::OPTION, array() );
		$opt                    = is_array( $opt ) ? $opt : array();
		$opt['auto_cursor']     = $cursor;
		$opt['auto_link_after'] = $link_after;
		$opt['auto_last']       = array( 'at' => current_time( 'mysql' ), 'summary' => $summary );
		if ( '' === $cursor && empty( $summary['error'] ) ) {
			$opt['auto_since'] = $run_at - 2 * DAY_IN_SECONDS; // finished a full pass: next run only needs what is newer
		} elseif ( empty( $opt['auto_since'] ) ) {
			$opt['auto_since'] = $since;
		}
		update_option( self::OPTION, $opt, false );
	}

	// ── emails sent from AJCore (your Zoho / SMTP profile) ──────────────────────

	private function opt( $key, $default = '' ) {
		$opt = get_option( self::OPTION, array() );
		return is_array( $opt ) && isset( $opt[ $key ] ) ? $opt[ $key ] : $default;
	}

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

	public static function preserved_prefixes( $prefixes ) {
		$prefixes[] = 'wp_esign_';
		return $prefixes;
	}

	public static function add_templates( $templates ) {
		return array_merge( $templates, self::email_templates() );
	}

	/** Editable in AJCore's Email Templates screen (overrides saved as wp_esign_*). */
	private static function email_templates() {
		return array(
			'esign_sent'   => array(
				'label'           => __( 'E-Signature Request (heads-up)', 'ajcore-ra' ),
				'key'             => 'wp_esign_sent',
				'sender_key'      => 'wp_esign',
				'brand_variants'  => false,
				'to'              => __( 'Each signer on the request.', 'ajcore-ra' ),
				'fixed'           => __( 'Sent from AJCore when "Email signers from AJCore" is on. BreezeDoc also sends its own signing email with the secure link.', 'ajcore-ra' ),
				'default_subject' => __( 'Please sign: {title}', 'ajcore-ra' ),
				'default_heading' => __( 'A document is waiting for your signature', 'ajcore-ra' ),
				'default_body'    => array(
					sprintf( __( 'Hi %s,', 'ajcore-ra' ), '{name}' ),
					__( 'We have sent you "{title}" to sign electronically. Look for an email from BreezeDoc with your secure signing link; check your spam folder if you do not see it.', 'ajcore-ra' ),
				),
			),
			'esign_signed' => array(
				'label'           => __( 'E-Signature Completed (signed copy)', 'ajcore-ra' ),
				'key'             => 'wp_esign_signed',
				'sender_key'      => 'wp_esign',
				'brand_variants'  => false,
				'to'              => __( 'The customer (or every signer, per the E-Signatures setting).', 'ajcore-ra' ),
				'fixed'           => __( 'The signed PDF is attached when BreezeDoc provides it.', 'ajcore-ra' ),
				'default_subject' => __( 'Your signed copy: {title}', 'ajcore-ra' ),
				'default_heading' => __( 'Your document has been signed', 'ajcore-ra' ),
				'default_body'    => array(
					sprintf( __( 'Hi %s,', 'ajcore-ra' ), '{name}' ),
					__( '"{title}" has been signed by everyone. A copy is attached for your records.', 'ajcore-ra' ),
				),
			),
		);
	}

	/** Sends one of the two emails through AJCore's branded-email toolkit. @return bool */
	private function send_esign_email( $which, $to, $name, $stripe_customer_id, $title, array $attachments = array() ) {
		$to  = sanitize_email( (string) $to );
		$kit = $this->email_kit();
		if ( ! is_email( $to ) || ! $kit ) {
			return false;
		}
		$settings = $kit['settings']();
		$brand    = $kit['brand']( (string) $stripe_customer_id, $to );
		$sender   = $kit['sender']( $settings, 'wp_esign_from_email', 'wp_esign_from_name' );
		if ( $kit['is_brand_variant']( $brand ) ) {
			$sender['from_name'] = $brand['site_name'];
		}
		$key      = 'signed' === $which ? 'esign_signed' : 'esign_sent';
		$prefix   = 'signed' === $which ? 'wp_esign_signed' : 'wp_esign_sent';
		$template = self::email_templates()[ $key ];
		$tokens   = array( '{name}' => '' !== trim( (string) $name ) ? $name : $to, '{title}' => '' !== trim( (string) $title ) ? $title : __( 'your document', 'ajcore-ra' ) );

		$subject_template = ! empty( $settings[ $prefix . '_subject' ] ) ? sanitize_text_field( (string) $settings[ $prefix . '_subject' ] ) : $template['default_subject'];
		$subject          = $kit['brand_subject']( strtr( $subject_template, $tokens ), $brand );
		$copy             = $kit['copy_raw']( $settings, $prefix . '_heading', $prefix . '_body', $template['default_heading'], $template['default_body'], $tokens );
		$message          = $kit['render'](
			array(
				'kicker'         => $brand['site_name'],
				'heading'        => $copy['heading'],
				'paragraphs'     => $copy['paragraphs'],
				'info_box_label' => __( 'Document', 'ajcore-ra' ),
				'info_box_value' => (string) $title,
				'footer_note'    => 'signed' === $which ? __( 'Keep this copy with your records.', 'ajcore-ra' ) : __( 'The signing link is in BreezeDoc\'s email to you.', 'ajcore-ra' ),
			)
		);
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $sender['from_email'] ) ) {
			$headers[] = 'From: ' . $sender['from_name'] . ' <' . $sender['from_email'] . '>';
			$headers[] = 'Reply-To: ' . $sender['from_email'];
		}
		return (bool) $kit['send']( $to, $subject, $message, $headers, $sender['from_email'], $sender['from_name'], isset( $sender['profile'] ) ? $sender['profile'] : '', $attachments );
	}

	/** Optional heads-up to each signer right after a request is sent (BreezeDoc still sends the signing link). */
	private function maybe_notify_signers( $row ) {
		if ( ! $this->opt( 'notify_on_send', 0 ) || ! is_array( $row ) ) {
			return;
		}
		foreach ( (array) $row['recipients'] as $r ) {
			$this->send_esign_email( 'sent', $r['email'] ?? '', $r['name'] ?? '', $row['stripeCustomerId'] ?? '', $row['templateTitle'] ?? '' );
		}
	}

	/**
	 * Runs once when a refresh sees a Team-API document become completed (never for imported history):
	 * downloads the signed PDF, optionally files it in the customer's portal Files, and optionally emails
	 * it (customer only, or every signer) from AJCore's own sender with the PDF attached.
	 */
	private function on_completed( $row, array $recipients ) {
		$doc_id = (string) $row->breezedoc_document_id;
		if ( 0 !== strpos( $doc_id, 'doc_' ) ) {
			return; // only the Team API offers the signed file
		}
		$mode = (string) $this->opt( 'signed_copy', 'off' );
		$file = (bool) $this->opt( 'file_signed', 0 );
		if ( 'off' === $mode && ! $file ) {
			return;
		}
		if ( in_array( $doc_id, $this->id_list( self::SIGNED_DONE_ROW ), true ) ) {
			return;
		}
		$this->add_ids( self::SIGNED_DONE_ROW, array( $doc_id ) ); // mark first: a failure must never re-send

		$dl = $this->breezedoc_api_request( 'GET', '/v1/documents/' . rawurlencode( $doc_id ) . '/download' );
		if ( is_wp_error( $dl ) ) {
			return;
		}
		$url = (string) ( $dl['url'] ?? $dl['pdf_url'] ?? $dl['download_url'] ?? '' );
		$dir = trailingslashit( get_temp_dir() ) . 'ajra-' . wp_generate_password( 8, false );
		$pdf = '';
		if ( '' !== $url && wp_mkdir_p( $dir ) ) {
			$resp = wp_remote_get( $url, array( 'timeout' => 40 ) );
			if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) && 0 === strpos( wp_remote_retrieve_body( $resp ), '%PDF' ) ) {
				$name = sanitize_file_name( ( '' !== (string) $row->template_title ? (string) $row->template_title : 'signed-document' ) );
				$name = preg_replace( '/\.pdf$/i', '', $name ) . ' - signed.pdf';
				$pdf  = $dir . '/' . $name;
				file_put_contents( $pdf, wp_remote_retrieve_body( $resp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

		if ( $file && '' !== $pdf && '' !== (string) $row->stripe_customer_id && self::admin() ) {
			$copy = wp_tempnam( basename( $pdf ) );
			copy( $pdf, $copy );
			self::admin()->upload_files_for_portal_customer(
				$row->stripe_customer_id,
				array( 'name' => basename( $pdf ), 'type' => 'application/pdf', 'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $copy ) ),
				'Others',
				''
			);
			if ( file_exists( $copy ) ) {
				unlink( $copy ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		if ( 'off' !== $mode ) {
			$targets = array();
			if ( 'all' === $mode ) {
				foreach ( $recipients as $r ) {
					$targets[ strtolower( (string) ( $r['email'] ?? '' ) ) ] = (string) ( $r['name'] ?? '' );
				}
			} else {
				$kit      = $this->email_kit();
				$customer = $kit && '' !== (string) $row->stripe_customer_id ? $kit['customer']( $row->stripe_customer_id ) : null;
				if ( $customer && is_email( $customer->email ) ) {
					$targets[ strtolower( $customer->email ) ] = (string) $customer->name;
				}
			}
			unset( $targets[''] );
			foreach ( $targets as $email => $name ) {
				$this->send_esign_email( 'signed', $email, $name, $row->stripe_customer_id, (string) $row->template_title, '' !== $pdf ? array( $pdf ) : array() );
			}
		}

		if ( '' !== $pdf && file_exists( $pdf ) ) {
			unlink( $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir
		}
	}

	// ── deleting local copies ───────────────────────────────────────────────────

	/** A persisted list of ids: shared-DB row when sharing is on, else a site option. @return string[] */
	private function id_list( $row_name ) {
		if ( self::is_shared() ) {
			list( $db, $table ) = self::shared_db_and_table();
			if ( $db ) {
				$value   = $db->get_var( $db->prepare( "SELECT setting_value FROM `{$table}` WHERE setting_name = %s LIMIT 1", $row_name ) );
				$decoded = json_decode( (string) $value, true );
				return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
			}
		}
		$list = get_option( $row_name, array() );
		return is_array( $list ) ? array_map( 'strval', $list ) : array();
	}

	private function clear_ids( $row_name ) {
		if ( self::is_shared() ) {
			list( $db, $table ) = self::shared_db_and_table();
			if ( $db ) {
				$db->delete( $table, array( 'setting_name' => $row_name ), array( '%s' ) );
				return;
			}
		}
		delete_option( $row_name );
	}

	private function add_ids( $row_name, array $ids ) {
		$all     = array_slice( array_values( array_unique( array_merge( $this->id_list( $row_name ), array_map( 'strval', $ids ) ) ) ), -5000 );
		$encoded = wp_json_encode( $all );
		if ( self::is_shared() ) {
			list( $db, $table ) = self::shared_db_and_table();
			if ( $db ) {
				$exists = $db->get_var( $db->prepare( "SELECT setting_name FROM `{$table}` WHERE setting_name = %s LIMIT 1", $row_name ) );
				if ( $exists ) {
					$db->update( $table, array( 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ), array( 'setting_name' => $row_name ), array( '%s', '%s' ), array( '%s' ) );
				} else {
					$db->insert( $table, array( 'setting_name' => $row_name, 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ), array( '%s', '%s', '%s' ) );
				}
				return;
			}
		}
		update_option( $row_name, $all, false );
	}

	private function selected_ids( $ids ) {
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

	/** Moves rows to Deleted (status "deleted:<previous>"). This portal only — nothing changes in BreezeDoc. */
	public function delete_logged_documents( $ids ) {
		$ids = $this->selected_ids( $ids );
		if ( empty( $ids ) ) {
			return new WP_Error( 'no_selection', __( 'Select at least one document.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( ! $this->ensure_esign_documents_table() ) {
			return new WP_Error( 'no_table', __( 'E-Signatures log table could not be created.', 'ajcore-ra' ) );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_esign_documents_table();
		$list  = implode( ',', $ids ); // integers only
		return array( 'deleted' => (int) $pdb->query( "UPDATE `{$table}` SET status = CONCAT('deleted:', LEFT(status, 20)), updated_at = NOW() WHERE id IN ({$list}) AND status NOT LIKE 'deleted:%'" ) );
	}

	/** Puts rows from Deleted back with their previous status. */
	public function restore_logged_documents( $ids ) {
		$ids = $this->selected_ids( $ids );
		if ( empty( $ids ) ) {
			return new WP_Error( 'no_selection', __( 'Select at least one document.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( ! $this->ensure_esign_documents_table() ) {
			return new WP_Error( 'no_table', __( 'E-Signatures log table could not be created.', 'ajcore-ra' ) );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_esign_documents_table();
		$list  = implode( ',', $ids );
		return array( 'restored' => (int) $pdb->query( "UPDATE `{$table}` SET status = IF(SUBSTRING(status, 9) = '', 'sent', SUBSTRING(status, 9)), updated_at = NOW() WHERE id IN ({$list}) AND status LIKE 'deleted:%'" ) );
	}

	/** Really deletes rows that are in Deleted. Nothing is remembered. */
	// ── Drafts: a request you started but have not sent ("Not sent"). Local only until it is sent. ──

	const DRAFTS_OPTION = 'ajcore_ra_esign_drafts';

	private function drafts_all() {
		$d = get_option( self::DRAFTS_OPTION, array() );
		return is_array( $d ) ? $d : array();
	}

	private function draft_pdf_path( $bid ) {
		$dir = $this->tpl_dir() . '/drafts';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir . '/' . sanitize_file_name( (string) $bid ) . '.pdf';
	}

	/** Creates or updates a draft. $payload: mode, template_id, customer_id, recipients, layout, vars, expires, ordered, message, title, page. */
	public function save_draft( $local_id, array $payload, $file = null ) {
		if ( ! $this->ensure_esign_documents_table() ) {
			return new WP_Error( 'no_table', __( 'E-Signatures log table could not be created.', 'ajcore-ra' ) );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_esign_documents_table();
		$mode  = 'pdf' === ( $payload['mode'] ?? '' ) ? 'pdf' : 'template';
		$rec   = array();
		foreach ( array_values( (array) ( $payload['recipients'] ?? array() ) ) as $i => $r ) {
			$rec[] = array( 'party' => $i + 1, 'name' => sanitize_text_field( (string) ( $r['name'] ?? '' ) ), 'email' => sanitize_email( (string) ( $r['email'] ?? '' ) ) );
		}
		$vars = array();
		foreach ( (array) ( $payload['vars'] ?? array() ) as $k => $v ) {
			$vars[ sanitize_key( (string) $k ) ] = sanitize_text_field( (string) $v );
		}
		$clean = array(
			'mode'        => $mode,
			'template_id' => sanitize_text_field( (string) ( $payload['template_id'] ?? '' ) ),
			'customer_id' => sanitize_text_field( (string) ( $payload['customer_id'] ?? '' ) ),
			'recipients'  => $rec,
			'layout'      => self::clean_layout( $payload['layout'] ?? array() ),
			'vars'        => $vars,
			'expires'     => max( 1, min( 365, (int) ( $payload['expires'] ?? 30 ) ) ),
			'ordered'     => ! isset( $payload['ordered'] ) || (bool) $payload['ordered'],
			'message'     => sanitize_textarea_field( (string) ( $payload['message'] ?? '' ) ),
			'title'       => mb_substr( sanitize_text_field( (string) ( $payload['title'] ?? '' ) ), 0, 191 ),
			'page'        => max( 0, (int) ( $payload['page'] ?? 0 ) ),
			'pdf_name'    => sanitize_file_name( (string) ( $payload['pdf_name'] ?? '' ) ),
			'saved'       => current_time( 'mysql' ),
		);
		// Name shown in the list: the form's title, the PDF's title, or "Untitled".
		$title = '';
		if ( 'template' === $mode && '' !== $clean['template_id'] ) {
			$all   = $this->local_templates();
			$title = isset( $all[ $clean['template_id'] ] ) ? (string) $all[ $clean['template_id'] ]['title'] : '';
		}
		if ( '' === $title ) {
			$title = '' !== $clean['title'] ? $clean['title'] : preg_replace( '/\.pdf$/i', '', $clean['pdf_name'] );
		}
		if ( '' === $title ) {
			$title = __( 'Untitled document', 'ajcore-ra' );
		}
		$cust_name = '';
		foreach ( $this->customers() as $c ) {
			if ( $c['id'] === $clean['customer_id'] ) {
				$cust_name = $c['name'];
				break;
			}
		}
		$row = (int) $local_id ? $pdb->get_row( $pdb->prepare( "SELECT id, breezedoc_document_id FROM `{$table}` WHERE id = %d", (int) $local_id ) ) : null;
		if ( $row && 0 === strpos( (string) $row->breezedoc_document_id, 'draft_' ) ) {
			$bid = (string) $row->breezedoc_document_id;
			$pdb->update( $table, array( 'template_title' => $title, 'stripe_customer_id' => $clean['customer_id'], 'customer_name' => $cust_name, 'recipients' => wp_json_encode( $rec ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row->id ) );
			$id = (int) $row->id;
		} else {
			$bid = 'draft_' . strtolower( wp_generate_password( 12, false ) );
			$pdb->insert( $table, array(
				'breezedoc_document_id' => $bid,
				'breezedoc_template_id' => $clean['template_id'],
				'template_title'        => $title,
				'stripe_customer_id'    => $clean['customer_id'],
				'customer_name'         => $cust_name,
				'status'                => 'draft',
				'recipients'            => wp_json_encode( $rec ),
				'document_url'          => '',
				'sent_at'               => current_time( 'mysql' ),
				'created_by'            => get_current_user_id(),
				'created_at'            => current_time( 'mysql' ),
				'updated_at'            => current_time( 'mysql' ),
			), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
			$id = (int) $pdb->insert_id;
		}
		if ( ! $id ) {
			return new WP_Error( 'draft_failed', __( 'Could not save the draft.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$path = $this->draft_pdf_path( $bid );
		if ( 'pdf' === $mode && is_array( $file ) && ! empty( $file['tmp_name'] ) && empty( $file['error'] ) && filesize( $file['tmp_name'] ) <= 25 * MB_IN_BYTES ) {
			$head = (string) file_get_contents( $file['tmp_name'], false, null, 0, 1024 );
			if ( 0 === strpos( ltrim( $head ), '%PDF-' ) ) {
				copy( $file['tmp_name'], $path );
				$clean['pdf_name'] = sanitize_file_name( (string) ( $file['name'] ?? 'document.pdf' ) );
			}
		}
		$clean['has_pdf'] = 'pdf' === $mode && is_readable( $path );
		$all              = $this->drafts_all();
		$all[ $bid ]      = $clean;
		update_option( self::DRAFTS_OPTION, $all, false );
		return array( 'id' => $id, 'savedAt' => $clean['saved'] );
	}

	/** A draft's saved state, for continuing it. */
	public function get_draft( $local_id ) {
		$row = $this->get_esign_row( (int) $local_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$bid = (string) $row->breezedoc_document_id;
		$all = $this->drafts_all();
		if ( 0 !== strpos( $bid, 'draft_' ) || ! isset( $all[ $bid ] ) ) {
			return new WP_Error( 'not_a_draft', __( 'This is not a saved draft.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		return array_merge( array( 'id' => (int) $row->id ), $all[ $bid ] );
	}

	/** Removes a draft completely (its saved state, its PDF and its list row) — used once it has been sent. */
	public function discard_draft( $local_id ) {
		$row = $this->get_esign_row( (int) $local_id );
		if ( is_wp_error( $row ) ) {
			return false;
		}
		$bid = (string) $row->breezedoc_document_id;
		if ( 0 !== strpos( $bid, 'draft_' ) ) {
			return false;
		}
		$this->forget_draft( $bid );
		$this->get_pdb()->delete( $this->get_esign_documents_table(), array( 'id' => (int) $row->id ) );
		return true;
	}

	private function forget_draft( $bid ) {
		$all = $this->drafts_all();
		unset( $all[ $bid ] );
		update_option( self::DRAFTS_OPTION, $all, false );
		$path = $this->draft_pdf_path( $bid );
		if ( is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	public function purge_logged_documents( $ids ) {
		$ids = $this->selected_ids( $ids );
		if ( empty( $ids ) ) {
			return new WP_Error( 'no_selection', __( 'Select at least one document.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( ! $this->ensure_esign_documents_table() ) {
			return new WP_Error( 'no_table', __( 'E-Signatures log table could not be created.', 'ajcore-ra' ) );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_esign_documents_table();
		$list  = implode( ',', $ids );
		foreach ( (array) $pdb->get_col( "SELECT breezedoc_document_id FROM `{$table}` WHERE id IN ({$list}) AND status LIKE 'deleted:%' AND breezedoc_document_id LIKE 'draft\_%'" ) as $bid ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->forget_draft( (string) $bid );
		}
		return array( 'purged' => (int) $pdb->query( "DELETE FROM `{$table}` WHERE id IN ({$list}) AND status LIKE 'deleted:%'" ) );
	}

	/** Raw recipient arrays for one document (legacy numeric id → /documents/{id}/recipients, doc_… → /v1/documents/{id}). */
	private function fetch_document_recipients( $doc_id ) {
		if ( 0 === strpos( (string) $doc_id, 'doc_' ) ) {
			$r = $this->breezedoc_api_request( 'GET', '/v1/documents/' . rawurlencode( (string) $doc_id ) );
			return is_wp_error( $r ) ? $r : (array) ( $r['recipients'] ?? array() );
		}
		$r = $this->breezedoc_api_request( 'GET', '/documents/' . absint( $doc_id ) . '/recipients' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return (array) ( isset( $r['data'] ) && is_array( $r['data'] ) ? $r['data'] : $r );
	}

	private static function normalize_name( $value ) {
		$value = strtolower( remove_accents( (string) $value ) );
		$value = preg_replace( '/\.pdf$/', '', $value );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		return trim( (string) $value );
	}

	/** normalized customer name => array( stripe_customer_id, name ), only names long enough to match safely. */
	private function customer_name_index() {
		$index = array();
		foreach ( $this->customers() as $c ) {
			$norm = self::normalize_name( $c['name'] );
			if ( strlen( $norm ) >= 8 && ! isset( $index[ $norm ] ) ) {
				$index[ $norm ] = array( $c['id'], $c['name'] );
			}
		}
		return $index;
	}

	/**
	 * Links a document to a portal customer: first by any signer's email, then by a customer's
	 * company name appearing in the document title (only when exactly one customer matches).
	 *
	 * @return array|null array( stripe_customer_id, name ) or null
	 */
	private function match_customer( array $recipients, $title, array $email_map, array $name_index ) {
		foreach ( $recipients as $r ) {
			$email = strtolower( trim( (string) ( $r['email'] ?? '' ) ) );
			if ( '' !== $email && isset( $email_map[ $email ] ) ) {
				return $email_map[ $email ];
			}
		}
		$haystack = ' ' . self::normalize_name( $title ) . ' ';
		$hits     = array();
		foreach ( $name_index as $norm => $customer ) {
			if ( false !== strpos( $haystack, ' ' . $norm . ' ' ) ) {
				$hits[ $customer[0] ] = $customer;
			}
		}
		return 1 === count( $hits ) ? array_values( $hits )[0] : null;
	}

	/**
	 * Links already-logged documents that have no customer yet (imports made before signers were
	 * fetched, or before the customer existed). Up to 25 rows per call; returns next_after so the
	 * caller can continue, and `wait` when BreezeDoc's 60/min limit was hit (retry the same call).
	 */
	public function link_documents( $after_id = 0 ) {
		if ( ! $this->ensure_esign_documents_table() ) {
			return new WP_Error( 'no_table', __( 'E-Signatures log table could not be created.', 'ajcore-ra' ) );
		}
		$pdb    = $this->get_pdb();
		$table  = $this->get_esign_documents_table();
		$map    = $this->customer_email_map();
		$names  = $this->customer_name_index();
		$rows   = $pdb->get_results( $pdb->prepare( "SELECT * FROM `{$table}` WHERE stripe_customer_id = '' AND status NOT LIKE 'deleted:%%' AND id > %d ORDER BY id ASC LIMIT 25", absint( $after_id ) ) );
		$counts = array( 'checked' => 0, 'linked' => 0, 'next_after' => 0, 'done' => count( $rows ) < 25 );
		foreach ( $rows as $row ) {
			$recipients = $row->recipients ? json_decode( (string) $row->recipients, true ) : array();
			$recipients = is_array( $recipients ) ? $recipients : array();
			$update     = array();
			if ( empty( $recipients ) ) {
				$fetched = $this->fetch_document_recipients( $row->breezedoc_document_id );
				if ( is_wp_error( $fetched ) ) {
					if ( 429 === (int) ( $fetched->get_error_data()['status'] ?? 0 ) ) {
						$counts['next_after'] = max( 0, (int) $row->id - 1 );
						$counts['done']       = false;
						$counts['wait']       = 30;
						return $counts;
					}
				} else {
					$recipients           = $this->breezedoc_map_recipients( $fetched );
					$update['recipients'] = wp_json_encode( $recipients );
				}
			}
			$customer = $this->match_customer( $recipients, (string) $row->template_title, $map, $names );
			if ( $customer ) {
				$update['stripe_customer_id'] = $customer[0];
				$update['customer_name']      = $customer[1];
				$counts['linked']++;
			}
			if ( $update ) {
				$update['updated_at'] = current_time( 'mysql' );
				$pdb->update( $table, $update, array( 'id' => $row->id ) );
			}
			$counts['checked']++;
			$counts['next_after'] = (int) $row->id;
		}
		return $counts;
	}

	/**
	 * Pulls historical documents from BreezeDoc into the local log: the team's (Team API key, `doc_` ids)
	 * and then your own (Personal token, numeric ids). The account may hold documents from other
	 * businesses, so by default only documents with a signer whose email matches a portal customer are
	 * imported; drafts are always skipped. Processes up to 300 documents per call and returns
	 * next_cursor ("p:N" = your own documents, page N) so the caller can continue.
	 */
	public function import_documents( $cursor = '', $include_unmatched = false, $since_ts = 0, $sync = false ) {
		if ( ! $this->breezedoc_is_connected() ) {
			return new WP_Error( 'breezedoc_not_configured', __( 'Import needs a BreezeDoc Team API key or Personal token.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		if ( ! $this->ensure_esign_documents_table() ) {
			return new WP_Error( 'no_table', __( 'E-Signatures log table could not be created.', 'ajcore-ra' ) );
		}
		$pdb    = $this->get_pdb();
		$table  = $this->get_esign_documents_table();
		$map    = $this->customer_email_map();
		$names  = $this->customer_name_index();
		$fetch_budget = 25; // signer lookups per call (BreezeDoc allows 60 requests/minute)
		$counts = array( 'imported' => 0, 'existing' => 0, 'unmatched' => 0, 'drafts' => 0, 'seen' => 0, 'updated' => 0, 'pruned' => 0 );
		$cursor = sanitize_text_field( (string) $cursor );

		// Sync: BreezeDoc is the source of truth. Every id seen across the whole listing is remembered so that, once the
		// listing has finished without error, local rows that no longer exist in BreezeDoc can be removed.
		$sk = 'ajcore_ra_esign_sync';
		$st = array( 'seen' => array(), 'team' => false, 'personal' => false );
		if ( $sync && '' !== $cursor ) {
			$prev = get_transient( $sk );
			if ( is_array( $prev ) && isset( $prev['seen'] ) ) {
				$st = array_merge( $st, $prev );
			}
		}
		$persist = function () use ( &$st, $sync, $sk ) {
			if ( $sync ) {
				set_transient( $sk, $st, 6 * HOUR_IN_SECONDS );
			}
		};

		$personal = 0 === strpos( $cursor, 'p:' );
		if ( ! $personal && ! $this->breezedoc_is_v1() ) {
			$personal = true;
			$cursor   = 'p:1';
		}
		$page_no = $personal ? max( 1, (int) substr( $cursor, 2 ) ) : 0;

		for ( $i = 0; $i < 3; $i++ ) {
			$page_cursor = $personal ? 'p:' . $page_no : $cursor;
			if ( $personal ) {
				$result = $this->breezedoc_api_request( 'GET', '/documents', array( 'query' => array( 'page' => $page_no, 'order_by' => 'id', 'direction' => 'desc' ) ) );
			} else {
				$query = array( 'limit' => 100 );
				if ( '' !== $cursor ) {
					$query['cursor'] = $cursor;
				}
				$result = $this->breezedoc_api_request( 'GET', '/v1/documents', array( 'query' => $query ) );
			}
			if ( is_wp_error( $result ) ) {
				$persist();
				return $result;
			}
			foreach ( (array) ( $result['data'] ?? array() ) as $doc ) {
				$doc_id = isset( $doc['id'] ) ? (string) $doc['id'] : '';
				if ( '' === $doc_id ) {
					continue;
				}
				if ( ! isset( $st['seen'][ $doc_id ] ) ) { // a page re-read after a rate-limit pause must not count twice
					$counts['seen']++;
				}
				$st['seen'][ $doc_id ] = 1;
				if ( $since_ts ) { // automatic runs only look at recent documents
					$created = strtotime( (string) ( $doc['created_at'] ?? $doc['sent_at'] ?? '' ) );
					if ( $created && $created < $since_ts ) {
						continue;
					}
				}
				$have = $pdb->get_row( $pdb->prepare( "SELECT id, status, template_title FROM `{$table}` WHERE breezedoc_document_id = %s", $doc_id ) );
				if ( $have ) {
					$counts['existing']++;
					if ( $sync ) { // refresh what BreezeDoc says now (title, and status when the list gives it reliably)
						$cur   = (string) $have->status;
						$del   = 0 === strpos( $cur, 'deleted:' );
						$base  = $del ? substr( $cur, 8 ) : $cur;
						$upd   = array();
						$now   = $personal ? $this->breezedoc_legacy_doc_state( $doc ) : $this->breezedoc_v1_doc_state( $doc );
						$trust = $personal ? ! empty( $doc['completed_at'] ) : ( isset( $doc['status'] ) || ! empty( $doc['completed_at'] ) );
						if ( $trust && '' !== $now['status'] && $now['status'] !== $base ) {
							$upd['status'] = $del ? 'deleted:' . substr( $now['status'], 0, 20 ) : $now['status'];
							if ( '' !== $now['completed_at'] ) {
								$upd['completed_at'] = $now['completed_at'];
							}
						}
						if ( '' !== $now['title'] && $now['title'] !== (string) $have->template_title ) {
							$upd['template_title'] = $now['title'];
						}
						if ( $upd ) {
							$upd['updated_at'] = current_time( 'mysql' );
							$pdb->update( $table, $upd, array( 'id' => (int) $have->id ) );
							$counts['updated']++;
						}
					}
					continue;
				}
				// The list responses carry no signers: look them up so the document can be linked by email.
				if ( empty( $doc['recipients'] ) && $fetch_budget > 0 ) {
					$fetched = $this->fetch_document_recipients( $doc_id );
					if ( is_wp_error( $fetched ) ) {
						if ( 429 === (int) ( $fetched->get_error_data()['status'] ?? 0 ) ) {
							$counts['next_cursor'] = $page_cursor; // rerun this page after a pause; imported rows are skipped as existing
							$counts['wait']        = 30;
							$persist();
							return $counts;
						}
					} else {
						$doc['recipients'] = $fetched;
						$fetch_budget--;
					}
				}
				$state = $personal ? $this->breezedoc_legacy_doc_state( $doc ) : $this->breezedoc_v1_doc_state( $doc );
				if ( 'draft' === $state['status'] ) {
					$counts['drafts']++; // imported too, shown under the Drafts filter
				}
				$customer = $this->match_customer( $state['recipients'], $state['title'], $map, $names );
				if ( ! $customer && ! $include_unmatched ) {
					$counts['unmatched']++;
					continue;
				}
				$sent_raw = (string) ( $doc['sent_at'] ?? '' );
				if ( '' === $sent_raw && ! empty( $doc['recipients'][0]['sent_at'] ) ) {
					$sent_raw = (string) $doc['recipients'][0]['sent_at'];
				}
				if ( '' === $sent_raw ) {
					$sent_raw = (string) ( $doc['created_at'] ?? '' );
				}
				$sent_at = $sent_raw && strtotime( $sent_raw ) ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $sent_raw ) ) ) : current_time( 'mysql' );
				$data    = array(
					'breezedoc_document_id' => $doc_id,
					'breezedoc_template_id' => (string) ( $doc['template_id'] ?? '' ),
					'template_title'        => $state['title'],
					'stripe_customer_id'    => $customer ? $customer[0] : '',
					'customer_name'         => $customer ? $customer[1] : '',
					'status'                => $state['status'],
					'recipients'            => wp_json_encode( $state['recipients'] ),
					'document_url'          => $state['url'],
					'sent_at'               => $sent_at,
					'created_by'            => 0,
					'created_at'            => current_time( 'mysql' ),
					'updated_at'            => current_time( 'mysql' ),
				);
				$format  = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
				if ( '' !== $state['completed_at'] ) {
					$data['completed_at'] = $state['completed_at'];
					$format[]             = '%s';
				}
				$pdb->insert( $table, $data, $format );
				$counts['imported']++;
			}
			if ( $personal ) {
				if ( empty( $result['next_page_url'] ) ) {
					$cursor        = '';
					$st['personal'] = true;
					break;
				}
				$page_no++;
				$cursor = 'p:' . $page_no;
			} else {
				$cursor = isset( $result['next_cursor'] ) ? (string) $result['next_cursor'] : '';
				if ( '' === $cursor ) {
					$st['team'] = true;
					if ( '' !== $this->setting( 'api_token' ) ) {
						$cursor = 'p:1'; // team finished: continue with your own documents
					}
					break;
				}
			}
		}
		$counts['next_cursor'] = $cursor;
		if ( $sync ) {
			if ( '' === $cursor ) { // listing finished: drop local rows BreezeDoc no longer has
				$pruned = $this->prune_missing_documents( $st );
				if ( is_array( $pruned ) ) {
					$counts['pruned_blocked'] = $pruned['blocked'];
				} else {
					$counts['pruned'] = (int) $pruned;
				}
				delete_transient( $sk );
			} else {
				$persist();
			}
		}
		return $counts;
	}

	/**
	 * Removes local rows whose BreezeDoc document is gone. Only runs after a complete listing of that account (team ids
	 * `doc_…`, personal numeric ids), never on an empty listing, and refuses to remove more than half the log at once.
	 *
	 * @return int|array Rows removed, or array( 'blocked' => n ) when the safety limit stopped it.
	 */
	private function prune_missing_documents( array $st ) {
		if ( empty( $st['seen'] ) ) {
			return 0;
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_esign_documents_table();
		$rows  = (array) $pdb->get_results( "SELECT id, breezedoc_document_id AS bid FROM `{$table}` WHERE breezedoc_document_id <> ''" );
		$gone  = array();
		foreach ( $rows as $r ) {
			$bid = (string) $r->bid;
			if ( isset( $st['seen'][ $bid ] ) ) {
				continue;
			}
			if ( ( ! empty( $st['team'] ) && 0 === strpos( $bid, 'doc_' ) ) || ( ! empty( $st['personal'] ) && ctype_digit( $bid ) ) ) {
				$gone[] = (int) $r->id;
			}
		}
		if ( ! $gone ) {
			return 0;
		}
		if ( count( $gone ) > max( 10, (int) ( count( $rows ) * 0.5 ) ) ) {
			return array( 'blocked' => count( $gone ) );
		}
		foreach ( array_chunk( $gone, 100 ) as $chunk ) {
			$pdb->query( "DELETE FROM `{$table}` WHERE id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return count( $gone );
	}

	// ── REST ────────────────────────────────────────────────────────────────────

	public static function catalog( $catalog ) {
		$e = static function ( $method, $path, $purpose ) {
			return array( 'surface' => 'OPS', 'method' => $method, 'path' => $path, 'auth' => 'Admin', 'purpose' => $purpose, 'app' => 'OPS e-signatures' );
		};
		$catalog[] = $e( 'GET', '/ops/esign/summary', 'Number of e-signature requests waiting for signature (sent, not fully signed).' );
		$catalog[] = $e( 'POST', '/ops/esign/preview-filled', 'Builds the request as it would be sent (form id or uploaded pdf, customer, recipients, layout, vars) and returns the filled PDF with signer boxes outlined. Nothing is sent to BreezeDoc.' );
		$catalog[] = $e( 'POST', '/ops/esign/merge-values', 'Resolves every merge field for one customer (stripe_customer_id, recipients, vars) as token => value, for live previews.' );
		$catalog[] = $e( 'GET', '/ops/esign/test', 'Tests the BreezeDoc Team API key (or legacy Personal token).' );
		$catalog[] = $e( 'GET', '/ops/esign/templates', 'Lists BreezeDoc templates (id, title, numbered signer parties).' );
		$catalog[] = $e( 'POST', '/ops/esign/templates/duplicate', 'Copies an AJCore template (and its PDF). JSON: id.' );
		$catalog[] = $e( 'POST', '/ops/esign/templates/save', 'Creates or updates an AJCore template (multipart: id?, title, signers[] role labels, page, pdf). AJCore templates are sent through the PDF upload (Team key).' );
		$catalog[] = $e( 'POST', '/ops/esign/templates/delete', 'Deletes an AJCore template and its stored PDF (body: id).' );
		$catalog[] = $e( 'GET', '/ops/esign/templates/{id}/file', 'Streams an AJCore template PDF.' );
		$catalog[] = $e( 'GET', '/ops/esign/templates/{id}', 'Fetches one BreezeDoc template with its signer parties.' );
		$catalog[] = $e( 'POST', '/ops/esign/send', 'Creates a document from a template (template_id, recipients[{party,name,email}], optional signing_order/message/expires_in_days/title), sends it, logs it.' );
		$catalog[] = $e( 'POST', '/ops/esign/send-pdf', 'Multipart: uploads a PDF (pdf, recipients JSON, stripe_customer_id, title, page, message, expires_in_days), places signature+date fields per signer, sends, logs it. Team API key only.' );
		$catalog[] = $e( 'GET', '/ops/esign/documents', 'Local log of sent signature requests with their last-known status.' );
		$catalog[] = $e( 'POST', '/ops/esign/documents/restore-rows', 'Restores documents from Deleted (body: ids).' );
		$catalog[] = $e( 'POST', '/ops/esign/documents/purge', 'Permanently deletes documents that are in Deleted (body: ids). Nothing is remembered and nothing changes in BreezeDoc.' );
		$catalog[] = $e( 'POST', '/ops/esign/documents/delete', 'Moves log rows (body: ids) to Deleted — this portal only, nothing changes in BreezeDoc. Restore them or delete them permanently from Deleted.' );
		$catalog[] = $e( 'GET', '/ops/esign/settings', 'Auto-import frequency, last automatic run, and which BreezeDoc credentials are saved.' );
		$catalog[] = $e( 'POST', '/ops/esign/settings/save', 'Sets the auto-import frequency (auto_import: off|hourly|twicedaily|daily). Master site only.' );
		$catalog[] = $e( 'POST', '/ops/esign/link', 'Links logged documents that have no customer yet: by signer email, then by a customer name in the title (after_id, 25 per call).' );
		$catalog[] = $e( 'GET', '/ops/esign/diagnose', 'Raw samples from the BreezeDoc Team API (team, first templates, first documents) for troubleshooting. Team API key only.' );
		$catalog[] = $e( 'POST', '/ops/esign/import', 'Imports historical BreezeDoc team documents into the local log (cursor, include_unmatched). Only documents whose signer email matches a portal customer unless include_unmatched. Team API key only.' );
		$catalog[] = $e( 'POST', '/ops/esign/documents/{id}/refresh', 'Re-reads one document\'s status from BreezeDoc.' );
		$catalog[] = $e( 'POST', '/ops/esign/documents/{id}/remind', 'Reminds unsigned recipients (BreezeDoc allows once per 24 hours). Team API key only.' );
		$catalog[] = $e( 'POST', '/ops/esign/documents/{id}/cancel', 'Cancels a sent document so it can no longer be signed. Team API key only.' );
		$catalog[] = $e( 'GET', '/ops/esign/documents/{id}/download', 'Short-lived (15 min) signed URL for the completed PDF and certificate. Team API key only.' );
		$catalog[] = $e( 'GET', '/ops/esign/documents/{id}/preview', 'What can be shown for a document: signed-PDF link (team documents once completed), page images if BreezeDoc returns any (personal documents), else the BreezeDoc viewer URL.' );
		$catalog[] = $e( 'GET', '/ops/esign/documents/{id}/events', 'Audit events for a document. Team API key only.' );
		$catalog[] = $e( 'GET', '/ops/esign/webhook', 'BreezeDoc webhook registration state (never the secret).' );
		$catalog[] = $e( 'POST', '/ops/esign/webhook/register', 'Registers (or re-registers) the BreezeDoc webhook pointing at this site.' );
		$catalog[] = $e( 'POST', '/ops/esign/webhook/test', 'Queues a BreezeDoc test event to the registered webhook.' );
		$catalog[] = $e( 'POST', '/ops/esign/webhook/remove', 'Removes the BreezeDoc webhook.' );
		$catalog[] = $e( 'GET', '/ops/esign/webhook/deliveries', 'Recent webhook deliveries (30-day log).' );
		$catalog[] = array( 'surface' => 'Public', 'method' => 'POST', 'path' => '/esign/webhook', 'auth' => 'HMAC signature', 'purpose' => 'Receiver for BreezeDoc webhook events (signature-verified; refreshes the affected documents).', 'app' => 'OPS e-signatures' );
		return $catalog;
	}

	public function register_routes() {
		$ns     = AJCore_Extensions::rest_namespace();
		$ops    = array( 'AJCore_Extensions', 'can_manage_ops' );
		$admin  = static function () {
			return current_user_can( 'manage_options' );
		};
		$id_re  = '(?P<id>\d+)';
		$tpl_re = '(?P<id>[A-Za-z0-9_]+)';
		$act_ro = '(?P<action>download|events|preview|sent-file)';
		$act_rw = '(?P<action>refresh|remind|cancel)';
		$r      = WP_REST_Server::READABLE;

		// Same handlers under /ops/esign/* (AJOps, ops JWT auth) and /esign/* (WP-admin, cookie auth).
		foreach ( array( '/ops/esign' => $ops, '/esign' => $admin ) as $base => $permission ) {
			$routes = array(
				'/test'                      => array( $r, 'rest_test' ),
				'/templates'                 => array( $r, 'rest_templates' ),
				'/templates/' . $tpl_re      => array( $r, 'rest_template' ),
				'/templates/' . $tpl_re . '/file' => array( $r, 'rest_template_file' ),
				'/templates/save'            => array( 'POST', 'rest_template_save' ),
				'/templates/delete'          => array( 'POST', 'rest_template_delete' ),
				'/templates/duplicate'       => array( 'POST', 'rest_template_duplicate' ),
				'/summary'                   => array( 'GET', 'rest_summary' ),
				'/our-details'               => array( 'GET', 'rest_our_details' ),
				'/our-details/save'          => array( 'POST', 'rest_our_details_save' ),
				'/send'                      => array( 'POST', 'rest_send' ),
				'/send-pdf'                  => array( 'POST', 'rest_send_pdf' ),
				'/preview-filled'            => array( 'POST', 'rest_preview_filled' ),
				'/drafts/save'               => array( 'POST', 'rest_draft_save' ),
				'/drafts/(?P<id>\d+)'        => array( $r, 'rest_draft_get' ),
				'/drafts/(?P<id>\d+)/pdf'    => array( $r, 'rest_draft_pdf' ),
				'/merge-values'              => array( 'POST', 'rest_merge_values' ),
				'/documents'                 => array( $r, 'rest_documents' ),
				'/import'                    => array( 'POST', 'rest_import' ),
				'/link'                      => array( 'POST', 'rest_link' ),
				'/settings'                  => array( $r, 'rest_settings' ),
				'/settings/save'             => array( 'POST', 'rest_settings_save' ),
				'/documents/delete'          => array( 'POST', 'rest_delete_documents' ),
				'/documents/restore-rows'    => array( 'POST', 'rest_restore_rows' ),
				'/documents/purge'           => array( 'POST', 'rest_purge_documents' ),
				'/diagnose'                  => array( $r, 'rest_diagnose' ),
				'/documents/' . $id_re . '/' . $act_rw => array( 'POST', 'rest_document_action' ),
				'/documents/' . $id_re . '/' . $act_ro => array( $r, 'rest_document_action' ),
				'/webhook/register'          => array( 'POST', 'rest_webhook_register' ),
				'/webhook/remove'            => array( 'POST', 'rest_webhook_remove' ),
				'/webhook/test'              => array( 'POST', 'rest_webhook_test' ),
				'/webhook/deliveries'        => array( $r, 'rest_webhook_deliveries' ),
			);
			foreach ( $routes as $path => $def ) {
				register_rest_route( $ns, $base . $path, array( 'methods' => $def[0], 'callback' => array( $this, $def[1] ), 'permission_callback' => $permission ) );
			}
		}
		// Webhook status (GET, authenticated) and receiver (POST, public — verified by HMAC signature).
		register_rest_route( $ns, '/ops/esign/webhook', array( 'methods' => $r, 'callback' => array( $this, 'rest_webhook_status' ), 'permission_callback' => $ops ) );
		register_rest_route(
			$ns,
			'/esign/webhook',
			array(
				array( 'methods' => $r, 'callback' => array( $this, 'rest_webhook_status' ), 'permission_callback' => $admin ),
				array( 'methods' => 'POST', 'callback' => array( $this, 'handle_esign_webhook' ), 'permission_callback' => '__return_true' ),
			)
		);
	}

	private function rest_result( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function rest_test() {
		return $this->rest_result( $this->breezedoc_test_connection() );
	}

	public function rest_templates() {
		$result = $this->breezedoc_list_templates();
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'templates' => $result, 'mergeFields' => self::merge_field_catalog() ) );
	}

	public function rest_template_save( WP_REST_Request $request ) {
		$files   = $request->get_file_params();
		$signers = $request->get_param( 'signers' );
		if ( is_string( $signers ) ) {
			$decoded = json_decode( $signers, true );
			$signers = is_array( $decoded ) ? $decoded : array( $signers );
		}
		return $this->rest_result( $this->save_local_template( sanitize_text_field( (string) $request->get_param( 'id' ) ), (string) $request->get_param( 'title' ), (array) $signers, (int) $request->get_param( 'page' ), $files['pdf'] ?? null, (string) $request->get_param( 'kind' ), (string) $request->get_param( 'body' ),$request->get_param( 'sig_x' ), $request->get_param( 'sig_y' ), $request->get_param( 'layout' ) ) );
	}

	public function rest_our_details() {
		return rest_ensure_response( array( 'values' => self::our_details(), 'fields' => self::our_fields() ) );
	}

	public function rest_our_details_save( WP_REST_Request $request ) {
		$in  = $request->get_param( 'values' );
		$in  = is_array( $in ) ? $in : ( is_string( $in ) ? json_decode( $in, true ) : array() );
		$out = array();
		foreach ( array_keys( self::our_fields() ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? mb_substr( sanitize_text_field( (string) $in[ $k ] ), 0, 200 ) : self::our_details()[ $k ];
		}
		update_option( self::OUR_OPTION, $out, false );
		return $this->rest_our_details();
	}

	public function rest_template_duplicate( WP_REST_Request $request ) {
		return $this->rest_result( $this->duplicate_local_template( sanitize_text_field( (string) $request->get_param( 'id' ) ) ) );
	}

	public function rest_template_delete( WP_REST_Request $request ) {
		return $this->rest_result( $this->delete_local_template( sanitize_text_field( (string) $request->get_param( 'id' ) ) ) );
	}

	public function rest_template_file( WP_REST_Request $request ) {
		return $this->stream_local_template( (string) $request['id'] ); // streams and exits, or returns a WP_Error
	}

	public function rest_template( WP_REST_Request $request ) {
		return $this->rest_result( $this->breezedoc_get_template( (string) $request['id'] ) );
	}

	private function send_options( WP_REST_Request $request ) {
		$options = array();
		if ( null !== $request->get_param( 'signing_order' ) ) {
			$options['signing_order'] = filter_var( $request->get_param( 'signing_order' ), FILTER_VALIDATE_BOOLEAN );
		}
		foreach ( array( 'message', 'title' ) as $key ) {
			if ( '' !== (string) $request->get_param( $key ) ) {
				$options[ $key ] = (string) $request->get_param( $key );
			}
		}
		if ( (int) $request->get_param( 'expires_in_days' ) > 0 ) {
			$options['expires_in_days'] = (int) $request->get_param( 'expires_in_days' );
		}
		if ( '' !== (string) $request->get_param( 'layout' ) ) {
			$options['layout'] = (string) $request->get_param( 'layout' );
		}
		$vars = $request->get_param( 'vars' );
		if ( is_string( $vars ) ) {
			$vars = json_decode( $vars, true );
		}
		if ( is_array( $vars ) ) {
			$options['vars'] = array_slice( $vars, 0, 30, true );
		}
		return $options;
	}

	public function rest_send( WP_REST_Request $request ) {
		$template_id = sanitize_text_field( (string) $request->get_param( 'template_id' ) );
		if ( '' === $template_id || '0' === $template_id ) {
			return new WP_Error( 'missing_template', __( 'template_id is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$res = $this->send_esign_document_from_template( $template_id, sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) ), (array) $request->get_param( 'recipients' ), $this->send_options( $request ) );
		if ( ! is_wp_error( $res ) && (int) $request->get_param( 'draft_id' ) ) {
			$this->discard_draft( (int) $request->get_param( 'draft_id' ) );
		}
		return $this->rest_result( $res );
	}

	public function rest_send_pdf( WP_REST_Request $request ) {
		$files      = $request->get_file_params();
		$recipients = $request->get_param( 'recipients' );
		if ( is_string( $recipients ) ) {
			$recipients = json_decode( $recipients, true );
		}
		$res = $this->send_esign_pdf(
			$files['pdf'] ?? null,
			(string) $request->get_param( 'title' ),
			sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) ),
			(array) $recipients,
			(int) $request->get_param( 'page' ),
			$this->send_options( $request )
		);
		if ( ! is_wp_error( $res ) && (int) $request->get_param( 'draft_id' ) ) {
			$this->discard_draft( (int) $request->get_param( 'draft_id' ) );
		}
		return $this->rest_result( $res );
	}

	public function rest_draft_save( WP_REST_Request $request ) {
		$files   = $request->get_file_params();
		$payload = $request->get_param( 'payload' );
		$payload = is_string( $payload ) ? json_decode( $payload, true ) : $payload;
		return $this->rest_result( $this->save_draft( (int) $request->get_param( 'id' ), is_array( $payload ) ? $payload : array(), $files['pdf'] ?? null ) );
	}

	public function rest_draft_get( WP_REST_Request $request ) {
		return $this->rest_result( $this->get_draft( (int) $request['id'] ) );
	}

	public function rest_draft_pdf( WP_REST_Request $request ) {
		$d = $this->get_draft( (int) $request['id'] );
		if ( is_wp_error( $d ) ) {
			return $this->rest_result( $d );
		}
		$row  = $this->get_esign_row( (int) $request['id'] );
		$path = $this->draft_pdf_path( (string) $row->breezedoc_document_id );
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'no_pdf', __( 'This draft has no PDF.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . str_replace( '"', '', (string) ( $d['pdf_name'] ?: 'document.pdf' ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/** Every merge field resolved for one customer (token => value), so an editor can show the filled form live. */
	public function rest_merge_values( WP_REST_Request $request ) {
		$rec = $request->get_param( 'recipients' );
		$rec = is_string( $rec ) ? json_decode( $rec, true ) : $rec;
		$rec = is_array( $rec ) ? array_values( $rec ) : array();
		$vars = $request->get_param( 'vars' );
		$vars = is_string( $vars ) ? json_decode( $vars, true ) : $vars;
		$vars = is_array( $vars ) ? $vars : array();
		return rest_ensure_response( (object) $this->merge_values( sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) ), $rec, $vars ) );
	}

	/** The request as it would be sent — customer values filled in, signer boxes outlined — without contacting BreezeDoc. */
	public function rest_preview_filled( WP_REST_Request $request ) {
		$files      = $request->get_file_params();
		$recipients = $request->get_param( 'recipients' );
		if ( is_string( $recipients ) ) {
			$recipients = json_decode( $recipients, true );
		}
		$recipients = array_values( (array) $recipients );
		foreach ( $recipients as $i => $r ) { // a preview doesn't need real signer details
			$recipients[ $i ]['name']  = '' !== trim( (string) ( $r['name'] ?? '' ) ) ? $r['name'] : 'Signer ' . ( $i + 1 );
			$recipients[ $i ]['email'] = is_email( (string) ( $r['email'] ?? '' ) ) ? $r['email'] : 'signer' . ( $i + 1 ) . '@example.com';
		}
		if ( empty( $recipients ) ) {
			$recipients = array( array( 'name' => 'Signer 1', 'email' => 'signer1@example.com' ) );
		}
		$options                 = $this->send_options( $request );
		$options['preview_only'] = true;
		$cust                    = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$tid                     = sanitize_text_field( (string) $request->get_param( 'template_id' ) );
		if ( '' !== $tid ) {
			if ( 0 !== strpos( $tid, 'ajt_' ) ) {
				return new WP_Error( 'bad_preview', __( 'Only AJCore forms can be previewed.', 'ajcore-ra' ), array( 'status' => 400 ) );
			}
			$res = $this->send_esign_document_from_template( $tid, $cust, $recipients, $options );
		} else {
			$res = $this->send_esign_pdf( $files['pdf'] ?? null, (string) $request->get_param( 'title' ), $cust, $recipients, (int) $request->get_param( 'page' ), $options );
		}
		if ( is_wp_error( $res ) ) {
			return $this->rest_result( $res );
		}
		if ( empty( $res['preview_pdf'] ) ) {
			return new WP_Error( 'no_preview', __( 'Could not build the preview.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="preview.pdf"' );
		header( 'Content-Length: ' . strlen( $res['preview_pdf'] ) );
		echo $res['preview_pdf']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function rest_restore_rows( WP_REST_Request $request ) {
		return $this->rest_result( $this->restore_logged_documents( (array) $request->get_param( 'ids' ) ) );
	}

	public function rest_purge_documents( WP_REST_Request $request ) {
		return $this->rest_result( $this->purge_logged_documents( (array) $request->get_param( 'ids' ) ) );
	}

	public function rest_delete_documents( WP_REST_Request $request ) {
		return $this->rest_result( $this->delete_logged_documents( (array) $request->get_param( 'ids' ) ) );
	}

	/** Things that are not possible (or not built yet), kept visible in the UI so they can be revisited. */
	public static function roadmap() {
		return array(
			array( 'status' => 'planned', 'title' => 'Bulk send', 'note' => 'Send one template to many customers in one go (BreezeDoc has it in its app; the API allows it one document at a time, 60 requests/minute).' ),
			array( 'status' => 'planned', 'title' => 'Own template library', 'note' => 'Save a PDF once with its signer layout and reuse it, because BreezeDoc templates cannot be created or edited through its API.' ),
			array( 'status' => 'planned', 'title' => 'Drag-to-place fields on uploaded PDFs', 'note' => 'Today a signature and date box is auto-placed per signer near the bottom of one page.' ),
			array( 'status' => 'planned', 'title' => 'Signing status and signed copies in the customer portal', 'note' => 'Show each customer their requests and signed files in the client portal.' ),
			array( 'status' => 'blocked', 'title' => 'Create, edit or delete templates from AJCore', 'note' => 'BreezeDoc API has no template write endpoints. Use Edit / Create in BreezeDoc.' ),
			array( 'status' => 'blocked', 'title' => 'Delete documents in BreezeDoc', 'note' => 'No delete endpoint (only invoices and webhooks have one). Cancel works for awaiting documents (Team key); delete in BreezeDoc\'s "..." menu.' ),
			array( 'status' => 'blocked', 'title' => 'Set logo, email subject/body or sender name from AJCore', 'note' => 'These are BreezeDoc account / team settings with no API. The custom message per request does work.' ),
			array( 'status' => 'blocked', 'title' => 'Signing requests from our own address, with our own signing links', 'note' => 'BreezeDoc always sends its own signing email and the API cannot turn it off or change the sender. AJCore sends the optional heads-up and the signed copy from your own sender instead. Ask BreezeDoc support whether a no-email option is planned.' ),
			array( 'status' => 'blocked', 'title' => 'Preview unsigned documents or template layout', 'note' => 'No endpoint. The signed PDF is available once completed (Team key).' ),
			array( 'status' => 'blocked', 'title' => 'Preview / download documents from the personal account', 'note' => 'The legacy API documents no file link. Check Diagnose → personal_documents; Open uses BreezeDoc\'s own viewer.' ),
			array( 'status' => 'blocked', 'title' => 'Redirect after signing, passcodes, Stripe payments on documents', 'note' => 'Configured inside BreezeDoc; not exposed by the API.' ),
		);
	}

	public function rest_settings() {
		$opt = get_option( self::OPTION, array() );
		$opt = is_array( $opt ) ? $opt : array();
		return rest_ensure_response(
			array(
				'autoImport' => (string) ( $opt['auto_import'] ?? 'off' ),
				'notifyOnSend' => ! empty( $opt['notify_on_send'] ),
				'signedCopy'   => (string) ( $opt['signed_copy'] ?? 'off' ),
				'fileSigned'   => ! empty( $opt['file_signed'] ),
				'roadmap'      => self::roadmap(),
				'lastRun'    => (array) ( $opt['auto_last'] ?? array() ),
				'readOnly'   => $this->read_only(),
				'hasTeam'    => $this->breezedoc_is_v1(),
				'hasPersonal' => '' !== $this->setting( 'api_token' ),
			)
		);
	}

	public function rest_settings_save( WP_REST_Request $request ) {
		if ( $this->read_only() ) {
			return new WP_Error( 'master_only', __( 'Managed by the AJCore master site.', 'ajcore-ra' ), array( 'status' => 403 ) );
		}
		$opt  = get_option( self::OPTION, array() );
		$opt  = is_array( $opt ) ? $opt : array();
		if ( null !== $request->get_param( 'auto_import' ) ) {
			$freq               = sanitize_key( (string) $request->get_param( 'auto_import' ) );
			$new_freq           = in_array( $freq, array( 'off', 'hourly', 'twicedaily', 'daily' ), true ) ? $freq : 'off';
			if ( 'off' !== $new_freq && 'off' === ( $opt['auto_import'] ?? 'off' ) ) {
				$opt['auto_since'] = time() - 2 * DAY_IN_SECONDS;
			}
			$opt['auto_import'] = $new_freq;
		}
		if ( null !== $request->get_param( 'notify_on_send' ) ) {
			$opt['notify_on_send'] = filter_var( $request->get_param( 'notify_on_send' ), FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
		}
		if ( null !== $request->get_param( 'file_signed' ) ) {
			$opt['file_signed'] = filter_var( $request->get_param( 'file_signed' ), FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
		}
		if ( null !== $request->get_param( 'signed_copy' ) ) {
			$mode               = sanitize_key( (string) $request->get_param( 'signed_copy' ) );
			$opt['signed_copy'] = in_array( $mode, array( 'off', 'customer', 'all' ), true ) ? $mode : 'off';
		}
		update_option( self::OPTION, $opt, false );
		self::sync_schedule();
		return $this->rest_settings();
	}

	public function rest_link( WP_REST_Request $request ) {
		return $this->rest_result( $this->link_documents( (int) $request->get_param( 'after_id' ) ) );
	}

	public function rest_diagnose() {
		return $this->rest_result( $this->esign_diagnose() );
	}

	public function rest_import( WP_REST_Request $request ) {
		$all = null === $request->get_param( 'include_unmatched' ) ? true : filter_var( $request->get_param( 'include_unmatched' ), FILTER_VALIDATE_BOOLEAN );
		return $this->rest_result( $this->import_documents( (string) $request->get_param( 'cursor' ), $all, 0, filter_var( $request->get_param( 'sync' ), FILTER_VALIDATE_BOOLEAN ) ) );
	}

	public function rest_documents() {
		return rest_ensure_response( $this->get_esign_documents_list() );
	}

	public function rest_document_action( WP_REST_Request $request ) {
		$action = (string) $request['action'];
		if ( 'refresh' === $action ) {
			return $this->rest_result( $this->refresh_esign_document_status( (int) $request['id'] ) );
		}
		if ( 'sent-file' === $action ) {
			return $this->stream_sent_copy( (int) $request['id'], filter_var( $request->get_param( 'download' ), FILTER_VALIDATE_BOOLEAN ) ); // streams and exits, or returns a WP_Error
		}
		return $this->rest_result( $this->esign_document_action( (int) $request['id'], $action ) );
	}

	/** Streams the PDF AJCore sent for a request (inline, or as a download). */
	private function stream_sent_copy( $local_id, $as_download ) {
		$row = $this->get_esign_row( $local_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$path = $this->sent_path( (string) $row->breezedoc_document_id );
		if ( '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'no_copy', __( 'AJCore has no copy of this document.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$name = sanitize_file_name( ( (string) $row->template_title ?: 'document' ) . '.pdf' );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $as_download ? 'attachment' : 'inline' ) . '; filename="' . str_replace( '"', '', $name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	public function rest_webhook_status() {
		return rest_ensure_response( $this->breezedoc_webhook_status() );
	}

	public function rest_webhook_register() {
		return $this->rest_result( $this->breezedoc_webhook_register() );
	}

	public function rest_webhook_remove() {
		return $this->rest_result( $this->breezedoc_webhook_remove() );
	}

	public function rest_webhook_test() {
		return $this->rest_result( $this->breezedoc_webhook_send_test() );
	}

	public function rest_webhook_deliveries() {
		return $this->rest_result( $this->breezedoc_webhook_deliveries() );
	}

	// ── Admin tab (Client Portal > E-Signatures) ────────────────────────────────

	public static function admin_tab( $tabs ) {
		$n                 = self::instance()->waiting_count();
		$tabs[ self::TAB ] = array( 'label' => $n > 0 ? sprintf( '%s (%d)', __( 'E-Signatures', 'ajcore-ra' ), $n ) : __( 'E-Signatures', 'ajcore-ra' ) );
		return $tabs;
	}

	/** Requests sent and not yet fully signed (BreezeDoc "Waiting"). Cached for 30 seconds. */
	public function waiting_count() {
		$cached = get_transient( 'ajcore_ra_esign_waiting' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$n = 0;
		if ( $this->kit ) {
			$pdb = $this->get_pdb();
			$t   = $this->get_esign_documents_table();
			if ( $pdb && $this->kit['table_exists']( $pdb, $t ) ) {
				$n = (int) $pdb->get_var( "SELECT COUNT(*) FROM `{$t}` WHERE status = 'sent'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		set_transient( 'ajcore_ra_esign_waiting', $n, 30 );
		return $n;
	}

	/** Card on AJCore's Client Portal Dashboard. */
	public static function dashboard_metric() {
		$n   = self::instance()->waiting_count();
		$url = add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB ), admin_url( 'admin.php' ) ) . '#documents';
		printf(
			'<a class="ajcore-dashboard-metric" href="%s"><span class="ajcore-dashboard-label">%s</span><strong>%d</strong><small>%s</small></a>',
			esc_url( $url ),
			esc_html__( 'Signatures waiting', 'ajcore-ra' ),
			(int) $n,
			esc_html__( 'Sent, not yet fully signed', 'ajcore-ra' )
		);
	}

	public function rest_summary() {
		return rest_ensure_response( array( 'waiting' => $this->waiting_count() ) );
	}

	public static function render_admin_tab( $tab ) {
		if ( self::TAB === $tab && current_user_can( 'manage_options' ) ) {
			self::instance()->render_admin();
		}
	}

	private function render_admin() {
		$connected = $this->breezedoc_is_connected();
		$is_v1     = $this->breezedoc_is_v1();
		$templates = array();
		$error     = '';
		if ( $connected ) {
			$result = $this->breezedoc_list_templates();
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$templates = $result;
			}
		}
		$log    = $this->get_esign_documents_list();
		$ro     = $this->read_only();
		$rest   = rest_url( AJCore_Extensions::rest_namespace() );
		$hook   = $this->breezedoc_webhook_status();
		$labels = array( 'draft' => __( 'Not sent', 'ajcore-ra' ), 'sent' => __( 'Waiting', 'ajcore-ra' ), 'completed' => __( 'Completed', 'ajcore-ra' ), 'cancelled' => __( 'Cancelled', 'ajcore-ra' ), 'expired' => __( 'Expired', 'ajcore-ra' ) );
		$group  = static function ( $status ) {
			if ( 0 === strpos( (string) $status, 'deleted:' ) ) {
				return 'deleted';
			}
			if ( 'draft' === $status ) {
				return 'draft';
			}
			return 'completed' === $status ? 'completed' : ( in_array( $status, array( 'cancelled', 'expired' ), true ) ? 'closed' : 'sent' );
		};
		$counts = array( 'all' => count( $log['documents'] ), 'sent' => 0, 'draft' => 0, 'completed' => 0, 'closed' => 0, 'deleted' => count( (array) ( $log['deleted'] ?? array() ) ) );
		foreach ( $log['documents'] as $doc ) {
			$counts[ $group( $doc['status'] ) ]++;
		}
		$progress = static function ( $recipients ) {
			$recipients = (array) $recipients;
			$signed     = count( array_filter( $recipients, static function ( $r ) {
				return ! empty( $r['completedAt'] );
			} ) );
			return array( $signed, count( $recipients ) );
		};
		?>
		<style>
			.ajra-es{max-width:none;width:100%}
			.ajra-es .ajra-subnav{display:flex;gap:4px;border-bottom:1px solid #dcdcde;margin:0 0 14px;flex-wrap:wrap}
			.ajra-es .ajra-subnav a{padding:9px 16px;cursor:pointer;text-decoration:none;color:#50575e;font-weight:600;border-bottom:3px solid transparent;margin-bottom:-1px}
			.ajra-es .ajra-subnav a:hover{color:#1d2327}.ajra-es .ajra-subnav a.on{color:#2563eb;border-bottom-color:#2563eb}
			.ajra-es .ajra-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px;margin:0 0 12px}
			.ajra-es .ajra-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 16px}
			@media(max-width:782px){.ajra-es .ajra-grid{grid-template-columns:1fr}}
			.ajra-es label{display:block;font-weight:600;margin:0 0 3px;font-size:12px;color:#3c434a}
			.ajra-es input[type=text],.ajra-es input[type=email],.ajra-es input[type=number],.ajra-es input[type=search],.ajra-es select,.ajra-es textarea{width:100%;min-height:34px;box-sizing:border-box}
			.ajra-es select[size]{height:auto;padding:2px}.ajra-es select[size] option{padding:4px 8px;border-radius:4px}
			.ajra-es .ajra-row{display:flex;gap:6px;align-items:center;margin-bottom:5px}.ajra-es .ajra-row input{flex:1;min-width:0}
			.ajra-es .ajra-muted{color:#646970;font-size:12px}
			.ajra-es .ajra-err{display:none;margin:0 0 10px;padding:8px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;color:#991b1b}
			.ajra-es .ajra-pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600;background:#fef3c7;color:#92400e;white-space:nowrap}
			.ajra-es .ajra-pill.completed{background:#dcfce7;color:#166534}.ajra-es .ajra-pill.cancelled,.ajra-es .ajra-pill.expired,.ajra-es .ajra-pill.draft{background:#f1f5f9;color:#475569}
			.ajra-es .ajra-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
			.ajra-es .ajra-head h2{margin:0;font-size:14px}
			.ajra-es .ajra-seg{display:inline-flex;border:1px solid #c3c4c7;border-radius:8px;overflow:hidden}
			.ajra-es .ajra-seg a{padding:5px 12px;cursor:pointer;text-decoration:none;color:#3c434a;background:#fff;font-size:12px;font-weight:600;border-right:1px solid #c3c4c7}
			.ajra-es .ajra-seg a:last-child{border-right:0}.ajra-es .ajra-seg a.on{background:#2563eb;color:#fff}
			.ajra-es .ajra-bar{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-top:12px;padding-top:12px;border-top:1px solid #f0f0f1}
			.ajra-es .ajra-bar .ajra-sp{flex:1}
			.ajra-es .ajra-bar input[type=number]{width:64px}
			.ajra-es .ajra-bar label{display:inline-flex;align-items:center;gap:4px;margin:0;font-weight:400;font-size:13px}
			.ajra-es #ajra-es-send.button-primary{height:36px;min-height:0;padding:0 18px;border-radius:8px;font-size:13px;line-height:34px;background:#2563eb!important;border-color:#2563eb!important;color:#fff!important;box-shadow:none}
			.ajra-es #ajra-es-send.button-primary:disabled{background:#94a3b8!important;border-color:#94a3b8!important;color:#fff!important;opacity:1;cursor:not-allowed}
			.ajra-es .ajra-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}
			.ajra-es .ajra-toolbar input[type=search]{width:220px;margin-left:auto}
			.ajra-es table.widefat td,.ajra-es table.widefat th{vertical-align:middle}
			.ajra-es td .button{margin:0 2px 2px 0}.ajra-es pre{margin:0;white-space:pre-wrap;font-size:12px}
			.ajra-es .ajra-empty{padding:18px;text-align:center;color:#646970}
.ajra-es #ajra-es-tpl-body{background:#fff;border:1px solid #cbd5e1;box-shadow:0 1px 4px rgba(0,0,0,.08);height:calc(100vh - 230px);min-height:520px;overflow:auto;padding:48px 56px;font:14px/1.5 Helvetica,Arial,sans-serif;color:#111;max-width:900px}
			.ajra-es #ajra-es-tpl-body table{border-collapse:collapse;width:100%}.ajra-es #ajra-es-tpl-body td,.ajra-es #ajra-es-tpl-body th{border:1px solid #444;padding:4px 6px;min-width:40px}
			.ajra-es #ajra-es-tpl-body img{max-width:100%}.ajra-es #ajra-es-tpl-body hr.pb{border:0;border-top:2px dashed #94a3b8;margin:22px 0;height:0;overflow:visible}
			.ajra-es #ajra-es-tpl-body hr.pb::after{content:"page break";display:block;text-align:center;font-size:10px;color:#94a3b8;margin-top:-8px;background:#fff;width:80px;margin-left:auto;margin-right:auto}
			.ajra-es .ajra-ws{display:flex;gap:12px;align-items:flex-start}.ajra-es .ajra-tree{width:240px;flex:none;border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:6px;max-height:80vh;overflow:auto}
			.ajra-es .ajra-tg{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;padding:8px 8px 4px}
			.ajra-es .ajra-ti{display:flex;align-items:center;gap:6px;padding:6px 8px;border-radius:6px;cursor:pointer;font-size:13px}.ajra-es .ajra-ti:hover{background:#f1f5f9}.ajra-es .ajra-ti.on{background:#eef2ff;color:#3730a3;font-weight:600}
			.ajra-es .ajra-ti span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ajra-es .ajra-ti em{font-style:normal;font-size:10px;color:#64748b;border:1px solid #e2e8f0;border-radius:4px;padding:0 4px}
			.ajra-es .ajra-wsmain{flex:1;min-width:0}.ajra-es .ajra-wsbox{border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;padding:10px}
			.ajra-es .ajra-wshead{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px}.ajra-es .ajra-reqhead{display:flex;flex-wrap:wrap;align-items:flex-start;gap:10px 18px}.ajra-es .ajra-reqhead h2{margin:6px 0 0;white-space:nowrap}.ajra-es .ajra-reqhead .ajra-seg{margin-left:auto}
			.ajra-es .ajra-reqbar{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:flex-start;flex:1;min-width:0}.ajra-es .ajra-reqbar>div{min-width:0}.ajra-es .ajra-reqbar select{max-width:240px}.ajra-es .ajra-opts summary{cursor:pointer;color:#64748b;font-weight:600}.ajra-es .ajra-opts label{display:block;margin-top:6px}
			.ajra-es .ajra-cust{position:relative}.ajra-es .ajra-cust .button{min-width:220px;text-align:left;justify-content:flex-start}.ajra-es .ajra-cust-pop{position:absolute;z-index:60;top:100%;left:0;background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:8px;width:340px;box-shadow:0 10px 24px rgba(15,23,42,.15)}.ajra-es .ajra-cust-pop input,.ajra-es .ajra-cust-pop select{width:100%;margin-bottom:4px}
			.ajra-es .ajra-reqbar .ajra-es-r>.ajra-muted{display:none}.ajra-es .ajra-signers{flex:1;min-width:320px}.ajra-es .ajra-signers .ajra-row{margin-bottom:4px}.ajra-es .ajra-signers .ajra-row input{width:170px;flex:none}.ajra-es .ajra-signers .ajra-row .ajra-muted{width:auto!important}
			.ajfe-side .ajra-grid{grid-template-columns:1fr!important;gap:8px!important}.ajfe-side #ajra-es-customer{height:auto}.ajfe-side #ajra-es-reqform{padding-bottom:10px}.ajfe-side .ajra-bar{border-top:1px solid #e2e8f0;padding-top:8px;margin-top:6px}.ajfe-side #ajra-es-send,.ajfe-side #ajra-es-preview{width:calc(50% - 4px);justify-content:center;text-align:center;margin:0}.ajfe-side #ajra-es-summary{width:100%;order:-1}.ajfe-side .ajra-row{flex-direction:column;align-items:stretch}.ajfe-side .ajra-row .ajra-muted{width:auto!important}.ajfe-side #ajra-es-customer{width:100%}.ajfe-side .ajra-bar{flex-wrap:wrap}
			.ajra-es .ajra-wsstrip{display:flex;flex-direction:column;gap:8px;padding-bottom:8px;border-bottom:1px solid #e2e8f0}.ajra-es .ajra-wsstrip input[type=text]{width:100%}.ajra-es .ajra-wsstrip .ajra-grid{grid-template-columns:repeat(3,1fr);gap:6px}
			.ajra-es .ajra-choice{border:1px solid #cbd5e1;border-radius:10px;background:#fff;padding:14px;cursor:pointer;display:flex;flex-direction:column;gap:4px}.ajra-es .ajra-choice:hover{border-color:#4f46e5;box-shadow:0 0 0 2px #e0e7ff}
			.ajra-es .ajra-wsrow{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:6px 0}
			.ajra-es .ajra-role{display:inline-flex;align-items:center;gap:2px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;padding-right:6px}.ajra-es .ajra-role input{border:0;box-shadow:none;width:110px;min-height:28px}.ajra-es .ajra-role a{text-decoration:none;color:#64748b}
			@media(max-width:900px){.ajra-es .ajra-ws{flex-direction:column}.ajra-es .ajra-tree{width:auto;align-self:stretch;max-height:220px}}
			.ajra-es .ajra-tpl{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:8px 0;border-top:1px solid #f0f0f1}
			.ajra-es .ajra-tpl:first-child{border-top:0}.ajra-es .ajra-tpl .t{flex:1;min-width:200px;font-weight:600}
			.ajra-es .ajra-tag{display:inline-block;padding:0 7px;border-radius:9px;font-size:11px;background:#eef2ff;color:#3730a3;margin-left:6px;font-weight:600}
			.ajra-es .ajra-tpl-detail{flex-basis:100%;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-size:12px}
			#ajra-es-modal{display:none;position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.6);align-items:center;justify-content:center;padding:24px}
			#ajra-es-modal.on{display:flex}
			#ajra-es-modal .ajra-box{background:#fff;border-radius:10px;width:min(980px,100%);height:min(90vh,900px);display:flex;flex-direction:column;overflow:hidden}
			#ajra-es-modal .ajra-mbar{display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid #dcdcde}
			#ajra-es-modal .ajra-mbar strong{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
			#ajra-es-modal .ajra-mbody{flex:1;overflow:auto;background:#f1f5f9}
			#ajra-es-modal iframe{width:100%;height:100%;border:0;background:#fff}
			#ajra-es-modal img{display:block;max-width:100%;margin:10px auto;box-shadow:0 1px 4px rgba(0,0,0,.2)}
			#ajra-es-modal .ajra-mmsg{padding:28px;text-align:center;color:#475569}
		</style>
		<div class="ajra-es">
			<div class="ajra-subnav" id="ajra-es-nav">
				<a data-v="documents"><?php echo esc_html( sprintf( '%s (%d)', __( 'Documents', 'ajcore-ra' ), $counts['all'] ) ); ?></a>
				<a data-v="templates"><?php echo esc_html( sprintf( '%s (%d)', __( 'Forms', 'ajcore-ra' ), count( $templates ) ) ); ?></a>
				<a data-v="settings"><?php esc_html_e( 'Settings', 'ajcore-ra' ); ?></a>
			</div>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success inline" data-view="settings"><p><?php esc_html_e( 'Saved.', 'ajcore-ra' ); ?></p></div>
			<?php endif; ?>

			<details class="ajra-card" data-view="settings" <?php echo $connected ? '' : 'open'; ?>>
				<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'Connection', 'ajcore-ra' ); ?> — <?php echo esc_html( $is_v1 ? __( 'Team API key', 'ajcore-ra' ) : ( $connected ? __( 'Personal token (legacy)', 'ajcore-ra' ) : __( 'not connected', 'ajcore-ra' ) ) ); ?></summary>
				<?php if ( $ro ) : ?>
					<div class="notice notice-info inline" style="margin:10px 0 0"><p><?php esc_html_e( 'Credentials are managed by the AJCore master site; this site uses the shared connection.', 'ajcore-ra' ); ?></p></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px">
					<input type="hidden" name="action" value="ajcore_ra_save_esign">
					<?php wp_nonce_field( 'ajcore_ra_save_esign' ); ?>
					<div class="ajra-grid">
						<div>
							<label for="breezedoc_team_api_key"><?php esc_html_e( 'Team API key', 'ajcore-ra' ); ?></label>
							<input type="text" name="breezedoc_team_api_key" id="breezedoc_team_api_key" autocomplete="off" <?php disabled( $ro ); ?> placeholder="<?php echo $is_v1 ? esc_attr__( '•••• saved — blank keeps', 'ajcore-ra' ) : 'bd_live_…'; ?>">
						</div>
						<div>
							<label for="breezedoc_api_token"><?php esc_html_e( 'Personal access token (legacy)', 'ajcore-ra' ); ?></label>
							<input type="text" name="breezedoc_api_token" id="breezedoc_api_token" autocomplete="off" <?php disabled( $ro ); ?> placeholder="<?php echo $this->setting( 'api_token' ) ? esc_attr__( '•••• saved — blank keeps', 'ajcore-ra' ) : ''; ?>">
							<?php if ( $this->setting( 'api_token' ) && ! $ro ) : ?>
								<label style="font-weight:400;margin-top:4px"><input type="checkbox" name="clear_api_token" value="1"> <?php esc_html_e( 'Remove saved token', 'ajcore-ra' ); ?></label>
							<?php endif; ?>
						</div>
					</div>
					<?php $auto = (string) ( get_option( self::OPTION, array() )['auto_import'] ?? 'off' ); $last = (array) ( get_option( self::OPTION, array() )['auto_last'] ?? array() ); ?>
					<p style="margin:10px 0 0">
						<label for="esign_auto_import" style="display:inline"><?php esc_html_e( 'Auto-import from BreezeDoc', 'ajcore-ra' ); ?></label>
						<select name="esign_auto_import" id="esign_auto_import" style="width:auto" <?php disabled( $ro ); ?>>
							<?php foreach ( array( 'off' => __( 'Off', 'ajcore-ra' ), 'hourly' => __( 'Hourly', 'ajcore-ra' ), 'twicedaily' => __( 'Twice daily', 'ajcore-ra' ), 'daily' => __( 'Daily', 'ajcore-ra' ) ) as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $auto, $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="ajra-muted"><?php echo esc_html( $last ? sprintf( /* translators: 1: time, 2: seen, 3: imported, 4: linked */ __( 'Last run %1$s — %2$d found, %3$d imported, %4$d linked%5$s', 'ajcore-ra' ), $last['at'], (int) ( $last['summary']['seen'] ?? 0 ), (int) ( $last['summary']['imported'] ?? 0 ), (int) ( $last['summary']['linked'] ?? 0 ), ! empty( $last['summary']['error'] ) ? ' — ' . $last['summary']['error'] : '' ) : __( 'Matches signers to customers; runs on the master site.', 'ajcore-ra' ) ); ?></span>
					</p>
					<?php $sc = (string) $this->opt( 'signed_copy', 'off' ); ?>
					<input type="hidden" name="esign_email_form" value="1">
					<p style="margin:10px 0 0">
						<label style="display:inline-flex;align-items:center;gap:4px;margin-right:14px"><input type="checkbox" name="esign_notify_on_send" value="1" <?php checked( (bool) $this->opt( 'notify_on_send', 0 ) ); ?> <?php disabled( $ro ); ?>> <?php esc_html_e( 'Email signers from AJCore when a request is sent', 'ajcore-ra' ); ?></label>
						<label style="display:inline-flex;align-items:center;gap:4px;margin-right:14px"><input type="checkbox" name="esign_file_signed" value="1" <?php checked( (bool) $this->opt( 'file_signed', 0 ) ); ?> <?php disabled( $ro ); ?>> <?php esc_html_e( 'File the signed copy in the customer\'s portal Files', 'ajcore-ra' ); ?></label>
						<label for="esign_signed_copy" style="display:inline"><?php esc_html_e( 'Email the signed copy to', 'ajcore-ra' ); ?></label>
						<select name="esign_signed_copy" id="esign_signed_copy" style="width:auto" <?php disabled( $ro ); ?>>
							<option value="off" <?php selected( $sc, 'off' ); ?>><?php esc_html_e( 'nobody', 'ajcore-ra' ); ?></option>
							<option value="customer" <?php selected( $sc, 'customer' ); ?>><?php esc_html_e( 'the customer', 'ajcore-ra' ); ?></option>
							<option value="all" <?php selected( $sc, 'all' ); ?>><?php esc_html_e( 'every signer', 'ajcore-ra' ); ?></option>
						</select>
						<span class="ajra-muted"><?php esc_html_e( 'Sent from your AJCore sender (Zoho / SMTP profile); templates are under Email Templates. Signed copies need the Team key and the webhook (or a Refresh).', 'ajcore-ra' ); ?></span>
					</p>
					<p style="margin:10px 0 0">
						<?php if ( ! $ro ) : ?><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ajcore-ra' ); ?></button><?php endif; ?>
						<button type="button" class="button" id="ajra-es-test"><?php esc_html_e( 'Test connection', 'ajcore-ra' ); ?></button>
						<?php if ( $connected ) : ?><button type="button" class="button" id="ajra-es-diagnose"><?php esc_html_e( 'Diagnose', 'ajcore-ra' ); ?></button><?php endif; ?>
						<span id="ajra-es-test-out" class="ajra-muted"></span>
					</p>
					<pre id="ajra-es-diag-out" style="max-height:260px;overflow:auto"></pre>
				</form>
				<?php if ( $is_v1 && $ro ) : ?>
					<hr>
					<p class="ajra-muted" style="margin:0"><?php esc_html_e( 'Webhook: registered once, from the master site.', 'ajcore-ra' ); ?></p>
				<?php elseif ( $is_v1 ) : ?>
					<hr>
					<p style="margin:0 0 6px"><strong><?php esc_html_e( 'Webhook', 'ajcore-ra' ); ?></strong>
						<span class="ajra-muted"><?php echo esc_html( $hook['registered'] ? __( 'registered', 'ajcore-ra' ) . ( $hook['lastEventAt'] ? ' · ' . __( 'last event', 'ajcore-ra' ) . ' ' . $hook['lastEventAt'] . ' (' . $hook['lastResult'] . ')' : ' · ' . __( 'no events yet', 'ajcore-ra' ) ) : __( 'not registered — status updates only on refresh', 'ajcore-ra' ) ); ?></span></p>
					<p style="margin:0">
						<button type="button" class="button" data-hook="register"><?php echo esc_html( $hook['registered'] ? __( 'Re-register', 'ajcore-ra' ) : __( 'Register webhook', 'ajcore-ra' ) ); ?></button>
						<?php if ( $hook['registered'] ) : ?>
							<button type="button" class="button" data-hook="test"><?php esc_html_e( 'Send test event', 'ajcore-ra' ); ?></button>
							<button type="button" class="button" data-hook="deliveries"><?php esc_html_e( 'Deliveries', 'ajcore-ra' ); ?></button>
							<button type="button" class="button" data-hook="remove"><?php esc_html_e( 'Remove', 'ajcore-ra' ); ?></button>
						<?php endif; ?>
					</p>
					<pre id="ajra-es-hook-out" style="margin-top:8px"></pre>
				<?php endif; ?>
			</details>

			<?php if ( ! $connected ) : ?>
				<div class="notice notice-warning inline" data-view="settings"><p><?php esc_html_e( 'BreezeDoc is not connected. Add a Team API key above.', 'ajcore-ra' ); ?></p></div>
			<?php else : ?>
				<?php if ( $error ) : ?>
					<div class="notice notice-error inline" data-view="templates new settings"><p><?php echo esc_html( sprintf( /* translators: %s: error */ __( 'Forms could not be loaded: %s', 'ajcore-ra' ), $error ) ); ?></p></div>
				<?php endif; ?>

				<div class="ajra-card" data-view="new">
					<div class="ajra-head ajra-reqhead">
						<h2><?php esc_html_e( 'New document', 'ajcore-ra' ); ?></h2>
						<div id="ajra-es-reqform" class="ajra-reqbar">
							<div class="ajra-cust">
								<button type="button" class="button" id="ajra-es-cust-btn"><?php esc_html_e( 'Select customer', 'ajcore-ra' ); ?> ▾</button>
								<div class="ajra-cust-pop" id="ajra-es-cust-pop" style="display:none">
									<input type="search" id="ajra-es-customer-q" placeholder="<?php esc_attr_e( 'Search name or email…', 'ajcore-ra' ); ?>">
									<select id="ajra-es-customer" size="6"></select>
								</div>
							</div>
							<div id="ajra-es-tpl-wrap">
								<select id="ajra-es-template" title="<?php esc_attr_e( 'Form', 'ajcore-ra' ); ?>"><option value=""><?php esc_html_e( '— pick a form —', 'ajcore-ra' ); ?></option></select>
							</div>
							<div id="ajra-es-pdf-wrap" style="display:none">
								<div class="ajra-row">
									<input type="file" id="ajra-es-pdf" accept="application/pdf,.pdf">
									<input type="text" id="ajra-es-title" maxlength="191" placeholder="<?php esc_attr_e( 'Title (defaults to file name)', 'ajcore-ra' ); ?>">
									<input type="number" id="ajra-es-page" min="1" placeholder="<?php esc_attr_e( 'Sign page', 'ajcore-ra' ); ?>" title="<?php esc_attr_e( 'Blank = last page. A signature and date box is placed per signer near the bottom.', 'ajcore-ra' ); ?>" style="max-width:90px;flex:none">
								</div>
							</div>
							<div class="ajra-signers">
								<div id="ajra-es-recipients"></div>
								<p id="ajra-es-add-wrap" style="display:none;margin:0"><button type="button" class="button button-small" id="ajra-es-add"><?php esc_html_e( '+ Add signer', 'ajcore-ra' ); ?></button></p>
							</div>
							<div id="ajra-es-vars" style="display:none"></div>
						</div>
						<?php if ( $is_v1 ) : ?>
							<div class="ajra-seg"><a class="on" data-mode="template"><?php esc_html_e( 'From form', 'ajcore-ra' ); ?></a><a data-mode="pdf"><?php esc_html_e( 'Upload PDF', 'ajcore-ra' ); ?></a></div>
						<?php endif; ?>
						<span class="ajra-muted" id="ajra-es-saved"></span>
						<button type="button" class="button" id="ajra-es-cancel" title="<?php esc_attr_e( 'Close this document. What you have so far is saved under Not sent.', 'ajcore-ra' ); ?>"><?php esc_html_e( 'Cancel', 'ajcore-ra' ); ?></button>
					</div>
					<div id="ajra-es-error" class="ajra-err"></div>
					<div id="ajra-es-inline" style="display:none;margin-top:10px"></div>
					<div id="ajra-es-reqside">
						<textarea id="ajra-es-message" rows="2" style="display:none;margin-top:10px" placeholder="<?php esc_attr_e( 'Message to signers (needs BreezeDoc custom-email plan)', 'ajcore-ra' ); ?>"></textarea>
						<div class="ajra-bar">
							<?php if ( $is_v1 ) : ?>
								<details class="ajra-opts">
									<summary><?php esc_html_e( 'Options', 'ajcore-ra' ); ?></summary>
									<label><?php esc_html_e( 'Expires in', 'ajcore-ra' ); ?> <input type="number" id="ajra-es-expires" min="1" max="365" value="30"> <?php esc_html_e( 'days', 'ajcore-ra' ); ?></label>
									<label><input type="checkbox" id="ajra-es-order" checked> <?php esc_html_e( 'Sign in order', 'ajcore-ra' ); ?></label>
									<a href="#" id="ajra-es-msg-toggle"><?php esc_html_e( 'Add message', 'ajcore-ra' ); ?></a>
								</details>
							<?php endif; ?>
							<span class="ajra-sp"></span>
							<span class="ajra-muted" id="ajra-es-summary"></span>
							<button type="button" class="button" id="ajra-es-preview" disabled title="<?php esc_attr_e( 'See the form filled in for this customer, with the signer boxes outlined. Nothing is sent.', 'ajcore-ra' ); ?>"><?php esc_html_e( 'Preview', 'ajcore-ra' ); ?></button>
							<button type="button" class="button button-primary" id="ajra-es-send" disabled><?php esc_html_e( 'Send for signature', 'ajcore-ra' ); ?></button>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $connected && ! $error ) : ?>
			<div class="ajra-card" id="ajra-es-tpl-card" data-view="templates">
				<div class="ajra-head">
					<h2><?php esc_html_e( 'Forms', 'ajcore-ra' ); ?> <span class="ajra-muted" id="ajra-es-tpl-count"></span></h2>
					<span>
						<button type="button" class="button button-primary button-small" id="ajra-es-tpl-new"><?php esc_html_e( 'New form', 'ajcore-ra' ); ?></button>
					</span>
				</div>
				<div class="ajra-ws">
					<div class="ajra-tree" id="ajra-es-tree"></div>
					<div class="ajra-wsmain" id="ajra-es-ws"></div>
				</div>
				<p class="ajra-muted" style="margin:10px 0 0"><?php esc_html_e( 'Forms live in AJCore: upload a PDF or build one, drag fields on, and fill them from customer data. BreezeDoc is only used to collect the signatures.', 'ajcore-ra' ); ?></p>
			</div>
			<?php endif; ?>

			<div class="ajra-card" id="ajra-es-our" data-view="settings">
				<div class="ajra-head"><h2><?php esc_html_e( 'Our company details', 'ajcore-ra' ); ?> <span class="ajra-muted"><?php esc_html_e( '— available as fields when filling forms', 'ajcore-ra' ); ?></span></h2></div>
				<div class="ajra-grid">
					<?php foreach ( self::our_details() as $our_k => $our_v ) : ?>
						<div><label><?php echo esc_html( ucfirst( self::our_fields()[ $our_k ] ) ); ?></label><input type="text" class="ajra-es-our" data-k="<?php echo esc_attr( $our_k ); ?>" value="<?php echo esc_attr( $our_v ); ?>" maxlength="200"></div>
					<?php endforeach; ?>
				</div>
				<p style="margin:8px 0 0"><button type="button" class="button button-primary button-small" id="ajra-es-our-save"><?php esc_html_e( 'Save', 'ajcore-ra' ); ?></button> <span class="ajra-muted" id="ajra-es-our-msg"></span></p>
			</div>

			<details class="ajra-card" id="ajra-es-roadmap" data-view="settings">
				<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'Roadmap — what is planned and what BreezeDoc does not allow yet', 'ajcore-ra' ); ?></summary>
				<table class="widefat striped" style="margin-top:10px">
					<tbody>
						<?php foreach ( self::roadmap() as $item ) : ?>
							<tr>
								<td style="width:110px"><span class="ajra-pill <?php echo 'planned' === $item['status'] ? 'completed' : 'cancelled'; ?>"><?php echo esc_html( 'planned' === $item['status'] ? __( 'Planned', 'ajcore-ra' ) : __( 'Not possible yet', 'ajcore-ra' ) ); ?></span></td>
								<td><strong><?php echo esc_html( $item['title'] ); ?></strong><br><span class="ajra-muted"><?php echo esc_html( $item['note'] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>

			<div class="ajra-card" data-view="documents">
				<div class="ajra-head">
					<h2><?php esc_html_e( 'Sent signature requests', 'ajcore-ra' ); ?></h2>
					<?php if ( $connected ) : ?>
						<span style="text-align:right">
							<button type="button" class="button button-primary button-small" id="ajra-es-new"><?php esc_html_e( 'New document', 'ajcore-ra' ); ?></button>
							<button type="button" class="button button-small" id="ajra-es-import" title="<?php esc_attr_e( 'Makes this list match BreezeDoc: adds new documents, updates changed ones, removes ones deleted in BreezeDoc', 'ajcore-ra' ); ?>"><?php esc_html_e( 'Sync', 'ajcore-ra' ); ?></button>
							<button type="button" class="button button-small" id="ajra-es-link"><?php esc_html_e( 'Link customers', 'ajcore-ra' ); ?></button>
							<span class="ajra-muted" id="ajra-es-import-out"></span>
						</span>
					<?php endif; ?>
				</div>
				<div class="ajra-toolbar">
					<div class="ajra-seg" id="ajra-es-filters">
						<a data-f="all"><?php echo esc_html( sprintf( '%s %d', __( 'All', 'ajcore-ra' ), $counts['all'] ) ); ?></a>
						<a class="on" data-f="sent"><?php echo esc_html( sprintf( '%s %d', __( 'Waiting', 'ajcore-ra' ), $counts['sent'] ) ); ?></a>
						<a data-f="draft"><?php echo esc_html( sprintf( '%s %d', __( 'Not sent', 'ajcore-ra' ), $counts['draft'] ) ); ?></a>
						<a data-f="completed"><?php echo esc_html( sprintf( '%s %d', __( 'Completed', 'ajcore-ra' ), $counts['completed'] ) ); ?></a>
						<a data-f="closed"><?php echo esc_html( sprintf( '%s %d', __( 'Closed', 'ajcore-ra' ), $counts['closed'] ) ); ?></a>
						<a data-f="deleted"><?php echo esc_html( sprintf( '%s %d', __( 'Deleted', 'ajcore-ra' ), $counts['deleted'] ) ); ?></a>
					</div>
					<?php
					$acct_nac   = count( array_filter( (array) $log['documents'], static function ( $d ) { return 0 === strpos( (string) $d['breezedocDocumentId'], 'doc_' ) || 0 === strpos( (string) $d['breezedocDocumentId'], 'draft_' ); } ) );
					$acct_yours = count( (array) $log['documents'] ) - $acct_nac;
					?>
					<div class="ajra-seg" id="ajra-es-accts" title="<?php esc_attr_e( 'BreezeDoc keeps documents sent from this app under NAC documents, and your own under Your documents', 'ajcore-ra' ); ?>">
						<a class="on" data-a="all"><?php echo esc_html( __( 'All accounts', 'ajcore-ra' ) ); ?></a>
						<a data-a="yours"><?php echo esc_html( sprintf( '%s %d', __( 'Your documents', 'ajcore-ra' ), $acct_yours ) ); ?></a>
						<a data-a="nac"><?php echo esc_html( sprintf( '%s %d', __( 'NAC documents', 'ajcore-ra' ), $acct_nac ) ); ?></a>
					</div>
					<button type="button" class="button button-small" id="ajra-es-del" disabled><?php esc_html_e( 'Delete selected', 'ajcore-ra' ); ?></button>
					<button type="button" class="button button-small" id="ajra-es-restore-rows" style="display:none" disabled><?php esc_html_e( 'Restore selected', 'ajcore-ra' ); ?></button>
					<button type="button" class="button button-small" id="ajra-es-purge" style="display:none;color:#b32d2e" disabled><?php esc_html_e( 'Delete permanently', 'ajcore-ra' ); ?></button>
					<input type="search" id="ajra-es-log-q" placeholder="<?php esc_attr_e( 'Search customer or document…', 'ajcore-ra' ); ?>">
				</div>
				<table class="widefat striped">
					<thead><tr><th style="width:28px"><input type="checkbox" id="ajra-es-sel-all" aria-label="<?php esc_attr_e( 'Select all', 'ajcore-ra' ); ?>"></th><th><?php esc_html_e( 'Customer', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Document', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Signed', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Status', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Sent', 'ajcore-ra' ); ?></th><th></th></tr></thead>
					<tbody id="ajra-es-log">
						<?php foreach ( array_merge( $log['documents'], (array) ( $log['deleted'] ?? array() ) ) as $doc ) :
							$is_del          = 0 === strpos( $doc['status'], 'deleted:' );
							$was             = $is_del ? substr( $doc['status'], 8 ) : '';
							$is_new          = 0 === strpos( $doc['breezedocDocumentId'], 'doc_' );
							$is_draft        = 0 === strpos( $doc['breezedocDocumentId'], 'draft_' );
							list( $sg, $tt ) = $progress( $doc['recipients'] );
							$names           = implode( ', ', array_map( static function ( $r ) {
								return trim( ( $r['name'] ?? '' ) . ' <' . ( $r['email'] ?? '' ) . '>' );
							}, (array) $doc['recipients'] ) );
							?>
							<tr data-row="<?php echo esc_attr( $doc['id'] ); ?>" data-acct="<?php echo ( $is_new || $is_draft ) ? 'nac' : 'yours'; ?>" data-group="<?php echo esc_attr( $group( $doc['status'] ) ); ?>" data-q="<?php echo esc_attr( strtolower( $doc['customerName'] . ' ' . $doc['templateTitle'] . ' ' . $names ) ); ?>">
								<td><input type="checkbox" class="ajra-es-sel" value="<?php echo esc_attr( $doc['id'] ); ?>"></td>
								<td><?php echo esc_html( $doc['customerName'] ?: '' ); ?><?php if ( '' === $doc['customerName'] ) : $first = ( (array) $doc['recipients'] )[0] ?? array(); ?><span class="ajra-muted"><?php echo esc_html( ( $first['name'] ?? '' ) ?: ( $first['email'] ?? '—' ) ); ?></span><?php endif; ?></td>
								<td><?php echo esc_html( $doc['templateTitle'] ?: '—' ); ?><?php if ( $is_new ) : ?> <span class="ajra-tag" title="<?php esc_attr_e( 'Listed under NAC documents in BreezeDoc', 'ajcore-ra' ); ?>">NAC</span><?php endif; ?></td>
								<td data-progress title="<?php echo esc_attr( $names ); ?>"><?php echo esc_html( $tt ? $sg . '/' . $tt : '—' ); ?></td>
								<td><span class="ajra-pill <?php echo esc_attr( $is_del ? 'cancelled' : $doc['status'] ); ?>" data-status><?php echo esc_html( $is_del ? sprintf( /* translators: %s: previous status */ __( 'Deleted (was %s)', 'ajcore-ra' ), $labels[ $was ] ?? $was ) : ( $labels[ $doc['status'] ] ?? $doc['status'] ) ); ?></span></td>
								<td><?php echo esc_html( $doc['sentAt'] ? mysql2date( get_option( 'date_format' ), $doc['sentAt'] ) : '—' ); ?></td>
								<td style="text-align:right;white-space:nowrap">
									<?php if ( $is_del ) : ?>
										<span class="ajra-muted"><?php esc_html_e( 'Restore it or delete it permanently', 'ajcore-ra' ); ?></span>
 									<?php elseif ( $is_draft ) : ?>
										<button type="button" class="button button-primary button-small" data-draft="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'Continue', 'ajcore-ra' ); ?></button>
									<?php else : ?>
									<button type="button" class="button button-small" data-act="refresh" data-id="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'Refresh', 'ajcore-ra' ); ?></button>
									<?php if ( $is_new ) : ?>
										<?php if ( 'sent' === $doc['status'] ) : ?>
											<button type="button" class="button button-small" data-act="remind" data-id="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'Resend', 'ajcore-ra' ); ?></button>
											<button type="button" class="button button-small" data-act="cancel" data-id="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'Cancel', 'ajcore-ra' ); ?></button>
										<?php endif; ?>
										<button type="button" class="button button-small" data-act="download" data-id="<?php echo esc_attr( $doc['id'] ); ?>" title="<?php esc_attr_e( 'Signed PDF when completed; the copy AJCore sent while waiting', 'ajcore-ra' ); ?>"><?php esc_html_e( 'Download', 'ajcore-ra' ); ?></button>
										<button type="button" class="button button-small" data-act="events" data-id="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'Activity', 'ajcore-ra' ); ?></button>
									<?php endif; ?>
									<button type="button" class="button button-small" data-act="preview" data-id="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'Preview', 'ajcore-ra' ); ?></button>
									<?php if ( $doc['documentUrl'] ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $doc['documentUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open', 'ajcore-ra' ); ?></a>
									<?php endif; ?>
									<?php endif; // $is_del ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div class="ajra-empty" id="ajra-es-log-empty" style="<?php echo empty( $log['documents'] ) ? '' : 'display:none'; ?>"><?php esc_html_e( 'Nothing to show.', 'ajcore-ra' ); ?></div>
			</div>
		</div>
		<div id="ajra-es-modal" role="dialog" aria-modal="true">
			<div class="ajra-box">
				<div class="ajra-mbar"><strong id="ajra-es-mtitle"></strong>
					<a class="button button-small" id="ajra-es-mopen" target="_blank" rel="noopener noreferrer" style="display:none"><?php esc_html_e( 'Open in new tab', 'ajcore-ra' ); ?></a>
					<a class="button button-small" id="ajra-es-mcert" target="_blank" rel="noopener noreferrer" style="display:none"><?php esc_html_e( 'Certificate', 'ajcore-ra' ); ?></a>
					<button type="button" class="button button-small" id="ajra-es-mclose"><?php esc_html_e( 'Close', 'ajcore-ra' ); ?></button>
				</div>
				<div class="ajra-mbody" id="ajra-es-mbody"></div>
			</div>
		</div>
		<script src="<?php echo esc_url( plugin_dir_url( dirname( __DIR__ ) . '/ajcore-ra.php' ) . 'assets/esign-field-editor.js?ver=' . (int) @filemtime( dirname( __DIR__ ) . '/assets/esign-field-editor.js' ) ); ?>"></script>
		<script>
		( function () {
			var base = <?php echo wp_json_encode( untrailingslashit( $rest ) . '/esign' ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var customers = <?php echo wp_json_encode( $this->customers() ); ?>, templates = <?php echo wp_json_encode( $templates ); ?>;
			var labels = <?php echo wp_json_encode( $labels ); ?>;
			var $ = function ( id ) { return document.getElementById( id ); };

			// Sub-menu: one section at a time; the hash keeps your place after any reload.
			var views = [ 'documents', 'templates', 'new', 'settings' ], defaultView = <?php echo wp_json_encode( $connected ? 'documents' : 'settings' ); ?>;
			var currentView = '';
			function setView( v ) {
				if ( views.indexOf( v ) < 0 ) { v = defaultView; }
				document.querySelectorAll( '.ajra-es [data-view]' ).forEach( function ( el ) {
					el.style.display = ( ' ' + el.getAttribute( 'data-view' ) + ' ' ).indexOf( ' ' + v + ' ' ) >= 0 ? '' : 'none';
				} );
				document.querySelectorAll( '#ajra-es-nav a' ).forEach( function ( a ) { a.classList.toggle( 'on', a.getAttribute( 'data-v' ) === ( 'new' === v ? 'documents' : v ) ); } );
				if ( currentView === 'new' && v !== 'new' && window.ajraLeaveNew ) { window.ajraLeaveNew(); }
				currentView = v;
				try { window.history.replaceState( null, '', '#' + v ); } catch ( e ) { /* hash is a convenience only */ }
			}
			document.querySelectorAll( '#ajra-es-nav a' ).forEach( function ( a ) { a.addEventListener( 'click', function () { setView( a.getAttribute( 'data-v' ) ); } ); } );
			setView( window.location.hash.replace( '#', '' ) );
			function api( path, opts ) {
				opts = opts || {}; opts.headers = Object.assign( { 'X-WP-Nonce': nonce }, opts.headers || {} );
				return fetch( base + path, opts ).then( function ( r ) { return r.json().then( function ( d ) { if ( ! r.ok ) { throw new Error( d.message || 'Request failed' ); } return d; } ); } );
			}
			function esc( s ) { return String( s || '' ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } ); }

			// Connection test + webhook controls.
			if ( $( 'ajra-es-test' ) ) { $( 'ajra-es-test' ).addEventListener( 'click', function () {
				var out = $( 'ajra-es-test-out' ); out.textContent = '…';
				api( '/test' ).then( function ( d ) { out.style.color = '#166534'; out.textContent = 'Connected: ' + ( d.email || '' ); } ).catch( function ( e ) { out.style.color = '#b32d2e'; out.textContent = e.message; } );
			} ); }
			if ( $( 'ajra-es-diagnose' ) ) { $( 'ajra-es-diagnose' ).addEventListener( 'click', function () {
				var out = $( 'ajra-es-diag-out' ); out.textContent = '…';
				api( '/diagnose' ).then( function ( d ) { out.textContent = JSON.stringify( d, null, 1 ).slice( 0, 8000 ); } ).catch( function ( e ) { out.textContent = e.message; } );
			} ); }
			document.querySelectorAll( '[data-hook]' ).forEach( function ( b ) { b.addEventListener( 'click', function () {
				var act = b.getAttribute( 'data-hook' ), out = $( 'ajra-es-hook-out' ); out.textContent = '…';
				api( '/webhook' + ( 'deliveries' === act ? '/deliveries' : '/' + act ), { method: 'deliveries' === act ? 'GET' : 'POST' } ).then( function ( d ) {
					if ( 'register' === act || 'remove' === act ) { window.location.reload(); return; }
					out.textContent = JSON.stringify( d, null, 1 ).slice( 0, 4000 );
				} ).catch( function ( e ) { out.textContent = e.message; } );
			} ); } );

			// Sent list: filter pills + search.
			var rows = Array.prototype.slice.call( document.querySelectorAll( '#ajra-es-log tr[data-row]' ) ), filter = 'sent';
			try { var wantF = window.sessionStorage.getItem( 'ajra_es_filter' ); if ( wantF ) { filter = wantF; window.sessionStorage.removeItem( 'ajra_es_filter' ); document.querySelectorAll( '#ajra-es-filters a' ).forEach( function ( x ) { x.classList.toggle( 'on', x.getAttribute( 'data-f' ) === filter ); } ); } } catch ( e ) { /* convenience only */ }
			var acct = 'all';
			document.querySelectorAll( '#ajra-es-accts a' ).forEach( function ( a ) { a.addEventListener( 'click', function () {
				acct = a.getAttribute( 'data-a' );
				document.querySelectorAll( '#ajra-es-accts a' ).forEach( function ( x ) { x.classList.toggle( 'on', x === a ); } ); applyFilter();
			} ); } );
			function applyFilter() {
				var q = $( 'ajra-es-log-q' ).value.toLowerCase().trim(), shown = 0;
				rows.forEach( function ( r ) {
					var g = r.getAttribute( 'data-group' ), ok = ( ( 'all' === filter && 'deleted' !== g ) || g === filter ) && ( 'all' === acct || r.getAttribute( 'data-acct' ) === acct ) && ( ! q || r.getAttribute( 'data-q' ).indexOf( q ) >= 0 );
					r.style.display = ok ? '' : 'none'; if ( ok ) { shown++; }
					var ev = r.nextElementSibling; if ( ev && ev.classList.contains( 'ajra-es-ev' ) ) { ev.style.display = ok ? '' : 'none'; }
				} );
				$( 'ajra-es-log-empty' ).style.display = shown ? 'none' : '';
				var inTrash = 'deleted' === filter;
				if ( $( 'ajra-es-del' ) ) { $( 'ajra-es-del' ).style.display = inTrash ? 'none' : ''; }
				if ( $( 'ajra-es-restore-rows' ) ) { $( 'ajra-es-restore-rows' ).style.display = inTrash ? '' : 'none'; }
				if ( $( 'ajra-es-purge' ) ) { $( 'ajra-es-purge' ).style.display = inTrash ? '' : 'none'; }
			}
			document.querySelectorAll( '#ajra-es-filters a' ).forEach( function ( a ) { a.addEventListener( 'click', function () {
				filter = a.getAttribute( 'data-f' );
				document.querySelectorAll( '#ajra-es-filters a' ).forEach( function ( x ) { x.classList.toggle( 'on', x === a ); } ); applyFilter();
			} ); } );
			$( 'ajra-es-log-q' ).addEventListener( 'input', applyFilter );
			applyFilter(); // Deleted rows stay out of All

			// New document starts clean (a page reload); Continue reopens a saved draft the same way.
			if ( $( 'ajra-es-new' ) ) { $( 'ajra-es-new' ).addEventListener( 'click', function () { window.location.hash = '#new'; window.location.reload(); } ); }
			document.querySelectorAll( '[data-draft]' ).forEach( function ( b ) { b.addEventListener( 'click', function () {
				try { window.sessionStorage.setItem( 'ajra_es_resume', b.getAttribute( 'data-draft' ) ); } catch ( e ) { /* no storage: just start a new one */ }
				window.location.hash = '#new'; window.location.reload();
			} ); } );

			// Sync with BreezeDoc, the source of truth: adds new documents, updates changed ones and removes ones deleted there (loops until BreezeDoc has no more pages).
			var imp = $( 'ajra-es-import' );
			if ( imp ) { imp.addEventListener( 'click', function () {
				var out = $( 'ajra-es-import-out' ), tot = { imported: 0, existing: 0, unmatched: 0, drafts: 0, seen: 0, updated: 0, pruned: 0 }, blocked = 0;
				imp.disabled = true;
				function step( cursor ) {
					out.textContent = 'Syncing… ' + tot.seen + ' checked';
					return api( '/import', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { cursor: cursor, include_unmatched: true, sync: true } ) } ).then( function ( d ) {
						if ( d.pruned_blocked ) { blocked = d.pruned_blocked; }
						Object.keys( tot ).forEach( function ( k ) { tot[ k ] += d[ k ] || 0; } );
						if ( d.wait ) {
							out.textContent = 'Waiting ' + d.wait + 's for BreezeDoc\'s rate limit… (' + tot.seen + ' checked so far)';
							return new Promise( function ( res ) { setTimeout( res, d.wait * 1000 ); } ).then( function () { return step( d.next_cursor ); } );
						}
						return d.next_cursor ? step( d.next_cursor ) : null;
					} );
				}
				step( '' ).then( function () {
					if ( 0 === tot.seen ) { out.textContent = 'BreezeDoc returned no documents — nothing was removed. Click Diagnose in Connection to see the raw response.'; return; }
					out.textContent = tot.seen + ' in BreezeDoc: ' + tot.imported + ' added, ' + tot.updated + ' updated, ' + tot.pruned + ' removed (no longer in BreezeDoc)' + ( blocked ? ' — ' + blocked + ' more NOT removed: that is more than half the list, so check BreezeDoc first' : '' );
					if ( tot.imported || tot.updated || tot.pruned ) { setTimeout( function () { window.location.reload(); }, 1500 ); }
				} ).catch( function ( e ) { out.style.color = '#b32d2e'; out.textContent = e.message; } ).finally( function () { imp.disabled = false; } );
			} ); }

			// Link already-logged documents to customers (signer email, then company name in the title).
			var lnk = $( 'ajra-es-link' );
			if ( lnk ) { lnk.addEventListener( 'click', function () {
				var out = $( 'ajra-es-import-out' ), tot = { checked: 0, linked: 0 };
				lnk.disabled = true;
				function step( after ) {
					out.textContent = 'Linking… ' + tot.linked + ' linked';
					return api( '/link', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { after_id: after } ) } ).then( function ( d ) {
						tot.checked += d.checked || 0; tot.linked += d.linked || 0;
						if ( d.wait ) {
							out.textContent = 'Waiting ' + d.wait + 's for BreezeDoc\'s rate limit… (' + tot.linked + ' linked so far)';
							return new Promise( function ( res ) { setTimeout( res, d.wait * 1000 ); } ).then( function () { return step( d.next_after ); } );
						}
						return d.done ? null : step( d.next_after );
					} );
				}
				step( 0 ).then( function () {
					out.textContent = tot.linked + ' of ' + tot.checked + ' unlinked documents matched a customer';
					if ( tot.linked ) { setTimeout( function () { window.location.reload(); }, 1500 ); }
				} ).catch( function ( e ) { out.style.color = '#b32d2e'; out.textContent = e.message; } ).finally( function () { lnk.disabled = false; } );
			} ); }

			// Templates workspace: tree (left) · preview that is also the editor (centre) · fields (right).
			var FE = window.AJEsignFieldEditor;
			var pdfjsBase = <?php echo wp_json_encode( plugin_dir_url( dirname( __DIR__ ) . '/ajcore-ra.php' ) . 'assets/pdfjs/' ); ?>;
			var mergeFields = <?php echo wp_json_encode( self::merge_field_catalog() ); ?>;
			var tplTree = $( 'ajra-es-tree' ), tplWs = $( 'ajra-es-ws' ), curTpl = '', fieldEd = null;
			function tplFileUrl( id ) { return base + '/templates/' + encodeURIComponent( id ) + '/file?_wpnonce=' + encodeURIComponent( nonce ); }
			function tplSource( t ) { return 'ajcore' === t.source ? 'ajcore' : ( String( t.id ).indexOf( 'tpl_' ) === 0 ? 'team' : 'personal' ); }
			function rememberTpl( id ) { try { window.sessionStorage.setItem( 'ajra_tpl_sel', id ); } catch ( e ) { /* convenience only */ } }
			function useTemplate( t ) {
				var form = $( 'ajra-es-template' ); if ( ! form ) { return; }
				setView( 'new' );
				document.querySelectorAll( '.ajra-es a[data-mode]' ).forEach( function ( a ) { if ( 'template' === a.getAttribute( 'data-mode' ) ) { a.click(); } } );
				form.value = t.id; form.dispatchEvent( new Event( 'change' ) );
				form.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			}
			function renderTree() {
				tplTree.innerHTML = '';
				function item( label, id ) {
					var d = document.createElement( 'div' ); d.className = 'ajra-ti' + ( curTpl === id ? ' on' : '' );
					d.innerHTML = '<span>' + esc( label ) + '</span>'; d.addEventListener( 'click', function () { selectTpl( id ); } ); return d;
				}
				if ( '__new' === curTpl ) { tplTree.appendChild( item( 'New form', '__new' ) ); }
				templates.forEach( function ( t ) { tplTree.appendChild( item( t.rawTitle || t.title || t.id, t.id ) ); } );
				if ( ! templates.length && '__new' !== curTpl ) { tplTree.innerHTML = '<div class="ajra-muted" style="padding:10px">No forms yet.</div>'; }
				$( 'ajra-es-tpl-count' ).textContent = templates.length ? '(' + templates.length + ')' : '';
			}
			function selectTpl( id ) {
				if ( fieldEd ) { fieldEd.destroy(); fieldEd = null; }
				curTpl = id; rememberTpl( id ); renderTree();
				if ( '__new' === id ) { openChooser(); return; }
				var t = templates.filter( function ( x ) { return x.id === id; } )[0];
				if ( ! t ) { tplWs.innerHTML = '<div class="ajra-empty">Select a form on the left, or create a new one.</div>'; return; }
				openLocal( t, '' );
			}
			function openChooser() {
				tplWs.innerHTML = '<div class="ajra-wsbox"><div class="ajra-grid" style="max-width:640px"><div class="ajra-choice" data-k="pdf"><strong>Upload a PDF</strong><span class="ajra-muted">An existing form or contract. Drag signature, text, date and checkbox fields onto it.</span></div>' +
					'<div class="ajra-choice" data-k="doc"><strong>Build a document</strong><span class="ajra-muted">Write it here with tables and images; customer details are merged in when you send.</span></div></div></div>';
				tplWs.querySelectorAll( '.ajra-choice' ).forEach( function ( c ) { c.addEventListener( 'click', function () { openLocal( null, c.getAttribute( 'data-k' ) ); } ); } );
			}

			// AJCore templates: settings strip + preview/editor + fields panel.
			function docEditor( host, html, top ) {
				FE.ensureCss();
				host.innerHTML = '';
				var root = document.createElement( 'div' ), main = document.createElement( 'div' ), side = document.createElement( 'div' ), tb = document.createElement( 'div' ), ed = document.createElement( 'div' );
				root.className = 'ajfe-root'; main.className = 'ajfe-main'; side.className = 'ajfe-side'; tb.className = 'ajfe-bar'; ed.id = 'ajra-es-tpl-body'; ed.contentEditable = 'true'; ed.spellcheck = true;
				if ( html && html.indexOf( '<' ) === -1 ) { // earlier plain-text templates
					html = html.split( /\n{2,}/ ).map( function ( para ) { return '<p>' + esc( para ).replace( /\n/g, '<br>' ) + '</p>'; } ).join( '' );
				}
				ed.innerHTML = html || '<p><br></p>';
				main.appendChild( tb ); main.appendChild( ed ); root.appendChild( main ); root.appendChild( side ); host.appendChild( root );
				function mk( label, fn, title ) {
					var b = document.createElement( 'button' ); b.type = 'button'; b.textContent = label; if ( title ) { b.title = title; }
					b.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } ); // keep the caret in the page
					b.addEventListener( 'click', function () { ed.focus(); fn(); } ); tb.appendChild( b ); return b;
				}
				var cmd = function ( c, v ) { return function () { document.execCommand( c, false, v || null ); }; };
				mk( 'B', cmd( 'bold' ) ).style.fontWeight = '700'; mk( 'I', cmd( 'italic' ) ).style.fontStyle = 'italic'; mk( 'U', cmd( 'underline' ) ).style.textDecoration = 'underline';
				mk( 'H1', cmd( 'formatBlock', 'h1' ) ); mk( 'H2', cmd( 'formatBlock', 'h2' ) ); mk( 'Text', cmd( 'formatBlock', 'p' ) );
				mk( '• List', cmd( 'insertUnorderedList' ) ); mk( '1. List', cmd( 'insertOrderedList' ) );
				mk( '⇤', cmd( 'justifyLeft' ), 'Align left' ); mk( '↔', cmd( 'justifyCenter' ), 'Center' ); mk( '⇥', cmd( 'justifyRight' ), 'Align right' );
				mk( 'Table', function () {
					var r = parseInt( window.prompt( 'Rows', '3' ), 10 ), c = parseInt( window.prompt( 'Columns', '3' ), 10 ); if ( ! r || ! c ) { return; }
					var h = '<table>'; for ( var i = 0; i < r; i++ ) { h += '<tr>'; for ( var j = 0; j < c; j++ ) { h += '<td>&nbsp;</td>'; } h += '</tr>'; } h += '</table><p><br></p>';
					document.execCommand( 'insertHTML', false, h );
				} );
				function cellOf() { var n = window.getSelection().anchorNode; while ( n && n !== ed ) { if ( n.nodeType === 1 && /^T[DH]$/.test( n.tagName ) ) { return n; } n = n.parentNode; } return null; }
				mk( '+Row', function () { var c = cellOf(); if ( ! c ) { return; } var tr = c.parentNode, n = tr.cloneNode( true ); Array.prototype.forEach.call( n.cells, function ( x ) { x.innerHTML = '&nbsp;'; } ); tr.parentNode.insertBefore( n, tr.nextSibling ); }, 'Add a row below the cursor' );
				mk( '+Col', function () { var c = cellOf(); if ( ! c ) { return; } var idx = c.cellIndex; Array.prototype.forEach.call( c.closest( 'table' ).rows, function ( tr ) { tr.insertCell( idx + 1 ).innerHTML = '&nbsp;'; } ); }, 'Add a column right of the cursor' );
				mk( '−Row', function () { var c = cellOf(); if ( c ) { var t = c.closest( 'table' ); c.parentNode.remove(); if ( ! t.rows.length ) { t.remove(); } } } );
				mk( '−Col', function () { var c = cellOf(); if ( ! c ) { return; } var idx = c.cellIndex, t = c.closest( 'table' ); Array.prototype.forEach.call( t.rows, function ( tr ) { if ( tr.cells[ idx ] ) { tr.deleteCell( idx ); } } ); if ( ! t.rows[0] || ! t.rows[0].cells.length ) { t.remove(); } } );
				mk( 'Image', function () {
					var inp = document.createElement( 'input' ); inp.type = 'file'; inp.accept = 'image/png,image/jpeg,image/gif,image/webp';
					inp.addEventListener( 'change', function () {
						var f = inp.files[0]; if ( ! f ) { return; }
						var rd = new FileReader();
						rd.onload = function () {
							var im = new Image();
							im.onload = function () {
								var sc = Math.min( 1, 1000 / im.width ), cv = document.createElement( 'canvas' ); cv.width = Math.round( im.width * sc ); cv.height = Math.round( im.height * sc );
								cv.getContext( '2d' ).drawImage( im, 0, 0, cv.width, cv.height );
								ed.focus(); document.execCommand( 'insertImage', false, cv.toDataURL( 'image/jpeg' === f.type ? 'image/jpeg' : 'image/png', 0.85 ) );
							};
							im.src = rd.result;
						};
						rd.readAsDataURL( f );
					} );
					inp.click();
				} );
				mk( 'Page break', function () { document.execCommand( 'insertHTML', false, '<hr class="pb"><p><br></p>' ); }, 'Start a new page here' );
				mk( 'Undo', cmd( 'undo' ) ); mk( 'Redo', cmd( 'redo' ) );
				side.innerHTML = '';
				if ( top ) { side.appendChild( top ); }
				var own = document.createElement( 'div' ); own.className = 'ajfe-own'; side.appendChild( own );
				own.insertAdjacentHTML( 'beforeend', '<h4>Fields — drag into the page</h4><input type="search" class="ajra-fsearch" placeholder="Search fields…">' );
				own.querySelector( '.ajra-fsearch' ).addEventListener( 'input', function () { var q = this.value.toLowerCase().trim(); own.querySelectorAll( '.ajfe-chip' ).forEach( function ( c ) { c.style.display = ! q || c.textContent.toLowerCase().indexOf( q ) >= 0 ? '' : 'none'; } ); } );
				Object.keys( mergeFields ).forEach( function ( k ) {
					var c = document.createElement( 'button' ); c.type = 'button'; c.className = 'ajfe-chip'; c.draggable = true; c.textContent = mergeFields[ k ]; c.title = '{{' + k + '}}';
					c.addEventListener( 'dragstart', function ( e ) { e.dataTransfer.setData( 'text/plain', '{{' + k + '}}' ); e.dataTransfer.effectAllowed = 'copy'; } );
					c.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } );
					c.addEventListener( 'click', function () { ed.focus(); document.execCommand( 'insertText', false, '{{' + k + '}}' ); } );
					own.appendChild( c );
				} );
				return ed;
			}

			function openLocal( t, startKind ) {
				var isNew = ! t, kind = t ? ( 'doc' === t.kind ? 'doc' : 'pdf' ) : startKind, layout = t && t.layout ? t.layout.slice() : [], newFile = null, docEl = null;
				var signerList = t && t.recipients && t.recipients.length ? t.recipients.map( function ( r, i ) { return r.role || ( 'Signer ' + ( i + 1 ) ); } ) : [ 'Signer 1' ];
				tplWs.innerHTML = '<div class="ajra-wsbox"><div id="ajra-es-t-host"></div></div>';
				var host = $( 'ajra-es-t-host' );
				// The panel holds only generic actions; it sits above the editor until it is mounted, then moves into the right panel.
				var strip = document.createElement( 'div' ); strip.className = 'ajra-wsstrip';
				strip.innerHTML = '<input type="text" id="ajra-es-t-title" maxlength="191" placeholder="Form name">' +
					( isNew ? '' : '<div class="ajra-wsrow"><button type="button" class="button button-primary button-small" id="ajra-es-t-use">Use form</button><button type="button" class="button button-small" id="ajra-es-t-dup">Duplicate</button>' +
						( 'doc' === kind ? '' : '<button type="button" class="button button-small" id="ajra-es-t-replbtn">Replace PDF</button><input type="file" id="ajra-es-t-repl" accept="application/pdf,.pdf" style="display:none">' ) +
						'<a class="button button-small" target="_blank" rel="noopener noreferrer" href="' + esc( tplFileUrl( t.id ) ) + '">Preview PDF</a><button type="button" class="button button-small" id="ajra-es-t-del" style="color:#b32d2e">Delete</button></div>' ) +
					( 'doc' === kind ? '<div class="ajra-wsrow"><span class="ajra-muted">Signers</span><button type="button" class="button button-small" id="ajra-es-t-sm">−</button><strong id="ajra-es-t-sn"></strong><button type="button" class="button button-small" id="ajra-es-t-sp">+</button></div>' : '' ) +
					'<div class="ajra-wsrow"><button type="button" class="button button-primary button-small" id="ajra-es-t-save">Save</button></div><div class="ajra-err" id="ajra-es-t-err"></div>';
				var q = function ( id ) { return strip.querySelector( '#' + id ); };
				q( 'ajra-es-t-title' ).value = t ? ( t.rawTitle || t.title ) : '';
				var err = q( 'ajra-es-t-err' );
				function setHost( html ) { if ( fieldEd ) { fieldEd.destroy(); fieldEd = null; } host.innerHTML = html; host.insertBefore( strip, host.firstChild ); }
				function mountPdf( buf ) {
					if ( fieldEd ) { fieldEd.destroy(); fieldEd = null; }
					fieldEd = FE.mount( host, { pdfData: buf, pdfjsBase: pdfjsBase, fields: layout, signers: signerList, mergeFields: mergeFields, sideTop: strip, onChange: function ( f ) { layout = f; } } );
				}
				function pdfPicker() {
					setHost( '<div class="ajra-empty" style="text-align:left;margin-top:8px"><label>Choose the PDF (max 25 MB)</label><br><input type="file" id="ajra-es-t-file" accept="application/pdf,.pdf"></div>' );
					host.querySelector( '#ajra-es-t-file' ).addEventListener( 'change', function ( ev ) {
						var f = ev.target.files[0]; if ( ! f ) { return; }
						newFile = f; layout = []; f.arrayBuffer().then( mountPdf );
						if ( ! q( 'ajra-es-t-title' ).value ) { q( 'ajra-es-t-title' ).value = f.name.replace( /\.pdf$/i, '' ); }
					} );
				}
				function stepper() { if ( q( 'ajra-es-t-sn' ) ) { q( 'ajra-es-t-sn' ).textContent = signerList.length; } }
				if ( 'doc' === kind ) {
					docEl = docEditor( host, t && t.body ? t.body : '', strip ); stepper();
					q( 'ajra-es-t-sp' ).addEventListener( 'click', function () { signerList.push( 'Signer ' + ( signerList.length + 1 ) ); stepper(); } );
					q( 'ajra-es-t-sm' ).addEventListener( 'click', function () { if ( signerList.length > 1 ) { signerList.pop(); stepper(); } } );
				} else if ( isNew ) { pdfPicker(); } else {
					setHost( '<div class="ajra-empty" style="margin-top:8px">Loading PDF…</div>' );
					fetch( tplFileUrl( t.id ), { credentials: 'same-origin' } ).then( function ( r ) { if ( ! r.ok ) { throw new Error( 'The form PDF could not be loaded.' ); } return r.arrayBuffer(); } ).then( mountPdf )
						.catch( function ( e ) { setHost( '<div class="ajra-empty" style="margin-top:8px">' + esc( e.message ) + '</div>' ); } );
				}
				if ( q( 'ajra-es-t-replbtn' ) ) {
					q( 'ajra-es-t-replbtn' ).addEventListener( 'click', function () { q( 'ajra-es-t-repl' ).click(); } );
					q( 'ajra-es-t-repl' ).addEventListener( 'change', function ( ev ) { var f = ev.target.files[0]; if ( f ) { newFile = f; f.arrayBuffer().then( mountPdf ); } } );
				}
				if ( q( 'ajra-es-t-use' ) ) { q( 'ajra-es-t-use' ).addEventListener( 'click', function () { useTemplate( t ); } ); }
				if ( q( 'ajra-es-t-dup' ) ) { q( 'ajra-es-t-dup' ).addEventListener( 'click', function () {
					api( '/templates/duplicate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { id: t.id } ) } ).then( function ( c ) { rememberTpl( c && c.id ? c.id : '' ); window.location.reload(); } ).catch( function ( e ) { window.alert( e.message ); } );
				} ); }
				if ( q( 'ajra-es-t-del' ) ) { q( 'ajra-es-t-del' ).addEventListener( 'click', function () {
					if ( ! window.confirm( 'Delete the form "' + ( t.rawTitle || t.title ) + '"? This cannot be undone.' ) ) { return; }
					api( '/templates/delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { id: t.id } ) } ).then( function () { rememberTpl( '' ); window.location.reload(); } ).catch( function ( e ) { window.alert( e.message ); } );
				} ); }
				q( 'ajra-es-t-save' ).addEventListener( 'click', function () {
					var btn = this, fd = new FormData();
					fd.append( 'id', t ? t.id : '' ); fd.append( 'title', q( 'ajra-es-t-title' ).value ); fd.append( 'kind', kind );
					if ( 'doc' === kind ) { fd.append( 'signers', JSON.stringify( signerList ) ); fd.append( 'body', docEl ? docEl.innerHTML : '' ); }
					else { fd.append( 'signers', JSON.stringify( fieldEd ? fieldEd.getSigners() : signerList ) ); fd.append( 'layout', JSON.stringify( fieldEd ? fieldEd.getFields() : layout ) ); if ( newFile ) { fd.append( 'pdf', newFile ); } }
					btn.disabled = true; err.style.display = 'none';
					api( '/templates/save', { method: 'POST', body: fd } ).then( function ( saved ) { rememberTpl( saved && saved.id ? saved.id : '' ); window.location.reload(); } )
						.catch( function ( e ) { err.style.display = 'block'; err.textContent = e.message; btn.disabled = false; } );
				} );
			}
			if ( tplTree ) {
				$( 'ajra-es-tpl-new' ).addEventListener( 'click', function () { selectTpl( '__new' ); } );
				var firstSel = '';
				try { firstSel = window.sessionStorage.getItem( 'ajra_tpl_sel' ) || ''; } catch ( e ) { firstSel = ''; }
				if ( '__new' === firstSel || ( firstSel && ! templates.some( function ( t ) { return t.id === firstSel; } ) ) ) { firstSel = ''; }
				renderTree();
				if ( firstSel ) { selectTpl( firstSel ); } else { tplWs.innerHTML = '<div class="ajra-empty">Select a form on the left, or create a new one.</div>'; }
			}

			if ( $( 'ajra-es-our-save' ) ) { $( 'ajra-es-our-save' ).addEventListener( 'click', function () {
				var vals = {}, msg = $( 'ajra-es-our-msg' ), btn = this;
				document.querySelectorAll( '.ajra-es-our' ).forEach( function ( i ) { vals[ i.getAttribute( 'data-k' ) ] = i.value; } );
				btn.disabled = true; msg.textContent = '';
				api( '/our-details/save', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { values: vals } ) } )
					.then( function () { msg.style.color = ''; msg.textContent = 'Saved'; } ).catch( function ( e ) { msg.style.color = '#b32d2e'; msg.textContent = e.message; } ).finally( function () { btn.disabled = false; } );
			} ); }

			// Select local copies and delete them (the BreezeDoc documents are untouched).
			var selAll = $( 'ajra-es-sel-all' ), delBtn = $( 'ajra-es-del' );
			function selectedIds() {
				return Array.prototype.filter.call( document.querySelectorAll( '.ajra-es-sel' ), function ( c ) { return c.checked; } ).map( function ( c ) { return parseInt( c.value, 10 ); } );
			}
			function updateDel() {
				var n = selectedIds().length;
				delBtn.disabled = ! n; delBtn.textContent = n ? 'Delete selected (' + n + ')' : 'Delete selected';
				var rr = $( 'ajra-es-restore-rows' ), pg = $( 'ajra-es-purge' );
				if ( rr ) { rr.disabled = ! n; rr.textContent = n ? 'Restore selected (' + n + ')' : 'Restore selected'; }
				if ( pg ) { pg.disabled = ! n; pg.textContent = n ? 'Delete permanently (' + n + ')' : 'Delete permanently'; }
			}
			document.querySelectorAll( '.ajra-es-sel' ).forEach( function ( c ) { c.addEventListener( 'change', updateDel ); } );
			selAll.addEventListener( 'change', function () {
				rows.forEach( function ( r ) { if ( 'none' !== r.style.display ) { r.querySelector( '.ajra-es-sel' ).checked = selAll.checked; } } );
				updateDel();
			} );
			delBtn.addEventListener( 'click', function () {
				var ids = selectedIds();
				if ( ! ids.length || ! window.confirm( 'Move ' + ids.length + ' document(s) to Deleted? Nothing changes in BreezeDoc.' ) ) { return; }
				delBtn.disabled = true;
				api( '/documents/delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { ids: ids } ) } )
					.then( function () { window.location.reload(); } )
					.catch( function ( e ) { window.alert( e.message ); updateDel(); } );
			} );

			// Preview modal: signed PDF (team), page images, or a pointer to BreezeDoc's viewer.
			var modal = $( 'ajra-es-modal' ), mbody = $( 'ajra-es-mbody' );
			function closeModal() { modal.classList.remove( 'on' ); mbody.innerHTML = ''; }
			$( 'ajra-es-mclose' ).addEventListener( 'click', closeModal );
			modal.addEventListener( 'click', function ( ev ) { if ( ev.target === modal ) { closeModal(); } } );
			document.addEventListener( 'keydown', function ( ev ) { if ( 'Escape' === ev.key ) { closeModal(); } } );
			function sentUrl( id, dl ) { return base + '/documents/' + id + '/sent-file?_wpnonce=' + encodeURIComponent( nonce ) + ( dl ? '&download=1' : '' ); }
			function showPreview( title, d, id ) {
				$( 'ajra-es-mtitle' ).textContent = title;
				var open = $( 'ajra-es-mopen' ), cert = $( 'ajra-es-mcert' );
				open.style.display = cert.style.display = 'none'; open.textContent = 'Open in new tab'; open.removeAttribute( 'href' );
				mbody.innerHTML = '';
				if ( 'pdf' === d.mode && d.url ) {
					mbody.innerHTML = '<iframe src="' + esc( d.url ) + '" title="Document preview"></iframe>';
					open.href = d.url; open.style.display = '';
					if ( d.certificateUrl ) { cert.href = d.certificateUrl; cert.style.display = ''; }
				} else if ( 'sent' === d.mode ) {
					mbody.innerHTML = '<iframe src="' + esc( sentUrl( id ) ) + '" title="Document preview"></iframe>';
					open.href = sentUrl( id, true ); open.textContent = 'Download'; open.style.display = '';
				} else if ( 'images' === d.mode && d.pages && d.pages.length ) {
					mbody.innerHTML = d.pages.map( function ( u ) { return '<img src="' + esc( u ) + '" alt="">'; } ).join( '' );
				} else {
					mbody.innerHTML = '<div class="ajra-mmsg">' + esc( d.message || 'No preview available.' ) + ( d.openUrl ? '<p><a class="button" target="_blank" rel="noopener noreferrer" href="' + esc( d.openUrl ) + '">Open in BreezeDoc</a></p>' : '' ) + '</div>';
				}
				modal.classList.add( 'on' );
			}

			// Deleted: restore rows, or delete them permanently.
			function trashPost( path, ids, confirmText ) {
				if ( ! ids.length || ( confirmText && ! window.confirm( confirmText ) ) ) { return; }
				api( path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { ids: ids } ) } )
					.then( function () { window.location.reload(); } )
					.catch( function ( e ) { window.alert( e.message ); } );
			}
			if ( $( 'ajra-es-restore-rows' ) ) { $( 'ajra-es-restore-rows' ).addEventListener( 'click', function () { trashPost( '/documents/restore-rows', selectedIds(), '' ); } ); }
			if ( $( 'ajra-es-purge' ) ) { $( 'ajra-es-purge' ).addEventListener( 'click', function () {
				var ids = selectedIds();
				trashPost( '/documents/purge', ids, 'Permanently delete ' + ids.length + ' document(s) from this portal? This cannot be undone. They stay in BreezeDoc.' );
			} ); }

			// Row actions.
			function setProgress( tr, recipients ) {
				var cell = tr.querySelector( '[data-progress]' ), list = recipients || [];
				cell.textContent = list.length ? list.filter( function ( r ) { return r.completedAt; } ).length + '/' + list.length : '—';
			}
			document.querySelectorAll( '#ajra-es-log [data-act]' ).forEach( function ( b ) { b.addEventListener( 'click', function () {
				var act = b.getAttribute( 'data-act' ), id = b.getAttribute( 'data-id' ), label = b.textContent, tr = b.closest( 'tr' );
				if ( 'cancel' === act && ! window.confirm( 'Cancel this document? Signers will no longer be able to sign it.' ) ) { return; }
				b.disabled = true; b.textContent = '…';
				api( '/documents/' + id + '/' + act, { method: ( 'download' === act || 'events' === act || 'preview' === act ) ? 'GET' : 'POST' } ).then( function ( d ) {
					if ( 'preview' === act ) {
						showPreview( ( tr.children[2] ? tr.children[2].textContent.trim() : '' ) || 'Document', d, id );
					} else if ( 'download' === act ) {
						if ( d.sent ) { window.location.href = sentUrl( id, true ); } else if ( d.url ) { window.open( d.url, '_blank', 'noopener' ); } else { window.alert( 'No download URL returned.' ); }
						if ( d.certificateUrl ) { window.open( d.certificateUrl, '_blank', 'noopener' ); }
					} else if ( 'events' === act ) {
						var nx = tr.nextElementSibling;
						if ( nx && nx.classList.contains( 'ajra-es-ev' ) ) { nx.remove(); } else {
							var lines = ( d.events || [] ).map( function ( e ) { return ( e.created_at || e.at || e.timestamp || '' ) + ' — ' + ( e.type || e.event || e.action || JSON.stringify( e ) ); } );
							var ev = document.createElement( 'tr' ); ev.className = 'ajra-es-ev'; ev.innerHTML = '<td colspan="7"><pre>' + esc( lines.join( '\n' ) || 'No events.' ) + '</pre></td>'; tr.parentNode.insertBefore( ev, nx );
						}
					} else if ( 'remind' === act ) {
						window.alert( 'Reminder sent.' );
					} else if ( d.status ) {
						var pill = tr.querySelector( '[data-status]' ); pill.className = 'ajra-pill ' + d.status; pill.textContent = labels[ d.status ] || d.status;
						setProgress( tr, d.recipients );
						if ( 'cancel' === act ) { window.location.reload(); }
					}
				} ).catch( function ( e ) { window.alert( e.message ); } ).finally( function () { b.disabled = false; b.textContent = label; } );
			} ); } );

			// New request form.
			var cust = $( 'ajra-es-customer' ), tpl = $( 'ajra-es-template' ), box = $( 'ajra-es-recipients' ), send = $( 'ajra-es-send' ), err = $( 'ajra-es-error' );
			if ( ! cust ) { return; }
			var mode = 'template', custQ = $( 'ajra-es-customer-q' ), summary = $( 'ajra-es-summary' );
			function fillCustomers() {
				var q = custQ.value.toLowerCase().trim(), keep = cust.value, shown = 0;
				cust.innerHTML = '';
				customers.forEach( function ( c ) {
					if ( q && ( c.name + ' ' + c.email ).toLowerCase().indexOf( q ) < 0 && c.id !== keep ) { return; }
					if ( shown++ >= 200 ) { return; }
					var o = document.createElement( 'option' ); o.value = c.id; o.textContent = c.name + ' (' + c.email + ')'; o.selected = c.id === keep; cust.appendChild( o );
				} );
			}
			custQ.addEventListener( 'input', fillCustomers );
			// Condensed customer picker: one button showing the choice; click to search and change.
			var custBtn = $( 'ajra-es-cust-btn' ), custPop = $( 'ajra-es-cust-pop' );
			function custLabel() { var c = customers.filter( function ( x ) { return x.id === cust.value; } )[0]; custBtn.textContent = ( c ? c.name || c.email : 'Select customer' ) + ' ▾'; }
			custBtn.addEventListener( 'click', function () { var open = 'none' === custPop.style.display; custPop.style.display = open ? '' : 'none'; if ( open ) { custQ.focus(); } } );
			document.addEventListener( 'click', function ( e ) { if ( ! custPop.contains( e.target ) && e.target !== custBtn ) { custPop.style.display = 'none'; } } );
			cust.addEventListener( 'change', function () {
				custLabel(); custPop.style.display = 'none';
				var c = customers.filter( function ( x ) { return x.id === cust.value; } )[0], r = box.querySelector( '.ajra-es-r' );
				if ( c && r && ! r.querySelector( '.n' ).value && ! r.querySelector( '.e' ).value ) { r.querySelector( '.n' ).value = c.name; r.querySelector( '.e' ).value = c.email; updateSend(); } // first signer = the customer, if empty
			} );
			fillCustomers();
			templates.forEach( function ( t ) { var o = document.createElement( 'option' ); o.value = t.id; o.textContent = t.title || t.id; tpl.appendChild( o ); } );

			function row( party, name, email, first, role ) {
				return '<div class="ajra-row ajra-es-r" data-party="' + party + '"><span class="ajra-muted" style="width:90px">' + esc( role || ( 'Party ' + party ) ) + '</span>' +
					'<input type="text" class="n" placeholder="Name" value="' + esc( name ) + '"><input type="email" class="e" placeholder="Email" value="' + esc( email ) + '">' +
					( first ? '<button type="button" class="button button-small ajra-es-use">Use customer</button>' : '' ) +
					( ! first ? '<button type="button" class="button button-small ajra-es-del">×</button>' : '' ) + '</div>';
			}
			function renderRecipients( list ) {
				box.innerHTML = ( list.length ? '<label>Signers (in signing order)</label>' : '' ) + list.map( function ( r, i ) { return row( r.party || i + 1, r.name, r.email, 0 === i, r.role ); } ).join( '' );
				$( 'ajra-es-add-wrap' ).style.display = 'block';
				if ( inlineEd ) { inlineEd.setSigners( currentRoles() ); }
				updateSend();
			}
			function readRecipients() {
				return Array.prototype.map.call( box.querySelectorAll( '.ajra-es-r' ), function ( r, i ) { return { party: parseInt( r.getAttribute( 'data-party' ), 10 ) || i + 1, name: r.querySelector( '.n' ).value.trim(), email: r.querySelector( '.e' ).value.trim() }; } );
			}
			function currentTemplateRecipients() {
				var t = templates.filter( function ( x ) { return String( x.id ) === tpl.value; } )[0];
				return t ? t.recipients : [];
			}
			// Enables Send only when everything needed is present, and says what is missing.
			function updateSend() {
				var rec = readRecipients(), missing = '';
				if ( ! cust.value ) { missing = 'Pick a customer'; }
				else if ( 'template' === mode && ! tpl.value ) { missing = 'Pick a form'; }
				else if ( 'pdf' === mode && ! $( 'ajra-es-pdf' ).files[0] ) { missing = 'Choose a PDF'; }
				else if ( ! rec.length ) { missing = 'No signers'; }
				else if ( rec.some( function ( r ) { return ! r.name || ! r.email; } ) ) { missing = 'Fill in every signer'; }
				queueRefresh();
				send.disabled = '' !== missing;
				if ( $( 'ajra-es-preview' ) ) { $( 'ajra-es-preview' ).disabled = '' !== missing; }
				summary.textContent = missing || ( rec.length + ( 1 === rec.length ? ' signer' : ' signers' ) );
			}
			function setMode( m ) {
				mode = m;
				document.querySelectorAll( '.ajra-es a[data-mode]' ).forEach( function ( a ) { a.classList.toggle( 'on', a.getAttribute( 'data-mode' ) === m ); } );
				$( 'ajra-es-tpl-wrap' ).style.display = 'template' === m ? '' : 'none';
				$( 'ajra-es-pdf-wrap' ).style.display = 'pdf' === m ? '' : 'none';
				renderRecipients( 'pdf' === m ? [ { party: 1, name: '', email: '' } ] : currentTemplateRecipients() );
				refreshInline();
			}
			document.querySelectorAll( '.ajra-es a[data-mode]' ).forEach( function ( a ) { a.addEventListener( 'click', function () { setMode( a.getAttribute( 'data-mode' ) ); } ); } );
			var tplLayout = [];
			var varVals = {};
			function renderVars() {
				var t = templates.filter( function ( x ) { return String( x.id ) === tpl.value; } )[0], layout = tplLayout.length ? tplLayout : ( t && t.layout ? t.layout : [] ), names = [], box2 = $( 'ajra-es-vars' );
				layout.forEach( function ( f ) { String( f.fill || '' ).replace( /\{\{\s*ask\.([a-z0-9_]+)\s*\}\}/gi, function ( m, n ) { n = n.toLowerCase(); if ( names.indexOf( n ) < 0 ) { names.push( n ); } return m; } ); } );
				box2.style.display = names.length ? '' : 'none';
				box2.innerHTML = names.map( function ( n ) { return '<label>' + esc( n.replace( /_/g, ' ' ).replace( /^./, function ( c ) { return c.toUpperCase(); } ) ) + '</label><input type="text" class="ajra-es-var" data-n="' + esc( n ) + '" value="' + esc( varVals[ n ] || '' ) + '">'; } ).join( '' );
				box2.querySelectorAll( '.ajra-es-var' ).forEach( function ( i ) { i.addEventListener( 'input', function () { varVals[ i.getAttribute( 'data-n' ) ] = i.value; queueRefresh(); } ); } );
			}
			function readVars() { var o = {}; document.querySelectorAll( '.ajra-es-var' ).forEach( function ( i ) { o[ i.getAttribute( 'data-n' ) ] = i.value; } ); return o; }
			function syncTplFields() {
				var t = templates.filter( function ( x ) { return String( x.id ) === tpl.value; } )[0];
				tplLayout = [];
				renderVars();
			}
			tpl.addEventListener( 'change', function () { renderRecipients( currentTemplateRecipients() ); syncTplFields(); refreshInline(); } );
			cust.addEventListener( 'change', updateSend );
			var upLayout = [], inlineEd = null, inlineHost = $( 'ajra-es-inline' ), inlineSeq = 0, reqside = $( 'ajra-es-reqside' ), reqHome = reqside.parentNode;
			function rehome() { if ( reqside.parentNode !== reqHome ) { reqHome.appendChild( reqside ); } } // back into the card before the editor is torn down
			// Live values for the "Preview with values" checkbox: every merge field resolved for the chosen customer.
			function resolveValues() {
				return fetch( base + '/merge-values', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify( { stripe_customer_id: cust.value, recipients: readRecipients(), vars: JSON.stringify( readVars() ) } ) } ).then( function ( r ) { return r.json(); } );
			}
			var rvTimer = null;
			function queueRefresh() { queueSave(); clearTimeout( rvTimer ); rvTimer = setTimeout( function () { if ( inlineEd && inlineEd.refreshValues ) { inlineEd.refreshValues(); } }, 350 ); }
			function currentRoles() { return readRecipients().map( function ( r, i ) { return r.name || ( 'Signer ' + ( i + 1 ) ); } ); }
			function hideInline() { inlineSeq++; rehome(); if ( inlineEd ) { inlineEd.destroy(); inlineEd = null; } inlineHost.style.display = 'none'; inlineHost.innerHTML = ''; }
			function showInline( loader, fields, onChange ) {
				var seq = ++inlineSeq; rehome(); if ( inlineEd ) { inlineEd.destroy(); inlineEd = null; }
				inlineHost.style.display = ''; inlineHost.innerHTML = '<div class="ajra-muted">Loading the document…</div>';
				loader().then( function ( buf ) {
					if ( seq !== inlineSeq ) { return; }
					inlineEd = window.AJEsignFieldEditor.mount( inlineHost, { pdfData: buf, pdfjsBase: pdfjsBase, fields: fields, signers: currentRoles(), mergeFields: mergeFields, sideTop: reqside, lockSigners: true, resolveValues: resolveValues, onChange: onChange } );
				} ).catch( function ( e ) { if ( seq === inlineSeq ) { inlineHost.innerHTML = '<div class="ajra-err" style="display:block">' + esc( e.message ) + '</div>'; } } );
			}
			// The document and its fields sit under the form: the template's saved fields load automatically; edits apply to this request only.
			function refreshInline() {
				if ( 'template' === mode ) {
					var t = templates.filter( function ( x ) { return String( x.id ) === tpl.value; } )[0];
					if ( ! t || 'doc' === t.kind ) { hideInline(); return; }
					showInline( function () { return fetch( tplFileUrl( t.id ), { credentials: 'same-origin' } ).then( function ( r ) { if ( ! r.ok ) { throw new Error( 'The form PDF could not be loaded.' ); } return r.arrayBuffer(); } ); }, tplLayout.length ? tplLayout : ( t.layout || [] ), function ( f ) { tplLayout = f; renderVars(); queueSave(); } );
				} else {
					var file = $( 'ajra-es-pdf' ).files[0];
					if ( ! file ) { hideInline(); return; }
					showInline( function () { return file.arrayBuffer(); }, upLayout, function ( f ) { upLayout = f; queueSave(); } );
				}
			}
			$( 'ajra-es-pdf' ) && $( 'ajra-es-pdf' ).addEventListener( 'change', function () { upLayout = []; fileDirty = true; refreshInline(); updateSend(); } );
			box.addEventListener( 'input', updateSend );
			$( 'ajra-es-add' ).addEventListener( 'click', function () { var cur = readRecipients(); cur.push( { party: cur.length + 1, name: '', email: '' } ); renderRecipients( cur ); } );
			var msgToggle = $( 'ajra-es-msg-toggle' );
			if ( msgToggle ) { msgToggle.addEventListener( 'click', function ( ev ) { ev.preventDefault(); var m = $( 'ajra-es-message' ); m.style.display = 'none' === m.style.display ? 'block' : 'none'; } ); }
			box.addEventListener( 'click', function ( ev ) {
				var t = ev.target;
				if ( t.classList.contains( 'ajra-es-use' ) ) {
					var c = customers.filter( function ( x ) { return x.id === cust.value; } )[0];
					if ( c ) { var r = t.closest( '.ajra-es-r' ); r.querySelector( '.n' ).value = c.name; r.querySelector( '.e' ).value = c.email; updateSend(); }
				} else if ( t.classList.contains( 'ajra-es-del' ) ) {
					var cur = readRecipients(); cur.splice( Array.prototype.indexOf.call( box.querySelectorAll( '.ajra-es-r' ), t.closest( '.ajra-es-r' ) ), 1 );
					cur.forEach( function ( r, i ) { r.party = i + 1; } ); renderRecipients( cur );
				}
			} );
			// ── Drafts: what you are working on is saved as "Not sent" as you go, and when you cancel or leave. ──
			var draftId = 0, saveTimer = null, fileDirty = false, saving = false, sentFlag = false;
			function meaningful() { return !! ( cust.value || ( 'template' === mode && tpl.value ) || ( 'pdf' === mode && $( 'ajra-es-pdf' ).files[0] ) ); }
			function draftPayload() {
				var f = $( 'ajra-es-pdf' ).files[0], ex = $( 'ajra-es-expires' ), od = $( 'ajra-es-order' );
				return { mode: mode, template_id: tpl.value, customer_id: cust.value, recipients: readRecipients(), layout: 'pdf' === mode ? upLayout : tplLayout, vars: readVars(),
					expires: ex ? parseInt( ex.value, 10 ) || 30 : 30, ordered: od ? od.checked : true, message: $( 'ajra-es-message' ).value, title: $( 'ajra-es-title' ).value, page: $( 'ajra-es-page' ).value || 0, pdf_name: f ? f.name : '' };
			}
			function saveDraft( keepalive ) {
				if ( sentFlag || saving || ! meaningful() ) { return Promise.resolve(); }
				var fd = new FormData(), f = $( 'ajra-es-pdf' ).files[0], withFile = 'pdf' === mode && f && fileDirty && ! keepalive;
				fd.append( 'id', draftId ); fd.append( 'payload', JSON.stringify( draftPayload() ) );
				if ( withFile ) { fd.append( 'pdf', f ); }
				saving = true;
				return fetch( base + '/drafts/save', { method: 'POST', headers: { 'X-WP-Nonce': nonce }, body: fd, keepalive: !! keepalive } ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
					if ( d && d.id ) { draftId = d.id; if ( withFile ) { fileDirty = false; } $( 'ajra-es-saved' ).textContent = 'Saved ' + new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } ); }
				} ).catch( function () { /* the next change tries again */ } ).then( function () { saving = false; } );
			}
			function queueSave() { clearTimeout( saveTimer ); if ( 'new' === currentView || '' === currentView ) { saveTimer = setTimeout( function () { saveDraft(); }, 1200 ); } }
			[ 'ajra-es-expires', 'ajra-es-order', 'ajra-es-message', 'ajra-es-title', 'ajra-es-page' ].forEach( function ( id ) { var el = $( id ); if ( el ) { el.addEventListener( 'input', queueSave ); el.addEventListener( 'change', queueSave ); } } );
			window.ajraLeaveNew = function () { clearTimeout( saveTimer ); saveDraft( true ); };
			window.addEventListener( 'beforeunload', function () { clearTimeout( saveTimer ); if ( ! sentFlag ) { saveDraft( true ); } } );
			$( 'ajra-es-cancel' ).addEventListener( 'click', function () {
				clearTimeout( saveTimer );
				function leave() { try { window.sessionStorage.setItem( 'ajra_es_filter', 'draft' ); } catch ( e ) { /* convenience only */ } window.location.hash = '#documents'; window.location.reload(); }
				if ( meaningful() ) { saveDraft().then( leave ); } else { leave(); }
			} );
			// Resume a saved draft (the Continue button reloads the page with its id remembered).
			function loadDraft( id ) {
				api( '/drafts/' + id ).then( function ( d ) {
					draftId = d.id;
					setMode( 'pdf' === d.mode ? 'pdf' : 'template' );
					if ( d.customer_id ) {
						if ( ! Array.prototype.some.call( cust.options, function ( o ) { return o.value === d.customer_id; } ) ) { var c0 = customers.filter( function ( x ) { return x.id === d.customer_id; } )[0]; if ( c0 ) { var o0 = document.createElement( 'option' ); o0.value = c0.id; o0.textContent = c0.name + ' (' + c0.email + ')'; cust.appendChild( o0 ); } }
						cust.value = d.customer_id; custLabel();
					}
					varVals = d.vars || {};
					if ( 'pdf' === d.mode ) {
						$( 'ajra-es-title' ).value = d.title || ''; $( 'ajra-es-page' ).value = d.page || '';
						upLayout = d.layout || [];
						renderRecipients( d.recipients && d.recipients.length ? d.recipients : [ { party: 1, name: '', email: '' } ] );
						if ( d.has_pdf ) {
							fetch( base + '/drafts/' + id + '/pdf?_wpnonce=' + encodeURIComponent( nonce ), { credentials: 'same-origin' } ).then( function ( r ) { return r.blob(); } ).then( function ( blob ) {
								var file = new File( [ blob ], d.pdf_name || 'document.pdf', { type: 'application/pdf' } ), dt = new DataTransfer(); dt.items.add( file ); $( 'ajra-es-pdf' ).files = dt.files;
								fileDirty = false; refreshInline(); updateSend();
							} );
						}
					} else {
						tpl.value = d.template_id || '';
						renderRecipients( currentTemplateRecipients() ); syncTplFields();
						tplLayout = d.layout || [];
						renderRecipients( d.recipients && d.recipients.length ? d.recipients : currentTemplateRecipients() );
						renderVars(); refreshInline();
					}
					if ( $( 'ajra-es-expires' ) ) { $( 'ajra-es-expires' ).value = d.expires || 30; $( 'ajra-es-order' ).checked = !! d.ordered; }
					if ( d.message ) { $( 'ajra-es-message' ).value = d.message; $( 'ajra-es-message' ).style.display = 'block'; }
					updateSend();
					$( 'ajra-es-saved' ).textContent = 'Saved draft loaded';
				} ).catch( function ( e ) { window.alert( e.message ); } );
			}
			try { var resumeId = window.sessionStorage.getItem( 'ajra_es_resume' ); if ( resumeId ) { window.sessionStorage.removeItem( 'ajra_es_resume' ); loadDraft( resumeId ); } } catch ( e ) { /* convenience only */ }

			if ( $( 'ajra-es-preview' ) ) { $( 'ajra-es-preview' ).addEventListener( 'click', function () {
				var btn = this, rec = readRecipients(), headers = { 'X-WP-Nonce': nonce }, opt;
				err.style.display = 'none';
				if ( 'pdf' === mode ) {
					var f = $( 'ajra-es-pdf' ).files[0]; if ( ! f ) { return; }
					var fd = new FormData();
					fd.append( 'pdf', f ); fd.append( 'recipients', JSON.stringify( rec ) ); fd.append( 'stripe_customer_id', cust.value ); fd.append( 'title', $( 'ajra-es-title' ).value ); fd.append( 'page', $( 'ajra-es-page' ).value || 0 );
					if ( upLayout.length ) { fd.append( 'layout', JSON.stringify( upLayout ) ); }
					fd.append( 'vars', JSON.stringify( readVars() ) );
					opt = { method: 'POST', headers: headers, body: fd };
				} else {
					var body = { template_id: tpl.value, stripe_customer_id: cust.value, recipients: rec, vars: JSON.stringify( readVars() ) };
					if ( tplLayout.length ) { body.layout = JSON.stringify( tplLayout ); }
					headers[ 'Content-Type' ] = 'application/json';
					opt = { method: 'POST', headers: headers, body: JSON.stringify( body ) };
				}
				btn.disabled = true; btn.textContent = 'Building…';
				fetch( base + '/preview-filled', opt ).then( function ( r ) {
					if ( ( r.headers.get( 'Content-Type' ) || '' ).indexOf( 'application/pdf' ) < 0 ) { return r.json().then( function ( d ) { throw new Error( d.message || 'Preview failed' ); } ); }
					return r.blob();
				} ).then( function ( blob ) { showPreview( 'Preview — not sent', { mode: 'pdf', url: URL.createObjectURL( blob ) } ); } )
					.catch( function ( e ) { err.style.display = 'block'; err.textContent = e.message; } )
					.finally( function () { btn.textContent = 'Preview'; updateSend(); } );
			} ); }
			send.addEventListener( 'click', function () {
				err.style.display = 'none';
				function fail( m ) { err.style.display = 'block'; err.textContent = m; updateSend(); send.textContent = 'Send for signature'; }
				var rec = readRecipients(), exp = $( 'ajra-es-expires' ), msg = $( 'ajra-es-message' ), ord = $( 'ajra-es-order' ), req;
				send.disabled = true; send.textContent = 'Sending…'; sentFlag = true; clearTimeout( saveTimer );
				if ( 'pdf' === mode ) {
					var fd = new FormData();
					fd.append( 'pdf', $( 'ajra-es-pdf' ).files[0] ); fd.append( 'recipients', JSON.stringify( rec ) ); fd.append( 'stripe_customer_id', cust.value ); fd.append( 'draft_id', draftId );
					fd.append( 'title', $( 'ajra-es-title' ).value ); fd.append( 'page', $( 'ajra-es-page' ).value || 0 );
					if ( upLayout.length ) { fd.append( 'layout', JSON.stringify( upLayout ) ); }
					if ( exp ) { fd.append( 'expires_in_days', exp.value ); fd.append( 'signing_order', ord.checked ? 1 : 0 ); fd.append( 'message', msg.value ); }
					req = api( '/send-pdf', { method: 'POST', body: fd } );
				} else {
					var body = { template_id: tpl.value, stripe_customer_id: cust.value, recipients: rec, draft_id: draftId };
					if ( tplLayout.length ) { body.layout = JSON.stringify( tplLayout ); }
					body.vars = JSON.stringify( readVars() );
					if ( exp ) { body.expires_in_days = parseInt( exp.value, 10 ) || 30; body.signing_order = ord.checked; body.message = msg.value; }
					req = api( '/send', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } );
				}
				req.then( function () { window.location.reload(); } ).catch( function ( e ) { fail( e.message ); } );
			} );
			setMode( 'template' );
		} )();
		</script>
		<?php
	}
}
