<?php
/**
 * Physical mail / service-of-process log ("Mail"), moved out of AJCore.
 *
 * Same /ajcore/v1 routes (/ops/mail*, /portal/mail), responses and log table
 * (aj_portal_mail_items, defined in AJCore's activator and created on demand). The handler code below
 * is unchanged apart from where AJCore's own helpers are reached. Also provides the Client Portal admin
 * tab "Mail" and the customer notification email, including its two email templates
 * (mail_item, mail_item_sop) whose saved overrides (wp_mail_item_*) stay in AJCore's settings.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Mail {

	const TAB = 'mail';

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
		add_action( 'rest_api_init', array( __CLASS__, 'boot_routes' ) );
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog' ) );
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ), 9 );
		add_action( 'ajcore_admin_portal_tab_render', array( __CLASS__, 'render_admin_tab' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_actions' ) );
		add_filter( 'ajcore_additional_branded_email_templates', array( __CLASS__, 'add_templates' ) );
		// Saved overrides (wp_mail_item_*) must survive AJCore's settings save on requests where the templates aren't listed.
		add_filter( 'ajcore_preserved_setting_prefixes', array( __CLASS__, 'preserved_prefixes' ) );
	}

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public static function preserved_prefixes( $prefixes ) {
		$prefixes[] = 'wp_mail_item_';
		return $prefixes;
	}

	public function __construct() {
		$this->kit = class_exists( 'AJCore_Extensions' ) ? AJCore_Extensions::rest_toolkit() : array();
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

	private function get_current_user_stripe_customer_id() {
		return $this->kit['current_customer_id']();
	}

	private function get_portal_stripe_customers_table() {
		return $this->portal_table( 'aj_portal_stripe_customers' );
	}

	/** AJCore's admin object, for its email toolkit only. */
	private static function admin() {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return null;
		}
		return AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
	}

	// ── Client Portal admin tab ─────────────────────────────────────────────────

	public static function admin_tab( $tabs ) {
		$tabs[ self::TAB ] = array( 'label' => __( 'Physical Mail', 'ajcore-ra' ) );
		return $tabs;
	}

	public static function render_admin_tab( $tab ) {
		if ( self::TAB === $tab ) {
			self::instance()->render_tab();
		}
	}

	/** Form posts from the Mail tab (create / update / delete / notify / publish). */
	public static function maybe_handle_actions() {
		if ( ! is_admin() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_GET['page'], $_GET['tab'] ) ) {
			return;
		}
		if ( 'ajforms-client-portal' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || self::TAB !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}
		self::instance()->handle_portal_mail_actions();
	}

	// ── REST routes (same URLs as before) ───────────────────────────────────────

	public function register_routes() {
		$ns     = AJCore_Extensions::rest_namespace();
		$ops    = array( 'AJCore_Extensions', 'can_manage_ops' );
		$portal = array( 'AJCore_Extensions', 'can_use_portal' );

		register_rest_route( $ns, '/ops/mail', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_mail_items' ),
				'permission_callback' => $ops,
				'args'                => array(
					'per_page' => array( 'default' => 25, 'sanitize_callback' => 'absint' ),
					'search'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_ops_mail_item' ),
				'permission_callback' => $ops,
			),
		) );
		register_rest_route( $ns, '/ops/mail/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_mail_item' ),
				'permission_callback' => $ops,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_ops_mail_item' ),
				'permission_callback' => $ops,
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_ops_mail_item' ),
				'permission_callback' => $ops,
			),
		) );
		register_rest_route( $ns, '/ops/mail/(?P<id>\d+)/notify', array( 'methods' => 'POST', 'callback' => array( $this, 'notify_ops_mail_item' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/mail/(?P<id>\d+)/publish', array( 'methods' => 'POST', 'callback' => array( $this, 'publish_ops_mail_item_to_files' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/portal/mail', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_portal_mail' ), 'permission_callback' => $portal ) );
	}

	public static function catalog( $catalog ) {
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/mail', 'auth' => 'Admin', 'purpose' => 'Mail/service-of-process intake queue with stats. Filters: search, status (received|scanned|notified|closed|open), mail_type, sop, stripe_customer_id.', 'app' => 'OPS mail' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/mail', 'auth' => 'Admin', 'purpose' => 'Log a received mail item (multipart; optional scan upload + notify flag for log-and-notify in one step).', 'app' => 'OPS mail' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/mail/{id}', 'auth' => 'Admin', 'purpose' => 'Update a mail item: fields, scan upload, disposition (forwarded|picked_up|shredded|returned|held).', 'app' => 'OPS mail' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/mail/{id}/notify', 'auth' => 'Admin', 'purpose' => 'Send (or resend) the client notification email for a mail item; stamps notified_at.', 'app' => 'OPS mail' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/mail/{id}/publish', 'auth' => 'Admin', 'purpose' => 'Publish the item scan into the client Files library and link it via file_id.', 'app' => 'OPS mail' );
		$catalog[] = array( 'surface' => 'Portal', 'method' => 'GET', 'path' => '/portal/mail', 'auth' => 'Portal user', 'purpose' => 'Current user mailbox: received mail items with scan links and dispositions (no staff fields).', 'app' => 'iOS app / portal' );
		return $catalog;
	}

	// ── Email templates (moved from AJCore's catalog) ───────────────────────────

	public static function add_templates( $templates ) {
		return array_merge( $templates, self::templates() );
	}

	private static function templates() {
		return array(
			'mail_item_sop' => array(
				'label' => __( 'Service of Process / Urgent Mail', 'ajcore-ra' ),
				'key' => 'wp_mail_item_sop',
				'sender_key' => 'wp_mail_item',
				'brand_variants' => false,
				'to' => __( 'Customer email supplied by the mail-item notification action.', 'ajcore-ra' ),
				'fixed' => __( 'Urgent copy when the mail item is marked as service of process. Shows mail type, sender and received date; View Scanned Document appears only when a scan URL exists.', 'ajcore-ra' ),
				'default_subject' => __( 'URGENT: Legal document received for you — {mail_type}', 'ajcore-ra' ),
				'default_heading' => __( 'A legal document was delivered to your registered agent', 'ajcore-ra' ),
				'default_body' => array(
					sprintf( __( 'Hi %s,', 'ajcore-ra' ), '{name}' ),
					__( 'We received service of process or another time-sensitive legal document addressed to you: {mail_type} from {sender_name}.', 'ajcore-ra' ),
					__( 'Legal documents often carry response deadlines. Please review it as soon as possible.', 'ajcore-ra' ),
				),
			),
			'mail_item' => array(
				'label' => __( 'Physical Mail Received', 'ajcore-ra' ),
				'key' => 'wp_mail_item',
				'sender_key' => 'wp_mail_item',
				'brand_variants' => false,
				'to' => __( 'Customer email supplied by the mail-item notification action.', 'ajcore-ra' ),
				'fixed' => __( 'Shows mail type, sender and received date; View Scanned Document appears only when a scan URL exists.', 'ajcore-ra' ),
				'default_subject' => __( 'New mail received for you: {mail_type}', 'ajcore-ra' ),
				'default_heading' => __( 'You have new mail at our office', 'ajcore-ra' ),
				'default_body' => array(
					sprintf( __( 'Hi %s,', 'ajcore-ra' ), '{name}' ),
					__( 'We received a {mail_type} addressed to you from {sender_name}.', 'ajcore-ra' ),
				),
			),
		);
	}

	/**
	 * Notifies a client that a piece of physical mail was received for them at the office.
	 * Service-of-process items get an urgent subject and copy (timely delivery is the registered
	 * agent's legal obligation). Built on AJCore's email toolkit.
	 *
	 * @return bool Whether wp_mail() accepted the message.
	 */
	public function send_mail_item_notification_for_ops( $item, $customer_name, $customer_email ) {
		$customer_email = sanitize_email( (string) $customer_email );
		$admin          = self::admin();
		if ( ! is_email( $customer_email ) || ! $admin || ! method_exists( $admin, 'get_email_toolkit' ) ) {
			return false;
		}
		$kit       = $admin->get_email_toolkit();
		$settings  = $kit['settings']();
		$brand     = $kit['brand']( ! empty( $item['stripe_customer_id'] ) ? $item['stripe_customer_id'] : '', $customer_email );
		$sender    = $kit['sender']( $settings, 'wp_mail_item_from_email', 'wp_mail_item_from_name' );
		$site_name = $brand['site_name'];
		if ( $kit['is_brand_variant']( $brand ) ) {
			$sender['from_name'] = $brand['site_name'];
		}

		$is_sop      = ! empty( $item['is_sop'] );
		$type_labels = array(
			'letter'     => __( 'Letter', 'ajcore-ra' ),
			'legal'      => __( 'Legal document', 'ajcore-ra' ),
			'government' => __( 'Government notice', 'ajcore-ra' ),
			'package'    => __( 'Package', 'ajcore-ra' ),
			'check'      => __( 'Check', 'ajcore-ra' ),
			'other'      => __( 'Mail item', 'ajcore-ra' ),
		);
		$mail_type  = isset( $item['mail_type'] ) ? sanitize_key( (string) $item['mail_type'] ) : 'letter';
		$type_label = isset( $type_labels[ $mail_type ] ) ? $type_labels[ $mail_type ] : $type_labels['other'];

		$received_at = ! empty( $item['received_at'] ) ? mysql2date( get_option( 'date_format' ), (string) $item['received_at'] ) : '';
		$sender_name = ! empty( $item['sender_name'] ) ? sanitize_text_field( (string) $item['sender_name'] ) : '';

		$email_tokens = array(
			'{name}'        => '' !== trim( (string) $customer_name ) ? $customer_name : $customer_email,
			'{mail_type}'   => $type_label,
			'{sender_name}' => '' !== $sender_name ? $sender_name : __( 'an unlisted sender', 'ajcore-ra' ),
			'{received_at}' => $received_at,
		);

		$all      = self::templates();
		$template = $is_sop ? $all['mail_item_sop'] : $all['mail_item'];
		$prefix   = $is_sop ? 'wp_mail_item_sop' : 'wp_mail_item';

		$subject_template = ! empty( $settings[ $prefix . '_subject' ] ) ? sanitize_text_field( (string) $settings[ $prefix . '_subject' ] ) : $template['default_subject'];
		$subject          = $kit['brand_subject']( strtr( $subject_template, $email_tokens ), $brand );
		$copy             = $kit['copy_raw']( $settings, $prefix . '_heading', $prefix . '_body', $template['default_heading'], $template['default_body'], $email_tokens );

		$info_value = $type_label
			. ( '' !== $sender_name ? ' — ' . sprintf( __( 'from %s', 'ajcore-ra' ), $sender_name ) : '' )
			. ( '' !== $received_at ? ' — ' . sprintf( __( 'received %s', 'ajcore-ra' ), $received_at ) : '' );

		$scan_url = ! empty( $item['scan_url'] ) ? esc_url_raw( (string) $item['scan_url'] ) : '';

		$message = $kit['render'](
			array(
				'kicker'         => $site_name,
				'heading'        => $copy['heading'],
				'paragraphs'     => $copy['paragraphs'],
				'info_box_label' => __( 'Mail item', 'ajcore-ra' ),
				'info_box_value' => $info_value,
				'cta_text'       => '' !== $scan_url ? __( 'View Scanned Document', 'ajcore-ra' ) : '',
				'cta_url'        => $scan_url,
				'footer_note'    => __( 'Log in to your client portal to view your mailbox and manage this item.', 'ajcore-ra' ),
			)
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $sender['from_email'] ) ) {
			$headers[] = 'From: ' . $sender['from_name'] . ' <' . $sender['from_email'] . '>';
			$headers[] = 'Reply-To: ' . $sender['from_email'];
		}

		return (bool) $kit['send']( $customer_email, $subject, $message, $headers, $sender['from_email'], $sender['from_name'], isset( $sender['profile'] ) ? $sender['profile'] : '' );
	}

	// ── Mail API handlers (moved unchanged from AJCore) ─────────────────────────

	private function get_mail_items_table() {
		return $this->portal_table( 'aj_portal_mail_items' );
	}

	private function ensure_mail_items_table() {
		$pdb   = $this->get_portal_db();
		$table = $this->get_mail_items_table();
		if ( $this->table_exists( $pdb, $table ) ) {
			return true;
		}
		if ( ! class_exists( 'AJForms_Activator' ) && defined( 'AJFORMS_PLUGIN_DIR' ) ) {
			require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
		}
		if ( class_exists( 'AJForms_Activator' ) && method_exists( 'AJForms_Activator', 'create_mail_tables_in_portal_db' ) ) {
			AJForms_Activator::create_mail_tables_in_portal_db( $pdb->prefix, $pdb->get_charset_collate(), $pdb );
		}
		return $this->table_exists( $pdb, $table );
	}

	private function mail_item_types() {
		return array( 'letter', 'legal', 'government', 'package', 'check', 'other' );
	}

	private function mail_item_dispositions() {
		return array( 'forwarded', 'picked_up', 'shredded', 'returned', 'held' );
	}

	private function format_mail_item_row( $row, $staff_view = true ) {
		$row = (array) $row;

		$item = array(
			'id'                 => (int) $row['id'],
			'mail_uuid'          => (string) $row['mail_uuid'],
			'stripe_customer_id' => (string) $row['stripe_customer_id'],
			'customer_email'     => (string) $row['customer_email'],
			'customer_name'      => isset( $row['customer_name'] ) ? (string) $row['customer_name'] : '',
			'recipient_name'     => (string) $row['recipient_name'],
			'mail_type'          => (string) $row['mail_type'],
			'is_sop'             => ! empty( $row['is_sop'] ),
			'sender_name'        => (string) $row['sender_name'],
			'carrier'            => (string) $row['carrier'],
			'tracking_number'    => (string) $row['tracking_number'],
			'description'        => (string) $row['description'],
			'status'             => (string) $row['status'],
			'disposition'        => (string) $row['disposition'],
			'scan_url'           => (string) $row['scan_url'],
			'file_id'            => (int) $row['file_id'],
			'received_at'        => (string) $row['received_at'],
			'notified_at'        => (string) $row['notified_at'],
			'disposed_at'        => (string) $row['disposed_at'],
			'created_at'         => (string) $row['created_at'],
			'updated_at'         => (string) $row['updated_at'],
		);

		if ( $staff_view ) {
			$item['scan_attachment_id'] = (int) $row['scan_attachment_id'];
			$item['admin_notes']        = (string) $row['admin_notes'];
			$item['created_by']         = (int) $row['created_by'];
		}

		return $item;
	}

	private function fetch_mail_item_row( $id ) {
		$pdb             = $this->get_portal_db();
		$table           = $this->get_mail_items_table();
		$customers_table = $this->portal_table( 'aj_portal_stripe_customers' );
		$join            = $this->table_exists( $pdb, $customers_table )
			? "LEFT JOIN `{$customers_table}` c ON c.stripe_customer_id = m.stripe_customer_id"
			: '';
		$name_select     = '' !== $join ? 'c.name AS customer_name, COALESCE(NULLIF(m.customer_email, \'\'), c.email) AS customer_email' : 'm.customer_email';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $pdb->get_row( $pdb->prepare( "SELECT m.*, {$name_select} FROM `{$table}` m {$join} WHERE m.id = %d", absint( $id ) ), ARRAY_A );
	}

	private function log_mail_event( $event_type, $item, $details = array() ) {
		$pdb   = $this->get_portal_db();
		$table = $this->portal_table( 'aj_portal_event_log' );
		if ( ! $this->table_exists( $pdb, $table ) ) {
			return;
		}
		$user = wp_get_current_user();
		$pdb->insert(
			$table,
			array(
				'event_type'         => sanitize_key( $event_type ),
				'severity'           => ! empty( $item['is_sop'] ) ? 'warning' : 'info',
				'source'             => 'ops_mail',
				'stripe_customer_id' => (string) $item['stripe_customer_id'],
				'actor_user_id'      => $user ? (int) $user->ID : 0,
				'actor_email'        => $user ? (string) $user->user_email : '',
				'details'            => wp_json_encode( array_merge( array( 'mail_item_id' => (int) $item['id'], 'mail_uuid' => (string) $item['mail_uuid'] ), $details ) ),
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/** Handles an optional multipart scan upload; returns array( attachment_id, url ) or WP_Error. */
	private function handle_mail_scan_upload( WP_REST_Request $request ) {
		$file_params = $request->get_file_params();
		if ( empty( $file_params['scan'] ) || UPLOAD_ERR_OK !== (int) $file_params['scan']['error'] ) {
			return array( 0, '' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$uploaded = wp_handle_upload( $file_params['scan'], array( 'test_form' => false ) );
		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error( 'upload_error', $uploaded['error'], array( 'status' => 400 ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => sanitize_text_field( basename( $file_params['scan']['name'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_mime_type' => $uploaded['type'],
			),
			$uploaded['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );

		return array( (int) $attachment_id, (string) wp_get_attachment_url( $attachment_id ) );
	}

	public function get_ops_mail_items( WP_REST_Request $request ) {
		$pdb   = $this->get_portal_db();
		$table = $this->get_mail_items_table();
		if ( ! $this->table_exists( $pdb, $table ) ) {
			return rest_ensure_response( array( 'mail_items' => array(), 'stats' => array( 'total' => 0, 'received' => 0, 'awaiting_notify' => 0, 'open' => 0, 'sop_open' => 0 ) ) );
		}

		$customers_table = $this->portal_table( 'aj_portal_stripe_customers' );
		$has_customers   = $this->table_exists( $pdb, $customers_table );
		$join            = $has_customers ? "LEFT JOIN `{$customers_table}` c ON c.stripe_customer_id = m.stripe_customer_id" : '';
		$name_select     = $has_customers ? 'c.name AS customer_name, COALESCE(NULLIF(m.customer_email, \'\'), c.email) AS customer_email' : 'm.customer_email';

		$where  = array( '1=1' );
		$params = array();

		$search = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?: '' ) );
		if ( '' !== $search ) {
			$like    = '%' . $pdb->esc_like( $search ) . '%';
			$clause  = '( m.recipient_name LIKE %s OR m.sender_name LIKE %s OR m.tracking_number LIKE %s OR m.description LIKE %s OR m.customer_email LIKE %s OR m.stripe_customer_id LIKE %s';
			$params  = array_merge( $params, array( $like, $like, $like, $like, $like, $like ) );
			if ( $has_customers ) {
				$clause  .= ' OR c.name LIKE %s OR c.email LIKE %s';
				$params[] = $like;
				$params[] = $like;
			}
			$where[] = $clause . ' )';
		}

		$status = sanitize_key( (string) ( $request->get_param( 'status' ) ?: '' ) );
		if ( in_array( $status, array( 'received', 'scanned', 'notified', 'closed' ), true ) ) {
			$where[]  = 'm.status = %s';
			$params[] = $status;
		} elseif ( 'open' === $status ) {
			$where[] = "m.status <> 'closed'";
		}

		$mail_type = sanitize_key( (string) ( $request->get_param( 'mail_type' ) ?: '' ) );
		if ( in_array( $mail_type, $this->mail_item_types(), true ) ) {
			$where[]  = 'm.mail_type = %s';
			$params[] = $mail_type;
		}

		if ( ! empty( $request->get_param( 'sop' ) ) ) {
			$where[] = 'm.is_sop = 1';
		}

		$customer_filter = sanitize_text_field( (string) ( $request->get_param( 'stripe_customer_id' ) ?: '' ) );
		if ( '' !== $customer_filter ) {
			$where[]  = 'm.stripe_customer_id = %s';
			$params[] = $customer_filter;
		}

		$per_page = min( 500, max( 1, absint( $request->get_param( 'per_page' ) ?: 200 ) ) );
		$params[] = $per_page;

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $pdb->get_results( $pdb->prepare( "SELECT m.*, {$name_select} FROM `{$table}` m {$join} WHERE {$where_sql} ORDER BY (m.is_sop = 1 AND m.status <> 'closed') DESC, m.received_at DESC, m.id DESC LIMIT %d", $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$stats = $pdb->get_row(
			"SELECT COUNT(*) AS total,
				SUM(status = 'received') AS received,
				SUM(status IN ('received','scanned')) AS awaiting_notify,
				SUM(status <> 'closed') AS open_items,
				SUM(is_sop = 1 AND status <> 'closed') AS sop_open
			FROM `{$table}`",
			ARRAY_A
		);

		return rest_ensure_response( array(
			'mail_items' => array_map( array( $this, 'format_mail_item_row' ), $rows ),
			'stats'      => array(
				'total'           => (int) ( $stats['total'] ?? 0 ),
				'received'        => (int) ( $stats['received'] ?? 0 ),
				'awaiting_notify' => (int) ( $stats['awaiting_notify'] ?? 0 ),
				'open'            => (int) ( $stats['open_items'] ?? 0 ),
				'sop_open'        => (int) ( $stats['sop_open'] ?? 0 ),
			),
		) );
	}

	public function get_ops_mail_item( WP_REST_Request $request ) {
		$row = $this->fetch_mail_item_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Mail item not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->format_mail_item_row( $row ) );
	}

	public function create_ops_mail_item( WP_REST_Request $request ) {
		if ( ! $this->ensure_mail_items_table() ) {
			return new WP_Error( 'no_table', __( 'Mail items table could not be created.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}

		$recipient_name = sanitize_text_field( (string) ( $request->get_param( 'recipient_name' ) ?: '' ) );
		if ( '' === $recipient_name ) {
			return new WP_Error( 'missing_recipient', __( 'Recipient name is required.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}

		$scan = $this->handle_mail_scan_upload( $request );
		if ( is_wp_error( $scan ) ) {
			return $scan;
		}
		list( $scan_attachment_id, $scan_url ) = $scan;

		$mail_type = sanitize_key( (string) ( $request->get_param( 'mail_type' ) ?: 'letter' ) );
		if ( ! in_array( $mail_type, $this->mail_item_types(), true ) ) {
			$mail_type = 'other';
		}

		$received_at = sanitize_text_field( (string) ( $request->get_param( 'received_at' ) ?: '' ) );
		$received_ts = '' !== $received_at ? strtotime( $received_at ) : false;

		$pdb   = $this->get_portal_db();
		$table = $this->get_mail_items_table();
		$data  = array(
			'mail_uuid'          => wp_generate_uuid4(),
			'stripe_customer_id' => sanitize_text_field( (string) ( $request->get_param( 'stripe_customer_id' ) ?: '' ) ),
			'customer_email'     => sanitize_email( (string) ( $request->get_param( 'customer_email' ) ?: '' ) ),
			'recipient_name'     => $recipient_name,
			'mail_type'          => $mail_type,
			'is_sop'             => ! empty( $request->get_param( 'is_sop' ) ) ? 1 : 0,
			'sender_name'        => sanitize_text_field( (string) ( $request->get_param( 'sender_name' ) ?: '' ) ),
			'carrier'            => sanitize_text_field( (string) ( $request->get_param( 'carrier' ) ?: '' ) ),
			'tracking_number'    => sanitize_text_field( (string) ( $request->get_param( 'tracking_number' ) ?: '' ) ),
			'description'        => sanitize_textarea_field( (string) ( $request->get_param( 'description' ) ?: '' ) ),
			'status'             => $scan_attachment_id ? 'scanned' : 'received',
			'disposition'        => '',
			'scan_attachment_id' => $scan_attachment_id,
			'scan_url'           => $scan_url,
			'received_at'        => false !== $received_ts ? gmdate( 'Y-m-d H:i:s', $received_ts ) : current_time( 'mysql' ),
			'admin_notes'        => sanitize_textarea_field( (string) ( $request->get_param( 'admin_notes' ) ?: '' ) ),
			'created_by'         => get_current_user_id(),
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		);

		$inserted = $pdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error( 'db_error', __( 'Could not create mail item.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}

		$item_id = (int) $pdb->insert_id;
		$row     = $this->fetch_mail_item_row( $item_id );
		$this->log_mail_event( 'mail_item_created', $row );

		// Optional immediate client notification ("log & notify" in one step).
		$notified = false;
		if ( ! empty( $request->get_param( 'notify' ) ) ) {
			$result = $this->send_mail_item_notification( $row );
			if ( ! is_wp_error( $result ) ) {
				$notified = true;
				$row      = $this->fetch_mail_item_row( $item_id );
			}
		}

		$response = $this->format_mail_item_row( $row );
		$response['notified'] = $notified;
		return rest_ensure_response( $response );
	}

	public function update_ops_mail_item( WP_REST_Request $request ) {
		$row = $this->fetch_mail_item_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Mail item not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}

		$scan = $this->handle_mail_scan_upload( $request );
		if ( is_wp_error( $scan ) ) {
			return $scan;
		}
		list( $scan_attachment_id, $scan_url ) = $scan;

		$data = array( 'updated_at' => current_time( 'mysql' ) );
		$fmt  = array( '%s' );

		$text_fields = array(
			'recipient_name'  => 'sanitize_text_field',
			'sender_name'     => 'sanitize_text_field',
			'carrier'         => 'sanitize_text_field',
			'tracking_number' => 'sanitize_text_field',
			'description'     => 'sanitize_textarea_field',
			'admin_notes'     => 'sanitize_textarea_field',
			'stripe_customer_id' => 'sanitize_text_field',
			'customer_email'  => 'sanitize_email',
		);
		foreach ( $text_fields as $field => $sanitizer ) {
			if ( null !== $request->get_param( $field ) ) {
				$data[ $field ] = call_user_func( $sanitizer, (string) $request->get_param( $field ) );
				$fmt[]          = '%s';
			}
		}

		if ( null !== $request->get_param( 'mail_type' ) ) {
			$mail_type         = sanitize_key( (string) $request->get_param( 'mail_type' ) );
			$data['mail_type'] = in_array( $mail_type, $this->mail_item_types(), true ) ? $mail_type : 'other';
			$fmt[]             = '%s';
		}

		if ( null !== $request->get_param( 'is_sop' ) ) {
			$data['is_sop'] = ! empty( $request->get_param( 'is_sop' ) ) ? 1 : 0;
			$fmt[]          = '%d';
		}

		if ( null !== $request->get_param( 'received_at' ) ) {
			$ts = strtotime( sanitize_text_field( (string) $request->get_param( 'received_at' ) ) );
			if ( false !== $ts ) {
				$data['received_at'] = gmdate( 'Y-m-d H:i:s', $ts );
				$fmt[]               = '%s';
			}
		}

		if ( $scan_attachment_id ) {
			$data['scan_attachment_id'] = $scan_attachment_id;
			$fmt[]                      = '%d';
			$data['scan_url']           = $scan_url;
			$fmt[]                      = '%s';
			if ( 'received' === (string) $row['status'] ) {
				$data['status'] = 'scanned';
				$fmt[]          = '%s';
			}
		}

		$disposition = null !== $request->get_param( 'disposition' ) ? sanitize_key( (string) $request->get_param( 'disposition' ) ) : null;
		if ( null !== $disposition && '' !== $disposition ) {
			if ( ! in_array( $disposition, $this->mail_item_dispositions(), true ) ) {
				return new WP_Error( 'invalid_disposition', __( 'Invalid disposition.', 'ajcore-ra' ), array( 'status' => 400 ) );
			}
			$data['disposition'] = $disposition;
			$fmt[]               = '%s';
			$data['disposed_at'] = current_time( 'mysql' );
			$fmt[]               = '%s';
			// "held" keeps the item open (e.g. awaiting pickup); everything else closes it.
			if ( 'held' !== $disposition ) {
				$data['status'] = 'closed';
				$fmt[]          = '%s';
			}
		} elseif ( '' === $disposition ) {
			$data['disposition'] = '';
			$fmt[]               = '%s';
			$data['disposed_at'] = null;
			$fmt[]               = '%s';
		}

		$pdb = $this->get_portal_db();
		$pdb->update( $this->get_mail_items_table(), $data, array( 'id' => (int) $row['id'] ), $fmt, array( '%d' ) );

		$updated = $this->fetch_mail_item_row( $row['id'] );
		if ( null !== $disposition && '' !== $disposition ) {
			$this->log_mail_event( 'mail_item_disposed', $updated, array( 'disposition' => $disposition ) );
		}

		return rest_ensure_response( $this->format_mail_item_row( $updated ) );
	}

	public function delete_ops_mail_item( WP_REST_Request $request ) {
		$row = $this->fetch_mail_item_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Mail item not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$pdb = $this->get_portal_db();
		$pdb->delete( $this->get_mail_items_table(), array( 'id' => (int) $row['id'] ), array( '%d' ) );
		return rest_ensure_response( array( 'deleted' => true, 'id' => (int) $row['id'] ) );
	}

	/** Sends the client notification for a mail item row and stamps notified_at/status. */
	private function send_mail_item_notification( $row ) {
		$row   = (array) $row;
		$email = sanitize_email( (string) $row['customer_email'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'no_email', __( 'No customer email on file for this mail item. Link a customer or set an email first.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}

		$name  = ! empty( $row['customer_name'] ) ? (string) $row['customer_name'] : (string) $row['recipient_name'];
		$sent  = $this->send_mail_item_notification_for_ops( $row, $name, $email );
		if ( ! $sent ) {
			return new WP_Error( 'send_failed', __( 'The notification email could not be sent.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}

		$pdb  = $this->get_portal_db();
		$data = array( 'notified_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) );
		$fmt  = array( '%s', '%s' );
		if ( in_array( (string) $row['status'], array( 'received', 'scanned' ), true ) ) {
			$data['status'] = 'notified';
			$fmt[]          = '%s';
		}
		$pdb->update( $this->get_mail_items_table(), $data, array( 'id' => (int) $row['id'] ), $fmt, array( '%d' ) );

		$this->log_mail_event( 'mail_item_notified', $row, array( 'email' => $email ) );
		return true;
	}

	public function notify_ops_mail_item( WP_REST_Request $request ) {
		$row = $this->fetch_mail_item_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Mail item not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$result = $this->send_mail_item_notification( $row );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $this->format_mail_item_row( $this->fetch_mail_item_row( $request['id'] ) ) );
	}

	/**
	 * Publishes the item's scan into the client Files library (for keeper documents) and
	 * links it back via file_id. Files live in the local WP database, same as /ops/files.
	 */
	public function publish_ops_mail_item_to_files( WP_REST_Request $request ) {
		global $wpdb;

		$row = $this->fetch_mail_item_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Mail item not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $row['file_id'] ) ) {
			$existing_attachment_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attachment_id FROM `{$wpdb->prefix}aj_portal_files` WHERE id = %d LIMIT 1", (int) $row['file_id'] ) );
			if ( $existing_attachment_id && class_exists( 'AJCore_Storage_Service' ) ) {
				$relocated = AJCore_Storage_Service::migrate_attachment_ids( array( $existing_attachment_id ) );
				if ( ! empty( $relocated['failed'][ $existing_attachment_id ] ) ) {
					return new WP_Error( 'storage_relocation_failed', $relocated['failed'][ $existing_attachment_id ], array( 'status' => 500 ) );
				}
			}
			return rest_ensure_response( $this->format_mail_item_row( $row ) );
		}
		if ( empty( $row['scan_attachment_id'] ) ) {
			return new WP_Error( 'no_scan', __( 'Attach a scan before publishing to Files.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}

		$files_table = $wpdb->prefix . 'aj_portal_files';
		$users_table = $wpdb->prefix . 'aj_portal_file_users';
		if ( ! $this->table_exists( $wpdb, $files_table ) ) {
			return new WP_Error( 'no_table', __( 'Files table not found.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}

		$received_day = ! empty( $row['received_at'] ) ? mysql2date( 'M j, Y', (string) $row['received_at'] ) : '';
		$title        = trim( sprintf( '%s — %s%s', (string) $row['recipient_name'], ucwords( str_replace( '_', ' ', (string) $row['mail_type'] ) ), '' !== $received_day ? ' (' . $received_day . ')' : '' ) );

		$wpdb->insert(
			$files_table,
			array(
				'attachment_id' => (int) $row['scan_attachment_id'],
				'title'         => $title,
				'category'      => 'Mail',
				'description'   => (string) $row['description'],
				'created_by'    => get_current_user_id(),
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$file_id = (int) $wpdb->insert_id;
		if ( ! $file_id ) {
			return new WP_Error( 'db_error', __( 'Could not create the file record.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}

		$email = sanitize_email( (string) $row['customer_email'] );
		if ( is_email( $email ) && $this->table_exists( $wpdb, $users_table ) ) {
			$user = get_user_by( 'email', $email );
			$wpdb->insert(
				$users_table,
				array( 'file_id' => $file_id, 'user_id' => $user ? (int) $user->ID : 0, 'user_email' => strtolower( $email ), 'created_at' => current_time( 'mysql' ) ),
				array( '%d', '%d', '%s', '%s' )
			);
		}

		$pdb = $this->get_portal_db();
		$pdb->update(
			$this->get_mail_items_table(),
			array( 'file_id' => $file_id, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $row['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( class_exists( 'AJCore_Storage_Service' ) ) {
			$attachment_id = (int) $row['scan_attachment_id'];
			$relocated = AJCore_Storage_Service::migrate_attachment_ids( array( $attachment_id ) );
			if ( ! empty( $relocated['failed'][ $attachment_id ] ) ) {
				return new WP_Error( 'storage_relocation_failed', $relocated['failed'][ $attachment_id ], array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response( $this->format_mail_item_row( $this->fetch_mail_item_row( $request['id'] ) ) );
	}

	/** Client mailbox: the current portal user's mail items, without staff-only fields. */
	public function get_portal_mail() {
		$pdb   = $this->get_portal_db();
		$table = $this->get_mail_items_table();
		if ( ! $this->table_exists( $pdb, $table ) ) {
			return rest_ensure_response( array( 'mail_items' => array() ) );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$user               = wp_get_current_user();
		$user_email         = $user ? (string) $user->user_email : '';
		if ( '' === $stripe_customer_id && '' === $user_email ) {
			return rest_ensure_response( array( 'mail_items' => array() ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $pdb->get_results(
			$pdb->prepare(
				"SELECT * FROM `{$table}` WHERE ( stripe_customer_id = %s AND stripe_customer_id <> '' ) OR ( customer_email = %s AND customer_email <> '' ) ORDER BY received_at DESC, id DESC LIMIT 200",
				$stripe_customer_id,
				strtolower( $user_email )
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = $this->format_mail_item_row( $row, false );
		}

		return rest_ensure_response( array( 'mail_items' => $items ) );
	}

	// ── Mail tab form handling ────────────────────────────────────────────────

	/**
	 * WP-admin Mail tab actions. Rather than reimplementing the mail business logic, these build a
	 * WP_REST_Request from $_POST/$_FILES and call straight into AJCore_REST_API's already-tested
	 * /ops/mail methods (same pattern AJOps' Mail screen uses over HTTP, just in-process here).
	 */
	private function handle_portal_mail_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rest = $this;
		$args = array( 'page' => 'ajforms-client-portal', 'tab' => 'mail' );

		if ( isset( $_POST['ajcore_mail_create_nonce'] ) ) {
			check_admin_referer( 'ajcore_mail_create', 'ajcore_mail_create_nonce' );
			$request = new WP_REST_Request( 'POST' );
			foreach ( array( 'recipient_name', 'mail_type', 'sender_name', 'carrier', 'tracking_number', 'description', 'stripe_customer_id', 'customer_email', 'received_at' ) as $field ) {
				$request->set_param( $field, wp_unslash( $_POST[ $field ] ?? '' ) );
			}
			$request->set_param( 'is_sop', ! empty( $_POST['is_sop'] ) ? '1' : '' );
			$request->set_param( 'notify', ! empty( $_POST['notify'] ) ? '1' : '' );
			$request->set_file_params( $_FILES );
			$result = $rest->create_ops_mail_item( $request );
			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['portal-mail-created'] = 1;
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_mail_update_nonce'], $_POST['mail_item_id'] ) ) {
			check_admin_referer( 'ajcore_mail_update', 'ajcore_mail_update_nonce' );
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'id', absint( $_POST['mail_item_id'] ) );
			foreach ( array( 'recipient_name', 'mail_type', 'sender_name', 'carrier', 'tracking_number', 'description', 'admin_notes', 'stripe_customer_id', 'customer_email', 'received_at', 'disposition' ) as $field ) {
				if ( isset( $_POST[ $field ] ) ) {
					$request->set_param( $field, wp_unslash( $_POST[ $field ] ) );
				}
			}
			if ( isset( $_POST['is_sop'] ) ) {
				$request->set_param( 'is_sop', ! empty( $_POST['is_sop'] ) ? '1' : '' );
			}
			$request->set_file_params( $_FILES );
			$result = $rest->update_ops_mail_item( $request );
			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['portal-mail-updated'] = 1;
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_mail_delete_nonce'], $_POST['mail_item_id'] ) ) {
			check_admin_referer( 'ajcore_mail_delete', 'ajcore_mail_delete_nonce' );
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'id', absint( $_POST['mail_item_id'] ) );
			$result = $rest->delete_ops_mail_item( $request );
			$args['portal-mail-deleted'] = is_wp_error( $result ) ? 0 : 1;
			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_mail_notify_nonce'], $_POST['mail_item_id'] ) ) {
			check_admin_referer( 'ajcore_mail_notify', 'ajcore_mail_notify_nonce' );
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'id', absint( $_POST['mail_item_id'] ) );
			$result = $rest->notify_ops_mail_item( $request );
			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['portal-mail-notified'] = 1;
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_mail_publish_nonce'], $_POST['mail_item_id'] ) ) {
			check_admin_referer( 'ajcore_mail_publish', 'ajcore_mail_publish_nonce' );
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'id', absint( $_POST['mail_item_id'] ) );
			$result = $rest->publish_ops_mail_item_to_files( $request );
			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['portal-mail-published'] = 1;
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	// ── Mail tab ──────────────────────────────────────────────────────────────

	/**
	 * WP-admin fallback for the AJOps Mail (service-of-process intake) screen, so staff can log,
	 * edit, notify, publish, and dispose of mail even if AJOps is down. Reads/writes go through
	 * AJCore_REST_API's /ops/mail methods (see handle_portal_mail_actions()) — this function is
	 * display-only.
	 */
	private function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		$rest = $this;

		$mail_types    = array( 'letter', 'legal', 'government', 'package', 'check', 'other' );
		$dispositions  = array( 'forwarded', 'picked_up', 'shredded', 'returned', 'held' );

		$status = isset( $_GET['mail_status'] ) ? sanitize_key( wp_unslash( $_GET['mail_status'] ) ) : 'open';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$list_request = new WP_REST_Request( 'GET' );
		if ( '' !== $status ) {
			$list_request->set_param( 'status', $status );
		}
		if ( '' !== $search ) {
			$list_request->set_param( 'search', $search );
		}
		$list_request->set_param( 'per_page', 300 );
		$response  = $rest->get_ops_mail_items( $list_request );
		$response  = is_wp_error( $response ) ? array( 'mail_items' => array(), 'stats' => array() ) : $response->get_data();
		$items     = isset( $response['mail_items'] ) ? $response['mail_items'] : array();
		$stats     = wp_parse_args( isset( $response['stats'] ) ? $response['stats'] : array(), array( 'total' => 0, 'received' => 0, 'awaiting_notify' => 0, 'open' => 0, 'sop_open' => 0 ) );

		$pdb                    = $this->get_pdb();
		$local_customers_table  = $pdb->prefix . 'aj_portal_local_customers';
		$customers              = array_merge(
			(array) $pdb->get_results( "SELECT stripe_customer_id, name, email FROM {$this->get_portal_stripe_customers_table()} ORDER BY name ASC" ),
			(array) $pdb->get_results( "SELECT local_customer_id AS stripe_customer_id, name, email FROM `{$local_customers_table}` ORDER BY name ASC" )
		);

		$base_url = add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'mail' ), admin_url( 'admin.php' ) );
		?>
		<div class="ajforms-settings-card">
			<div class="ajcore-section-head">
				<div>
					<h2><?php esc_html_e( 'Physical Mail', 'ajcore-ra' ); ?></h2>
					<p><?php esc_html_e( 'Physical mail / service-of-process intake log — the same data as the AJOps Mail screen.', 'ajcore-ra' ); ?></p>
				</div>
			</div>

			<?php foreach ( array(
				'portal-mail-created'   => __( 'Mail item logged.', 'ajcore-ra' ),
				'portal-mail-updated'   => __( 'Mail item updated.', 'ajcore-ra' ),
				'portal-mail-deleted'   => __( 'Mail item deleted.', 'ajcore-ra' ),
				'portal-mail-notified'  => __( 'Client notification sent.', 'ajcore-ra' ),
				'portal-mail-published' => __( 'Published to Files.', 'ajcore-ra' ),
			) as $flag => $message ) : ?>
				<?php if ( isset( $_GET[ $flag ] ) ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
				<?php endif; ?>
			<?php endforeach; ?>

			<div class="ajcore-kpi-grid">
				<div class="ajcore-kpi-card"><span><?php esc_html_e( 'Open', 'ajcore-ra' ); ?></span><strong><?php echo esc_html( (int) $stats['open'] ); ?></strong></div>
				<div class="ajcore-kpi-card"><span><?php esc_html_e( 'SOP Open', 'ajcore-ra' ); ?></span><strong><?php echo esc_html( (int) $stats['sop_open'] ); ?></strong></div>
				<div class="ajcore-kpi-card"><span><?php esc_html_e( 'Awaiting Notify', 'ajcore-ra' ); ?></span><strong><?php echo esc_html( (int) $stats['awaiting_notify'] ); ?></strong></div>
				<div class="ajcore-kpi-card"><span><?php esc_html_e( 'Total', 'ajcore-ra' ); ?></span><strong><?php echo esc_html( (int) $stats['total'] ); ?></strong></div>
			</div>

			<details class="ajforms-settings-card" style="margin:14px 0;">
				<summary><?php esc_html_e( 'Log New Mail Item', 'ajcore-ra' ); ?></summary>
				<form method="post" enctype="multipart/form-data" style="margin-top:14px;">
					<?php wp_nonce_field( 'ajcore_mail_create', 'ajcore_mail_create_nonce' ); ?>
					<div class="ajforms-settings-grid">
						<div class="ajforms-settings-field">
							<label><?php esc_html_e( 'Recipient Name', 'ajcore-ra' ); ?> *</label>
							<input type="text" name="recipient_name" required>
						</div>
						<div class="ajforms-settings-field">
							<label><?php esc_html_e( 'Mail Type', 'ajcore-ra' ); ?></label>
							<select name="mail_type">
								<?php foreach ( $mail_types as $type ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="ajforms-settings-field">
							<label><?php esc_html_e( 'Sender Name', 'ajcore-ra' ); ?></label>
							<input type="text" name="sender_name">
						</div>
						<div class="ajforms-settings-field">
							<label><?php esc_html_e( 'Carrier', 'ajcore-ra' ); ?></label>
							<input type="text" name="carrier">
						</div>
						<div class="ajforms-settings-field">
							<label><?php esc_html_e( 'Tracking Number', 'ajcore-ra' ); ?></label>
							<input type="text" name="tracking_number">
						</div>
						<div class="ajforms-settings-field">
							<label><?php esc_html_e( 'Customer', 'ajcore-ra' ); ?></label>
							<select name="stripe_customer_id">
								<option value=""><?php esc_html_e( '— none —', 'ajcore-ra' ); ?></option>
								<?php foreach ( $customers as $c ) : ?>
									<option value="<?php echo esc_attr( $c->stripe_customer_id ); ?>"><?php echo esc_html( $c->name . ' (' . $c->email . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="ajforms-settings-field">
							<label><?php esc_html_e( 'Customer Email (if not linking above)', 'ajcore-ra' ); ?></label>
							<input type="email" name="customer_email">
						</div>
						<div class="ajforms-settings-field">
							<label><?php esc_html_e( 'Scan (optional)', 'ajcore-ra' ); ?></label>
							<input type="file" name="scan" accept="application/pdf,image/*">
						</div>
					</div>
					<div class="ajforms-settings-field" style="margin-top:10px;">
						<label><?php esc_html_e( 'Description', 'ajcore-ra' ); ?></label>
						<textarea name="description" rows="2" style="width:100%;"></textarea>
					</div>
					<p style="margin:10px 0;">
						<label><input type="checkbox" name="is_sop" value="1"> <?php esc_html_e( 'This is a Service of Process (SOP) delivery', 'ajcore-ra' ); ?></label><br>
						<label><input type="checkbox" name="notify" value="1"> <?php esc_html_e( 'Notify the customer immediately after logging', 'ajcore-ra' ); ?></label>
					</p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Log Mail Item', 'ajcore-ra' ); ?></button>
				</form>
			</details>

			<form method="get" class="ajcore-filter-bar" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 16px;">
				<input type="hidden" name="page" value="ajforms-client-portal">
				<input type="hidden" name="tab" value="mail">
				<select name="mail_status">
					<option value="open" <?php selected( $status, 'open' ); ?>><?php esc_html_e( 'Open', 'ajcore-ra' ); ?></option>
					<option value="received" <?php selected( $status, 'received' ); ?>><?php esc_html_e( 'Received', 'ajcore-ra' ); ?></option>
					<option value="scanned" <?php selected( $status, 'scanned' ); ?>><?php esc_html_e( 'Scanned', 'ajcore-ra' ); ?></option>
					<option value="notified" <?php selected( $status, 'notified' ); ?>><?php esc_html_e( 'Notified', 'ajcore-ra' ); ?></option>
					<option value="closed" <?php selected( $status, 'closed' ); ?>><?php esc_html_e( 'Closed', 'ajcore-ra' ); ?></option>
					<option value="" <?php selected( $status, '' ); ?>><?php esc_html_e( 'All', 'ajcore-ra' ); ?></option>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search recipient, sender, tracking, customer…', 'ajcore-ra' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ajcore-ra' ); ?></button>
				<a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Reset', 'ajcore-ra' ); ?></a>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Received', 'ajcore-ra' ); ?></th>
						<th><?php esc_html_e( 'Recipient / Type', 'ajcore-ra' ); ?></th>
						<th><?php esc_html_e( 'Sender / Tracking', 'ajcore-ra' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'ajcore-ra' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ajcore-ra' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ajcore-ra' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Nothing here.', 'ajcore-ra' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $items as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['received_at'] ); ?></td>
								<td>
									<strong><?php echo esc_html( $item['recipient_name'] ); ?></strong><br>
									<span class="description"><?php echo esc_html( ucfirst( $item['mail_type'] ) ); ?><?php echo ! empty( $item['is_sop'] ) ? ' — ' . esc_html__( 'SOP', 'ajcore-ra' ) : ''; ?></span>
								</td>
								<td>
									<?php echo esc_html( $item['sender_name'] ); ?>
									<?php if ( ! empty( $item['tracking_number'] ) ) : ?><br><span class="description"><?php echo esc_html( $item['carrier'] . ' ' . $item['tracking_number'] ); ?></span><?php endif; ?>
									<?php if ( ! empty( $item['scan_url'] ) ) : ?><br><a href="<?php echo esc_url( $item['scan_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View scan', 'ajcore-ra' ); ?></a><?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( $item['customer_name'] ?: $item['customer_email'] ); ?>
								</td>
								<td>
									<span class="ajcore-status-pill <?php echo esc_attr( 'closed' === $item['status'] ? 'active' : ( ! empty( $item['is_sop'] ) ? 'disabled' : '' ) ); ?>">
										<?php echo esc_html( ucfirst( $item['status'] ) ); ?>
									</span>
									<?php if ( ! empty( $item['disposition'] ) ) : ?><br><span class="description"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $item['disposition'] ) ) ); ?></span><?php endif; ?>
								</td>
								<td style="white-space:nowrap;">
									<?php if ( empty( $item['notified_at'] ) ) : ?>
										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'ajcore_mail_notify', 'ajcore_mail_notify_nonce' ); ?>
											<input type="hidden" name="mail_item_id" value="<?php echo esc_attr( $item['id'] ); ?>">
											<button type="submit" class="button button-small"><?php esc_html_e( 'Notify', 'ajcore-ra' ); ?></button>
										</form>
									<?php endif; ?>
									<?php if ( empty( $item['file_id'] ) && ! empty( $item['scan_url'] ) ) : ?>
										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'ajcore_mail_publish', 'ajcore_mail_publish_nonce' ); ?>
											<input type="hidden" name="mail_item_id" value="<?php echo esc_attr( $item['id'] ); ?>">
											<button type="submit" class="button button-small"><?php esc_html_e( 'Publish to Files', 'ajcore-ra' ); ?></button>
										</form>
									<?php endif; ?>
									<details style="display:inline-block;">
										<summary class="button button-small"><?php esc_html_e( 'Edit', 'ajcore-ra' ); ?></summary>
										<form method="post" enctype="multipart/form-data" style="margin-top:8px;min-width:280px;">
											<?php wp_nonce_field( 'ajcore_mail_update', 'ajcore_mail_update_nonce' ); ?>
											<input type="hidden" name="mail_item_id" value="<?php echo esc_attr( $item['id'] ); ?>">
											<div class="ajforms-settings-field"><label><?php esc_html_e( 'Recipient', 'ajcore-ra' ); ?></label><input type="text" name="recipient_name" value="<?php echo esc_attr( $item['recipient_name'] ); ?>"></div>
											<div class="ajforms-settings-field"><label><?php esc_html_e( 'Sender', 'ajcore-ra' ); ?></label><input type="text" name="sender_name" value="<?php echo esc_attr( $item['sender_name'] ); ?>"></div>
											<div class="ajforms-settings-field"><label><?php esc_html_e( 'Tracking #', 'ajcore-ra' ); ?></label><input type="text" name="tracking_number" value="<?php echo esc_attr( $item['tracking_number'] ); ?>"></div>
											<div class="ajforms-settings-field"><label><?php esc_html_e( 'Admin Notes', 'ajcore-ra' ); ?></label><textarea name="admin_notes" rows="2" style="width:100%;"></textarea></div>
											<div class="ajforms-settings-field">
												<label><?php esc_html_e( 'Disposition', 'ajcore-ra' ); ?></label>
												<select name="disposition">
													<option value=""><?php esc_html_e( '— none —', 'ajcore-ra' ); ?></option>
													<?php foreach ( $dispositions as $d ) : ?>
														<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $item['disposition'], $d ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $d ) ) ); ?></option>
													<?php endforeach; ?>
												</select>
											</div>
											<div class="ajforms-settings-field"><label><?php esc_html_e( 'Replace Scan', 'ajcore-ra' ); ?></label><input type="file" name="scan" accept="application/pdf,image/*"></div>
											<button type="submit" class="button button-primary button-small" style="margin-top:8px;"><?php esc_html_e( 'Save', 'ajcore-ra' ); ?></button>
										</form>
									</details>
									<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this mail item permanently?', 'ajcore-ra' ) ); ?>');">
										<?php wp_nonce_field( 'ajcore_mail_delete', 'ajcore_mail_delete_nonce' ); ?>
										<input type="hidden" name="mail_item_id" value="<?php echo esc_attr( $item['id'] ); ?>">
										<button type="submit" class="button button-small" style="color:#dc2626;"><?php esc_html_e( 'Delete', 'ajcore-ra' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
