<?php
/**
 * Expense invoices (Rentec property expenses), moved out of AJCore.
 *
 * Same /ajcore/v1/ops/expense-invoices* routes, parameters, permissions and responses AJOps already
 * uses, and the same table (aj_portal_expense_invoices, defined in AJCore's activator). The handler
 * code below is unchanged apart from where AJCore's helpers are reached. Units/vendors for the options
 * endpoint come from AJCore_RA_Rentec via the 'ajcore_expense_invoice_options' filter.
 * The accounting catalog (charge/payment categories) stays in AJCore: its Products & Services admin screen uses it.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Expense_Invoices {

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
	}

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public function __construct() {
		$this->kit = class_exists( 'AJCore_Extensions' ) ? AJCore_Extensions::rest_toolkit() : array();
	}

	private function get_portal_db() {
		return $this->kit['portal_db']();
	}

	private function portal_table( $suffix ) {
		return $this->kit['portal_table']( $suffix );
	}

	public static function catalog( $catalog ) {
		$rows = array(
			array( 'GET', '/ops/expense-invoices', 'Property expense invoices (unpaid first, by due date).' ),
			array( 'POST', '/ops/expense-invoices', 'Create an expense invoice (title and amount required).' ),
			array( 'GET', '/ops/expense-invoices/options', 'Rentec units and vendors for the invoice form.' ),
			array( 'PATCH', '/ops/expense-invoices/{id}', 'Update an expense invoice.' ),
			array( 'DELETE', '/ops/expense-invoices/{id}', 'Delete an expense invoice and its stored attachments.' ),
			array( 'POST', '/ops/expense-invoices/{id}/attachments', 'Upload an attachment (max 20 MB, stored in RustFS).' ),
			array( 'GET', '/ops/expense-invoices/{id}/attachments/{attachment_id}', 'Short-lived download URL for an attachment.' ),
			array( 'DELETE', '/ops/expense-invoices/{id}/attachments/{attachment_id}', 'Remove an attachment.' ),
		);
		foreach ( $rows as $r ) {
			$catalog[] = array( 'surface' => 'OPS', 'method' => $r[0], 'path' => $r[1], 'auth' => 'Admin', 'purpose' => $r[2], 'app' => 'OPS invoices' );
		}
		return $catalog;
	}

	public function register_routes() {
		$ops = array( 'AJCore_Extensions', 'can_manage_ops' );
		register_rest_route( AJCore_Extensions::rest_namespace(), '/ops/expense-invoices', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_ops_expense_invoices' ), 'permission_callback' => $ops ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create_ops_expense_invoice' ), 'permission_callback' => $ops ),
		) );
		register_rest_route( AJCore_Extensions::rest_namespace(), '/ops/expense-invoices/options', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_ops_expense_invoice_options' ), 'permission_callback' => $ops,
		) );
		register_rest_route( AJCore_Extensions::rest_namespace(), '/ops/expense-invoices/(?P<id>\d+)', array(
			array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'update_ops_expense_invoice' ), 'permission_callback' => $ops ),
			array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( $this, 'delete_ops_expense_invoice' ), 'permission_callback' => $ops ),
		) );
		register_rest_route( AJCore_Extensions::rest_namespace(), '/ops/expense-invoices/(?P<id>\d+)/attachments', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'upload_ops_expense_invoice_attachment' ), 'permission_callback' => $ops,
		) );
		register_rest_route( AJCore_Extensions::rest_namespace(), '/ops/expense-invoices/(?P<id>\d+)/attachments/(?P<attachment_id>\d+)', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_ops_expense_invoice_attachment_url' ), 'permission_callback' => $ops ),
			array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( $this, 'delete_ops_expense_invoice_attachment' ), 'permission_callback' => $ops ),
		) );
	}

	private function expense_invoice_table() {
		return $this->portal_table( 'aj_portal_expense_invoices' );
	}

	private function expense_invoice_data( WP_REST_Request $request ) {
		$date = static function ( $value ) {
			$value = sanitize_text_field( (string) $value );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
		};
		$attachments = array();
		foreach ( (array) $request->get_param( 'attachments' ) as $attachment ) {
			if ( ! is_array( $attachment ) || empty( $attachment['id'] ) ) continue;
			$attachments[] = array(
				'id' => absint( $attachment['id'] ),
				'name' => sanitize_file_name( (string) ( $attachment['name'] ?? '' ) ),
				'mime' => sanitize_mime_type( (string) ( $attachment['mime'] ?? '' ) ),
				'size' => absint( $attachment['size'] ?? 0 ),
			);
		}
		return array(
			'rentec_account'    => sanitize_key( (string) $request->get_param( 'rentec_account' ) ),
			'rentec_property_id'=> absint( $request->get_param( 'rentec_property_id' ) ),
			'unit_label'        => sanitize_text_field( (string) $request->get_param( 'unit_label' ) ),
			'title'             => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'vendor'            => sanitize_text_field( (string) $request->get_param( 'vendor' ) ),
			'attachments'       => wp_json_encode( $attachments ),
			'paid'              => $request->get_param( 'paid' ) ? 1 : 0,
			'invoice_number'    => sanitize_text_field( (string) $request->get_param( 'invoice_number' ) ),
			'invoice_date'      => $date( $request->get_param( 'invoice_date' ) ),
			'due_date'          => $date( $request->get_param( 'due_date' ) ),
			'amount'            => round( max( 0, (float) $request->get_param( 'amount' ) ), 2 ),
			'payment_method'    => sanitize_text_field( (string) $request->get_param( 'payment_method' ) ),
			'payment_date'      => $date( $request->get_param( 'payment_date' ) ),
			'comments'          => sanitize_textarea_field( (string) $request->get_param( 'comments' ) ),
		);
	}

	private function normalize_expense_invoice_rows( $rows ) {
		foreach ( $rows as &$row ) {
			$row['id']                 = absint( $row['id'] );
			$row['rentec_property_id'] = absint( $row['rentec_property_id'] );
			$row['amount']             = (float) $row['amount'];
			$row['paid']               = (bool) $row['paid'];
			$row['attachments']        = json_decode( (string) $row['attachments'], true );
			if ( ! is_array( $row['attachments'] ) ) $row['attachments'] = array();
		}
		unset( $row );
		return $rows;
	}

	public function get_ops_expense_invoices() {
		$pdb = $this->get_portal_db();
		$table = $this->expense_invoice_table();
		$rows = $pdb->get_results( "SELECT * FROM `{$table}` ORDER BY paid ASC,due_date ASC,invoice_date DESC,id DESC", ARRAY_A );
		if ( null === $rows && $pdb->last_error ) return new WP_Error( 'ajcore_invoice_table_missing', __( 'The shared invoice table is unavailable. Run the AJCore database upgrade.', 'ajforms' ), array( 'status' => 503 ) );
		return rest_ensure_response( array( 'invoices' => $this->normalize_expense_invoice_rows( (array) $rows ) ) );
	}

	public function create_ops_expense_invoice( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$data = $this->expense_invoice_data( $request );
		if ( '' === $data['title'] || $data['amount'] <= 0 ) return new WP_Error( 'ajcore_invoice_required', __( 'Title and an amount greater than zero are required.', 'ajforms' ), array( 'status' => 400 ) );
		$data['created_by'] = get_current_user_id();
		if ( false === $pdb->insert( $this->expense_invoice_table(), $data ) ) return new WP_Error( 'ajcore_invoice_create_failed', $pdb->last_error ?: __( 'The invoice could not be created.', 'ajforms' ), array( 'status' => 500 ) );
		$request->set_param( 'id', $pdb->insert_id );
		return $this->get_ops_expense_invoice( $request );
	}

	private function get_ops_expense_invoice( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$table = $this->expense_invoice_table();
		$row = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE id=%d", absint( $request->get_param( 'id' ) ) ), ARRAY_A );
		if ( ! $row ) return new WP_Error( 'ajcore_invoice_not_found', __( 'Invoice not found.', 'ajforms' ), array( 'status' => 404 ) );
		return rest_ensure_response( array( 'invoice' => $this->normalize_expense_invoice_rows( array( $row ) )[0] ) );
	}

	public function update_ops_expense_invoice( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$data = $this->expense_invoice_data( $request );
		if ( '' === $data['title'] || $data['amount'] <= 0 ) return new WP_Error( 'ajcore_invoice_required', __( 'Title and an amount greater than zero are required.', 'ajforms' ), array( 'status' => 400 ) );
		if ( false === $pdb->update( $this->expense_invoice_table(), $data, array( 'id' => absint( $request->get_param( 'id' ) ) ) ) ) return new WP_Error( 'ajcore_invoice_update_failed', $pdb->last_error ?: __( 'The invoice could not be updated.', 'ajforms' ), array( 'status' => 500 ) );
		return $this->get_ops_expense_invoice( $request );
	}

	public function delete_ops_expense_invoice( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$table = $this->expense_invoice_table();
		$id = absint( $request->get_param( 'id' ) );
		$attachments = json_decode( (string) $pdb->get_var( $pdb->prepare( "SELECT attachments FROM `{$table}` WHERE id=%d", $id ) ), true );
		$result = $pdb->delete( $table, array( 'id' => $id ) );
		if ( false === $result ) return new WP_Error( 'ajcore_invoice_delete_failed', $pdb->last_error ?: __( 'The invoice could not be deleted.', 'ajforms' ), array( 'status' => 500 ) );
		foreach ( (array) $attachments as $attachment ) {
			$attachment_id = absint( $attachment['id'] ?? 0 );
			if ( ! $attachment_id ) continue;
			if ( class_exists( 'AJCore_Storage_Service' ) ) AJCore_Storage_Service::delete_attachment_storage( $attachment_id );
			wp_delete_attachment( $attachment_id, true );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function get_ops_expense_invoice_options() {
		// Units and vendors come from property-management extensions (AJCore-RA supplies Rentec's).
		$options = apply_filters( 'ajcore_expense_invoice_options', array( 'units' => array(), 'vendors' => array() ) );
		return rest_ensure_response( array( 'units' => array_values( (array) ( $options['units'] ?? array() ) ), 'vendors' => array_values( (array) ( $options['vendors'] ?? array() ) ) ) );
	}

	public function upload_ops_expense_invoice_attachment( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$table = $this->expense_invoice_table();
		$id = absint( $request->get_param( 'id' ) );
		$row = $pdb->get_row( $pdb->prepare( "SELECT attachments FROM `{$table}` WHERE id=%d", $id ), ARRAY_A );
		if ( ! $row ) return new WP_Error( 'ajcore_invoice_not_found', __( 'Invoice not found.', 'ajforms' ), array( 'status' => 404 ) );
		$file_params = $request->get_file_params();
		$file = $file_params['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) return new WP_Error( 'ajcore_invoice_file_required', __( 'Choose a file to upload.', 'ajforms' ), array( 'status' => 400 ) );
		if ( (int) ( $file['size'] ?? 0 ) > 20 * MB_IN_BYTES ) return new WP_Error( 'ajcore_invoice_file_too_large', __( 'Invoice attachments must be 20 MB or smaller.', 'ajforms' ), array( 'status' => 413 ) );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );
		if ( ! empty( $uploaded['error'] ) ) return new WP_Error( 'ajcore_invoice_upload_failed', $uploaded['error'], array( 'status' => 400 ) );
		$attachment_id = wp_insert_attachment( array(
			'post_title' => sanitize_text_field( pathinfo( (string) $file['name'], PATHINFO_FILENAME ) ),
			'post_status' => 'inherit',
			'post_mime_type' => $uploaded['type'],
		), $uploaded['file'], 0, true );
		if ( is_wp_error( $attachment_id ) ) return $attachment_id;
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
		if ( ! class_exists( 'AJCore_Storage_Service' ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'ajcore_storage_unavailable', __( 'RustFS storage is unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$migrated = AJCore_Storage_Service::migrate_attachment_ids( array( $attachment_id ) );
		if ( ! empty( $migrated['failed'] ) || ! AJCore_Storage_Service::get_remote_record( $attachment_id ) ) {
			$message = ! empty( $migrated['failed'][ $attachment_id ] ) ? $migrated['failed'][ $attachment_id ] : __( 'The file could not be stored in RustFS.', 'ajforms' );
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'ajcore_invoice_storage_failed', $message, array( 'status' => 502 ) );
		}
		$attachments = json_decode( (string) $row['attachments'], true );
		if ( ! is_array( $attachments ) ) $attachments = array();
		$attachments[] = array( 'id' => (int) $attachment_id, 'name' => sanitize_file_name( (string) $file['name'] ), 'mime' => sanitize_mime_type( (string) $uploaded['type'] ), 'size' => absint( $file['size'] ) );
		if ( false === $pdb->update( $table, array( 'attachments' => wp_json_encode( $attachments ) ), array( 'id' => $id ) ) ) return new WP_Error( 'ajcore_invoice_attachment_save_failed', $pdb->last_error, array( 'status' => 500 ) );
		return $this->get_ops_expense_invoice( $request );
	}

	private function expense_invoice_has_attachment( $invoice_id, $attachment_id ) {
		$pdb = $this->get_portal_db();
		$table = $this->expense_invoice_table();
		$json = $pdb->get_var( $pdb->prepare( "SELECT attachments FROM `{$table}` WHERE id=%d", $invoice_id ) );
		foreach ( (array) json_decode( (string) $json, true ) as $attachment ) if ( absint( $attachment['id'] ?? 0 ) === $attachment_id ) return true;
		return false;
	}

	public function get_ops_expense_invoice_attachment_url( WP_REST_Request $request ) {
		$invoice_id = absint( $request->get_param( 'id' ) );
		$attachment_id = absint( $request->get_param( 'attachment_id' ) );
		if ( ! $this->expense_invoice_has_attachment( $invoice_id, $attachment_id ) ) return new WP_Error( 'ajcore_invoice_attachment_not_found', __( 'Invoice attachment not found.', 'ajforms' ), array( 'status' => 404 ) );
		$url = class_exists( 'AJCore_Storage_Service' ) ? AJCore_Storage_Service::get_presigned_download_url( $attachment_id, 300 ) : false;
		if ( ! $url ) return new WP_Error( 'ajcore_invoice_attachment_unavailable', __( 'The RustFS attachment is unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		return rest_ensure_response( array( 'url' => $url ) );
	}

	public function delete_ops_expense_invoice_attachment( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$table = $this->expense_invoice_table();
		$invoice_id = absint( $request->get_param( 'id' ) );
		$attachment_id = absint( $request->get_param( 'attachment_id' ) );
		$row = $pdb->get_row( $pdb->prepare( "SELECT attachments FROM `{$table}` WHERE id=%d", $invoice_id ), ARRAY_A );
		if ( ! $row ) return new WP_Error( 'ajcore_invoice_not_found', __( 'Invoice not found.', 'ajforms' ), array( 'status' => 404 ) );
		$attachments = array_values( array_filter( (array) json_decode( (string) $row['attachments'], true ), static function ( $attachment ) use ( $attachment_id ) { return absint( $attachment['id'] ?? 0 ) !== $attachment_id; } ) );
		if ( false === $pdb->update( $table, array( 'attachments' => wp_json_encode( $attachments ) ), array( 'id' => $invoice_id ) ) ) return new WP_Error( 'ajcore_invoice_attachment_delete_failed', $pdb->last_error, array( 'status' => 500 ) );
		if ( class_exists( 'AJCore_Storage_Service' ) ) AJCore_Storage_Service::delete_attachment_storage( $attachment_id );
		wp_delete_attachment( $attachment_id, true );
		return rest_ensure_response( array( 'success' => true ) );
	}
}
