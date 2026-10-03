<?php
/**
 * Registered Agent tasks: starter templates for the Tasks tab, and one customer task per
 * annual-report filing that follows the filing.
 *
 * - Templates appear under "Start from template" when adding a task in AJCore.
 * - For every Active compliance record with a customer, a task "File your {year} annual report"
 *   is created in the customer's portal Tasks list once the filing is due within 90 days.
 *   Filed -> completed; waived or record made inactive -> cancelled; pending -> open (or
 *   completed once the customer marked the filing done). If the customer completes the task
 *   themselves, the filing is flagged "client completed" (ops still confirm it as filed).
 * - The filing/task link is kept in an option (filing_id => task_id), so no table changes. If
 *   an admin deletes a task it is not recreated.
 *
 * Reuses AJCore's task tables through AJForms_Admin::get_task_toolkit().
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Compliance_Tasks {

	const OPTION   = 'ajcore_ra_filing_tasks';
	const WANTED   = 'ajcore_ra_filing_task_status'; // filing_id => status we last applied
	const HORIZON  = 90; // days before the due date that the task appears.
	const GUARD    = 'ajcore_ra_filing_tasks_sync';

	public static function init() {
		add_filter( 'ajcore_task_templates', array( __CLASS__, 'templates' ) );
		add_action( 'ajcore_compliance_reminders', array( __CLASS__, 'reconcile' ), 20 );
	}

	/** Starter tasks for Registered Agent customers. */
	public static function templates( $templates ) {
		$templates['ra_annual_report']   = array(
			'label'           => __( 'File annual report', 'ajcore-ra' ),
			'title'           => __( 'File your annual report', 'ajcore-ra' ),
			'action_required' => __( 'Your company’s annual report is due with the Secretary of State. Reply to us and we will prepare and file it for you.', 'ajcore-ra' ),
			'task_frequency'  => 'recurring',
			'task_scope'      => 'client',
			'client_visible'  => true,
		);
		$templates['ra_boi_report']      = array(
			'label'           => __( 'Beneficial ownership (BOI) report', 'ajcore-ra' ),
			'title'           => __( 'Review your Beneficial Ownership Information (BOI) report', 'ajcore-ra' ),
			'action_required' => __( 'Confirm whether your company must file a BOI report with FinCEN and let us know if you need help.', 'ajcore-ra' ),
			'task_frequency'  => 'one_time',
			'task_scope'      => 'client',
			'client_visible'  => true,
		);
		$templates['ra_confirm_address'] = array(
			'label'           => __( 'Confirm registered agent address', 'ajcore-ra' ),
			'title'           => __( 'Confirm your registered agent address is current', 'ajcore-ra' ),
			'action_required' => __( 'Check that your company records list our registered agent address, and tell us if anything has changed.', 'ajcore-ra' ),
			'task_frequency'  => 'one_time',
			'task_scope'      => 'client',
			'client_visible'  => true,
		);
		return $templates;
	}

	private static function toolkit() {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return null;
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		return method_exists( $admin, 'get_task_toolkit' ) ? $admin->get_task_toolkit() : null;
	}

	/** At most hourly, for the list screen. */
	public static function maybe_reconcile() {
		if ( get_transient( self::GUARD ) ) {
			return;
		}
		self::reconcile();
		set_transient( self::GUARD, 1, HOUR_IN_SECONDS );
	}

	/** Idempotent: safe to run any number of times. */
	public static function reconcile() {
		// One site only: the compliance data is shared, but the filing->task list is kept per site,
		// so a second site running this would create every task again. Same rule as the reminders.
		if ( function_exists( 'ajcore_is_stripe_sync_owner' ) && ! ajcore_is_stripe_sync_owner() ) {
			return;
		}
		$tasks = self::toolkit();
		if ( ! $tasks || ! class_exists( 'AJCore_Extensions' ) ) {
			return;
		}
		$rest = AJCore_Extensions::rest_toolkit();
		$pdb  = $rest['portal_db']();
		$t_e  = $rest['portal_table']( 'aj_portal_compliance_entities' );
		$t_f  = $rest['portal_table']( 'aj_portal_compliance_filings' );
		if ( ! $rest['table_exists']( $pdb, $t_e ) || ! $rest['table_exists']( $pdb, $t_f ) ) {
			return;
		}

		$map     = get_option( self::OPTION, array() );
		$map     = is_array( $map ) ? $map : array();
		$applied = get_option( self::WANTED, array() );
		$applied = is_array( $applied ) ? $applied : array();
		$mapped  = array_map( 'absint', array_keys( $map ) );
		$horizon = gmdate( 'Y-m-d', time() + self::HORIZON * DAY_IN_SECONDS );

		$where_mapped = $mapped ? ' OR f.id IN (' . implode( ',', $mapped ) . ')' : '';
		$rows         = $pdb->get_results( $pdb->prepare(
			"SELECT f.id, f.entity_id, f.period_year, f.due_date, f.status, f.client_completed,
				e.stripe_customer_id, e.entity_name, e.entity_status
			FROM `{$t_f}` f
			INNER JOIN `{$t_e}` e ON e.id = f.entity_id
			WHERE e.stripe_customer_id <> ''
			AND ( ( f.status = 'pending' AND e.entity_status = 'active' AND f.due_date <= %s ){$where_mapped} )
			LIMIT 1000",
			$horizon
		) );

		$changed = false;
		foreach ( (array) $rows as $row ) {
			$fid      = (int) $row->id;
			$customer = (string) $row->stripe_customer_id;
			$task_id  = isset( $map[ $fid ] ) ? (int) $map[ $fid ] : 0;

			if ( -1 === $task_id ) {
				continue; // deleted by an admin on purpose
			}

			// What the task should say about this filing.
			if ( 'filed' === $row->status ) {
				$want = 'completed';
			} elseif ( 'waived' === $row->status || 'active' !== $row->entity_status ) {
				$want = 'cancelled';
			} else {
				$want = ! empty( $row->client_completed ) ? 'completed' : 'open';
			}

			if ( ! $task_id ) {
				if ( 'pending' !== $row->status || 'active' !== $row->entity_status ) {
					continue; // never create a task for something already settled
				}
				$title = sprintf(
					/* translators: 1: report year, 2: company name */
					__( 'File your %1$s annual report — %2$s', 'ajcore-ra' ),
					(int) $row->period_year,
					(string) $row->entity_name
				);
				$body  = sprintf(
					/* translators: %s: due date */
					__( 'Your annual report is due %s. We will prepare and file it for you — reply to us if you have any changes.', 'ajcore-ra' ),
					mysql2date( get_option( 'date_format' ), (string) $row->due_date . ' 00:00:00' )
				);
				$new = (int) $tasks['create']( $customer, $title, (string) $row->due_date, $body, $want );
				if ( $new ) {
					$map[ $fid ]     = $new;
					$applied[ $fid ] = $want;
					$changed         = true;
				}
				continue;
			}

			$state = $tasks['state']( $task_id, $customer );
			if ( null === $state ) {
				$map[ $fid ] = -1; // task was deleted; remember so it isn't recreated
				$changed     = true;
				continue;
			}

			// The customer completed the task themselves (we did not set it completed): flag the
			// filing as client-completed and keep the task completed. An ops undo of that flag
			// is not mistaken for this, because we last applied 'completed' ourselves.
			$last = isset( $applied[ $fid ] ) ? $applied[ $fid ] : '';
			if ( 'completed' === $state['customer_status'] && 'pending' === $row->status && empty( $row->client_completed ) && 'completed' !== $last ) {
				$pdb->update( $t_f, array( 'client_completed' => 1, 'client_completed_at' => current_time( 'mysql' ) ), array( 'id' => $fid ), array( '%d', '%s' ), array( '%d' ) );
				$applied[ $fid ] = 'completed';
				$changed         = true;
				continue;
			}

			$current = '' !== $state['customer_status'] ? $state['customer_status'] : $state['task_status'];
			if ( $current !== $want ) {
				$tasks['set_status']( $task_id, $customer, $want );
			}
			if ( $last !== $want ) {
				$applied[ $fid ] = $want;
				$changed         = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $map, false );
			update_option( self::WANTED, $applied, false );
		}
	}
}
