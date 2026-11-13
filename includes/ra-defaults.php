<?php
/**
 * NC LLC Agents Inc / Registered Agent defaults that used to be hard-coded in AJCore.
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
// Stored university_* settings belong to RA's University Place brand; AJCore's save leaves them alone.
add_filter( 'ajcore_brand_setting_prefixes', 'ajcore_ra_brand_setting_prefixes' );
add_filter( 'ajcore_portal_office_address', 'ajcore_ra_portal_office_address', 10, 2 );
add_filter( 'ajcore_portal_file_settings', 'ajcore_ra_portal_file_settings' );
add_filter( 'ajcore_service_status_labels', 'ajcore_ra_service_status_labels' );
add_filter( 'ajcore_service_workflow_labels', 'ajcore_ra_service_workflow_labels' );
add_filter( 'ajcore_service_status_pipelines', 'ajcore_ra_service_status_pipelines' );
add_filter( 'ajcore_service_request_product_type', 'ajcore_ra_service_request_product_type', 10, 3 );
add_filter( 'ajcore_service_request_types', 'ajcore_ra_service_request_types' );
add_filter( 'ajcore_service_request_quick_actions', 'ajcore_ra_pllc_quick_actions', 10, 4 );
add_filter( 'ajcore_service_request_action_map', 'ajcore_ra_action_map' );
add_filter( 'ajcore_service_request_waiting_statuses', function ( $s ) { $s[] = 'waiting_licensing_board'; return $s; } );
add_filter( 'ajcore_customer_billing_types', 'ajcore_ra_customer_billing_types' );
add_filter( 'ajcore_partners_enabled', '__return_true' );
// Registered Agent / Virtual Office customer filters in AJOps.
add_filter( 'ajcore_registered_agent_enabled', '__return_true' );
// Numbers AJ Core RA adds to the AJOps menu and dashboard (see AJCore REST get_ops_counts).
add_filter( 'ajcore_ops_counts', function ( $counts ) {
	$row = static function ( $value, $label, $alert = false ) {
		return array( 'value' => (int) $value, 'label' => $label, 'alert' => (bool) $alert && (int) $value > 0 );
	};
	$call = static function ( $route, $args = array() ) {
		$req = new WP_REST_Request( 'GET', '/ajcore/v1' . $route );
		foreach ( $args as $k => $v ) {
			$req->set_param( $k, $v );
		}
		$res = rest_do_request( $req );
		return $res->is_error() ? array() : (array) $res->get_data();
	};
	try {
		if ( class_exists( 'AJCore_RA_Website' ) ) {
			$counts['websites'] = $row( count( (array) AJCore_RA_Website::list_sites() ), 'Websites' );
		}
		if ( class_exists( 'AJCore_RA_Business_Profile' ) ) {
			$counts['business-profiles'] = $row( count( (array) AJCore_RA_Business_Profile::api_list() ), 'Profiles' );
		}
		$esign = $call( '/ops/esign/summary' );
		if ( isset( $esign['waiting'] ) ) {
			$counts['esign'] = $row( $esign['waiting'], 'Waiting for signature', true );
		}
	} catch ( \Throwable $e ) {
		// Counts are optional; never break the menu over them.
	}
	return $counts;
} );
// AJOps menu items AJ Core RA provides (AJ Core lists its own).
add_filter( 'ajcore_ops_modules', function ( $modules ) {
	return array_merge( (array) $modules, array( 'partners', 'compliance', 'websites', 'business-profiles', 'mail', 'esign', 'rentec', 'cmra', 'reception', 'leases', 'guest-log' ) );
} );
// Postal Mail belongs to AJ Core RA: its staff-access section and its /ops/mail route mapping are added here, not in AJ Core.
add_filter( 'ajcore_ops_sections', function ( $sections ) {
	$sections         = (array) $sections;
	$sections['mail'] = 'Postal Mail';
	return $sections;
} );
add_filter( 'ajcore_ops_route_sections', function ( $map ) {
	$map         = (array) $map;
	$map['mail'] = 'mail';
	return $map;
} );
add_filter( 'ajcore_portal_menu_default_items', 'ajcore_ra_portal_menu_compliance_on', 20 );
add_filter( 'ajcore_partner_site_domain', 'ajcore_ra_partner_site_domain', 10, 2 );
add_filter( 'ajcore_reservation_location', 'ajcore_ra_reservation_location' );
add_filter( 'ajcore_business_contact', 'ajcore_ra_business_contact' );
add_filter( 'ajcore_ra_authorization_default_body_lines', 'ajcore_ra_authorization_body_lines' );
add_filter( 'ajcore_ra_authorization_default_address', 'ajcore_ra_authorization_address' );

/**
 * True on the University Place Office Suites site. That site gets its own brand defaults
 * (AJCore already carries them as university_* keys) and none of the NC LLC Agents Inc content,
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

function ajcore_ra_brand_setting_prefixes( $prefixes ) {
	$prefixes[] = 'university_';
	return $prefixes;
}

/**
 * University Place Office Suites defaults (moved out of AJCore). Used for customers and leads
 * claimed by ajcore_ra_customer_brand(). The footer address is blank on purpose: the business's
 * postal address was never provided, and a wrong address in a customer email is a real error.
 */
