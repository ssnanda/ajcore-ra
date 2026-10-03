<?php
/**
 * Registered Agent authorization / address-use notice. Moved out of AJCore: AJCore keeps the
 * Email Templates tab and the ops routes, and asks this file to build and send the email
 * through the 'ajcore_ra_authorization_*' filters. Uses AJCore's mail helpers via
 * AJForms_Admin::get_email_toolkit(), so branding, sender rules, logging and tracking are
 * unchanged.
 *
 * The notice is always FROM the registered agent, whichever site the customer is assigned
 * to: the kicker comes from the first line of the address block, never from the customer's
 * brand (a University Place customer must not see it branded as University Place).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

add_filter( 'ajcore_ra_authorization_build', 'ajcore_ra_authorization_build', 10, 3 );
add_filter( 'ajcore_ra_authorization_send', 'ajcore_ra_authorization_send', 10, 3 );
add_filter( 'ajcore_ra_authorization_static_parts', 'ajcore_ra_authorization_static_parts', 10, 2 );

/** Layout and labels for the template (also used by the Email Templates preview). */
function ajcore_ra_authorization_static_parts( $parts, $address ) {
	$signature = trim( preg_replace( '/\s*\n\s*/', " \xc2\xb7 ", trim( (string) $address ) ) );

	return array(
		'info_box_label'  => __( 'Registered Agent / Registered Office address', 'ajcore-ra' ),
		'info_box_layout' => 'stacked',
		'checklist_title' => __( 'Please note the following important requirements', 'ajcore-ra' ),
		'footer_note'     => '' !== $signature
			? sprintf( __( 'Thank you, %s', 'ajcore-ra' ), $signature )
			: __( 'Thank you,', 'ajcore-ra' ),
	);
}

/** @return array|WP_Error Assembled email (to/subject/message/headers/from_*), not sent. */
function ajcore_ra_authorization_build( $built, $stripe_customer_id, $company = '' ) {
	if ( ! class_exists( 'AJForms_Admin' ) || ! AJForms_Admin::$instance || ! method_exists( AJForms_Admin::$instance, 'get_email_toolkit' ) ) {
		return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
	}
	$kit = AJForms_Admin::$instance->get_email_toolkit();

	$customer = $kit['customer']( $stripe_customer_id );
	if ( ! $customer ) {
		return new WP_Error( 'customer_not_found', __( 'Customer not found.', 'ajcore-ra' ) );
	}
	if ( ! is_email( (string) $customer->email ) ) {
		return new WP_Error( 'no_customer_email', __( 'This customer has no valid email address on file.', 'ajcore-ra' ) );
	}

	$settings   = $kit['settings']();
	$sender     = $kit['sender']( $settings, 'ra_authorization_from_email', 'ra_authorization_from_name' );
	$from_email = $sender['from_email'];
	$from_name  = $sender['from_name'];

	$company_name = sanitize_text_field( (string) $company );
	if ( '' === $company_name ) {
		$company_name = sanitize_text_field( (string) $customer->name );
	}
	if ( '' === $company_name ) {
		$company_name = (string) $customer->email;
	}

	$address = isset( $settings['ra_authorization_address'] ) && '' !== trim( (string) $settings['ra_authorization_address'] )
		? (string) $settings['ra_authorization_address']
		: ajcore_ra_authorization_address();

	$address_lines = preg_split( '/\r\n|\r|\n/', trim( $address ) );
	$agent_name    = ! empty( $address_lines[0] ) ? trim( (string) $address_lines[0] ) : get_bloginfo( 'name' );

	$tokens = array(
		'{name}'      => '' !== (string) $customer->name ? (string) $customer->name : (string) $customer->email,
		'{company}'   => $company_name,
		'{site_name}' => $agent_name,
	);

	$subject_template = ! empty( $settings['ra_authorization_subject'] )
		? sanitize_text_field( (string) $settings['ra_authorization_subject'] )
		: __( 'Registered Agent Authorization and Address Use for {company}', 'ajcore-ra' );
	$subject = strtr( $subject_template, $tokens );

	$copy = $kit['copy'](
		$settings,
		'ra_authorization_heading',
		'ra_authorization_body',
		__( 'Registered Agent Authorization', 'ajcore-ra' ),
		ajcore_ra_authorization_body_lines(),
		$tokens
	);

	$message = $kit['render'](
		array_merge(
			array(
				'kicker'          => $agent_name,
				'heading'         => $copy['heading'],
				'paragraphs'      => $copy['paragraphs'],
				'checklist_items' => $copy['checklist_items'],
				'info_box_value'  => $address,
			),
			ajcore_ra_authorization_static_parts( array(), $address ),
			$kit['footer_parts']( array(), __( 'You received this because we act as the Registered Agent for your company.', 'ajcore-ra' ) )
		)
	);

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	if ( is_email( $from_email ) ) {
		$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
		$headers[] = 'Reply-To: ' . $from_email;
	}

	return array(
		'to'         => $customer->email,
		'subject'    => $subject,
		'message'    => $message,
		'headers'    => $headers,
		'from_email' => $from_email,
		'from_name'  => $from_name,
	);
}

/**
 * Sends the notice to the customer record's own email. Does not need a linked WordPress user,
 * so it can go to a customer who never got portal access. wp_mail() is logged and tracked by
 * AJCore (aj_portal_email_log), which is what AJOps' Email Log screen reads.
 *
 * @return true|WP_Error
 */
function ajcore_ra_authorization_send( $result, $stripe_customer_id, $company = '' ) {
	$built = ajcore_ra_authorization_build( null, $stripe_customer_id, $company );
	if ( is_wp_error( $built ) ) {
		return $built;
	}

	$kit  = AJForms_Admin::$instance->get_email_toolkit();
	$sent = $kit['send']( $built['to'], $built['subject'], $built['message'], $built['headers'], $built['from_email'], $built['from_name'] );
	if ( ! $sent ) {
		return new WP_Error( 'ra_authorization_failed', __( 'Registered Agent authorization email could not be sent.', 'ajcore-ra' ) );
	}

	return true;
}
