<?php
/**
 * Suite Clients: one-way refresh from Rentec.
 *
 * Suite Clients are local AJ Core customers (customer_kind = suite_client) that mirror the Rentec tenants of the
 * multi-unit "UPOS" property (every unit under it: 103, 104, ...; "UPOS_VO" virtual-office tenants are not included). Rentec is the source: new tenants become
 * Suite Clients, and name / email / phone changes made in Rentec update the linked customer. Nothing is written
 * back to Rentec and nothing is ever deleted or archived here.
 *
 * Each Suite Client also gets its Rentec lease tracked as a Local Service (move-in, lease start, lease end, suite
 * number). It is tracking only: $0 rate, no charge dates, so AJ Core never bills or posts it. Suite client payments
 * stay in Rentec.
 *
 *   POST /ops/rentec/suite-sync/preview   what would change (nothing is written)
 *   POST /ops/rentec/suite-sync/apply     does it
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Suite_Sync {

	const KIND   = 'suite_client';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'ajcore_ops_counts', array( __CLASS__, 'filter_counts' ) );
		// Rentec Sync (10-min job or Sync now) just stored fresh leases: re-track linked Suite Clients' leases so the
		// Leases screen never waits for a manual Refresh Suite Clients.
		add_action( 'ajcore_ra_rentec_resource_synced', array( __CLASS__, 'on_rentec_synced' ) );
	}

	public static function register_routes() {
		$ns  = AJCore_Extensions::rest_namespace();
		$ops = array( 'AJCore_Extensions', 'can_manage_ops' );
		register_rest_route( $ns, '/ops/rentec/suite-sync/preview', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_preview' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/suite-leases', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'rest_leases' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, '/ops/rentec/suite-sync/apply', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_apply' ), 'permission_callback' => $ops ) );
	}

	/** The Rentec property whose tenants are Suite Clients. */
	private static function property_name() {
		return (string) apply_filters( 'ajcore_ra_suite_property', 'UPOS' );
	}

	private static function digits( $p ) {
		$d = preg_replace( '/\D+/', '', (string) $p );
		return ( 11 === strlen( $d ) && '1' === $d[0] ) ? substr( $d, 1 ) : $d;
	}

	/** A Rentec move-out date that has already passed ("" / 0000-00-00 / future = still in the suite). */
	private static function moved_out( $date ) {
		$d = substr( (string) $date, 0, 10 );
		return '' !== $d && '0000-00-00' !== $d && $d < gmdate( 'Y-m-d' );
	}

	/**
	 * Move status for a lease, normalized to Moving in / Moved in / Moving out / Moved out. Rentec's v3 field name for
	 * the tenant status is not documented, so the usual names are checked on the lease, the tenant's unit entry and
	 * the tenant; failing that, the move-in / move-out dates decide.
	 */
	private static function lease_status( $lease, $unit_entry, $tenant ) {
		$keys = array( 'tenant_status', 'move_status', 'occupancy_status', 'lease_status', 'status' );
		foreach ( array( $lease, $unit_entry, $tenant ) as $src ) {
			if ( ! is_array( $src ) ) {
				continue;
			}
			foreach ( $keys as $k ) {
				$v = isset( $src[ $k ] ) && is_scalar( $src[ $k ] ) ? strtolower( trim( (string) $src[ $k ] ) ) : '';
				if ( preg_match( '/notice|moving[\s_-]*out/', $v ) ) {
					return 'Moving out';
				}
				if ( preg_match( '/moved[\s_-]*out|^past|former/', $v ) ) {
					return 'Moved out';
				}
				if ( preg_match( '/moving[\s_-]*in|future|pending/', $v ) ) {
					return 'Moving in';
				}
				if ( preg_match( '/moved[\s_-]*in|current/', $v ) ) {
					return 'Moved in';
				}
			}
		}
		foreach ( array( $lease, $unit_entry ) as $src ) {
			$out = is_array( $src ) ? substr( (string) ( $src['move_out'] ?? '' ), 0, 10 ) : '';
			if ( '' !== $out && '0000-00-00' !== $out ) {
				return self::moved_out( $out ) ? 'Moved out' : 'Moving out';
			}
		}
		$in = is_array( $lease ) ? substr( (string) ( $lease['move_in'] ?? '' ), 0, 10 ) : '';
		return ( '' !== $in && $in > gmdate( 'Y-m-d' ) ) ? 'Moving in' : 'Moved in';
	}

	/** Walks Rentec's nested property list: every property at or under the one named $target gets id => unit label. */
	private static function collect_units( $props, $target, $inside, array &$ids, array &$tops ) {
		foreach ( (array) $props as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$nick   = trim( (string) ( $p['nickname'] ?? '' ) );
			$pid    = absint( $p['property_id'] ?? 0 );
			$is_top = ! $inside && 0 === strcasecmp( $nick, $target );
			if ( ! $inside && count( $tops ) < 15 ) {
				$tops[] = $nick;
			}
			if ( ( $inside || $is_top ) && $pid ) {
				$ids[ $pid ] = $is_top ? '' : trim( preg_replace( '/^' . preg_quote( $target, '/' ) . '(?![A-Za-z0-9_])[\s:,\-]*/i', '', $nick ) );
			}
			self::collect_units( $p['subunits'] ?? array(), $target, $inside || $is_top, $ids, $tops );
		}
	}

	/**
	 * Rentec tenants that live in the multi-unit property named $property (its units 103, 104, ...), keyed
	 * "account:renter_id". The property is found by name in Rentec's property list and every unit under it counts,
	 * so a unit's own nickname format does not matter; "UPOS_VO" is a different property and is not included.
	 * Also returns what was read, for the preview when nothing matches.
	 *
	 * @return array{tenants:array,diag:array}|WP_Error
	 */
	private static function rentec_tenants( $property ) {
		$accounts = AJCore_RA_Rentec::rows_for_sync( 'tenants' );
		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}
		$props = AJCore_RA_Rentec::rows_for_sync( 'properties' );
		if ( is_wp_error( $props ) ) {
			return $props;
		}
		$leases_by_acct = AJCore_RA_Rentec::rows_for_sync( 'leases' );
		$leases_by_acct = is_wp_error( $leases_by_acct ) ? array() : $leases_by_acct;
		$out  = array();
		$diag = array( 'accounts' => array(), 'nicknames' => array(), 'similar' => array() );
		foreach ( $accounts as $acct => $data ) {
			$ids  = array();
			$tops = array();
			self::collect_units( $props[ $acct ]['rows'] ?? array(), $property, false, $ids, $tops );
			$diag['accounts'][] = array( 'id' => (string) $acct, 'label' => (string) $data['label'], 'tenants' => count( $data['rows'] ), 'units' => count( $ids ) );
			foreach ( $tops as $n ) {
				if ( '' !== $n && count( $diag['nicknames'] ) < 15 && ! in_array( $n, $diag['nicknames'], true ) ) {
					$diag['nicknames'][] = $n;
				}
				if ( '' !== $n && false !== stripos( $n, $property ) && ! in_array( $n, $diag['similar'], true ) ) {
					$diag['similar'][] = $n;
				}
			}
			if ( ! $ids ) {
				continue; // this Rentec account has no such property
			}
			foreach ( $data['rows'] as $t ) {
				if ( ! is_array( $t ) || empty( $t['renter_id'] ) || ! empty( $t['archived'] ) || ! empty( $t['subtenant'] ) ) {
					continue;
				}
				$units      = array();
				$unit_entry = array(); // the tenant's entry per property id, for the move status
				foreach ( (array) ( $t['properties'] ?? array() ) as $pr ) {
					$pid = absint( $pr['property_id'] ?? 0 );
					// A future move-out (tenant marked Moving Out) is still in the suite.
					if ( $pid && isset( $ids[ $pid ] ) && ! self::moved_out( $pr['move_out'] ?? '' ) ) {
						$units[]            = '' !== $ids[ $pid ] ? $ids[ $pid ] : trim( (string) ( $pr['nickname'] ?? '' ) );
						$unit_entry[ $pid ] = $pr;
					}
				}
				if ( ! $units ) {
					continue;
				}
				// The current lease on a UPOS unit: no move-out, latest lease start.
				$lease = null;
				foreach ( (array) ( $leases_by_acct[ $acct ]['rows'] ?? array() ) as $l ) {
					if ( ! is_array( $l ) || absint( $l['renter_id'] ?? 0 ) !== (int) $t['renter_id'] || ! isset( $ids[ absint( $l['property_id'] ?? 0 ) ] ) || self::moved_out( $l['move_out'] ?? '' ) ) {
						continue;
					}
					if ( ! $lease || strcmp( substr( (string) ( $l['lease_begin'] ?? '' ), 0, 10 ), (string) $lease['begin'] ) > 0 ) {
						$pid_unit = $ids[ absint( $l['property_id'] ) ];
						$lease    = array(
							'lease_id' => absint( $l['lease_id'] ?? 0 ),
							'unit'     => '' !== $pid_unit ? $pid_unit : $units[0],
							'move_in'  => substr( (string) ( $l['move_in'] ?? '' ), 0, 10 ),
							'begin'    => substr( (string) ( $l['lease_begin'] ?? '' ), 0, 10 ),
							'end'      => substr( (string) ( $l['lease_end'] ?? '' ), 0, 10 ),
							'rent'     => round( (float) ( $l['recurring_rent'] ?? 0 ) + (float) ( $l['recurring_other'] ?? 0 ), 2 ),
							'deposit'  => round( (float) ( $l['deposit_balance'] ?? 0 ), 2 ),
							'status'   => self::lease_status( $l, $unit_entry[ absint( $l['property_id'] ) ] ?? null, $t ),
						);
					}
				}
				$company = trim( (string) ( $t['company'] ?? '' ) );
				$person  = trim( ( $t['f_name'] ?? '' ) . ' ' . ( $t['l_name'] ?? '' ) );
				$out[ $acct . ':' . $t['renter_id'] ] = array(
					'account'   => (string) $acct,
					'renter_id' => (int) $t['renter_id'],
					'name'      => '' !== $company ? $company : $person,
					'company'   => $company,
					'person'    => $person,
					'email'     => trim( (string) ( $t['email'] ?? '' ) ),
					'phone'     => self::digits( '' !== trim( (string) ( $t['phone'] ?? '' ) ) ? $t['phone'] : ( $t['mphone'] ?? '' ) ),
					'units'     => $units,
					'lease'     => $lease,
				);
			}
		}
		return array( 'tenants' => $out, 'diag' => $diag );
	}

	/** The local customers table with each row's metadata decoded. @return array{db:object,table:string,rows:array}|WP_Error */
	private static function local_customers() {
		$kit = AJCore_Extensions::rest_toolkit();
		$db  = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
		if ( ! $db ) {
			return new WP_Error( 'unavailable', 'The customer database is not available.', array( 'status' => 500 ) );
		}
		$t = $kit['portal_table']( 'aj_portal_local_customers' );
		if ( ! $kit['table_exists']( $db, $t ) ) {
			return array( 'db' => $db, 'table' => $t, 'rows' => array() );
		}
		$rows = (array) $db->get_results( "SELECT local_customer_id, customer_number, email, name, phone, metadata FROM `{$t}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as &$r ) {
			$m         = json_decode( (string) $r['metadata'], true );
			$r['meta'] = is_array( $m ) ? $m : array();
		}
		unset( $r );
		return array( 'db' => $db, 'table' => $t, 'rows' => $rows );
	}

	private static function values_of( $row ) {
		return array( 'name' => (string) $row['name'], 'email' => strtolower( (string) $row['email'] ), 'phone' => self::digits( $row['phone'] ) );
	}

	/**
	 * @return array{summary:array,changes:array,ops:array}|WP_Error
	 */
	private static function plan( $property ) {
		$read = self::rentec_tenants( $property );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$tenants = $read['tenants'];
		$local = self::local_customers();
		if ( is_wp_error( $local ) ) {
			return $local;
		}
		$existing_leases = array(); // "customer|service" => row
		$kit             = AJCore_Extensions::rest_toolkit();
		$svc_table       = $kit['portal_table']( 'aj_portal_local_services' );
		if ( $kit['table_exists']( $local['db'], $svc_table ) ) {
			foreach ( (array) $local['db']->get_results( "SELECT local_customer_id, local_service_id, contract_start_date, contract_end_date, move_in_date, service_name, features, status FROM `{$svc_table}` WHERE local_service_id LIKE 'rentec_lease_%'", ARRAY_A ) as $sv ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$existing_leases[ $sv['local_customer_id'] . '|' . $sv['local_service_id'] ] = $sv;
			}
		}
		$by_link  = array();
		$by_email = array();
		foreach ( $local['rows'] as $r ) {
			$link = $r['meta']['rentec'] ?? null;
			if ( is_array( $link ) && ! empty( $link['renter_id'] ) ) {
				$by_link[ ( $link['account'] ?? '1' ) . ':' . $link['renter_id'] ] = $r;
			}
			if ( '' !== (string) $r['email'] ) {
				$by_email[ strtolower( (string) $r['email'] ) ] = $r;
			}
		}
		$sum     = array( 'in_rentec' => count( $tenants ), 'new' => 0, 'linked_existing' => 0, 'pulled' => 0, 'unchanged' => 0, 'no_email' => 0, 'not_in_rentec' => 0, 'leases_new' => 0, 'leases_updated' => 0 );
		$changes = array();
		$ops     = array();
		$seen    = array();

		$note_lease = static function ( $t, $cust_id ) use ( &$sum, &$changes, $existing_leases ) {
			$l = $t['lease'] ?? null;
			if ( ! $l ) {
				return;
			}
			$sid = 'rentec_lease_' . $t['account'] . '_' . $l['lease_id'];
			$ex  = $cust_id ? ( $existing_leases[ $cust_id . '|' . $sid ] ?? null ) : null;
			$same = $ex && (string) $ex['contract_start_date'] === ( $l['begin'] ?: $l['move_in'] ) && (string) ( $ex['contract_end_date'] ?? '' ) === $l['end'] && (string) $ex['move_in_date'] === ( $l['move_in'] ?: $l['begin'] ) && (string) $ex['features'] === self::lease_features( $l );
			if ( $same ) {
				return;
			}
			$sum[ $ex ? 'leases_updated' : 'leases_new' ]++;
			$changes[] = array( 'unit' => $l['unit'], 'kind' => 'lease', 'detail' => 'Suite ' . $l['unit'] . ' lease ' . ( $l['begin'] ?: $l['move_in'] ) . ' → ' . ( $l['end'] ?: 'ongoing' ) . ( ( $l['rent'] ?? 0 ) > 0 ? ', rent $' . number_format( (float) $l['rent'], 2 ) : '' ) . ', ' . $l['status'] . ( $ex ? ' (updated)' : ' (tracked)' ) );
		};
		foreach ( $tenants as $key => $t ) {
			$unit = implode( ', ', $t['units'] );
			if ( isset( $by_link[ $key ] ) ) {
				$cust         = $by_link[ $key ];
				$seen[ $key ] = true;
				$a            = self::values_of( $cust );
				$r            = array( 'name' => $t['name'], 'email' => strtolower( $t['email'] ), 'phone' => $t['phone'] );
				$pull         = array();
				foreach ( $r as $f => $rv ) {
					if ( '' === $rv || $rv === $a[ $f ] ) {
						continue; // Rentec blank never blanks out AJ Core
					}
					$pull[ $f ] = $t[ $f ];
					$sum['pulled']++;
					$changes[] = array( 'unit' => $unit, 'kind' => 'pulled', 'detail' => $cust['name'] . ' — ' . $f . ': ' . ( '' !== $a[ $f ] ? $a[ $f ] : '(blank)' ) . ' → ' . $rv );
				}
				if ( ! $pull ) {
					$sum['unchanged']++;
				}
				$note_lease( $t, $cust['local_customer_id'] );
				$ops[ $key ] = array( 'type' => 'update', 'id' => $cust['local_customer_id'], 'tenant' => $t, 'pull' => $pull, 'meta' => $cust['meta'] );
				continue;
			}
			// not linked yet: same email = the same person already in AJ Core
			if ( '' !== $t['email'] && isset( $by_email[ strtolower( $t['email'] ) ] ) ) {
				$cust = $by_email[ strtolower( $t['email'] ) ];
				$sum['linked_existing']++;
				$changes[] = array( 'unit' => $unit, 'kind' => 'linked', 'detail' => $cust['name'] . ' — linked to Rentec tenant ' . $t['renter_id'] . ' and marked Suite Client' );
				$note_lease( $t, $cust['local_customer_id'] );
				$ops[ $key ] = array( 'type' => 'link', 'id' => $cust['local_customer_id'], 'tenant' => $t, 'meta' => $cust['meta'] );
				continue;
			}
			if ( '' === $t['email'] ) {
				$sum['no_email']++;
				$changes[] = array( 'unit' => $unit, 'kind' => 'no_email', 'detail' => $t['name'] . ' — no email in Rentec, skipped. Add one in Rentec.' );
				continue;
			}
			$sum['new']++;
			$changes[]   = array( 'unit' => $unit, 'kind' => 'new', 'detail' => $t['name'] . ' (' . $t['email'] . ')' );
			$note_lease( $t, '' );
			$ops[ $key ] = array( 'type' => 'create', 'tenant' => $t );
		}
		foreach ( $by_link as $key => $cust ) {
			if ( ! isset( $seen[ $key ] ) && self::KIND === ( $cust['meta']['customer_kind'] ?? '' ) ) {
				$sum['not_in_rentec']++;
				$changes[] = array( 'unit' => '', 'kind' => 'not_in_rentec', 'detail' => $cust['name'] . ' — no longer on this property in Rentec (kept here).' );
			}
		}
		return array( 'summary' => $sum, 'changes' => $changes, 'ops' => $ops, 'diag' => $read['diag'] );
	}

	/** Lease terms + move status as display text (the rate column stays 0 so nothing is ever billed). */
	private static function lease_features( $l ) {
		return wp_json_encode( array_values( array_filter( array(
			( $l['rent'] ?? 0 ) > 0 ? 'Rent $' . number_format( (float) $l['rent'], 2 ) . '/mo' : '',
			( $l['deposit'] ?? 0 ) > 0 ? 'Deposit $' . number_format( (float) $l['deposit'], 2 ) : '',
			! empty( $l['status'] ) ? 'Status: ' . $l['status'] : '',
		) ) ) );
	}

	/** Tracks the Rentec lease as a $0 Local Service: no rate, no charge dates, so AJ Core never bills or posts it. */
	private static function upsert_lease_service( $local, $customer_id, $t ) {
		$l = $t['lease'] ?? null;
		if ( ! $l || '' === $customer_id ) {
			return;
		}
		$kit   = AJCore_Extensions::rest_toolkit();
		$table = $kit['portal_table']( 'aj_portal_local_services' );
		if ( ! $kit['table_exists']( $local['db'], $table ) ) {
			return;
		}
		$begin  = $l['begin'] ?: $l['move_in'];
		$move   = $l['move_in'] ?: $l['begin'];
		if ( '' === $begin || ! strtotime( $begin ) ) {
			return;
		}
		$end    = $l['end'] && strtotime( $l['end'] ) ? gmdate( 'Y-m-d', strtotime( $l['end'] ) ) : null;
		$sid    = 'rentec_lease_' . $t['account'] . '_' . $l['lease_id'];
		$status = $end && $end < gmdate( 'Y-m-d' ) ? 'cancelled' : 'active';
		$data   = array(
			'local_service_id'    => $sid,
			'local_customer_id'   => $customer_id,
			'service_name'        => 'Suite ' . $l['unit'] . ' Lease',
			'contract_start_date' => gmdate( 'Y-m-d', strtotime( $begin ) ),
			'contract_end_date'   => $end,
			'move_in_date'        => gmdate( 'Y-m-d', strtotime( $move ?: $begin ) ),
			'billing_start_date'  => null,
			'next_charge_date'    => null,
			'monthly_rate'        => 0,
			'currency'            => 'usd',
			'billing_interval'    => 'month',
			'features'            => self::lease_features( $l ),
			'variable_charges'    => wp_json_encode( array() ),
			'status'              => $status,
			'notes'               => 'Lease tracked from Rentec. Payments are handled in Rentec; nothing is billed here.',
		);
		$db  = $local['db'];
		$row = $db->get_var( $db->prepare( "SELECT id FROM `{$table}` WHERE local_customer_id = %s AND local_service_id = %s LIMIT 1", $customer_id, $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $row ) {
			$db->update( $table, $data, array( 'id' => (int) $row ) );
		} else {
			$db->insert( $table, $data );
		}
		// Only the tenant's current lease is tracked: an older one (renewed as a new Rentec lease) stops showing.
		$db->query( $db->prepare( "UPDATE `{$table}` SET status = 'cancelled' WHERE local_customer_id = %s AND local_service_id LIKE %s AND local_service_id <> %s AND status <> 'cancelled'", $customer_id, $db->esc_like( 'rentec_lease_' . $t['account'] . '_' ) . '%', $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function apply_ops( $local, $ops, $property ) {
		$db    = $local['db'];
		$now   = current_time( 'mysql' );
		$admin = class_exists( 'AJForms_Admin' ) ? ( AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin() ) : null;
		foreach ( $ops as $op ) {
			$t    = $op['tenant'];
			$link = array(
				'account'   => $t['account'],
				'renter_id' => $t['renter_id'],
				'units'     => $t['units'],
				'property'  => $property,
				'synced_at' => gmdate( 'Y-m-d H:i:s' ),
			);
			if ( 'create' === $op['type'] ) {
				$meta             = array_filter( array( 'business_name' => $t['company'], 'individual_name' => $t['person'], 'customer_type' => 'local', 'customer_kind' => self::KIND ) );
				$meta['rentec']   = $link;
				$new_id = 'local_' . str_replace( '-', '', wp_generate_uuid4() );
				$db->insert( $local['table'], array(
					'local_customer_id' => $new_id,
					'customer_number'   => $admin ? $admin->generate_customer_number() : '',
					'email'             => $t['email'],
					'name'              => $t['name'],
					'phone'             => $t['phone'],
					'description'       => 'Suite ' . implode( ', ', $t['units'] ),
					'address'           => wp_json_encode( new stdClass() ),
					'metadata'          => wp_json_encode( $meta ),
					'partner_key'       => '',
					'status'            => 'active',
					'created_at'        => $now,
					'updated_at'        => $now,
				) );
				self::upsert_lease_service( $local, $new_id, $t );
				continue;
			}
			$meta                  = (array) $op['meta'];
			$meta['customer_kind'] = self::KIND;
			$cols                  = array( 'updated_at' => $now );
			if ( 'update' === $op['type'] ) {
				foreach ( (array) $op['pull'] as $f => $v ) {
					$cols[ $f ] = 'phone' === $f ? self::digits( $v ) : $v;
					if ( 'name' === $f ) {
						$meta['business_name'] = $t['company'];
					}
				}
			}
			$meta['rentec'] = $link;
			$cols['metadata'] = wp_json_encode( $meta );
			$db->update( $local['table'], $cols, array( 'local_customer_id' => $op['id'] ) );
			self::upsert_lease_service( $local, $op['id'], $t );
		}
	}

	public static function rest_preview( WP_REST_Request $request ) {
		$property = self::property_name();
		$plan     = self::plan( $property );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		return rest_ensure_response( array( 'property' => $property, 'summary' => $plan['summary'], 'changes' => array_slice( $plan['changes'], 0, 400 ), 'changes_total' => count( $plan['changes'] ), 'diagnostics' => $plan['diag'] ) );
	}

	public static function rest_apply( WP_REST_Request $request ) {
		$property = self::property_name();
		$plan     = self::plan( $property );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$local = self::local_customers();
		if ( is_wp_error( $local ) ) {
			return $local;
		}
		self::apply_ops( $local, $plan['ops'], $property );
		return rest_ensure_response( array( 'success' => true, 'property' => $property, 'summary' => $plan['summary'] ) );
	}

	/**
	 * After Rentec Sync stores a leases list: refresh the tracked lease of every already-linked Suite Client from the
	 * local copy (no extra Rentec calls). New tenants and contact changes still wait for Refresh Suite Clients.
	 */
	public static function on_rentec_synced( $resource ) {
		if ( 'leases' === $resource ) {
			self::refresh_leases();
		}
	}

	/** @return int|WP_Error leases re-tracked */
	public static function refresh_leases() {
		$plan = self::plan( self::property_name() );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$local = self::local_customers();
		if ( is_wp_error( $local ) ) {
			return $local;
		}
		$n = 0;
		foreach ( $plan['ops'] as $op ) {
			if ( 'update' === $op['type'] ) {
				self::upsert_lease_service( $local, $op['id'], $op['tenant'] );
				$n++;
			}
		}
		return $n;
	}

	// ------------------------------------------------------------------ lease tracking (renewals)

	/** @return array<int,array<string,mixed>> Tracked Suite Client leases, soonest lease end first (ongoing last). */
	private static function leases() {
		$kit = AJCore_Extensions::rest_toolkit();
		$db  = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
		if ( ! $db ) {
			return array();
		}
		$st = $kit['portal_table']( 'aj_portal_local_services' );
		$ct = $kit['portal_table']( 'aj_portal_local_customers' );
		if ( ! $kit['table_exists']( $db, $st ) ) {
			return array();
		}
		$rows  = (array) $db->get_results( "SELECT s.local_service_id, s.local_customer_id, s.service_name, s.move_in_date, s.contract_start_date, s.contract_end_date, s.status, s.features, c.name AS customer_name, c.email FROM `{$st}` s LEFT JOIN `{$ct}` c ON c.local_customer_id = s.local_customer_id WHERE s.local_service_id LIKE 'rentec\\_lease\\_%' AND s.status <> 'cancelled' ORDER BY s.contract_end_date IS NULL, s.contract_end_date ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$today = strtotime( gmdate( 'Y-m-d' ) );
		$out   = array();
		foreach ( $rows as $r ) {
			$feat = json_decode( (string) $r['features'], true );
			$rent   = null;
			$dep    = null;
			$status = '';
			foreach ( (array) $feat as $f ) {
				if ( 0 === strpos( (string) $f, 'Status: ' ) ) {
					$status = substr( (string) $f, 8 );
				} elseif ( preg_match( '/^Rent \$([\d,\.]+)/', (string) $f, $m ) ) {
					$rent = (float) str_replace( ',', '', $m[1] );
				} elseif ( preg_match( '/^Deposit \$([\d,\.]+)/', (string) $f, $m ) ) {
					$dep = (float) str_replace( ',', '', $m[1] );
				}
			}
			$end  = (string) $r['contract_end_date'];
			$days = $end ? (int) floor( ( strtotime( $end ) - $today ) / DAY_IN_SECONDS ) : null;
			$out[] = array(
				'customer_id'   => (string) $r['local_customer_id'],
				'customer_name' => (string) $r['customer_name'],
				'suite'         => trim( preg_replace( '/^Suite\s+|\s+Lease$/i', '', (string) $r['service_name'] ) ),
				'move_in'       => (string) $r['move_in_date'],
				'lease_start'   => (string) $r['contract_start_date'],
				'lease_end'     => $end,
				'days_left'     => $days,
				'rent'          => $rent,
				'deposit'       => $dep,
				'status'        => $status,
			);
		}
		return $out;
	}

	public static function rest_leases() {
		return rest_ensure_response( array( 'leases' => self::leases() ) );
	}

	/** AJOps menu number: leases ending within 90 days (or already past their end). */
	public static function filter_counts( $counts ) {
		$n = 0;
		foreach ( self::leases() as $l ) {
			if ( null !== $l['days_left'] && $l['days_left'] <= 90 ) {
				$n++;
			}
		}
		$counts['leases'] = array( 'value' => $n, 'label' => 'Ending in 90 days', 'alert' => true );
		return $counts;
	}
}