function ajcore_ra_university_defaults() {
	return array(
			'university_wp_password_reset_subject'    => 'Password reset for your University Place Office Suites LLC portal login',
			'university_wp_password_reset_heading'    => 'Set your client portal password',
			'university_wp_password_reset_body'       => "Hi {name},\nUse the secure button below to create a new password for your client portal account. This link is private and should only be used by you.",
			'university_wp_password_reset_from_email' => 'donotreply@universityofficesuites.com',
			'university_wp_password_reset_from_name'  => 'University Place Office Suites LLC',
			'university_wp_welcome_email_subject'     => 'Welcome : Your portal access is enabled to University Place Office Suites LLC',
			'university_wp_welcome_heading'           => 'Welcome to your client portal',
			'university_wp_welcome_body'              => "Hi {name},\nYour client portal access has been enabled. Use the button below to set your password and sign in securely.",
			'university_wp_welcome_from_email'        => 'donotreply@universityofficesuites.com',
			'university_wp_welcome_from_name'         => 'University Place Office Suites LLC',
			'university_wp_service_status_subject'    => 'Update on {service_name}: {status_label}',
			'university_wp_service_status_heading'    => 'Your service request was updated',
			'university_wp_service_status_body'       => "Hi {name},\nThe status of \"{service_name}\" has changed.",
			'university_wp_service_status_from_email' => 'donotreply@universityofficesuites.com',
			'university_wp_service_status_from_name'  => 'University Place Office Suites LLC',
			'university_lead_followup_email_subject'  => 'Following up from University Place Office Suites LLC',
			'university_lead_followup_heading'        => "We'd love to hear from you",
			'university_lead_followup_body'           => "Hi {name},\nWe wanted to follow up on your recent inquiry with University Place Office Suites LLC. If you have any questions or would like to talk through your options, give us a call — we are happy to help.\nReady to get started? You can review our services and pricing anytime on our website.",
			'university_lead_followup_from_email'     => 'donotreply@universityofficesuites.com',
			'university_lead_followup_from_name'      => 'University Place Office Suites LLC',
			'university_email_footer_address' => '',
	);
}

function ajcore_ra_settings_defaults( $defaults ) {
	$defaults = array_merge( $defaults, ajcore_ra_university_defaults() );
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
			'lead_followup_email_subject' => 'Following up from NC LLC Agents Inc',
			'lead_followup_body'          => "Hi {name},\nWe wanted to follow up on your recent inquiry with NC LLC Agents Inc. If you have any questions or would like to talk through your options, give us a call — we are happy to help.\nReady to get started? You can review our services and pricing anytime on our website.",
			'email_footer_address'        => "NC LLC Agents Inc.\n1914 J N Pease Pl., Charlotte, NC 28262\n(704) 307-2135 \xc2\xb7 contactus@ncllcagents.com",
		)
	);
}

