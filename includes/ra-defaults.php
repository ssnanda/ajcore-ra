<?php
/**
 * NC LLC Agents / Registered Agent defaults that used to be hard-coded in AJCore.
 * AJCore now ships neutral defaults and exposes two filters; these callbacks restore the
 * original values while AJCore-RA is active. Saved site settings still override both, so a
 * site that has customized any of these is unaffected.
 *
 * Registered at file load (not on plugins_loaded) because AJCore may read settings before
 * plugins_loaded priority 20. Harmless if AJCore is absent: the filters never fire.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

add_filter( 'ajforms_settings_defaults', 'ajcore_ra_settings_defaults' );
add_filter( 'ajcore_portal_overview_defaults', 'ajcore_ra_overview_defaults' );
add_filter( 'ajcore_email_powered_by', 'ajcore_ra_email_powered_by' );
// Turns on the Registered Agent Authorization email template (tab + ops send/preview).
add_filter( 'ajcore_ra_authorization_enabled', '__return_true' );
add_filter( 'ajcore_default_brand', 'ajcore_ra_default_brand' );
add_filter( 'ajcore_customer_brand', 'ajcore_ra_customer_brand', 10, 2 );
add_filter( 'ajcore_business_contact', 'ajcore_ra_business_contact' );
add_filter( 'ajcore_ra_authorization_default_body_lines', 'ajcore_ra_authorization_body_lines' );
add_filter( 'ajcore_ra_authorization_default_address', 'ajcore_ra_authorization_address' );

/**
 * True on the University Place Office Suites site. That site gets its own brand defaults
 * (AJCore already carries them as university_* keys) and none of the NC LLC Agents content,
 * whether or not RA is enabled elsewhere. Filterable for other hosts/local domains.
 */
function ajcore_ra_is_university_site() {
	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$hosts = apply_filters( 'ajcore_ra_university_hosts', array( 'universityofficesuites.com', 'upos.ddev.site' ) );
	foreach ( (array) $hosts as $h ) {
		if ( $host === $h || substr( $host, -strlen( '.' . $h ) ) === '.' . $h ) {
			return true;
		}
	}
	return false;
}

/**
 * TEMPORARY test aid: tags the five email defaults RA supplies with " [RA]" so it's visible
 * whether a default came from RA or from AJCore alone. Only affects fields with nothing saved.
 * Remove by returning '' from the 'ajcore_ra_defaults_marker' filter, or delete this function.
 */
function ajcore_ra_mark_defaults( $defaults ) {
	$marker = (string) apply_filters( 'ajcore_ra_defaults_marker', ' [RA]' );
	if ( '' === $marker ) {
		return $defaults;
	}
	foreach ( array( 'wp_password_reset_subject', 'wp_welcome_email_subject', 'lead_followup_email_subject', 'lead_followup_body', 'email_footer_address' ) as $key ) {
		if ( isset( $defaults[ $key ] ) && '' !== $defaults[ $key ] ) {
			$defaults[ $key ] .= $marker;
		}
	}
	return $defaults;
}

function ajcore_ra_settings_defaults( $defaults ) {
	$defaults['ra_authorization_subject'] = 'Registered Agent Authorization and Address Use for {company}';
	$defaults['ra_authorization_heading'] = 'Registered Agent Authorization';
	$defaults['ra_authorization_body']    = implode( "\n", ajcore_ra_authorization_body_lines() );
	$defaults['ra_authorization_address'] = ajcore_ra_authorization_address();
	return ajcore_ra_mark_defaults( ajcore_ra_brand_defaults( $defaults ) );
}

function ajcore_ra_brand_defaults( $defaults ) {
	if ( ajcore_ra_is_university_site() ) {
		$map = array(
			'wp_password_reset_subject'   => 'university_wp_password_reset_subject',
			'wp_welcome_email_subject'    => 'university_wp_welcome_email_subject',
			'lead_followup_email_subject' => 'university_lead_followup_email_subject',
			'lead_followup_body'          => 'university_lead_followup_body',
			'email_footer_address'        => 'university_email_footer_address',
		);
		foreach ( $map as $key => $university_key ) {
			if ( isset( $defaults[ $university_key ] ) ) {
				$defaults[ $key ] = $defaults[ $university_key ];
			}
		}
		return $defaults;
	}

	return array_merge(
		$defaults,
		array(
			'wp_password_reset_subject'   => 'Password reset for your Portal Login for NC LLC Agents Inc',
			'wp_welcome_email_subject'    => 'Welcome : Your portal access is enabled to NC LLC Agents Inc',
			'lead_followup_email_subject' => 'Following up from NC LLC Agents',
			'lead_followup_body'          => "Hi {name},\nWe wanted to follow up on your recent inquiry with NC LLC Agents. If you have any questions or would like to talk through your options, give us a call — we are happy to help.\nReady to get started? You can review our services and pricing anytime on our website.",
			'email_footer_address'        => "NC LLC Agents Inc.\n1914 J N Pease Pl., Charlotte, NC 28262\n(704) 307-2135 \xc2\xb7 contactus@ncllcagents.com",
		)
	);
}

