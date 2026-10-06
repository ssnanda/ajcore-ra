<?php
/**
 * REST routes (ajcore/v1, ops login) so AJOps can manage customer websites and business profiles.
 * Same data and rules as the AJCore admin pages: they call the same methods.
 *
 *   GET  /ops/websites                         all customer websites
 *   POST /ops/websites                         add or update one (customer_id, subdomain, status, note, label)
 *   POST /ops/websites/remove                  unlink one (customer_id, host)
 *   GET  /ops/business-profiles                customers with a profile (completeness, needs review)
 *   GET  /ops/business-profiles/{customer_id}  one profile: fields, values, files, history, brief
 *   POST /ops/business-profiles/{customer_id}  staff edit of the text fields (values: {...})
 *   POST /ops/business-profiles/{customer_id}/reviewed   mark "website is up to date"
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Websites_API {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog' ) );
	}

	public static function catalog( $catalog ) {
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/websites', 'auth' => 'Admin', 'purpose' => 'Customer websites (address, status, note).', 'app' => 'OPS websites' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/business-profiles', 'auth' => 'Admin', 'purpose' => 'Customers with a business profile; GET /ops/business-profiles/{customer_id} for one, with history.', 'app' => 'OPS business profiles' );
		return $catalog;
	}

	public static function register_routes() {
		$ns  = AJCore_Extensions::rest_namespace();
		$ops = array( 'AJCore_Extensions', 'can_manage_ops' );
		$id  = '(?P<customer_id>[A-Za-z0-9_\-]+)';

		register_rest_route( $ns, '/ops/websites', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'list_websites' ), 'permission_callback' => $ops ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'save_website' ), 'permission_callback' => $ops ),
		) );
		register_rest_route( $ns, '/ops/websites/remove', array(
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'remove_website' ), 'permission_callback' => $ops ),
		) );
		register_rest_route( $ns, '/ops/business-profiles', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'list_profiles' ), 'permission_callback' => $ops ),
		) );
		register_rest_route( $ns, '/ops/business-profiles/' . $id, array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_profile' ), 'permission_callback' => $ops ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'save_profile' ), 'permission_callback' => $ops ),
		) );
		register_rest_route( $ns, '/ops/business-profiles/' . $id . '/reviewed', array(
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'mark_reviewed' ), 'permission_callback' => $ops ),
		) );
	}

	private static function who() {
		$u = wp_get_current_user();
		return 'staff: ' . ( $u && $u->user_login ? $u->user_login : 'ops' );
	}

	public static function list_websites() {
		$customers = array();
		foreach ( AJCore_RA_Website::customers() as $cid => $c ) {
			$customers[] = array( 'customer_id' => $cid, 'name' => $c['name'], 'business' => $c['business'], 'suggestion' => AJCore_RA_Website::suggest_subdomain( '' !== $c['business'] ? $c['business'] : $c['name'] ) );
		}
		$sites = array();
		foreach ( AJCore_RA_Website::list_sites() as $s ) {
			$sites[] = array( 'customer_id' => $s['customer'], 'customer_name' => $s['customer_name'], 'host' => $s['key'], 'label' => $s['label'], 'status' => $s['status'], 'note' => $s['note'] );
		}
		$statuses = array();
		foreach ( AJCore_RA_Website::statuses_public() as $k => $v ) {
			$statuses[] = array( 'key' => $k, 'label' => $v );
		}
		return rest_ensure_response( array( 'sites' => $sites, 'customers' => $customers, 'statuses' => $statuses, 'base_domain' => AJCore_RA_Website::base_domain() ) );
	}

	public static function save_website( WP_REST_Request $request ) {
		$r = AJCore_RA_Website::save_site(
			sanitize_text_field( (string) $request->get_param( 'customer_id' ) ),
			(string) $request->get_param( 'subdomain' ),
			sanitize_key( (string) $request->get_param( 'status' ) ),
			(string) $request->get_param( 'note' ),
			(string) $request->get_param( 'label' )
		);
		if ( ! $r['ok'] ) {
			return new WP_Error( 'bad_request', $r['message'], array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'host' => $r['host'], 'message' => $r['message'] ) );
	}

	public static function remove_website( WP_REST_Request $request ) {
		AJCore_RA_Website::remove_site( sanitize_text_field( (string) $request->get_param( 'customer_id' ) ), sanitize_text_field( (string) $request->get_param( 'host' ) ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function list_profiles() {
		return rest_ensure_response( array( 'profiles' => AJCore_RA_Business_Profile::api_list() ) );
	}

	public static function get_profile( WP_REST_Request $request ) {
		return rest_ensure_response( AJCore_RA_Business_Profile::api_profile( sanitize_text_field( (string) $request['customer_id'] ) ) );
	}

	public static function save_profile( WP_REST_Request $request ) {
		$customer = sanitize_text_field( (string) $request['customer_id'] );
		$values   = $request->get_param( 'values' );
		if ( ! is_array( $values ) ) {
			return new WP_Error( 'bad_request', 'values is required.', array( 'status' => 400 ) );
		}
		$r = AJCore_RA_Business_Profile::save( $customer, $values, self::who() );
		if ( ! $r['ok'] ) {
			return new WP_Error( 'save_failed', 'The profile could not be saved.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array_merge( array( 'success' => true, 'changed' => $r['changed'] ), array( 'profile' => AJCore_RA_Business_Profile::api_profile( $customer ) ) ) );
	}

	public static function mark_reviewed( WP_REST_Request $request ) {
		$customer = sanitize_text_field( (string) $request['customer_id'] );
		if ( ! AJCore_RA_Business_Profile::mark_reviewed( $customer, self::who() ) ) {
			return new WP_Error( 'not_found', 'No profile for this customer.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'profile' => AJCore_RA_Business_Profile::api_profile( $customer ) ) );
	}
}