function ajcore_ra_overview_defaults( $defaults ) {
	if ( ajcore_ra_is_university_site() ) {
		return $defaults; // These links are NC LLC Agents' own.
	}
	$defaults['resources'] = array(
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

/** NC LLC Agents Inc identity for emails sent to non-University customers. */
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

/**
 * "Our Office Address" card on the client portal Overview. Same rule AJCore used to hard-code:
 * shown on the University Place site, and for any customer with tracking services.
 */
/** Files categories RA's filing intake files into. Added to whatever the site has saved, and shown to customers as an important group. */
function ajcore_ra_portal_file_settings( $settings ) {
	// Filing categories are RA's; AJCore ships only "Others". Added on read, so existing saved settings pick them up.
	$groups = array(
		'important_categories' => array( 'IRS EIN Letters', 'Formation Documents' ),
		'extra_categories'     => array( 'SOSNC Flyers' ),
	);
	$cats = array_values( array_diff( (array) $settings['categories'], array( 'Others', 'Articles of Organization' ) ) );
	$settings['important_categories'] = array_values( array_diff( (array) $settings['important_categories'], array( 'Articles of Organization' ) ) );
	foreach ( array( 'Formation Documents', 'IRS EIN Letters', 'SOSNC Flyers', 'Change of RA', 'ID Proof', 'Address Proof', 'USPS Form 1583' ) as $cat ) {
		if ( ! in_array( $cat, $cats, true ) ) {
			$cats[] = $cat;
		}
	}
	$cats[] = 'Others';
	$settings['categories'] = $cats;
	// Internal tags are RA's too; on by default for migration like before.
	foreach ( array( 'registered-agent' => 'RegisteredAgent', 'virtual-office' => 'VirtualOffice' ) as $slug => $label ) {
		$norm = function ( $v ) {
			return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $v ) );
		};
		$have = array_map( $norm, array_merge( array_keys( (array) $settings['tags'] ), array_values( (array) $settings['tags'] ) ) );
		if ( ! in_array( $norm( $label ), $have, true ) ) {
			$settings['tags'][ $slug ] = $label;
			$settings['migration_tags'][] = $slug;
		}
	}
	$settings['migration_tags'] = array_values( array_unique( (array) $settings['migration_tags'] ) );
	foreach ( $groups as $key => $names ) {
		foreach ( $names as $cat ) {
			if ( ! in_array( $cat, (array) $settings[ $key ], true ) ) {
				$settings[ $key ][] = $cat;
			}
		}
	}
	return $settings;
}

/** OPUS and Alliance VO customers are University Place customers: pinned to its site. */
function ajcore_ra_partner_site_domain( $domain, $partner_key ) {
	return in_array( (string) $partner_key, array( 'opus', 'alliance_vo' ), true ) ? 'universityofficesuites.com' : $domain;
}

/** Where conference-room reservations are held (Reservations page header and Zoho calendar events). */
function ajcore_ra_reservation_location( $loc ) {
	return array(
		'name'    => 'University Place Office Suites',
		'address' => '1914 J N Pease Pl, Charlotte, NC 28262, United States',
		'url'     => 'https://universityofficesuites.com/',
	);
}

function ajcore_ra_portal_office_address( $address, $context ) {
	if ( ! ajcore_ra_is_university_site() && empty( $context['has_tracking_services'] ) ) {
		return $address;
	}
	return array(
		'title' => __( 'Our Office Address', 'ajcore-ra' ),
		'name'  => 'University Place Office Suites',
		'lines' => array( '1914 J N PEASE PL.', 'CHARLOTTE, NC 28262' ),
	);
}

/** The customer portal Compliance tab is off by default in AJCore; Registered Agent sites turn it on (still editable under Client Portal, Menu). */
function ajcore_ra_portal_menu_compliance_on( $items ) {
	foreach ( (array) $items as $i => $item ) {
		if ( is_array( $item ) && isset( $item['id'] ) && 'tasks' === $item['id'] ) {
			$items[ $i ]['enabled'] = true;
		}
	}
	return $items;
}

/** Registered Agent / LLC / Virtual Office request statuses (AJCore ships only the generic ones). */
function ajcore_ra_service_status_labels( $labels ) {
	$ra = array(
		'sosnc_filing'            => __( 'Filed with SOS/NC', 'ajcore-ra' ),
		'llc_documents_emailed'   => __( 'LLC Documents Emailed', 'ajcore-ra' ),
		'signing_cmra'            => __( 'Signing CMRA', 'ajcore-ra' ),
		'id_proof_needed'         => __( 'ID Proof Needed', 'ajcore-ra' ),
		'address_proof_needed'    => __( 'Address Proof Needed', 'ajcore-ra' ),
		'vo_setup_required'       => __( 'Virtual Office Setup Required', 'ajcore-ra' ),
		'sosnc_client'            => __( 'Customer Updating SOS/NC', 'ajcore-ra' ),
		'waiting_licensing_board' => __( 'Waiting on Licensing Board', 'ajcore-ra' ),
		'updating_sosn'           => __( 'RA Change Filed with SOS/NC', 'ajcore-ra' ),
		'included_with_llc_setup' => __( 'Included with LLC Setup', 'ajcore-ra' ),
	);
	// Keep the generic order, slotting RA's statuses in before Active/Completed/Cancelled.
	$tail = array();
	foreach ( array( 'active', 'completed', 'cancelled' ) as $key ) {
		if ( isset( $labels[ $key ] ) ) {
			$tail[ $key ] = $labels[ $key ];
			unset( $labels[ $key ] );
		}
	}
	return $labels + $ra + $tail;
}

