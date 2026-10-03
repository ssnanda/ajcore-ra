<?php
/**
 * Compliance calendar API (entities + annual-report filings), moved out of AJCore.
 *
 * Same routes, parameters, permissions and responses as before, registered under AJCore's
 * ajcore/v1 namespace while AJCore-RA is active. The handler code below is unchanged; the four
 * small helpers at the top forward to AJCore's own (private) ones through the extension toolkit,
 * so every query and table name behaves exactly as it did inside AJCore.
 *
 * The reminder email and daily job are in class-ra-compliance-reminders.php.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Compliance_API {

	const AUTO_OPTION    = 'ajcore_ra_entity_auto_status';  // entity_id => status RA last set (auto-managed records)
	const MISSING_OPTION = 'ajcore_ra_entity_ra_missing';   // entity_id => when the RA subscription was first missing

	/** @var array<string,callable> */
	private $kit;

	public static function init() {
		add_action(
			'rest_api_init',
			static function () {
				// Built here (not at plugins_loaded) so AJCore's classes are certainly loaded.
				$api = new self();
				$api->register_routes();
			}
		);
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog' ) );
	}

	public static function catalog( $catalog ) {
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/compliance', 'auth' => 'Admin', 'purpose' => 'Compliance calendar: registered entities with annual-report deadlines and filing history.', 'app' => 'OPS compliance' );
		return $catalog;
	}

	public function __construct() {
		$this->kit = class_exists( 'AJCore_Extensions' ) ? AJCore_Extensions::rest_toolkit() : array();
	}

	// ── AJCore helpers (forwarded) ──────────────────────────────────────────────

	private function get_portal_db() {
		return $this->kit['portal_db']();
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

	// ── Routes ──────────────────────────────────────────────────────────────────

	public function register_routes() {
		$ns     = AJCore_Extensions::rest_namespace();
		$ops    = array( 'AJCore_Extensions', 'can_manage_ops' );
		$portal = array( 'AJCore_Extensions', 'can_use_portal' );
		$read   = array(
			'per_page' => array( 'default' => 25, 'sanitize_callback' => 'absint' ),
			'search'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
		);

		register_rest_route(
			$ns,
			'/ops/compliance',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_ops_compliance_entity' ),
					'permission_callback' => $ops,
					'args'                => array(
						'entity_name'        => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
						'stripe_customer_id' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'entity_type'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'jurisdiction'       => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'sos_id'             => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'formation_date'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'first_report_year'  => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						'due_month'          => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						'due_day'            => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						'entity_status'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'notes'              => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ops/compliance/filings/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ops_compliance_filing' ),
					'permission_callback' => $ops,
					'args'                => array(
						'action'       => array( 'required' => true,  'sanitize_callback' => 'sanitize_key' ),
						'confirmation' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'due_date'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'notes'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					),
				),
			)
		);

		// Client portal: mark an annual-report filing complete (or undo) for one of the current
		// user's own entities.
		register_rest_route(
			$ns,
			'/portal/compliance/filings/(?P<id>\d+)/complete',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'complete_portal_compliance_filing' ),
					'permission_callback' => $portal,
					'args'                => array(
						'completed' => array( 'required' => false ),
						'note'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ops/compliance/(?P<id>\d+)/remind',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'remind_ops_compliance_entity' ),
					'permission_callback' => $ops,
				),
			)
		);

		register_rest_route(
			$ns,
			'/ops/compliance/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ops_compliance_entity' ),
					'permission_callback' => $ops,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_ops_compliance_entity' ),
					'permission_callback' => $ops,
				),
			)
		);

		// Every customer is a compliance record; this creates any that are missing (dry_run=1 only counts).
		register_rest_route(
			$ns,
			'/ops/compliance/sync-customers',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_customers_to_entities_route' ),
					'permission_callback' => $ops,
					'args'                => array(
						'dry_run' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);

		// Read endpoints (were in AJCore's route map).
		register_rest_route(
			$ns,
			'/ops/compliance',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_compliance' ),
				'permission_callback' => $ops,
				'args'                => $read,
			)
		);
		register_rest_route(
			$ns,
			'/portal/compliance',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_portal_compliance' ),
				'permission_callback' => $portal,
				'args'                => array(),
			)
		);
	}

	// ── Handlers (moved unchanged from AJCore_REST_API) ─────────────────────────

	private function get_compliance_valid_entity_types() {
		return array( 'llc', 'corp', 'nonprofit', 'lp', 'llp', 'other' );
	}

	private function get_compliance_valid_entity_statuses() {
		return array( 'active', 'inactive', 'dissolved' );
	}

	/** The report year an active entity currently owes (its next/current deadline). */
	private function get_compliance_target_year( $entity ) {
		$current = (int) gmdate( 'Y' );
		$first   = (int) $entity->first_report_year;
		return max( $current, $first );
	}

	private function compute_compliance_due_date( $entity, $year ) {
		$month = max( 1, min( 12, (int) $entity->due_month ) );
		$day   = max( 1, min( 28, (int) $entity->due_day ) ); // clamp to 28 so every month/year is valid
		return sprintf( '%04d-%02d-%02d', (int) $year, $month, $day );
	}

	/**
	 * Lazily guarantees every active entity has a filing row for its current target
	 * year, so year rollover needs no cron: the row appears on the first list/cron
	 * touch after Jan 1. Uses the (entity_id, filing_type, period_year) unique key
	 * to stay idempotent.
	 */
	private function ensure_compliance_filing_rows() {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );

		$entities = $pdb->get_results( "SELECT * FROM `{$t_entities}` WHERE entity_status = 'active'" );
		foreach ( (array) $entities as $entity ) {
			$year   = $this->get_compliance_target_year( $entity );
			$exists = (int) $pdb->get_var( $pdb->prepare(
				"SELECT COUNT(*) FROM `{$t_filings}` WHERE entity_id = %d AND filing_type = 'annual_report' AND period_year = %d",
				(int) $entity->id,
				$year
			) );
			if ( ! $exists ) {
				$pdb->query( $pdb->prepare(
					"INSERT IGNORE INTO `{$t_filings}` (entity_id, filing_type, period_year, due_date) VALUES (%d, 'annual_report', %d, %s)",
					(int) $entity->id,
					$year,
					$this->compute_compliance_due_date( $entity, $year )
				) );
			}
		}
	}

	private function format_compliance_filing_row( $f ) {
		return array(
			'id'                  => (int) $f->id,
			'entity_id'           => (int) $f->entity_id,
			'filing_type'         => (string) $f->filing_type,
			'period_year'         => (int) $f->period_year,
			'due_date'            => (string) $f->due_date,
			'status'              => (string) $f->status,
			'filed_at'            => (string) $f->filed_at,
			'confirmation'        => (string) $f->confirmation,
			'notes'               => (string) $f->notes,
			'client_completed'    => ! empty( $f->client_completed ),
			'client_completed_at' => (string) $f->client_completed_at,
			'client_note'         => (string) $f->client_note,
			'reminder_stage'      => (string) $f->reminder_stage,
			'last_reminder_at'    => (string) $f->last_reminder_at,
			'reminders_sent'      => (int) $f->reminders_sent,
		);
	}

	// ── Customers are the entities ──────────────────────────────────────────────

	/** @return array<string,int>|null customer id => index for active Registered Agent subscriptions; null if unavailable. */
	private function get_registered_agent_customer_set() {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return null;
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		if ( ! method_exists( $admin, 'get_portal_core_subscription_product_counts' ) ) {
			return null;
		}
		$counts = $admin->get_portal_core_subscription_product_counts();
		return array_flip( (array) $counts['registered_agent_subscription_customers'] );
	}

	/**
	 * Gives every customer a compliance record, so nobody has to "add an entity" by hand.
	 *
	 * - Active record (annual-report filings + reminders) for customers with an active Registered
	 *   Agent subscription; inactive for everyone else, so no one gets reminders by surprise.
	 * - Name is the customer's business name when they have one, else their name, else email.
	 * - First report year is the next April 15 that has not passed yet, so a record created now
	 *   never starts out overdue and the daily job cannot send an "OVERDUE" email on day one.
	 * - Existing records are never touched; archived customers are skipped.
	 *
	 * @return array{created:int,active:int,inactive:int}
	 */
	private function sync_customers_to_entities( $dry_run = false ) {
		$result = array( 'created' => 0, 'active' => 0, 'inactive' => 0 );

		$pdb         = $this->get_portal_db();
		$t_entities  = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_customers = $this->portal_table( 'aj_portal_stripe_customers' );
		if ( ! $this->table_exists( $pdb, $t_entities ) || ! $this->table_exists( $pdb, $t_customers ) ) {
			return $result;
		}

		$linked = array_flip( (array) $pdb->get_col( "SELECT DISTINCT stripe_customer_id FROM `{$t_entities}` WHERE stripe_customer_id <> ''" ) );

		$ra_customers = (array) $this->get_registered_agent_customer_set();

		$year  = (int) gmdate( 'Y' );
		$first = ( gmdate( 'Y-m-d' ) <= sprintf( '%04d-04-15', $year ) ) ? $year : $year + 1;

		$customers = $pdb->get_results( "SELECT stripe_customer_id, name, email, metadata FROM `{$t_customers}` WHERE portal_status IS NULL OR portal_status <> 'archived'" );
		foreach ( (array) $customers as $c ) {
			$cid = (string) $c->stripe_customer_id;
			if ( '' === $cid || isset( $linked[ $cid ] ) ) {
				continue;
			}

			$business = '';
			$meta     = json_decode( (string) $c->metadata, true );
			if ( is_array( $meta ) ) {
				foreach ( array( 'business_name', 'business', 'company', 'company_name' ) as $key ) {
					if ( ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
						$business = trim( $meta[ $key ] );
						break;
					}
				}
			}
			$name = '' !== $business ? $business : ( '' !== trim( (string) $c->name ) ? trim( (string) $c->name ) : trim( (string) $c->email ) );
			if ( '' === $name ) {
				continue;
			}

			$status = isset( $ra_customers[ $cid ] ) ? 'active' : 'inactive';
			if ( ! $dry_run ) {
				$ok = $pdb->insert( $t_entities, array(
					'stripe_customer_id' => $cid,
					'entity_name'        => sanitize_text_field( $name ),
					'entity_type'        => 'llc',
					'jurisdiction'       => 'NC',
					'first_report_year'  => $first,
					'due_month'          => 4,
					'due_day'            => 15,
					'entity_status'      => $status,
					'created_by'         => get_current_user_id(),
				), array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d' ) );
				if ( ! $ok ) {
					continue;
				}
				$auto                    = get_option( self::AUTO_OPTION, array() );
				$auto                    = is_array( $auto ) ? $auto : array();
				$auto[ (int) $pdb->insert_id ] = $status;
				update_option( self::AUTO_OPTION, $auto, false );
			}
			$result['created']++;
			$result[ $status ]++;
		}

		return $result;
	}

	/**
	 * Keeps auto-created records in step with the Registered Agent subscription: Active while
	 * the customer has one, Inactive after it has been gone for 14 days (a failed payment must
	 * not switch reminders off), Active again as soon as it returns. A record whose status was
	 * changed by hand is never touched, and neither is a dissolved one.
	 */
	private function follow_subscriptions() {
		$ra = $this->get_registered_agent_customer_set();
		if ( null === $ra || empty( $ra ) ) {
			return; // unknown or empty: never mass-deactivate on a bad read
		}

		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		if ( ! $this->table_exists( $pdb, $t_entities ) ) {
			return;
		}

		$auto    = get_option( self::AUTO_OPTION, array() );
		$auto    = is_array( $auto ) ? $auto : array();
		$missing = get_option( self::MISSING_OPTION, array() );
		$missing = is_array( $missing ) ? $missing : array();
		$changed = false;
		$now     = time();

		foreach ( (array) $pdb->get_results( "SELECT id, stripe_customer_id, entity_status FROM `{$t_entities}` WHERE stripe_customer_id <> '' AND entity_status IN ('active','inactive')" ) as $e ) {
			$id = (int) $e->id;
			if ( ! isset( $auto[ $id ] ) || $auto[ $id ] !== $e->entity_status ) {
				continue; // not auto-managed, or changed by hand
			}
			$has = isset( $ra[ (string) $e->stripe_customer_id ] );

			if ( $has ) {
				if ( isset( $missing[ $id ] ) ) {
					unset( $missing[ $id ] );
					$changed = true;
				}
				if ( 'inactive' === $e->entity_status ) {
					$pdb->update( $t_entities, array( 'entity_status' => 'active' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
					$auto[ $id ] = 'active';
					$changed     = true;
				}
				continue;
			}

			if ( 'active' === $e->entity_status ) {
				if ( ! isset( $missing[ $id ] ) ) {
					$missing[ $id ] = $now;
					$changed        = true;
				} elseif ( $now - (int) $missing[ $id ] >= 14 * DAY_IN_SECONDS ) {
					$pdb->update( $t_entities, array( 'entity_status' => 'inactive' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
					$auto[ $id ] = 'inactive';
					unset( $missing[ $id ] );
					$changed = true;
				}
			}
		}

		if ( $changed ) {
			update_option( self::AUTO_OPTION, $auto, false );
			update_option( self::MISSING_OPTION, $missing, false );
		}
	}

	/** At most hourly, so the list screen stays quick. */
	private function maybe_sync_customers() {
		if ( get_transient( 'ajcore_ra_compliance_customer_sync' ) ) {
			return;
		}
		$this->sync_customers_to_entities( false );
		$this->follow_subscriptions();
		set_transient( 'ajcore_ra_compliance_customer_sync', 1, HOUR_IN_SECONDS );
	}

	public function sync_customers_to_entities_route( WP_REST_Request $request ) {
		$dry = (bool) absint( $request->get_param( 'dry_run' ) );
		$out = $this->sync_customers_to_entities( $dry );
		if ( ! $dry ) {
			set_transient( 'ajcore_ra_compliance_customer_sync', 1, HOUR_IN_SECONDS );
		}
		return rest_ensure_response( array_merge( array( 'success' => true, 'dry_run' => $dry ), $out ) );
	}

	public function get_ops_compliance( WP_REST_Request $request ) {
		$this->maybe_sync_customers();
		AJCore_RA_Compliance_Tasks::maybe_reconcile();
		$pdb         = $this->get_portal_db();
		$t_entities  = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings   = $this->portal_table( 'aj_portal_compliance_filings' );
		$t_customers = $this->portal_table( 'aj_portal_stripe_customers' );

		$this->ensure_compliance_filing_rows();

		$search        = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status_filter = sanitize_key( (string) $request->get_param( 'status' ) );
		$entity_status = sanitize_key( (string) $request->get_param( 'entity_status' ) );
		$client        = sanitize_text_field( (string) $request->get_param( 'client' ) );

		$valid_derived  = array( 'overdue', 'due_soon', 'upcoming', 'filed', 'waived' );
		$status_filter  = in_array( $status_filter, $valid_derived, true ) ? $status_filter : '';
		$entity_status  = in_array( $entity_status, $this->get_compliance_valid_entity_statuses(), true ) ? $entity_status : '';

		$where  = '1=1';
		$params = array();
		if ( '' !== $entity_status ) {
			$where   .= ' AND e.entity_status = %s';
			$params[] = $entity_status;
		}
		if ( '' !== $client ) {
			$where   .= ' AND e.stripe_customer_id = %s';
			$params[] = $client;
		}
		if ( '' !== $search ) {
			$like     = '%' . $pdb->esc_like( $search ) . '%';
			$where   .= ' AND (e.entity_name LIKE %s OR e.sos_id LIKE %s OR c.name LIKE %s OR c.email LIKE %s)';
			$params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
		}

		$sql = "SELECT e.*, c.name AS customer_name, c.email AS customer_email
			FROM `{$t_entities}` e
			LEFT JOIN `{$t_customers}` c ON c.stripe_customer_id = e.stripe_customer_id
			WHERE {$where}
			ORDER BY e.entity_name ASC
			LIMIT 2000";
		$entities_raw = $params ? $pdb->get_results( $pdb->prepare( $sql, $params ) ) : $pdb->get_results( $sql );
		$entities_raw = is_array( $entities_raw ) ? $entities_raw : array();

		// Filings for the listed entities, newest period first.
		$filings_by_entity = array();
		$entity_ids        = array_map( 'intval', wp_list_pluck( $entities_raw, 'id' ) );
		if ( ! empty( $entity_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $entity_ids ), '%d' ) );
			$filing_rows  = $pdb->get_results( $pdb->prepare(
				"SELECT * FROM `{$t_filings}` WHERE entity_id IN ({$placeholders}) ORDER BY period_year DESC, id DESC",
				$entity_ids
			) );
			foreach ( (array) $filing_rows as $f ) {
				$filings_by_entity[ (int) $f->entity_id ][] = $f;
			}
		}

		$today    = gmdate( 'Y-m-d' );
		$year     = (int) gmdate( 'Y' );
		$due30    = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
		$due90    = gmdate( 'Y-m-d', time() + 90 * DAY_IN_SECONDS );
		$stats    = array( 'shown' => 0, 'due_30' => 0, 'due_90' => 0, 'overdue' => 0, 'filed_this_year' => 0 );
		$entities = array();

		foreach ( $entities_raw as $e ) {
			$id      = (int) $e->id;
			$filings = isset( $filings_by_entity[ $id ] ) ? $filings_by_entity[ $id ] : array();

			// Current filing = oldest still-pending one; else the most recent row.
			$current = null;
			foreach ( array_reverse( $filings ) as $f ) {
				if ( 'pending' === $f->status ) {
					$current = $f;
					break;
				}
			}
			if ( ! $current && ! empty( $filings ) ) {
				$current = $filings[0];
			}

			$derived = 'upcoming';
			if ( $current ) {
				if ( 'filed' === $current->status ) {
					$derived = 'filed';
				} elseif ( 'waived' === $current->status ) {
					$derived = 'waived';
				} elseif ( $current->due_date < $today ) {
					$derived = 'overdue';
				} elseif ( $current->due_date <= $due90 ) {
					$derived = 'due_soon';
				}
			}

			if ( '' !== $status_filter && $derived !== $status_filter ) {
				continue;
			}

			$entities[] = array(
				'id'                 => $id,
				'stripe_customer_id' => (string) $e->stripe_customer_id,
				'entity_name'        => (string) $e->entity_name,
				'entity_type'        => (string) $e->entity_type,
				'jurisdiction'       => (string) $e->jurisdiction,
				'sos_id'             => (string) $e->sos_id,
				'formation_date'     => (string) $e->formation_date,
				'first_report_year'  => (int) $e->first_report_year,
				'due_month'          => (int) $e->due_month,
				'due_day'            => (int) $e->due_day,
				'entity_status'      => (string) $e->entity_status,
				'notes'              => (string) $e->notes,
				'created_at'         => (string) $e->created_at,
				'updated_at'         => (string) $e->updated_at,
				'customer_name'      => (string) $e->customer_name,
				'customer_email'     => (string) $e->customer_email,
				'derived_status'     => $derived,
				'current_filing'     => $current ? $this->format_compliance_filing_row( $current ) : null,
				'filings'            => array_map( array( $this, 'format_compliance_filing_row' ), $filings ),
			);

			$stats['shown']++;
			if ( 'overdue' === $derived ) {
				$stats['overdue']++;
			}
			if ( $current && 'pending' === $current->status && $current->due_date >= $today ) {
				if ( $current->due_date <= $due30 ) {
					$stats['due_30']++;
				}
				if ( $current->due_date <= $due90 ) {
					$stats['due_90']++;
				}
			}
			foreach ( $filings as $f ) {
				if ( 'filed' === $f->status && (int) $f->period_year === $year ) {
					$stats['filed_this_year']++;
					break;
				}
			}
		}

		return rest_ensure_response( array( 'entities' => $entities, 'stats' => $stats ) );
	}

	public function create_ops_compliance_entity( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );

		$name = sanitize_text_field( (string) $request->get_param( 'entity_name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'bad_request', 'Entity name is required.', array( 'status' => 400 ) );
		}

		$type   = sanitize_key( (string) $request->get_param( 'entity_type' ) );
		$type   = in_array( $type, $this->get_compliance_valid_entity_types(), true ) ? $type : 'llc';
		$estatus = sanitize_key( (string) $request->get_param( 'entity_status' ) );
		$estatus = in_array( $estatus, $this->get_compliance_valid_entity_statuses(), true ) ? $estatus : 'active';

		$jurisdiction = strtoupper( sanitize_text_field( (string) $request->get_param( 'jurisdiction' ) ) );
		$jurisdiction = preg_match( '/^[A-Z]{2}$/', $jurisdiction ) ? $jurisdiction : 'NC';

		$formation = sanitize_text_field( (string) $request->get_param( 'formation_date' ) );
		$formation = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $formation ) ? $formation : null;

		$first_year = absint( $request->get_param( 'first_report_year' ) );
		if ( ! $first_year && $formation ) {
			// NC annual reports start the year after formation.
			$first_year = (int) substr( $formation, 0, 4 ) + 1;
		}
		if ( ! $first_year ) {
			$first_year = (int) gmdate( 'Y' );
		}

		$due_month = absint( $request->get_param( 'due_month' ) );
		$due_month = ( $due_month >= 1 && $due_month <= 12 ) ? $due_month : 4;
		$due_day   = absint( $request->get_param( 'due_day' ) );
		$due_day   = ( $due_day >= 1 && $due_day <= 28 ) ? $due_day : 15;

		$inserted = $pdb->insert( $t_entities, array(
			'stripe_customer_id' => sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) ),
			'entity_name'        => $name,
			'entity_type'        => $type,
			'jurisdiction'       => $jurisdiction,
			'sos_id'             => sanitize_text_field( (string) $request->get_param( 'sos_id' ) ),
			'formation_date'     => $formation,
			'first_report_year'  => $first_year,
			'due_month'          => $due_month,
			'due_day'            => $due_day,
			'entity_status'      => $estatus,
			'notes'              => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
			'created_by'         => get_current_user_id(),
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d' ) );

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', 'Failed to create entity.', array( 'status' => 500 ) );
		}
		$entity_id = (int) $pdb->insert_id;

		if ( 'active' === $estatus ) {
			$entity = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_entities}` WHERE id = %d", $entity_id ) );
			$year   = $this->get_compliance_target_year( $entity );
			$pdb->query( $pdb->prepare(
				"INSERT IGNORE INTO `{$t_filings}` (entity_id, filing_type, period_year, due_date) VALUES (%d, 'annual_report', %d, %s)",
				$entity_id,
				$year,
				$this->compute_compliance_due_date( $entity, $year )
			) );
		}

		return rest_ensure_response( array( 'success' => true, 'id' => $entity_id ) );
	}

	public function update_ops_compliance_entity( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );
		$id         = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_Error( 'bad_request', 'Invalid entity ID.', array( 'status' => 400 ) );
		}

		$data    = array();
		$formats = array();
		$p       = $request->get_params();

		if ( isset( $p['entity_name'] ) && '' !== $p['entity_name'] ) {
			$data['entity_name'] = sanitize_text_field( (string) $p['entity_name'] ); $formats[] = '%s';
		}
		if ( isset( $p['stripe_customer_id'] ) ) {
			$data['stripe_customer_id'] = sanitize_text_field( (string) $p['stripe_customer_id'] ); $formats[] = '%s';
		}
		if ( isset( $p['entity_type'] ) && in_array( $p['entity_type'], $this->get_compliance_valid_entity_types(), true ) ) {
			$data['entity_type'] = $p['entity_type']; $formats[] = '%s';
		}
		if ( isset( $p['jurisdiction'] ) && preg_match( '/^[A-Za-z]{2}$/', (string) $p['jurisdiction'] ) ) {
			$data['jurisdiction'] = strtoupper( (string) $p['jurisdiction'] ); $formats[] = '%s';
		}
		if ( isset( $p['sos_id'] ) ) {
			$data['sos_id'] = sanitize_text_field( (string) $p['sos_id'] ); $formats[] = '%s';
		}
		if ( array_key_exists( 'formation_date', $p ) ) {
			$fd = sanitize_text_field( (string) $p['formation_date'] );
			$data['formation_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fd ) ? $fd : null;
			$formats[] = '%s';
		}
		if ( isset( $p['first_report_year'] ) ) {
			$data['first_report_year'] = absint( $p['first_report_year'] ); $formats[] = '%d';
		}
		if ( isset( $p['due_month'] ) && absint( $p['due_month'] ) >= 1 && absint( $p['due_month'] ) <= 12 ) {
			$data['due_month'] = absint( $p['due_month'] ); $formats[] = '%d';
		}
		if ( isset( $p['due_day'] ) && absint( $p['due_day'] ) >= 1 && absint( $p['due_day'] ) <= 28 ) {
			$data['due_day'] = absint( $p['due_day'] ); $formats[] = '%d';
		}
		if ( isset( $p['entity_status'] ) && in_array( $p['entity_status'], $this->get_compliance_valid_entity_statuses(), true ) ) {
			$data['entity_status'] = $p['entity_status']; $formats[] = '%s';
		}
		if ( array_key_exists( 'notes', $p ) ) {
			$data['notes'] = sanitize_textarea_field( (string) $p['notes'] ); $formats[] = '%s';
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'bad_request', 'No fields to update.', array( 'status' => 400 ) );
		}

		$updated = $pdb->update( $t_entities, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $updated ) {
			return new WP_Error( 'db_error', 'Failed to update entity.', array( 'status' => 500 ) );
		}

		// Keep pending deadlines in sync when the due-date rule changes.
		if ( isset( $data['due_month'] ) || isset( $data['due_day'] ) ) {
			$entity   = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_entities}` WHERE id = %d", $id ) );
			$pending  = $pdb->get_results( $pdb->prepare( "SELECT id, period_year FROM `{$t_filings}` WHERE entity_id = %d AND status = 'pending'", $id ) );
			foreach ( (array) $pending as $f ) {
				$pdb->update(
					$t_filings,
					array( 'due_date' => $this->compute_compliance_due_date( $entity, (int) $f->period_year ) ),
					array( 'id' => (int) $f->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	public function delete_ops_compliance_entity( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$id  = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_Error( 'bad_request', 'Invalid entity ID.', array( 'status' => 400 ) );
		}
		$pdb->delete( $this->portal_table( 'aj_portal_compliance_filings' ), array( 'entity_id' => $id ), array( '%d' ) );
		$pdb->delete( $this->portal_table( 'aj_portal_compliance_entities' ), array( 'id' => $id ), array( '%d' ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function update_ops_compliance_filing( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );
		$id         = absint( $request->get_param( 'id' ) );
		$action     = sanitize_key( (string) $request->get_param( 'action' ) );

		$filing = $id ? $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_filings}` WHERE id = %d", $id ) ) : null;
		if ( ! $filing ) {
			return new WP_Error( 'not_found', 'Filing not found.', array( 'status' => 404 ) );
		}

		if ( 'file' === $action ) {
			$pdb->update( $t_filings, array(
				'status'       => 'filed',
				'filed_at'     => current_time( 'mysql' ),
				'filed_by'     => get_current_user_id(),
				'confirmation' => sanitize_text_field( (string) $request->get_param( 'confirmation' ) ),
			), array( 'id' => $id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );

			// Queue next year's deadline so the calendar always shows what is next.
			$entity = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_entities}` WHERE id = %d", (int) $filing->entity_id ) );
			if ( $entity && 'active' === $entity->entity_status ) {
				$next_year = (int) $filing->period_year + 1;
				$pdb->query( $pdb->prepare(
					"INSERT IGNORE INTO `{$t_filings}` (entity_id, filing_type, period_year, due_date) VALUES (%d, %s, %d, %s)",
					(int) $entity->id,
					(string) $filing->filing_type,
					$next_year,
					$this->compute_compliance_due_date( $entity, $next_year )
				) );
			}
		} elseif ( 'reopen' === $action ) {
			$pdb->update( $t_filings, array(
				'status'       => 'pending',
				'filed_at'     => null,
				'filed_by'     => 0,
				'confirmation' => '',
			), array( 'id' => $id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );
		} elseif ( 'waive' === $action ) {
			$pdb->update( $t_filings, array( 'status' => 'waived' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		} elseif ( 'update' === $action ) {
			$data    = array();
			$formats = array();
			$p       = $request->get_params();
			if ( array_key_exists( 'notes', $p ) ) {
				$data['notes'] = sanitize_textarea_field( (string) $p['notes'] ); $formats[] = '%s';
			}
			if ( isset( $p['due_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $p['due_date'] ) ) {
				$data['due_date'] = (string) $p['due_date']; $formats[] = '%s';
			}
			if ( empty( $data ) ) {
				return new WP_Error( 'bad_request', 'No fields to update.', array( 'status' => 400 ) );
			}
			$pdb->update( $t_filings, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		} else {
			return new WP_Error( 'bad_request', 'Unknown filing action.', array( 'status' => 400 ) );
		}

		AJCore_RA_Compliance_Tasks::reconcile();
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function remind_ops_compliance_entity( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );
		$t_customers = $this->portal_table( 'aj_portal_stripe_customers' );
		$id         = absint( $request->get_param( 'id' ) );

		$entity = $id ? $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_entities}` WHERE id = %d", $id ) ) : null;
		if ( ! $entity ) {
			return new WP_Error( 'not_found', 'Entity not found.', array( 'status' => 404 ) );
		}

		$filing = $pdb->get_row( $pdb->prepare(
			"SELECT * FROM `{$t_filings}` WHERE entity_id = %d AND status = 'pending' ORDER BY due_date ASC LIMIT 1",
			$id
		) );
		if ( ! $filing ) {
			return new WP_Error( 'bad_request', 'No pending filing to remind about.', array( 'status' => 400 ) );
		}

		$customer = '' !== (string) $entity->stripe_customer_id
			? $pdb->get_row( $pdb->prepare( "SELECT name, email FROM `{$t_customers}` WHERE stripe_customer_id = %s", (string) $entity->stripe_customer_id ) )
			: null;
		if ( ! $customer || ! is_email( (string) $customer->email ) ) {
			return new WP_Error( 'bad_request', 'Entity has no linked customer with a valid email.', array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'server_error', 'Admin module unavailable.', array( 'status' => 500 ) );
		}
		$sent = AJCore_RA_Compliance_Reminders::send( $entity, $filing, (string) $customer->name, (string) $customer->email );
		if ( ! $sent ) {
			return new WP_Error( 'send_failed', 'The reminder email could not be sent.', array( 'status' => 500 ) );
		}

		$pdb->update( $t_filings, array(
			'last_reminder_at' => current_time( 'mysql' ),
			'reminders_sent'   => (int) $filing->reminders_sent + 1,
		), array( 'id' => (int) $filing->id ), array( '%s', '%d' ), array( '%d' ) );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/** Client portal: the current user's entities with their filing deadlines. */
	public function get_portal_compliance() {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );
		if ( ! $this->table_exists( $pdb, $t_entities ) ) {
			return rest_ensure_response( array( 'entities' => array() ) );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return rest_ensure_response( array( 'entities' => array() ) );
		}

		$this->ensure_compliance_filing_rows();

		$entities_raw = $pdb->get_results( $pdb->prepare(
			"SELECT * FROM `{$t_entities}` WHERE stripe_customer_id = %s ORDER BY entity_name ASC",
			$stripe_customer_id
		) );
		$entities_raw = is_array( $entities_raw ) ? $entities_raw : array();

		$entities = array();
		foreach ( $entities_raw as $e ) {
			$filings = $pdb->get_results( $pdb->prepare(
				"SELECT * FROM `{$t_filings}` WHERE entity_id = %d ORDER BY period_year DESC, id DESC",
				(int) $e->id
			) );
			$entities[] = array(
				'id'             => (int) $e->id,
				'entity_name'    => (string) $e->entity_name,
				'entity_type'    => (string) $e->entity_type,
				'jurisdiction'   => (string) $e->jurisdiction,
				'sos_id'         => (string) $e->sos_id,
				'formation_date' => (string) $e->formation_date,
				'entity_status'  => (string) $e->entity_status,
				'filings'        => array_map( array( $this, 'format_compliance_filing_row' ), (array) $filings ),
			);
		}

		return rest_ensure_response( array( 'entities' => $entities ) );
	}

	/**
	 * Client portal: the customer marks a filing complete on their side ("I handled
	 * this / it's filed"). Staff still verify and mark it officially filed in ops;
	 * automated reminders stop once the client has marked it complete.
	 */
	public function complete_portal_compliance_filing( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );
		$id         = absint( $request->get_param( 'id' ) );

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return new WP_Error( 'forbidden', __( 'No linked customer account.', 'ajforms' ), array( 'status' => 403 ) );
		}

		$filing = $id ? $pdb->get_row( $pdb->prepare(
			"SELECT f.*, e.stripe_customer_id AS owner_customer_id
			FROM `{$t_filings}` f
			INNER JOIN `{$t_entities}` e ON e.id = f.entity_id
			WHERE f.id = %d",
			$id
		) ) : null;
		if ( ! $filing || (string) $filing->owner_customer_id !== $stripe_customer_id ) {
			return new WP_Error( 'not_found', __( 'Filing not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$completed = $request->get_param( 'completed' );
		$completed = null === $completed ? true : rest_sanitize_boolean( $completed );

		$data = array(
			'client_completed'    => $completed ? 1 : 0,
			'client_completed_at' => $completed ? current_time( 'mysql' ) : null,
		);
		$formats = array( '%d', '%s' );
		if ( null !== $request->get_param( 'note' ) ) {
			$data['client_note'] = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
			$formats[]           = '%s';
		}
		$pdb->update( $t_filings, $data, array( 'id' => $id ), $formats, array( '%d' ) );

		$fresh = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_filings}` WHERE id = %d", $id ) );
		AJCore_RA_Compliance_Tasks::reconcile();
		return rest_ensure_response( array( 'success' => true, 'filing' => $this->format_compliance_filing_row( $fresh ) ) );
	}
}
