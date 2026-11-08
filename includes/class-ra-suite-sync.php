<?php
/**
 * Suite Clients: one-way refresh from Rentec.
 *
 * Suite Clients are local AJ Core customers (customer_kind = suite_client) that mirror the Rentec tenants of the
 * multi-unit "UPOS" property (every unit under it: 103, 104, ...; "UPOS_VO" virtual-office tenants are not included). Rentec is the source: new tenants become
 * Suite Clients, and name / email / phone changes made in Rentec update the linked customer. Nothing is written
 * back to Rentec and nothing is ever deleted or archived here.
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
	}

	public static function register_routes() {
		$ns  = AJCore_Extensions::rest_namespace();
		$ops = array( 'AJCore_Extensions', 'can_manage_ops' );
		register_rest_route( $ns, '/ops/rentec/suite-sync/preview', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_preview' ), 'permission_callback' => $ops ) );
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
				$units = array();
				foreach ( (array) ( $t['properties'] ?? array() ) as $pr ) {
					$pid = absint( $pr['property_id'] ?? 0 );
					if ( $pid && isset( $ids[ $pid ] ) && empty( $pr['move_out'] ) ) {
						$units[] = '' !== $ids[ $pid ] ? $ids[ $pid ] : trim( (string) ( $pr['nickname'] ?? '' ) );
					}
				}
				if ( ! $units ) {
					continue;
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
		$sum     = array( 'in_rentec' => count( $tenants ), 'new' => 0, 'linked_existing' => 0, 'pulled' => 0, 'unchanged' => 0, 'no_email' => 0, 'not_in_rentec' => 0 );
		$changes = array();
		$ops     = array();
		$seen    = array();

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
				$ops[ $key ] = array( 'type' => 'update', 'id' => $cust['local_customer_id'], 'tenant' => $t, 'pull' => $pull, 'meta' => $cust['meta'] );
				continue;
			}
			// not linked yet: same email = the same person already in AJ Core
			if ( '' !== $t['email'] && isset( $by_email[ strtolower( $t['email'] ) ] ) ) {
				$cust = $by_email[ strtolower( $t['email'] ) ];
				$sum['linked_existing']++;
				$changes[] = array( 'unit' => $unit, 'kind' => 'linked', 'detail' => $cust['name'] . ' — linked to Rentec tenant ' . $t['renter_id'] . ' and marked Suite Client' );
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
				$db->insert( $local['table'], array(
					'local_customer_id' => 'local_' . str_replace( '-', '', wp_generate_uuid4() ),
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
}