/** Fixed forward pipelines per Registered Agent product. */
function ajcore_ra_service_status_pipelines( $sets ) {
	// This bundle always includes a year of Registered Agent service, so it settles into "active" like a standalone subscription.
	$sets['llc_setup']                     = array( 'new', 'welcome', 'meeting_scheduled', 'sosnc_filing', 'llc_documents_emailed', 'active', 'cancelled' );
	// Always bundled with a Virtual Office subscription; settles into "active" once the one-time proof setup is done.
	$sets['virtual_office_setup']          = array( 'new', 'welcome', 'signing_cmra', 'id_proof_needed', 'address_proof_needed', 'active', 'cancelled' );
	// Buying this subscription means one thing: get the CMRA signed, then it is active.
	$sets['virtual_office_subscription']   = array( 'new', 'welcome', 'signing_cmra', 'active', 'cancelled' );
	// One thing: update the registered agent on SOS/NC (we do it, or the customer does), then it is active.
	$sets['registered_agent_subscription'] = array( 'new', 'welcome', 'updating_sosn', 'active', 'cancelled' );
	// A PLLC (professional LLC) also needs its licensing board's approval before the registered agent change is filed.
	$sets['registered_agent_subscription_pllc'] = array( 'new', 'welcome', 'waiting_licensing_board', 'updating_sosn', 'active', 'cancelled' );
	return $sets;
}

/** Maps a request's product name (lowercased, separators removed) to the RA product types above. */
function ajcore_ra_service_request_product_type( $type, $name, $request = null ) {
	$request_type = is_object( $request ) && isset( $request->request_type ) ? sanitize_key( (string) $request->request_type ) : '';
	if ( 'registered_agent_subscription' === $request_type ) {
		return ajcore_ra_request_is_pllc( $request, $name ) ? 'registered_agent_subscription_pllc' : 'registered_agent_subscription';
	}
	if ( in_array( $request_type, array( 'llc_setup', 'virtual_office_setup', 'virtual_office_subscription' ), true ) ) {
		return $request_type;
	}
	if ( false !== strpos( $name, 'llc setup' ) || false !== strpos( $name, 'entity setup' ) || false !== strpos( $name, 'new entity' ) ) {
		return 'llc_setup';
	}
	if ( false !== strpos( $name, 'registered agent' ) ) {
		return ajcore_ra_request_is_pllc( $request, $name ) ? 'registered_agent_subscription_pllc' : 'registered_agent_subscription';
	}
	if ( false !== strpos( $name, 'virtual office' ) ) {
		return ( false !== strpos( $name, 'setup' ) || false !== strpos( $name, 'one time' ) || false !== strpos( $name, 'one-time' ) ) ? 'virtual_office_setup' : 'virtual_office_subscription';
	}
	return $type;
}

/**
 * True when the request's customer is a PLLC (customer name, or the business profile's business name, says so).
 * Only the workflow's steps depend on this; an existing request keeps its current status either way.
 */
