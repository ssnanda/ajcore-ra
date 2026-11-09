<?php
/**
 * Front desk: visitor check-in for the AJ Front Desk iPad app (ported from the NAC Insiders core reception API).
 *
 * Kiosk endpoints (device key in X-AJCore-Device-Key, or staff login):
 *   GET  /reception/ping                 checks the key; returns the site name
 *   GET  /reception/hosts                everyone a visitor can ask for (staff + Suite Clients)
 *   GET  /reception/hosts/search?q=      same, filtered
 *   POST /reception/lookup-by-phone      returning visitor
 *   POST /reception/check-in             logs the visit and emails the host
 * Staff endpoints (login only):
 *   GET  /ops/reception/visits           recent visits
 *   POST /ops/reception/visits/{id}/check-out
 *
 * Tables live in the site's own database. Hook 'ajcore_ra_reception_checked_in' ( $visit, $guest, $host ) fires after
 * each check-in: AJPhone texting plugs in there.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Reception {

	const TAB        = 'ra-reception';
	const DB_VERSION = '4'; // 2: tables moved to the portal (shared) DB; 3: visits.notified_at + visit_notes table; 4: visits.visit_code
	const DEVICES    = 'ajcore_ra_reception_devices';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ), 9 );
		add_filter( 'ajcore_ops_counts', array( __CLASS__, 'filter_counts' ) );
		add_action( 'ajcore_admin_portal_tab_render', array( __CLASS__, 'render_admin_tab' ) );
		add_action( 'admin_post_ajcore_ra_reception_checkout', array( __CLASS__, 'handle_checkout' ) );
		add_action( 'admin_post_ajcore_ra_reception_device_add', array( __CLASS__, 'handle_device_add' ) );
		add_action( 'admin_post_ajcore_ra_reception_device_revoke', array( __CLASS__, 'handle_device_revoke' ) );
	}

	private static function t( $name ) {
		return self::db()->prefix . 'aj_ra_reception_' . $name;
	}

	/** Reception data lives in the portal DB: the shared DB when sharing is on, this site's DB otherwise. */
	private static function db() {
		$kit = AJCore_Extensions::rest_toolkit();
		$db  = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
		if ( $db ) {
			return $db;
		}
		global $wpdb;
		return $wpdb;
	}

	public static function maybe_install() {
		if ( get_option( 'ajcore_ra_reception_db' ) === self::DB_VERSION ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// dbDelta writes through the global $wpdb, so point it at the portal DB for the call.
		global $wpdb;
		$original = $wpdb;
		$wpdb     = self::db(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		try {
			self::create_tables( $wpdb );
		} finally {
			$wpdb = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
		update_option( 'ajcore_ra_reception_db', self::DB_VERSION );
	}

	private static function create_tables( $wpdb ) {
		$cc = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t( 'guests' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(32) NOT NULL,
			full_name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY phone (phone)
		) {$cc};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'visits' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			guest_id bigint(20) unsigned NOT NULL,
			host_type varchar(16) NOT NULL DEFAULT '',
			host_id varchar(64) NOT NULL DEFAULT '',
			host_label varchar(190) NOT NULL DEFAULT '',
			visit_type varchar(32) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'checked_in',
			notes text NULL,
			check_in_at datetime NOT NULL,
			check_out_at datetime NULL,
			notified_at datetime NULL,
			visit_code varchar(24) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY guest_id (guest_id),
			KEY check_in_at (check_in_at),
			KEY visit_code (visit_code)
		) {$cc};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'visit_notes' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visit_id bigint(20) unsigned NOT NULL,
			author varchar(190) NOT NULL DEFAULT '',
			note text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY visit_id (visit_id)
		) {$cc};" );
	}

	/**
	 * True when the guests + visits tables exist in the portal DB. Checks the DB itself (not only the version
	 * option, which can say "installed" while the tables are missing) and creates them when absent.
	 * On failure the DB error is kept in self::$last_error so REST callers can report it.
	 */
	private static $last_error = '';

	private static function ensure_tables() {
		static $ok = null;
		if ( null !== $ok ) {
			return $ok;
		}
		$db  = self::db();
		$has = static function () use ( $db ) {
			foreach ( array( 'guests', 'visits', 'visit_notes' ) as $n ) {
				$t = self::t( $n );
				if ( $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
					return false;
				}
			}
			return true;
		};
		if ( $has() ) {
			return $ok = true;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$original = $wpdb;
		$wpdb     = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		try {
			self::create_tables( $wpdb );
		} finally {
			$wpdb = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
		$ok = $has();
		if ( $ok ) {
			update_option( 'ajcore_ra_reception_db', self::DB_VERSION );
		} else {
			self::$last_error = ( $db->last_error ? $db->last_error : 'tables could not be created' ) . ' (db ' . $db->dbname . ', prefix ' . $db->prefix . ')';
		}
		return $ok;
	}

	private static function tables_error() {
		return new WP_Error( 'reception_tables', 'Reception tables are not available: ' . self::$last_error, array( 'status' => 500 ) );
	}

	// ------------------------------------------------------------------ device keys

	/** @return array<int,array{id:string,name:string,hash:string,created:string,last_seen:string}> */
	private static function devices() {
		$d = get_option( self::DEVICES, array() );
		return is_array( $d ) ? $d : array();
	}

	private static function device_for_request( WP_REST_Request $request ) {
		$key = trim( (string) $request->get_header( 'X-AJCore-Device-Key' ) );
		if ( '' === $key ) {
			return null;
		}
		$hash = hash( 'sha256', $key );
		foreach ( self::devices() as $d ) {
			if ( hash_equals( (string) $d['hash'], $hash ) ) {
				return $d;
			}
		}
		return null;
	}

	/** permission_callback for kiosk routes: a valid device key, or a staff login. */
	public static function can_kiosk( WP_REST_Request $request ) {
		if ( self::device_for_request( $request ) ) {
			return true;
		}
		return (bool) AJCore_Extensions::can_manage_ops();
	}

	private static function touch_device( $id ) {
		$devices = self::devices();
		foreach ( $devices as &$d ) {
			if ( $d['id'] === $id && ( empty( $d['last_seen'] ) || time() - strtotime( $d['last_seen'] . ' UTC' ) > 300 ) ) {
				$d['last_seen'] = gmdate( 'Y-m-d H:i:s' );
				update_option( self::DEVICES, $devices, false );
				break;
			}
		}
	}

	// ------------------------------------------------------------------ REST

	public static function register_routes() {
		$ns    = AJCore_Extensions::rest_namespace();
		$kiosk = array( __CLASS__, 'can_kiosk' );
		$ops   = array( 'AJCore_Extensions', 'can_manage_ops' );
		register_rest_route( $ns, '/reception/ping', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'ping' ), 'permission_callback' => $kiosk ) );
		register_rest_route( $ns, '/reception/hosts', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'hosts' ), 'permission_callback' => $kiosk ) );
		register_rest_route( $ns, '/reception/hosts/search', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'hosts_search' ), 'permission_callback' => $kiosk ) );
		register_rest_route( $ns, '/reception/lookup-by-phone', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'lookup' ), 'permission_callback' => $kiosk ) );
		register_rest_route( $ns, '/reception/check-in', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'check_in' ), 'permission_callback' => $kiosk ) );
		register_rest_route( $ns, '/ops/reception/visits', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'visits' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/reception/devices', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'rest_devices' ), 'permission_callback' => $ops ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_device_add' ), 'permission_callback' => $ops ),
		) );
		register_rest_route( $ns, '/ops/reception/devices/(?P<id>[A-Za-z0-9]+)/revoke', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_device_revoke' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/reception/visits/(?P<id>\d+)/update', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_update_visit' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/reception/visits/(?P<id>\d+)/delete', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_delete_visit' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/reception/visits/(?P<id>\d+)/notes/(?P<note_id>\d+)/update', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_note_update' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/reception/visits/(?P<id>\d+)/notes/(?P<note_id>\d+)/delete', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_note_delete' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/reception/visits/(?P<id>\d+)/notify-info', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'rest_notify_info' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/reception/visits/(?P<id>\d+)/notified', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_mark_notified' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/reception/visits/(?P<id>\d+)/notes', array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( __CLASS__, 'rest_notes' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/reception/visits/(?P<id>\d+)/check-out', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_check_out' ), 'permission_callback' => $ops ) );
	}

	public static function ping( WP_REST_Request $request ) {
		$d = self::device_for_request( $request );
		if ( $d ) {
			self::touch_device( $d['id'] );
		}
		return rest_ensure_response( array( 'ok' => true, 'site_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 'device_name' => $d ? $d['name'] : '' ) );
	}

	private static function phone_digits( $phone ) {
		return preg_replace( '/\D+/', '', (string) $phone );
	}

	/** @return array<int,array{id:string,host_type:string,label:string,subtitle:string,email:string}> */
	private static function all_hosts( $q = '' ) {
		$q     = trim( (string) $q );
		$hosts = array();

		// Staff: administrators, the AJOps role, or anyone with ops access.
		$users = array();
		foreach ( get_users( array( 'role__in' => array( 'administrator', 'aj_ops_user' ) ) ) as $u ) {
			$users[ $u->ID ] = $u;
		}
		foreach ( get_users( array( 'meta_key' => 'ajcore_ops_access', 'meta_value' => '1' ) ) as $u ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$users[ $u->ID ] = $u;
		}
		foreach ( $users as $u ) {
			$label = '' !== trim( (string) $u->display_name ) ? $u->display_name : $u->user_login;
			$hosts[] = array( 'id' => (string) $u->ID, 'host_type' => 'staff', 'label' => $label, 'subtitle' => '', 'email' => $u->user_email, 'phone' => (string) get_user_meta( $u->ID, 'phone', true ) );
		}

		// Customers a visitor can ask for: Suite Clients only (local customers marked customer_kind = suite_client by the
		// Rentec Suite Clients sync). Mailbox (PMB) customers are deliberately excluded.
		$kit = AJCore_Extensions::rest_toolkit();
		$db  = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
		if ( $db ) {
			$lt = $kit['portal_table']( 'aj_portal_local_customers' );
			if ( $kit['table_exists']( $db, $lt ) ) {
				foreach ( (array) $db->get_results( "SELECT local_customer_id AS id, name, email, phone FROM `{$lt}` WHERE metadata LIKE '%\"customer_kind\":\"suite_client\"%' AND (status IS NULL OR status <> 'archived')" ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
					$hosts[] = array( 'id' => (string) $c->id, 'host_type' => 'customer', 'label' => '' !== trim( (string) $c->name ) ? (string) $c->name : (string) $c->email, 'subtitle' => '', 'email' => (string) $c->email, 'phone' => (string) $c->phone );
				}
			}
		}

		if ( '' !== $q ) {
			$needle = strtolower( $q );
			$hosts  = array_values( array_filter( $hosts, static function ( $h ) use ( $needle ) {
				return false !== strpos( strtolower( $h['label'] ), $needle );
			} ) );
		}
		usort( $hosts, static function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		} );
		return $hosts;
	}

	private static function public_host( $h ) {
		// The kiosk gets names only: never an email, phone, PMB or anything else about a customer or staff member.
		return array( 'id' => $h['id'], 'host_type' => $h['host_type'], 'label' => $h['label'], 'subtitle' => '' );
	}

	public static function hosts() {
		return rest_ensure_response( array( 'hosts' => array_map( array( __CLASS__, 'public_host' ), array_slice( self::all_hosts(), 0, 500 ) ) ) );
	}

	public static function hosts_search( WP_REST_Request $request ) {
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		return rest_ensure_response( array( 'query' => $q, 'results' => array_map( array( __CLASS__, 'public_host' ), array_slice( self::all_hosts( $q ), 0, 10 ) ) ) );
	}

	private static function guest_row( $g ) {
		return array( 'id' => (int) $g->id, 'full_name' => (string) $g->full_name, 'phone' => (string) $g->phone, 'email' => (string) $g->email, 'notes' => (string) $g->notes );
	}

	private static function visit_row( $v, $guest = null ) {
		return array(
			'id'           => (int) $v->id,
			'code'         => ! empty( $v->visit_code ) ? (string) $v->visit_code : sprintf( 'G-%s-%03d', str_replace( '-', '', substr( (string) $v->check_in_at, 0, 10 ) ), (int) $v->id ),
			'guest_id'     => (int) $v->guest_id,
			'guest'        => $guest ? self::guest_row( $guest ) : null,
			'host_type'    => (string) $v->host_type,
			'host_id'      => (string) $v->host_id,
			'host_id'      => (string) $v->host_id,
			'host_label'   => (string) $v->host_label,
			'visit_type'   => (string) $v->visit_type,
			'status'       => (string) $v->status,
			'notes'        => (string) $v->notes,
			'check_in_at'  => (string) $v->check_in_at,
			'check_out_at' => $v->check_out_at ? (string) $v->check_out_at : null,
			'notified_at'  => ! empty( $v->notified_at ) ? (string) $v->notified_at : null,
		);
	}

	public static function lookup( WP_REST_Request $request ) {
		if ( ! self::ensure_tables() ) {
			return self::tables_error();
		}
		$wpdb = self::db();
		$phone = self::phone_digits( $request->get_param( 'phone' ) );
		if ( '' === $phone ) {
			return new WP_Error( 'bad_request', 'Phone number is required.', array( 'status' => 422 ) );
		}
		$g = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'guests' ) . ' WHERE phone = %s', $phone ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $g ) {
			return rest_ensure_response( array( 'found' => false ) );
		}
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'visits' ) . ' WHERE guest_id = %d ORDER BY check_in_at DESC, id DESC LIMIT 1', $g->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		// The kiosk only gets what is needed to greet a returning visitor: name and last host. No email, no notes.
		$guest = array( 'id' => (int) $g->id, 'full_name' => (string) $g->full_name, 'phone' => (string) $g->phone, 'email' => '', 'notes' => '' );
		$last  = $v ? array_intersect_key( self::visit_row( $v ), array_flip( array( 'id', 'host_type', 'host_id', 'host_label', 'visit_type', 'check_in_at' ) ) ) : null;
		return rest_ensure_response( array( 'found' => true, 'guest' => $guest, 'latest_visit' => $last ) );
	}

	public static function check_in( WP_REST_Request $request ) {
		if ( ! self::ensure_tables() ) {
			return self::tables_error();
		}
		$wpdb = self::db();
		$phone = self::phone_digits( $request->get_param( 'phone' ) );
		$name  = trim( sanitize_text_field( (string) $request->get_param( 'full_name' ) ) );
		$type  = sanitize_key( (string) $request->get_param( 'visit_type' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$notes = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		if ( '' === $phone ) {
			return new WP_Error( 'bad_request', 'Phone number is required.', array( 'status' => 422 ) );
		}
		if ( '' === $name ) {
			return new WP_Error( 'bad_request', 'Full name is required.', array( 'status' => 422 ) );
		}
		if ( '' === $type ) {
			return new WP_Error( 'bad_request', 'Visit type is required.', array( 'status' => 422 ) );
		}
		$host_type = sanitize_key( (string) $request->get_param( 'host_type' ) );
		$host_id   = sanitize_text_field( (string) $request->get_param( 'host_id' ) );
		$host      = null;
		foreach ( self::all_hosts() as $h ) {
			if ( $h['host_type'] === $host_type && (string) $h['id'] === $host_id ) {
				$host = $h;
				break;
			}
		}
		// A tour has no host: the visit just captures the prospect's details (email/company/interest go in the note).
		if ( ! $host && 'tour' === $type ) {
			$host = array( 'id' => '', 'host_type' => 'tour', 'label' => 'Tour', 'email' => '', 'phone' => '' );
		}
		if ( ! $host ) {
			return new WP_Error( 'bad_request', 'Choose who you are here to see.', array( 'status' => 422 ) );
		}

		$now = current_time( 'mysql' );
		$g   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'guests' ) . ' WHERE phone = %s', $phone ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $g ) {
			$wpdb->insert( self::t( 'guests' ), array( 'phone' => $phone, 'full_name' => $name, 'email' => $email, 'notes' => $notes, 'created_at' => $now, 'updated_at' => $now ) );
			$gid = (int) $wpdb->insert_id;
		} else {
			$gid = (int) $g->id;
			$wpdb->update( self::t( 'guests' ), array( 'full_name' => $name, 'email' => '' !== $email ? $email : $g->email, 'notes' => '' !== $notes ? $notes : $g->notes, 'updated_at' => $now ), array( 'id' => $gid ) );
		}
		// Guest ID: G-YYYYMMDD-NNN = the check-in date plus that day's running number.
		$day  = substr( $now, 0, 10 );
		$seq  = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'visits' ) . ' WHERE check_in_at >= %s AND check_in_at <= %s', $day . ' 00:00:00', $day . ' 23:59:59' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$code = sprintf( 'G-%s-%03d', str_replace( '-', '', $day ), $seq );
		$wpdb->insert( self::t( 'visits' ), array( 'visit_code' => $code, 'guest_id' => $gid, 'host_type' => $host['host_type'], 'host_id' => $host['id'], 'host_label' => $host['label'], 'visit_type' => $type, 'status' => 'checked_in', 'notes' => $notes, 'check_in_at' => $now ) );
		$vid   = (int) $wpdb->insert_id;
		if ( $vid < 1 ) {
			// Never tell the iPad "Checked in" when nothing was saved (e.g. tables missing).
			return new WP_Error( 'save_failed', 'Could not save the visit: ' . ( $wpdb->last_error ? $wpdb->last_error : 'unknown database error' ), array( 'status' => 500 ) );
		}
		$guest = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'guests' ) . ' WHERE id = %d', $gid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$visit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'visits' ) . ' WHERE id = %d', $vid ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		// No arrival email: staff notify the client by text (Guest Log > Notify client in AJOps).
		do_action( 'ajcore_ra_reception_checked_in', $visit, $guest, $host );

		return new WP_REST_Response( array_merge( self::visit_row( $visit, $guest ), array( 'message' => 'Checked in.' ) ), 201 );
	}

	public static function visits( WP_REST_Request $request ) {
		if ( ! self::ensure_tables() ) {
			return self::tables_error();
		}
		$wpdb = self::db();
		$limit  = min( 500, max( 1, absint( $request->get_param( 'limit' ) ?: 100 ) ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$where  = '' !== $status ? $wpdb->prepare( 'WHERE v.status = %s', $status ) : '';
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT v.*, g.full_name, g.phone, g.email, g.notes AS guest_notes FROM ' . self::t( 'visits' ) . ' v JOIN ' . self::t( 'guests' ) . " g ON g.id = v.guest_id {$where} ORDER BY v.check_in_at DESC, v.id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out    = array();
		foreach ( $rows as $r ) {
			$guest = (object) array( 'id' => $r->guest_id, 'full_name' => $r->full_name, 'phone' => $r->phone, 'email' => $r->email, 'notes' => $r->guest_notes );
			$out[] = self::visit_row( $r, $guest );
		}
		return rest_ensure_response( array( 'visits' => $out ) );
	}

	private static function do_check_out( $id ) {
		$wpdb = self::db();
		// Still on site = checked in, or checked in and the client has been notified.
		return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'visits' ) . " SET status = 'checked_out', check_out_at = %s WHERE id = %d AND status IN ('checked_in','notified')", current_time( 'mysql' ), (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function get_visit( $id ) {
		$wpdb = self::db();
		return $wpdb->get_row( $wpdb->prepare( 'SELECT v.*, g.full_name, g.phone, g.email, g.notes AS guest_notes FROM ' . self::t( 'visits' ) . ' v JOIN ' . self::t( 'guests' ) . ' g ON g.id = v.guest_id WHERE v.id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Who to text for a visit: the host's phone (Suite Client or staff) plus the wording pieces AJOps needs. */
	public static function rest_notify_info( WP_REST_Request $request ) {
		if ( ! self::ensure_tables() ) {
			return self::tables_error();
		}
		$v = self::get_visit( (int) $request['id'] );
		if ( ! $v ) {
			return new WP_Error( 'not_found', 'Visit not found.', array( 'status' => 404 ) );
		}
		$phone = '';
		foreach ( self::all_hosts() as $h ) {
			if ( $h['host_type'] === $v->host_type && (string) $h['id'] === (string) $v->host_id ) {
				$phone = (string) ( $h['phone'] ?? '' );
				break;
			}
		}
		return rest_ensure_response( array(
			'phone'      => $phone,
			'guest_phone' => (string) $v->phone,
			'host_label' => (string) $v->host_label,
			'guest_name' => (string) $v->full_name,
			'site_name'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'status'     => (string) $v->status,
		) );
	}

	/** Called by AJOps after the text was sent: status becomes 'notified' (only from checked_in) and a note is logged. */
	public static function rest_mark_notified( WP_REST_Request $request ) {
		if ( ! self::ensure_tables() ) {
			return self::tables_error();
		}
		$wpdb = self::db();
		$id   = (int) $request['id'];
		$now  = current_time( 'mysql' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'visits' ) . " SET status = 'notified', notified_at = %s WHERE id = %d AND status = 'checked_in'", $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		self::add_note( $id, 'Client notified by text' . ( '' !== (string) $request->get_param( 'to' ) ? ' (' . sanitize_text_field( (string) $request->get_param( 'to' ) ) . ')' : '' ) . '.', 'System' );
		return rest_ensure_response( array( 'success' => true, 'notified_at' => $now ) );
	}

	/** Staff edit of a visit: the guest's name/phone/email (shared by all that guest's visits) and this visit's type. */
	public static function rest_update_visit( WP_REST_Request $request ) {
		if ( ! self::ensure_tables() ) {
			return self::tables_error();
		}
		$wpdb = self::db();
		$v    = self::get_visit( (int) $request['id'] );
		if ( ! $v ) {
			return new WP_Error( 'not_found', 'Visit not found.', array( 'status' => 404 ) );
		}
		$name  = trim( sanitize_text_field( (string) $request->get_param( 'full_name' ) ) );
		$phone = self::phone_digits( $request->get_param( 'phone' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$type  = sanitize_key( (string) $request->get_param( 'visit_type' ) );
		if ( '' === $name || '' === $phone ) {
			return new WP_Error( 'bad_request', 'Name and phone are required.', array( 'status' => 422 ) );
		}
		$taken = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'guests' ) . ' WHERE phone = %s AND id <> %d', $phone, (int) $v->guest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $taken ) {
			return new WP_Error( 'conflict', 'Another guest already uses that phone number.', array( 'status' => 409 ) );
		}
		// Optional new host ("Here to see"): a staff member / Suite Client from the host list, or 'tour' (no host).
		$host_type = sanitize_key( (string) $request->get_param( 'host_type' ) );
		$host_id   = sanitize_text_field( (string) $request->get_param( 'host_id' ) );
		$host      = null;
		if ( 'tour' === $host_type ) {
			$host = array( 'host_type' => 'tour', 'id' => '', 'label' => 'Tour' );
		} elseif ( '' !== $host_type ) {
			foreach ( self::all_hosts() as $h ) {
				if ( $h['host_type'] === $host_type && (string) $h['id'] === $host_id ) {
					$host = $h;
					break;
				}
			}
			if ( ! $host ) {
				return new WP_Error( 'bad_request', 'That host was not found.', array( 'status' => 422 ) );
			}
		}
		$wpdb->update( self::t( 'guests' ), array( 'full_name' => $name, 'phone' => $phone, 'email' => $email, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $v->guest_id ) );
		$visit_changes = '' !== $type ? array( 'visit_type' => $type ) : array();
		if ( $host ) {
			$visit_changes += array( 'host_type' => $host['host_type'], 'host_id' => (string) $host['id'], 'host_label' => $host['label'] );
		}
		if ( $visit_changes ) {
			$wpdb->update( self::t( 'visits' ), $visit_changes, array( 'id' => (int) $v->id ) );
		}
		$v     = self::get_visit( (int) $v->id );
		$guest = (object) array( 'id' => $v->guest_id, 'full_name' => $v->full_name, 'phone' => $v->phone, 'email' => $v->email, 'notes' => $v->guest_notes );
		return rest_ensure_response( self::visit_row( $v, $guest ) );
	}

	/** Deletes a visit and its notes; the guest record goes too when it has no other visits. */
	public static function rest_delete_visit( WP_REST_Request $request ) {
		if ( ! self::ensure_tables() ) {
			return self::tables_error();
		}
		$wpdb = self::db();
		$v    = self::get_visit( (int) $request['id'] );
		if ( ! $v ) {
			return new WP_Error( 'not_found', 'Visit not found.', array( 'status' => 404 ) );
		}
		$wpdb->delete( self::t( 'visit_notes' ), array( 'visit_id' => (int) $v->id ) );
		$wpdb->delete( self::t( 'visits' ), array( 'id' => (int) $v->id ) );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'visits' ) . ' WHERE guest_id = %d', (int) $v->guest_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->delete( self::t( 'guests' ), array( 'id' => (int) $v->guest_id ) );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function rest_note_update( WP_REST_Request $request ) {
		if ( ! self::ensure_tables() ) {
			return self::tables_error();
		}
		$wpdb = self::db();
		$note = trim( sanitize_textarea_field( (string) $request->get_param( 'note' ) ) );
		if ( '' === $note ) {
			return new WP_Error( 'bad_request', 'Note is empty.', array( 'status' => 422 ) );
		}
		$wpdb->update( self::t( 'visit_notes' ), array( 'note' => $note ), array( 'id' => (int) $request['note_id'], 'visit_id' => (int) $request['id'] ) );
		return self::notes_response( (int) $request['id'] );
	}

	public static function rest_note_delete( WP_REST_Request $request ) {
		if ( ! self::ensure_tables() ) {
			return self::tables_error();
		}
		self::db()->delete( self::t( 'visit_notes' ), array( 'id' => (int) $request['note_id'], 'visit_id' => (int) $request['id'] ) );
		return self::notes_response( (int) $request['id'] );
	}

	private static function add_note( $visit_id, $note, $author ) {
		$wpdb = self::db();
		$wpdb->insert( self::t( 'visit_notes' ), array( 'visit_id' => (int) $visit_id, 'author' => $author, 'note' => $note, 'created_at' => current_time( 'mysql' ) ) );
		return (int) $wpdb->insert_id;
	}

	public static function rest_notes( WP_REST_Request $request ) {
		if ( ! self::ensure_tables() ) {
			return self::tables_error();
		}
		$wpdb = self::db();
		$id   = (int) $request['id'];
		if ( WP_REST_Server::CREATABLE === $request->get_method() ) {
			$note = trim( sanitize_textarea_field( (string) $request->get_param( 'note' ) ) );
			if ( '' === $note ) {
				return new WP_Error( 'bad_request', 'Note is empty.', array( 'status' => 422 ) );
			}
			$user   = wp_get_current_user();
			$author = ( $user && $user->exists() ) ? ( '' !== trim( (string) $user->display_name ) ? $user->display_name : $user->user_login ) : 'Staff';
			self::add_note( $id, $note, $author );
		}
		return self::notes_response( $id );
	}

	private static function notes_response( $id ) {
		$wpdb = self::db();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, author, note, created_at FROM ' . self::t( 'visit_notes' ) . ' WHERE visit_id = %d ORDER BY id DESC LIMIT 200', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return rest_ensure_response( array( 'notes' => array_map( static function ( $r ) {
			return array( 'id' => (int) $r->id, 'author' => (string) $r->author, 'note' => (string) $r->note, 'created_at' => (string) $r->created_at );
		}, $rows ) ) );
	}

	public static function rest_check_out( WP_REST_Request $request ) {
		self::do_check_out( (int) $request['id'] );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/** AJOps menu number for Guest Log: visitors who checked in today. */
	public static function filter_counts( $counts ) {
		if ( ! self::ensure_tables() ) {
			return $counts;
		}
		$wpdb = self::db();
		$today = current_time( 'Y-m-d' );
		$n     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'visits' ) . ' WHERE check_in_at >= %s', $today . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$counts['guest-log'] = array( 'value' => $n, 'label' => 'Visitors today', 'alert' => false );
		return $counts;
	}

	public static function rest_devices() {
		$out = array();
		foreach ( self::devices() as $d ) {
			$out[] = array( 'id' => $d['id'], 'name' => $d['name'], 'created' => $d['created'], 'last_seen' => $d['last_seen'] );
		}
		return rest_ensure_response( array( 'devices' => $out ) );
	}

	/** Creates a device key. The key is returned once and only its hash is stored. */
	public static function rest_device_add( WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$name = '' !== $name ? $name : 'Front desk iPad';
		$key  = wp_generate_password( 40, false, false );
		$id   = wp_generate_password( 8, false, false );
		$devices   = self::devices();
		$devices[] = array( 'id' => $id, 'name' => $name, 'hash' => hash( 'sha256', $key ), 'created' => gmdate( 'Y-m-d H:i:s' ), 'last_seen' => '' );
		update_option( self::DEVICES, $devices, false );
		return rest_ensure_response( array( 'id' => $id, 'name' => $name, 'key' => $key ) );
	}

	public static function rest_device_revoke( WP_REST_Request $request ) {
		$id = sanitize_text_field( (string) $request['id'] );
		update_option( self::DEVICES, array_values( array_filter( self::devices(), static function ( $d ) use ( $id ) {
			return $d['id'] !== $id;
		} ) ), false );
		return rest_ensure_response( array( 'success' => true ) );
	}

	// ------------------------------------------------------------------ admin tab (Client Portal > Reception)

	public static function admin_tab( $tabs ) {
		$tabs[ self::TAB ] = array( 'label' => __( 'Reception', 'ajcore-ra' ) );
		return $tabs;
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB ), $args ), admin_url( 'admin.php' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ajcore-ra' ) );
		}
		check_admin_referer( $action );
	}

	public static function handle_checkout() {
		self::guard( 'ajcore_ra_reception_checkout' );
		self::do_check_out( absint( $_POST['visit_id'] ?? 0 ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function handle_device_add() {
		self::guard( 'ajcore_ra_reception_device_add' );
		$name = sanitize_text_field( wp_unslash( $_POST['device_name'] ?? '' ) );
		$name = '' !== $name ? $name : 'Front desk iPad';
		$key  = wp_generate_password( 40, false, false );
		$devices   = self::devices();
		$devices[] = array( 'id' => wp_generate_password( 8, false, false ), 'name' => $name, 'hash' => hash( 'sha256', $key ), 'created' => gmdate( 'Y-m-d H:i:s' ), 'last_seen' => '' );
		update_option( self::DEVICES, $devices, false );
		// Shown once, on the next page load.
		set_transient( 'ajcore_ra_reception_newkey_' . get_current_user_id(), array( 'name' => $name, 'key' => $key ), 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function handle_device_revoke() {
		self::guard( 'ajcore_ra_reception_device_revoke' );
		$id = sanitize_text_field( wp_unslash( $_POST['device_id'] ?? '' ) );
		update_option( self::DEVICES, array_values( array_filter( self::devices(), static function ( $d ) use ( $id ) {
			return $d['id'] !== $id;
		} ) ), false );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function render_admin_tab( $tab ) {
		if ( self::TAB !== $tab || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$wpdb = self::db();
		$labels = array( 'appointment' => 'Appointment', 'walk_in' => 'Walk-in', 'returning_visitor' => 'Returning' );
		$rows   = (array) $wpdb->get_results( 'SELECT v.*, g.full_name, g.phone FROM ' . self::t( 'visits' ) . ' v JOIN ' . self::t( 'guests' ) . ' g ON g.id = v.guest_id ORDER BY v.check_in_at DESC, v.id DESC LIMIT 100' ); // phpcs:ignore WordPress.DB.PreparedSQL
		$today  = current_time( 'Y-m-d' );
		$in_now = 0;
		$today_n = 0;
		foreach ( $rows as $r ) {
			if ( 0 === strpos( (string) $r->check_in_at, $today ) ) {
				$today_n++;
			}
			if ( in_array( $r->status, array( 'checked_in', 'notified' ), true ) ) {
				$in_now++;
			}
		}
		echo '<div style="max-width:1100px">';
		$new = get_transient( 'ajcore_ra_reception_newkey_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'ajcore_ra_reception_newkey_' . get_current_user_id() );
			echo '<div class="notice notice-success inline"><p><b>' . esc_html( $new['name'] ) . '</b> ' . esc_html__( 'device key (shown once; enter it in the AJ Front Desk app):', 'ajcore-ra' ) . '<br><code style="font-size:14px;user-select:all">' . esc_html( $new['key'] ) . '</code></p></div>';
		}
		echo '<p style="margin:12px 0"><span style="padding:4px 10px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7;margin-right:8px"><b>' . (int) $today_n . '</b> ' . esc_html__( 'today', 'ajcore-ra' ) . '</span><span style="padding:4px 10px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7"><b>' . (int) $in_now . '</b> ' . esc_html__( 'checked in now', 'ajcore-ra' ) . '</span></p>';

		if ( ! $rows ) {
			echo '<p style="color:#646970">' . esc_html__( 'No visits yet.', 'ajcore-ra' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="font-size:13px"><thead><tr><th>' . esc_html__( 'Arrived', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Visitor', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Phone', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Type', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Here to see', 'ajcore-ra' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( mysql2date( 'M j, g:i a', $r->check_in_at ) ) . '</td><td><b>' . esc_html( $r->full_name ) . '</b></td><td>' . esc_html( $r->phone ) . '</td><td>' . esc_html( $labels[ $r->visit_type ] ?? $r->visit_type ) . '</td><td>' . esc_html( $r->host_label ) . '</td><td>';
				if ( in_array( $r->status, array( 'checked_in', 'notified' ), true ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0">';
					wp_nonce_field( 'ajcore_ra_reception_checkout' );
					echo '<input type="hidden" name="action" value="ajcore_ra_reception_checkout"><input type="hidden" name="visit_id" value="' . (int) $r->id . '"><button class="button button-small">' . esc_html__( 'Check out', 'ajcore-ra' ) . '</button></form>';
				} else {
					echo '<span style="color:#646970">' . esc_html( $r->check_out_at ? mysql2date( 'g:i a', $r->check_out_at ) : '' ) . '</span>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h3 style="margin-top:24px">' . esc_html__( 'Devices', 'ajcore-ra' ) . '</h3>';
		$devices = self::devices();
		if ( $devices ) {
			echo '<table class="widefat striped" style="font-size:13px;max-width:700px"><thead><tr><th>' . esc_html__( 'Name', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Added', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Last seen', 'ajcore-ra' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $devices as $d ) {
				echo '<tr><td>' . esc_html( $d['name'] ) . '</td><td>' . esc_html( mysql2date( get_option( 'date_format' ), $d['created'] ) ) . '</td><td>' . esc_html( $d['last_seen'] ? mysql2date( 'M j, g:i a', $d['last_seen'] ) : '—' ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0" onsubmit="return confirm(\'Revoke this device? It stops working immediately.\');">';
				wp_nonce_field( 'ajcore_ra_reception_device_revoke' );
				echo '<input type="hidden" name="action" value="ajcore_ra_reception_device_revoke"><input type="hidden" name="device_id" value="' . esc_attr( $d['id'] ) . '"><button class="button button-small">' . esc_html__( 'Revoke', 'ajcore-ra' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;display:flex;gap:8px;align-items:center">';
		wp_nonce_field( 'ajcore_ra_reception_device_add' );
		echo '<input type="hidden" name="action" value="ajcore_ra_reception_device_add"><input type="text" name="device_name" placeholder="' . esc_attr__( 'Front desk iPad', 'ajcore-ra' ) . '"><button class="button button-primary">' . esc_html__( 'Add device', 'ajcore-ra' ) . '</button></form></div>';
	}
}
