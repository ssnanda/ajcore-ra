<?php
/**
 * Annual-report reminders (email + daily job), moved out of AJCore.
 *
 * The reminder email, the daily scheduler and the two Email Templates entries ("Annual Report
 * Reminder" / "Overdue") are unchanged copies of the AJCore code, using AJCore's mail helpers
 * through AJForms_Admin::get_email_toolkit(). The cron hook keeps its old name
 * (ajcore_compliance_reminders), so the schedule already stored in each site's WP-Cron keeps
 * working and RA only has to supply the callback; with RA off the event fires and does nothing.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Compliance_Reminders {

	const HOOK = 'ajcore_compliance_reminders';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_job' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_filter( 'ajcore_additional_branded_email_templates', array( __CLASS__, 'add_templates' ) );
		// Saved overrides for these emails (wp_compliance_*) must survive AJCore's settings save
		// even on a request where the templates aren't listed.
		add_filter( 'ajcore_preserved_setting_prefixes', array( __CLASS__, 'preserved_prefixes' ) );
	}

	public static function preserved_prefixes( $prefixes ) {
		$prefixes[] = 'wp_compliance_';
		return $prefixes;
	}

	/** Daily, first run two hours after being noticed missing (same as AJCore did). */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function add_templates( $templates ) {
		return array_merge( $templates, self::templates() );
	}

	private static function email_kit() {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return null;
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		return method_exists( $admin, 'get_email_toolkit' ) ? $admin->get_email_toolkit() : null;
	}

	private static function portal_db() {
		$kit = AJCore_Extensions::rest_toolkit();
		return $kit['portal_db']();
	}

	/** The two Email Templates entries (read-only catalog) that used to live in AJCore. */
	public static function templates() {
		return array(
			'compliance_overdue' => array(
				'label' => __( 'Annual Report Overdue', 'ajcore-ra' ),
				'key' => 'wp_compliance_overdue',
				'sender_key' => 'wp_compliance',
				'brand_variants' => false,
				'to' => __( 'Email of the customer linked to the compliance entity.', 'ajcore-ra' ),
				'fixed' => __( 'Shown when the filing due date has passed. Deadline box includes entity, report year and due date. Footer: You are receiving this because we serve as registered agent for this entity.', 'ajcore-ra' ),
				'default_subject' => __( 'OVERDUE: {year} {state} Annual Report for {entity_name}', 'ajcore-ra' ),
				'default_heading' => __( 'The annual report for {entity_name} is past due', 'ajcore-ra' ),
				'default_body' => array(
					sprintf( __( 'Hi %s,', 'ajcore-ra' ), '{name}' ),
					__( 'The {year} annual report for {entity_name} was due {due_date} and has not been filed with the {state} Secretary of State.', 'ajcore-ra' ),
					__( 'A missed annual report can lead to administrative dissolution. We can prepare and file it for you — reply to this email or contact us to get it taken care of.', 'ajcore-ra' ),
				),
			),
			'compliance_reminder' => array(
				'label' => __( 'Annual Report Reminder', 'ajcore-ra' ),
				'key' => 'wp_compliance_reminder',
				'sender_key' => 'wp_compliance',
				'brand_variants' => false,
				'to' => __( 'Email of the customer linked to the compliance entity.', 'ajcore-ra' ),
				'fixed' => __( 'Sent by Remind now or the scheduled reminder windows. Deadline box includes entity, report year and due date. Footer: You are receiving this because we serve as registered agent for this entity.', 'ajcore-ra' ),
				'default_subject' => __( 'Reminder: {year} {state} Annual Report for {entity_name} is due {due_date}', 'ajcore-ra' ),
				'default_heading' => __( 'An annual report deadline is coming up', 'ajcore-ra' ),
				'default_body' => array(
					sprintf( __( 'Hi %s,', 'ajcore-ra' ), '{name}' ),
					__( 'The {year} annual report for {entity_name} is due {due_date} with the {state} Secretary of State.', 'ajcore-ra' ),
					__( 'We can prepare and file it for you so nothing slips — reply to this email or contact us and we will handle the rest.', 'ajcore-ra' ),
				),
			)
		);
	}

	/**
	 * Branded compliance-deadline reminder (annual report). Used by the ops REST
	 * "Remind now" action and the daily reminder cron.
	 */
	public static function send( $entity, $filing, $customer_name, $customer_email ) {
		$customer_email = sanitize_email( (string) $customer_email );
		if ( ! is_email( $customer_email ) ) {
			return false;
		}
		$kit = self::email_kit();
		if ( ! $kit ) {
			return false;
		}

		$settings  = $kit['settings']();
		$brand     = $kit['brand']( ! empty( $entity->stripe_customer_id ) ? $entity->stripe_customer_id : '', $customer_email );
		$sender    = $kit['sender']( $settings, 'wp_compliance_from_email', 'wp_compliance_from_name' );
		$site_name = $brand['site_name'];
		if ( $kit['is_brand_variant']( $brand ) ) {
			$sender['from_name'] = $brand['site_name'];
		}

		$due_date  = mysql2date( get_option( 'date_format' ), (string) $filing->due_date . ' 00:00:00' );
		$days_left = (int) floor( ( strtotime( (string) $filing->due_date ) - strtotime( gmdate( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
		$overdue   = $days_left < 0;

		$email_tokens = array(
			'{name}'        => '' !== trim( (string) $customer_name ) ? $customer_name : $customer_email,
			'{entity_name}' => (string) $entity->entity_name,
			'{year}'        => (string) $filing->period_year,
			'{due_date}'    => $due_date,
			'{state}'       => (string) $entity->jurisdiction,
		);

		if ( $overdue ) {
			$template = self::templates()['compliance_overdue'];
			$default_subject = $template['default_subject'];
			$default_heading = $template['default_heading'];
			$default_body    = $template['default_body'];
			$subject_key = 'wp_compliance_overdue_subject';
			$heading_key = 'wp_compliance_overdue_heading';
			$body_key    = 'wp_compliance_overdue_body';
		} else {
			$template = self::templates()['compliance_reminder'];
			$default_subject = $template['default_subject'];
			$default_heading = $template['default_heading'];
			$default_body    = $template['default_body'];
			$subject_key = 'wp_compliance_reminder_subject';
			$heading_key = 'wp_compliance_reminder_heading';
			$body_key    = 'wp_compliance_reminder_body';
		}

		$subject_template = ! empty( $settings[ $subject_key ] ) ? sanitize_text_field( (string) $settings[ $subject_key ] ) : $default_subject;
		$subject          = $kit['brand_subject']( strtr( $subject_template, $email_tokens ), $brand );

		$copy = $kit['copy_raw']( $settings, $heading_key, $body_key, $default_heading, $default_body, $email_tokens );

		$info_value = sprintf(
			/* translators: 1: entity name, 2: report year, 3: due date */
			__( '%1$s — %2$s annual report — due %3$s', 'ajcore-ra' ),
			(string) $entity->entity_name,
			(string) $filing->period_year,
			$due_date
		);

		$message = $kit['render'](
			array(
				'kicker'         => $site_name,
				'heading'        => $copy['heading'],
				'paragraphs'     => $copy['paragraphs'],
				'info_box_label' => __( 'Deadline', 'ajcore-ra' ),
				'info_box_value' => $info_value,
				'footer_note'    => __( 'You are receiving this because we serve as registered agent for this entity.', 'ajcore-ra' ),
			)
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $sender['from_email'] ) ) {
			$headers[] = 'From: ' . $sender['from_name'] . ' <' . $sender['from_email'] . '>';
			$headers[] = 'Reply-To: ' . $sender['from_email'];
		}

		return (bool) $kit['send']( $customer_email, $subject, $message, $headers, $sender['from_email'], $sender['from_name'], isset( $sender['profile'] ) ? $sender['profile'] : '' );
	}


	/**
	 * Daily cron: walk pending filings and email the linked customer as each
	 * reminder window opens (60/30/14/7 days out, then overdue). Stage tracking on
	 * the filing row makes the job idempotent — each window fires at most once.
	 */
	public static function run_job() {
		if ( '1' !== (string) get_option( 'ajcore_compliance_reminders_enabled', '1' ) ) {
			return;
		}
		if ( function_exists( 'ajcore_is_stripe_sync_owner' ) && ! ajcore_is_stripe_sync_owner() ) {
			return;
		}

		$pdb         = self::portal_db();
		$t_entities  = $pdb->prefix . 'aj_portal_compliance_entities';
		$t_filings   = $pdb->prefix . 'aj_portal_compliance_filings';
		$t_customers = $pdb->prefix . 'aj_portal_stripe_customers';
		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $t_filings ) ) !== $t_filings ) {
			return;
		}

		// Seed the current year's filing row for any active entity missing one, so
		// reminders fire after Jan 1 even if nobody has opened the ops calendar yet.
		$current_year = (int) gmdate( 'Y' );
		$entities     = $pdb->get_results( "SELECT * FROM `{$t_entities}` WHERE entity_status = 'active'" );
		foreach ( (array) $entities as $entity ) {
			$year   = max( $current_year, (int) $entity->first_report_year );
			$exists = (int) $pdb->get_var( $pdb->prepare(
				"SELECT COUNT(*) FROM `{$t_filings}` WHERE entity_id = %d AND filing_type = 'annual_report' AND period_year = %d",
				(int) $entity->id,
				$year
			) );
			if ( ! $exists ) {
				$month = max( 1, min( 12, (int) $entity->due_month ) );
				$day   = max( 1, min( 28, (int) $entity->due_day ) );
				$pdb->query( $pdb->prepare(
					"INSERT IGNORE INTO `{$t_filings}` (entity_id, filing_type, period_year, due_date) VALUES (%d, 'annual_report', %d, %s)",
					(int) $entity->id,
					$year,
					sprintf( '%04d-%02d-%02d', $year, $month, $day )
				) );
			}
		}

		$horizon = gmdate( 'Y-m-d', time() + 60 * DAY_IN_SECONDS );
		$rows    = $pdb->get_results( $pdb->prepare(
			"SELECT f.*, e.entity_name, e.jurisdiction, e.stripe_customer_id, c.name AS customer_name, c.email AS customer_email
			FROM `{$t_filings}` f
			INNER JOIN `{$t_entities}` e ON e.id = f.entity_id AND e.entity_status = 'active'
			LEFT JOIN `{$t_customers}` c ON c.stripe_customer_id = e.stripe_customer_id
			WHERE f.status = 'pending' AND f.client_completed = 0 AND f.due_date <= %s
			ORDER BY f.due_date ASC
			LIMIT 500",
			$horizon
		) );

		$stage_rank = array( '' => 0, '60d' => 1, '30d' => 2, '14d' => 3, '7d' => 4, 'overdue' => 5 );
		$today_ts   = strtotime( gmdate( 'Y-m-d' ) );

		foreach ( (array) $rows as $row ) {
			if ( ! is_email( (string) $row->customer_email ) ) {
				continue;
			}

			$days_left = (int) floor( ( strtotime( (string) $row->due_date ) - $today_ts ) / DAY_IN_SECONDS );
			if ( $days_left < 0 ) {
				$desired = 'overdue';
			} elseif ( $days_left <= 7 ) {
				$desired = '7d';
			} elseif ( $days_left <= 14 ) {
				$desired = '14d';
			} elseif ( $days_left <= 30 ) {
				$desired = '30d';
			} else {
				$desired = '60d';
			}

			$current = isset( $stage_rank[ (string) $row->reminder_stage ] ) ? (string) $row->reminder_stage : '';
			if ( $stage_rank[ $desired ] <= $stage_rank[ $current ] ) {
				continue;
			}

			$sent = self::send( $row, $row, (string) $row->customer_name, (string) $row->customer_email );
			if ( $sent ) {
				$pdb->update(
					$t_filings,
					array(
						'reminder_stage'   => $desired,
						'last_reminder_at' => current_time( 'mysql' ),
						'reminders_sent'   => (int) $row->reminders_sent + 1,
					),
					array( 'id' => (int) $row->id ),
					array( '%s', '%s', '%d' ),
					array( '%d' )
				);
			}
		}
	}
}