function ajcore_ra_request_is_pllc( $request, $name = '' ) {
	static $cache = array();
	$re = '/\bp\.?\s?l\.?\s?l\.?\s?c\b|professional\s+limited\s+liability/i';
	if ( '' !== $name && preg_match( $re, (string) $name ) ) {
		return true;
	}
	$customer_id = is_object( $request ) && ! empty( $request->stripe_customer_id ) ? (string) $request->stripe_customer_id : '';
	if ( '' === $customer_id || ! class_exists( 'AJCore_Extensions' ) ) {
		return false;
	}
	if ( isset( $cache[ $customer_id ] ) ) {
		return $cache[ $customer_id ];
	}
	$found = false;
	$kit   = AJCore_Extensions::rest_toolkit();
	$db    = isset( $kit['portal_db'] ) ? $kit['portal_db']() : null;
	if ( $db ) {
		$names = array();
		foreach ( array( array( 'aj_portal_stripe_customers', 'stripe_customer_id' ), array( 'aj_portal_local_customers', 'local_customer_id' ) ) as $src ) {
			$table = $kit['portal_table']( $src[0] );
			if ( $kit['table_exists']( $db, $table ) ) {
				$names[] = (string) $db->get_var( $db->prepare( "SELECT name FROM `{$table}` WHERE {$src[1]} = %s LIMIT 1", $customer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}
		$mappings = $kit['portal_table']( 'aj_portal_entity_mappings' );
		if ( $kit['table_exists']( $db, $mappings ) ) {
			$names[] = (string) $db->get_var( $db->prepare( "SELECT entity_label FROM `{$mappings}` WHERE stripe_customer_id = %s AND entity_type = 'business_profile' LIMIT 1", $customer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		foreach ( $names as $n ) {
			if ( '' !== $n && preg_match( $re, $n ) ) {
				$found = true;
				break;
			}
		}
	}
	$cache[ $customer_id ] = $found;
	return $found;
}

/** Next-step buttons for the PLLC Registered Agent workflow (the standard one is built into AJ Core). */
function ajcore_ra_pllc_quick_actions( $actions, $request, $product_type, $service_status ) {
	if ( 'registered_agent_subscription_pllc' !== $product_type || ! is_array( $actions ) ) {
		return $actions;
	}
	// Only the steps before the SOS/NC filing change; from there on AJ Core's own buttons (Activate, Cancel, ...) apply.
	if ( 'waiting_licensing_board' === $service_status ) {
		return array( 'update_sosn' => __( 'File RA Change with SOS/NC', 'ajcore-ra' ) ) + array_diff_key( $actions, array_flip( array( 'activate', 'complete', 'under_review', 'pending_customer', 'pending_agent' ) ) );
	}
	if ( in_array( $service_status, array( 'new', '', 'welcome', 'under_review' ), true ) && ! empty( $actions ) ) {
		// AJ Core builds its generic buttons for this type (Start Review, ...); a PLLC goes to the licensing board instead.
		return array( 'wait_licensing_board' => __( 'Waiting on Licensing Board', 'ajcore-ra' ) ) + array_diff_key( $actions, array_flip( array( 'under_review', 'pending_customer', 'pending_agent', 'complete', 'update_sosn' ) ) );
	}
	return $actions;
}

function ajcore_ra_action_map( $map ) {
	if ( is_array( $map ) ) {
		$map['wait_licensing_board'] = 'waiting_licensing_board';
	}
	return $map;
}

/** Types offered in New request (the type sets the workflow). */
function ajcore_ra_service_request_types( $types ) {
	$types['llc_setup']                     = __( 'LLC Setup', 'ajcore-ra' );
	$types['virtual_office_setup']          = __( 'Virtual Office Setup', 'ajcore-ra' );
	$types['virtual_office_subscription']   = __( 'Virtual Office Subscription', 'ajcore-ra' );
	$types['registered_agent_subscription'] = __( 'Registered Agent Subscription', 'ajcore-ra' );
	return $types;
}

/** Partner-billed customer types (University Place). */
function ajcore_ra_customer_billing_types( $types ) {
	$types['opus']        = array( 'label' => __( 'OPUS (Stripe)', 'ajcore-ra' ), 'local' => false, 'partner' => 'opus', 'hint' => __( 'Created in Stripe. OPUS pays the fixed VO rate.', 'ajcore-ra' ) );
	$types['alliance_vo'] = array( 'label' => __( 'Alliance VO (local)', 'ajcore-ra' ), 'local' => true, 'partner' => 'alliance_vo', 'hint' => __( 'Local customer assigned to Alliance VO. Not created in Stripe.', 'ajcore-ra' ) );
	$types['suite_client'] = array( 'label' => __( 'Suite Clients (local)', 'ajcore-ra' ), 'local' => true, 'hint' => __( 'Office suite client. Local customer, not created in Stripe.', 'ajcore-ra' ) );
	return $types;
}

/** Display names for the RA workflows (Workflows view). */
function ajcore_ra_service_workflow_labels( $names ) {
	$names['llc_setup']                     = __( 'LLC Setup', 'ajcore-ra' );
	$names['virtual_office_setup']          = __( 'Virtual Office Setup', 'ajcore-ra' );
	$names['virtual_office_subscription']   = __( 'Virtual Office Subscription', 'ajcore-ra' );
	$names['registered_agent_subscription'] = __( 'Registered Agent Subscription', 'ajcore-ra' );
	$names['registered_agent_subscription_pllc'] = __( 'Registered Agent Subscription (PLLC)', 'ajcore-ra' );
	return $names;
}
