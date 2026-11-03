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
		add_action( 'admin_post_ajcore_ra_save_esign', array( __CLASS__, 'handle_save' ) );
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

	private function setting( $key ) {
		$opt   = get_option( self::OPTION, array() );
		$value = is_array( $opt ) && isset( $opt[ $key ] ) ? (string) $opt[ $key ] : '';
		return trim( function_exists( 'ajcore_decrypt_setting_value' ) ? (string) ajcore_decrypt_setting_value( $value ) : $value );
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
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_save_esign' );
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
		$opt['migrated'] = 1;
		update_option( self::OPTION, $opt, false );
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
			return array( 'documents' => array(), 'stats' => array( 'total' => 0, 'sent' => 0, 'completed' => 0 ) );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_esign_documents_table();
		$rows  = $pdb->get_results( "SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT 500" );

		$documents = array();
		$stats     = array( 'total' => 0, 'sent' => 0, 'completed' => 0 );
		foreach ( $rows as $row ) {
			$documents[] = $this->format_esign_document_row( $row );
			$stats['total']++;
			'completed' === $row->status ? $stats['completed']++ : $stats['sent']++;
		}
		return array( 'documents' => $documents, 'stats' => $stats );
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

	private function breezedoc_map_template( $raw ) {
		return array(
			'id'         => isset( $raw['id'] ) ? (string) $raw['id'] : '',
			'title'      => isset( $raw['title'] ) ? (string) $raw['title'] : ( isset( $raw['name'] ) ? (string) $raw['name'] : '' ),
			'recipients' => $this->breezedoc_map_recipients( $raw['parties'] ?? $raw['recipients'] ?? array() ),
		);
	}

	public function breezedoc_list_templates() {
		if ( ! $this->breezedoc_is_v1() ) {
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

	public function breezedoc_get_template( $template_id ) {
		$template_id = sanitize_text_field( (string) $template_id );
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
		return $this->format_esign_document_row( $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $pdb->insert_id ) ) );
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

		// One signature + one date box per signer, stacked on the chosen page (page-fraction coords).
		$fields = array();
		foreach ( $recipients as $i => $r ) {
			$col = $i % 2;
			$row = intdiv( $i, 2 );
			$x   = 0.08 + 0.48 * $col;
			$y   = max( 0.05, 0.78 - 0.14 * $row );
			$fields[] = array( 'party' => $r['party'], 'page' => $page, 'type' => 'signature', 'properties' => array( 'x' => $x, 'y' => $y, 'w' => 0.36, 'h' => 0.06 ) );
			$fields[] = array( 'party' => $r['party'], 'page' => $page, 'type' => 'date', 'properties' => array( 'x' => $x, 'y' => $y + 0.07, 'w' => 0.2, 'h' => 0.03 ) );
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
		return $this->breezedoc_v1_send_and_log( $created, '', $stripe_customer_id, $options, $idem );
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
			'registered'  => ! empty( $state['id'] ),
			'url'         => (string) ( $state['url'] ?? '' ),
			'endpointUrl' => rest_url( AJCore_Extensions::rest_namespace() . '/esign/webhook' ),
			'lastEventAt' => (string) ( $state['last_event_at'] ?? '' ),
			'lastResult'  => (string) ( $state['last_result'] ?? '' ),
		);
	}

	public function breezedoc_webhook_register() {
		if ( ! $this->breezedoc_is_v1() ) {
			return new WP_Error( 'breezedoc_not_configured', __( 'Webhooks need a Team API key.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$url = rest_url( AJCore_Extensions::rest_namespace() . '/esign/webhook' );
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'https_required', __( 'BreezeDoc webhooks need a public HTTPS URL (port 443); this site is not on HTTPS.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$existing = $this->breezedoc_webhook_state();
		if ( ! empty( $existing['id'] ) ) {
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
		$state = $this->breezedoc_webhook_state();
		if ( ! empty( $state['id'] ) ) {
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

	// ── REST ────────────────────────────────────────────────────────────────────

	public static function catalog( $catalog ) {
		$e = static function ( $method, $path, $purpose ) {
			return array( 'surface' => 'OPS', 'method' => $method, 'path' => $path, 'auth' => 'Admin', 'purpose' => $purpose, 'app' => 'OPS e-signatures' );
		};
		$catalog[] = $e( 'GET', '/ops/esign/test', 'Tests the BreezeDoc Team API key (or legacy Personal token).' );
		$catalog[] = $e( 'GET', '/ops/esign/templates', 'Lists BreezeDoc templates (id, title, numbered signer parties).' );
		$catalog[] = $e( 'GET', '/ops/esign/templates/{id}', 'Fetches one BreezeDoc template with its signer parties.' );
		$catalog[] = $e( 'POST', '/ops/esign/send', 'Creates a document from a template (template_id, recipients[{party,name,email}], optional signing_order/message/expires_in_days/title), sends it, logs it.' );
		$catalog[] = $e( 'POST', '/ops/esign/send-pdf', 'Multipart: uploads a PDF (pdf, recipients JSON, stripe_customer_id, title, page, message, expires_in_days), places signature+date fields per signer, sends, logs it. Team API key only.' );
		$catalog[] = $e( 'GET', '/ops/esign/documents', 'Local log of sent signature requests with their last-known status.' );
		$catalog[] = $e( 'POST', '/ops/esign/documents/{id}/refresh', 'Re-reads one document\'s status from BreezeDoc.' );
		$catalog[] = $e( 'POST', '/ops/esign/documents/{id}/remind', 'Reminds unsigned recipients (BreezeDoc allows once per 24 hours). Team API key only.' );
		$catalog[] = $e( 'POST', '/ops/esign/documents/{id}/cancel', 'Cancels a sent document so it can no longer be signed. Team API key only.' );
		$catalog[] = $e( 'GET', '/ops/esign/documents/{id}/download', 'Short-lived (15 min) signed URL for the completed PDF and certificate. Team API key only.' );
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
		$act_ro = '(?P<action>download|events)';
		$act_rw = '(?P<action>refresh|remind|cancel)';
		$r      = WP_REST_Server::READABLE;

		// Same handlers under /ops/esign/* (AJOps, ops JWT auth) and /esign/* (WP-admin, cookie auth).
		foreach ( array( '/ops/esign' => $ops, '/esign' => $admin ) as $base => $permission ) {
			$routes = array(
				'/test'                      => array( $r, 'rest_test' ),
				'/templates'                 => array( $r, 'rest_templates' ),
				'/templates/' . $tpl_re      => array( $r, 'rest_template' ),
				'/send'                      => array( 'POST', 'rest_send' ),
				'/send-pdf'                  => array( 'POST', 'rest_send_pdf' ),
				'/documents'                 => array( $r, 'rest_documents' ),
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
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'templates' => $result ) );
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
		return $options;
	}

	public function rest_send( WP_REST_Request $request ) {
		$template_id = sanitize_text_field( (string) $request->get_param( 'template_id' ) );
		if ( '' === $template_id || '0' === $template_id ) {
			return new WP_Error( 'missing_template', __( 'template_id is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		return $this->rest_result( $this->send_esign_document_from_template( $template_id, sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) ), (array) $request->get_param( 'recipients' ), $this->send_options( $request ) ) );
	}

	public function rest_send_pdf( WP_REST_Request $request ) {
		$files      = $request->get_file_params();
		$recipients = $request->get_param( 'recipients' );
		if ( is_string( $recipients ) ) {
			$recipients = json_decode( $recipients, true );
		}
		return $this->rest_result(
			$this->send_esign_pdf(
				$files['pdf'] ?? null,
				(string) $request->get_param( 'title' ),
				sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) ),
				(array) $recipients,
				(int) $request->get_param( 'page' ),
				$this->send_options( $request )
			)
		);
	}

	public function rest_documents() {
		return rest_ensure_response( $this->get_esign_documents_list() );
	}

	public function rest_document_action( WP_REST_Request $request ) {
		$action = (string) $request['action'];
		if ( 'refresh' === $action ) {
			return $this->rest_result( $this->refresh_esign_document_status( (int) $request['id'] ) );
		}
		return $this->rest_result( $this->esign_document_action( (int) $request['id'], $action ) );
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
		$tabs[ self::TAB ] = array( 'label' => __( 'E-Signatures', 'ajcore-ra' ) );
		return $tabs;
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
		$log   = $this->get_esign_documents_list();
		$rest  = rest_url( AJCore_Extensions::rest_namespace() );
		$hook  = $this->breezedoc_webhook_status();
		$labels = array( 'draft' => __( 'Draft', 'ajcore-ra' ), 'sent' => __( 'Awaiting signature', 'ajcore-ra' ), 'completed' => __( 'Completed', 'ajcore-ra' ), 'cancelled' => __( 'Cancelled', 'ajcore-ra' ), 'expired' => __( 'Expired', 'ajcore-ra' ) );
		?>
		<style>
			.ajra-es{max-width:1000px}.ajra-es .card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:0 0 14px}
			.ajra-es h2{margin:0 0 8px;font-size:15px}.ajra-es .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px}
			.ajra-es label{display:block;font-weight:600;margin:0 0 3px}.ajra-es input[type=text],.ajra-es input[type=email],.ajra-es input[type=number],.ajra-es select,.ajra-es textarea{width:100%}
			.ajra-es .row{display:flex;gap:8px;align-items:center;margin-bottom:6px}.ajra-es .row input{flex:1;min-width:0}.ajra-es .muted{color:#646970;font-size:12px}
			.ajra-es .err{display:none;margin:0 0 10px;padding:8px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;color:#991b1b}
			.ajra-es .pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600;background:#fef3c7;color:#92400e}.ajra-es .pill.completed{background:#dcfce7;color:#166534}.ajra-es .pill.cancelled,.ajra-es .pill.expired{background:#f1f5f9;color:#475569}
			.ajra-es .tabs a{display:inline-block;padding:4px 12px;border:1px solid #dcdcde;border-radius:6px 6px 0 0;background:#f6f7f7;margin-right:4px;cursor:pointer;text-decoration:none}.ajra-es .tabs a.on{background:#fff;font-weight:700;border-bottom-color:#fff}
			.ajra-es td .button{margin:0 2px 2px 0}.ajra-es pre{margin:0;white-space:pre-wrap;font-size:12px}
		</style>
		<div class="ajra-es">
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Saved.', 'ajcore-ra' ); ?></p></div>
			<?php endif; ?>

			<details class="card" <?php echo $connected ? '' : 'open'; ?>>
				<summary style="cursor:pointer;font-weight:700"><?php esc_html_e( 'Connection', 'ajcore-ra' ); ?> — <?php echo esc_html( $is_v1 ? __( 'Team API key', 'ajcore-ra' ) : ( $connected ? __( 'Personal token (legacy)', 'ajcore-ra' ) : __( 'not connected', 'ajcore-ra' ) ) ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px">
					<input type="hidden" name="action" value="ajcore_ra_save_esign">
					<?php wp_nonce_field( 'ajcore_ra_save_esign' ); ?>
					<div class="grid">
						<div>
							<label for="breezedoc_team_api_key"><?php esc_html_e( 'Team API key', 'ajcore-ra' ); ?></label>
							<input type="text" name="breezedoc_team_api_key" id="breezedoc_team_api_key" autocomplete="off" placeholder="<?php echo $is_v1 ? esc_attr__( '•••• saved — blank keeps', 'ajcore-ra' ) : 'bd_live_…'; ?>">
							<span class="muted"><?php esc_html_e( 'BreezeDoc → API Settings → Manage team keys (read + write).', 'ajcore-ra' ); ?></span>
						</div>
						<div>
							<label for="breezedoc_api_token"><?php esc_html_e( 'Personal access token (legacy)', 'ajcore-ra' ); ?></label>
							<input type="text" name="breezedoc_api_token" id="breezedoc_api_token" autocomplete="off" placeholder="<?php echo $this->setting( 'api_token' ) ? esc_attr__( '•••• saved — blank keeps', 'ajcore-ra' ) : ''; ?>">
							<?php if ( $this->setting( 'api_token' ) ) : ?>
								<label style="font-weight:400;margin-top:4px"><input type="checkbox" name="clear_api_token" value="1"> <?php esc_html_e( 'Remove saved token', 'ajcore-ra' ); ?></label>
							<?php endif; ?>
						</div>
					</div>
					<p style="margin:10px 0 0">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ajcore-ra' ); ?></button>
						<button type="button" class="button" id="ajra-es-test"><?php esc_html_e( 'Test connection', 'ajcore-ra' ); ?></button>
						<span id="ajra-es-test-out" class="muted"></span>
					</p>
				</form>
				<?php if ( $is_v1 ) : ?>
					<hr>
					<p style="margin:0 0 6px"><strong><?php esc_html_e( 'Webhook', 'ajcore-ra' ); ?></strong>
						<span class="muted" id="ajra-es-hook-state"><?php echo esc_html( $hook['registered'] ? __( 'registered', 'ajcore-ra' ) . ( $hook['lastEventAt'] ? ' · ' . __( 'last event', 'ajcore-ra' ) . ' ' . $hook['lastEventAt'] . ' (' . $hook['lastResult'] . ')' : ' · ' . __( 'no events yet', 'ajcore-ra' ) ) : __( 'not registered — status updates only on manual refresh', 'ajcore-ra' ) ); ?></span></p>
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
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'BreezeDoc is not connected. Add a Team API key above.', 'ajcore-ra' ); ?></p></div>
			<?php elseif ( $error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php else : ?>
				<div class="card">
					<div class="tabs" style="margin-bottom:-1px">
						<a class="on" data-mode="template"><?php esc_html_e( 'From template', 'ajcore-ra' ); ?></a>
						<?php if ( $is_v1 ) : ?><a data-mode="pdf"><?php esc_html_e( 'Upload PDF', 'ajcore-ra' ); ?></a><?php endif; ?>
					</div>
					<div style="border-top:1px solid #dcdcde;padding-top:12px">
						<div id="ajra-es-error" class="err"></div>
						<div class="grid">
							<div>
								<label><?php esc_html_e( 'Customer', 'ajcore-ra' ); ?></label>
								<select id="ajra-es-customer"><option value=""><?php esc_html_e( '— pick a customer —', 'ajcore-ra' ); ?></option></select>
							</div>
							<div id="ajra-es-tpl-wrap">
								<label><?php esc_html_e( 'Template', 'ajcore-ra' ); ?></label>
								<select id="ajra-es-template"><option value=""><?php esc_html_e( '— pick a template —', 'ajcore-ra' ); ?></option></select>
							</div>
							<div id="ajra-es-pdf-wrap" style="display:none">
								<label><?php esc_html_e( 'PDF (max 25 MB, 100 pages)', 'ajcore-ra' ); ?></label>
								<input type="file" id="ajra-es-pdf" accept="application/pdf,.pdf">
							</div>
						</div>
						<div id="ajra-es-pdf-opts" class="grid" style="display:none;margin-top:10px">
							<div><label><?php esc_html_e( 'Title', 'ajcore-ra' ); ?></label><input type="text" id="ajra-es-title" maxlength="191" placeholder="<?php esc_attr_e( 'Defaults to the file name', 'ajcore-ra' ); ?>"></div>
							<div><label><?php esc_html_e( 'Signature page', 'ajcore-ra' ); ?></label><input type="number" id="ajra-es-page" min="1" placeholder="<?php esc_attr_e( 'blank = last page', 'ajcore-ra' ); ?>">
								<span class="muted"><?php esc_html_e( 'Signature + date boxes are placed per signer near the bottom of this page.', 'ajcore-ra' ); ?></span></div>
						</div>
						<div id="ajra-es-recipients" style="margin-top:12px"></div>
						<p id="ajra-es-add-wrap" style="display:none;margin:0 0 8px"><button type="button" class="button button-small" id="ajra-es-add"><?php esc_html_e( '+ Add signer', 'ajcore-ra' ); ?></button></p>
						<?php if ( $is_v1 ) : ?>
							<details style="margin:8px 0">
								<summary style="cursor:pointer"><?php esc_html_e( 'Options', 'ajcore-ra' ); ?></summary>
								<div class="grid" style="margin-top:8px">
									<div><label><?php esc_html_e( 'Expires in (days)', 'ajcore-ra' ); ?></label><input type="number" id="ajra-es-expires" min="1" max="365" value="30"></div>
									<div><label><input type="checkbox" id="ajra-es-order" checked> <?php esc_html_e( 'Signers sign in order', 'ajcore-ra' ); ?></label></div>
									<div style="grid-column:1/-1"><label><?php esc_html_e( 'Message to signers (needs BreezeDoc custom-email plan)', 'ajcore-ra' ); ?></label><textarea id="ajra-es-message" rows="2"></textarea></div>
								</div>
							</details>
						<?php endif; ?>
						<button type="button" class="button button-primary" id="ajra-es-send" disabled><?php esc_html_e( 'Send for signature', 'ajcore-ra' ); ?></button>
					</div>
				</div>
			<?php endif; ?>

			<div class="card">
				<h2><?php esc_html_e( 'Sent signature requests', 'ajcore-ra' ); ?> <span class="muted"><?php echo esc_html( sprintf( '%d total · %d completed', $log['stats']['total'], $log['stats']['completed'] ) ); ?></span></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Customer', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Document', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Status', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Sent', 'ajcore-ra' ); ?></th><th></th></tr></thead>
					<tbody id="ajra-es-log">
						<?php if ( empty( $log['documents'] ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'None yet.', 'ajcore-ra' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $log['documents'] as $doc ) :
							$is_new = 0 === strpos( $doc['breezedocDocumentId'], 'doc_' );
							?>
							<tr data-row="<?php echo esc_attr( $doc['id'] ); ?>">
								<td><?php echo esc_html( $doc['customerName'] ?: '—' ); ?></td>
								<td><?php echo esc_html( $doc['templateTitle'] ?: '—' ); ?></td>
								<td><span class="pill <?php echo esc_attr( $doc['status'] ); ?>" data-status><?php echo esc_html( $labels[ $doc['status'] ] ?? $doc['status'] ); ?></span></td>
								<td><?php echo esc_html( $doc['sentAt'] ? mysql2date( get_option( 'date_format' ), $doc['sentAt'] ) : '—' ); ?></td>
								<td>
									<button type="button" class="button button-small" data-act="refresh" data-id="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'Refresh', 'ajcore-ra' ); ?></button>
									<?php if ( $is_new ) : ?>
										<?php if ( 'sent' === $doc['status'] ) : ?>
											<button type="button" class="button button-small" data-act="remind" data-id="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'Remind', 'ajcore-ra' ); ?></button>
											<button type="button" class="button button-small" data-act="cancel" data-id="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'Cancel', 'ajcore-ra' ); ?></button>
										<?php endif; ?>
										<?php if ( 'completed' === $doc['status'] ) : ?>
											<button type="button" class="button button-small" data-act="download" data-id="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'Signed PDF', 'ajcore-ra' ); ?></button>
										<?php endif; ?>
										<button type="button" class="button button-small" data-act="events" data-id="<?php echo esc_attr( $doc['id'] ); ?>"><?php esc_html_e( 'History', 'ajcore-ra' ); ?></button>
									<?php endif; ?>
									<?php if ( $doc['documentUrl'] ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $doc['documentUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open', 'ajcore-ra' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<script>
		( function () {
			var base = <?php echo wp_json_encode( untrailingslashit( $rest ) . '/esign' ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var customers = <?php echo wp_json_encode( $this->customers() ); ?>, templates = <?php echo wp_json_encode( $templates ); ?>;
			var labels = <?php echo wp_json_encode( $labels ); ?>;
			var $ = function ( id ) { return document.getElementById( id ); };
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
			document.querySelectorAll( '[data-hook]' ).forEach( function ( b ) { b.addEventListener( 'click', function () {
				var act = b.getAttribute( 'data-hook' ), out = $( 'ajra-es-hook-out' ); out.textContent = '…';
				api( '/webhook' + ( 'deliveries' === act ? '/deliveries' : '/' + act ), { method: 'deliveries' === act ? 'GET' : 'POST' } ).then( function ( d ) {
					if ( 'register' === act || 'remove' === act ) { window.location.reload(); return; }
					out.textContent = JSON.stringify( d, null, 1 ).slice( 0, 4000 );
				} ).catch( function ( e ) { out.textContent = e.message; } );
			} ); } );

			// New request form.
			var mode = 'template', cust = $( 'ajra-es-customer' ), tpl = $( 'ajra-es-template' ), box = $( 'ajra-es-recipients' ), send = $( 'ajra-es-send' ), err = $( 'ajra-es-error' );
			if ( ! cust ) { bindLog(); return; }
			customers.forEach( function ( c ) { var o = document.createElement( 'option' ); o.value = c.id; o.textContent = c.name + ' (' + c.email + ')'; cust.appendChild( o ); } );
			templates.forEach( function ( t ) { var o = document.createElement( 'option' ); o.value = t.id; o.textContent = t.title || t.id; tpl.appendChild( o ); } );
			function row( party, name, email, first ) {
				return '<div class="row ajra-es-r" data-party="' + party + '"><span class="muted" style="width:56px">Party ' + party + '</span>' +
					'<input type="text" class="n" placeholder="Name" value="' + esc( name ) + '"><input type="email" class="e" placeholder="Email" value="' + esc( email ) + '">' +
					( first ? '<button type="button" class="button button-small ajra-es-use">Use customer</button>' : '' ) +
					( 'pdf' === mode && ! first ? '<button type="button" class="button button-small ajra-es-del">×</button>' : '' ) + '</div>';
			}
			function renderRecipients( list ) {
				box.innerHTML = '<label>Signers (in signing order)</label>' + list.map( function ( r, i ) { return row( r.party || i + 1, r.name, r.email, 0 === i ); } ).join( '' );
				send.disabled = 0 === list.length;
				$( 'ajra-es-add-wrap' ).style.display = 'pdf' === mode ? 'block' : 'none';
			}
			function readRecipients() {
				return Array.prototype.map.call( box.querySelectorAll( '.ajra-es-r' ), function ( r, i ) { return { party: parseInt( r.getAttribute( 'data-party' ), 10 ) || i + 1, name: r.querySelector( '.n' ).value.trim(), email: r.querySelector( '.e' ).value.trim() }; } );
			}
			function setMode( m ) {
				mode = m;
				document.querySelectorAll( '.ajra-es .tabs a' ).forEach( function ( a ) { a.classList.toggle( 'on', a.getAttribute( 'data-mode' ) === m ); } );
				$( 'ajra-es-tpl-wrap' ).style.display = 'template' === m ? '' : 'none';
				$( 'ajra-es-pdf-wrap' ).style.display = 'pdf' === m ? '' : 'none';
				$( 'ajra-es-pdf-opts' ).style.display = 'pdf' === m ? '' : 'none';
				renderRecipients( 'pdf' === m ? [ { party: 1, name: '', email: '' } ] : currentTemplateRecipients() );
			}
			function currentTemplateRecipients() {
				var t = templates.filter( function ( x ) { return String( x.id ) === tpl.value; } )[0];
				return t ? t.recipients : [];
			}
			document.querySelectorAll( '.ajra-es .tabs a' ).forEach( function ( a ) { a.addEventListener( 'click', function () { setMode( a.getAttribute( 'data-mode' ) ); } ); } );
			tpl.addEventListener( 'change', function () { renderRecipients( currentTemplateRecipients() ); } );
			$( 'ajra-es-add' ).addEventListener( 'click', function () {
				var cur = readRecipients(); cur.push( { party: cur.length + 1, name: '', email: '' } ); renderRecipients( cur );
			} );
			box.addEventListener( 'click', function ( ev ) {
				var t = ev.target;
				if ( t.classList.contains( 'ajra-es-use' ) ) {
					var c = customers.filter( function ( x ) { return x.id === cust.value; } )[0];
					if ( c ) { var r = t.closest( '.ajra-es-r' ); r.querySelector( '.n' ).value = c.name; r.querySelector( '.e' ).value = c.email; }
				} else if ( t.classList.contains( 'ajra-es-del' ) ) {
					var cur = readRecipients(); cur.splice( Array.prototype.indexOf.call( box.querySelectorAll( '.ajra-es-r' ), t.closest( '.ajra-es-r' ) ), 1 );
					cur.forEach( function ( r, i ) { r.party = i + 1; } ); renderRecipients( cur );
				}
			} );
			send.addEventListener( 'click', function () {
				err.style.display = 'none';
				function fail( m ) { err.style.display = 'block'; err.textContent = m; send.disabled = false; send.textContent = 'Send for signature'; }
				if ( ! cust.value ) { return fail( 'Pick a customer first.' ); }
				var rec = readRecipients(), exp = $( 'ajra-es-expires' ), msg = $( 'ajra-es-message' ), ord = $( 'ajra-es-order' ), req;
				send.disabled = true; send.textContent = 'Sending…';
				if ( 'pdf' === mode ) {
					var f = $( 'ajra-es-pdf' ).files[0];
					if ( ! f ) { return fail( 'Choose a PDF.' ); }
					var fd = new FormData();
					fd.append( 'pdf', f ); fd.append( 'recipients', JSON.stringify( rec ) ); fd.append( 'stripe_customer_id', cust.value );
					fd.append( 'title', $( 'ajra-es-title' ).value ); fd.append( 'page', $( 'ajra-es-page' ).value || 0 );
					if ( exp ) { fd.append( 'expires_in_days', exp.value ); fd.append( 'signing_order', ord.checked ? 1 : 0 ); fd.append( 'message', msg.value ); }
					req = api( '/send-pdf', { method: 'POST', body: fd } );
				} else {
					if ( ! tpl.value ) { return fail( 'Pick a template first.' ); }
					var body = { template_id: tpl.value, stripe_customer_id: cust.value, recipients: rec };
					if ( exp ) { body.expires_in_days = parseInt( exp.value, 10 ) || 30; body.signing_order = ord.checked; body.message = msg.value; }
					req = api( '/send', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } );
				}
				req.then( function () { window.location.reload(); } ).catch( function ( e ) { fail( e.message ); } );
			} );
			setMode( 'template' );
			bindLog();

			// Log row actions.
			function bindLog() {
				document.querySelectorAll( '#ajra-es-log [data-act]' ).forEach( function ( b ) { b.addEventListener( 'click', function () {
					var act = b.getAttribute( 'data-act' ), id = b.getAttribute( 'data-id' ), label = b.textContent;
					if ( 'cancel' === act && ! window.confirm( 'Cancel this document? Signers will no longer be able to sign it.' ) ) { return; }
					b.disabled = true; b.textContent = '…';
					api( '/documents/' + id + '/' + act, { method: ( 'download' === act || 'events' === act ) ? 'GET' : 'POST' } ).then( function ( d ) {
						if ( 'download' === act ) {
							if ( d.url ) { window.open( d.url, '_blank', 'noopener' ); } else { window.alert( 'No download URL returned.' ); }
							if ( d.certificateUrl ) { window.open( d.certificateUrl, '_blank', 'noopener' ); }
						} else if ( 'events' === act ) {
							var tr = b.closest( 'tr' ), nx = tr.nextElementSibling;
							if ( nx && nx.classList.contains( 'ajra-es-ev' ) ) { nx.remove(); } else {
								var lines = ( d.events || [] ).map( function ( e ) { return ( e.created_at || e.at || e.timestamp || '' ) + ' — ' + ( e.type || e.event || e.action || JSON.stringify( e ) ); } );
								var ev = document.createElement( 'tr' ); ev.className = 'ajra-es-ev'; ev.innerHTML = '<td colspan="5"><pre>' + esc( lines.join( '\n' ) || 'No events.' ) + '</pre></td>'; tr.parentNode.insertBefore( ev, nx );
							}
						} else if ( 'remind' === act ) {
							window.alert( 'Reminder sent.' );
						} else if ( d.status ) {
							var pill = b.closest( 'tr' ).querySelector( '[data-status]' ); pill.className = 'pill ' + d.status; pill.textContent = labels[ d.status ] || d.status;
							if ( 'cancel' === act ) { window.location.reload(); }
						}
					} ).catch( function ( e ) { window.alert( e.message ); } ).finally( function () { b.disabled = false; b.textContent = label; } );
				} ); } );
			}
		} )();
		</script>
		<?php
	}
}