function ajcore_ra_overview_defaults( $defaults ) {
	if ( ajcore_ra_is_university_site() ) {
		return $defaults; // BOI / NC LLC content is NC LLC Agents only.
	}
	$defaults['banner_enabled'] = true;
	$defaults['banner_heading'] = __( 'Beneficial Ownership Information (BOI) Report:', 'ajcore-ra' );
	$defaults['banner_message'] = __( 'a federal filing most LLCs and corporations must submit to FinCEN — significant penalties can apply if you miss the deadline.', 'ajcore-ra' );
	$defaults['banner_button']  = __( 'Learn More', 'ajcore-ra' );
	$defaults['banner_url']     = home_url( '/do-you-need-to-file-a-beneficial-ownership-information-boi-report/' );
	$defaults['resources']      = array(
		array(
			'url'     => home_url( '/do-you-need-to-file-a-beneficial-ownership-information-boi-report/' ),
			'enabled' => true,
			'title'   => __( 'Do You Need to File a Beneficial Ownership Information (BOI) Report?', 'ajcore-ra' ),
			'blurb'   => __( 'A federal filing with FinCEN, separate from anything you file with NC — significant penalties can apply if you miss it.', 'ajcore-ra' ),
		),
		array(
			'url'     => home_url( '/beware-misleading-mailings-targeting-new-nc-companies/' ),
			'enabled' => true,
			'title'   => __( 'Beware: Misleading Mailings Targeting New NC Companies', 'ajcore-ra' ),
			'blurb'   => __( 'Official-looking mail that isn’t from the state, charging well above what NC actually charges — here’s how to spot it.', 'ajcore-ra' ),
		),
		array(
			'url'     => home_url( '/important-notice-to-employers-your-new-nc-llcs-reporting-responsibilities/' ),
			'enabled' => true,
			'title'   => __( 'Important Notice to Employers: Your New NC LLC’s Reporting Responsibilities', 'ajcore-ra' ),
			'blurb'   => __( 'Hiring your first employee triggers obligations with three different state agencies — what kicks in and when.', 'ajcore-ra' ),
		),
	);
	return $defaults;
}

/** Email footer credit: shows RA is active alongside AJCore. */
function ajcore_ra_email_powered_by( $text ) {
	return '' === trim( (string) $text ) ? $text : $text . ' + AJ Core RA';
}

/** NC LLC Agents identity for emails sent to non-University customers. */
function ajcore_ra_default_brand( $brand ) {
	$brand['entity_name'] = 'NC LLC Agents Inc';
	$brand['from_email']  = 'donotreply@ncllcagents.com';
	return $brand;
}

/**
 * University Place Office Suites brand (moved out of AJCore). Claims a customer or lead for
 * University Place when its site is universityofficesuites.com or, for customers, when it is
 * partner-billed (opus / alliance_vo). 'settings_prefix' makes AJCore read that brand's own
 * university_* saved settings.
 *
 * @param array|null $brand   Null unless another extension already claimed it.
 * @param array      $context kind (customer|lead), site_domain, partner_key.
 */
function ajcore_ra_customer_brand( $brand, $context ) {
	if ( is_array( $brand ) ) {
		return $brand;
	}
	$domain  = isset( $context['site_domain'] ) ? (string) $context['site_domain'] : '';
	$partner = isset( $context['partner_key'] ) ? (string) $context['partner_key'] : '';
	$is_customer = isset( $context['kind'] ) && 'customer' === $context['kind'];

	if ( false === strpos( $domain, 'universityofficesuites.com' ) && ! ( $is_customer && in_array( $partner, array( 'opus', 'alliance_vo' ), true ) ) ) {
		return $brand;
	}

	return array(
		'entity_name'     => 'University Place Office Suites LLC',
		'site_name'       => 'University Place Office Suites LLC',
		'site_url'        => 'https://universityofficesuites.com/',
		'from_email'      => 'donotreply@universityofficesuites.com',
		'settings_prefix' => 'university_',
	);
}

/** Contact details shown in email footers and info boxes. None on the University Place site,
 *  which has no phone/link of its own to show. */
function ajcore_ra_business_contact( $contact ) {
	if ( ajcore_ra_is_university_site() ) {
		return $contact;
	}
	return array(
		'phone'       => '(704) 307-2135',
		'email'       => 'contactus@ncllcagents.com',
		'service_url' => 'https://ncllcagents.com/service',
	);
}

function ajcore_ra_authorization_address() {
	return "NC LLC Agents Inc.\n1914 J N Pease Pl.\nCharlotte, NC 28262\nagent@ncllcagents.com";
}

function ajcore_ra_authorization_body_lines() {
	return array(
		__( 'You are authorized to use the following information for Registered Agent purposes only:', 'ajcore-ra' ),
		__( '- Do not use our phone number anywhere on the filing.', 'ajcore-ra' ),
		__( "- The address above is the Registered Agent / Registered Office address only. It is not authorized for use as the company's Principal Office address, Mailing Address, or Business Address.", 'ajcore-ra' ),
		__( '- We authorize use of this address only for the North Carolina Secretary of State filing through the SOSNC website.', 'ajcore-ra' ),
		__( '- This authorization does not permit use of our address on Google, business directories, websites, bank accounts, licenses, marketing materials, vendor accounts, or any other registrations or filings.', 'ajcore-ra' ),
		__( '- If you need to use our address anywhere other than the Registered Agent section of the NC Secretary of State filing, please text or contact us first for approval.', 'ajcore-ra' ),
	);
}
