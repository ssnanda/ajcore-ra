<?php
/**
 * Insurance mailbox parser: turns one email into facts (what it is, policy / account number, amount, due date, property, tenant).
 *
 * Three kinds of mail share the insurance mailbox:
 *  - carrier notices for the owner's own policies (Auto-Owners dwelling fire bills / renewals / cancellation invoices, Steadily, CNA, AmTrust, Travelers, Progressive);
 *  - renters insurance for tenants: proof sent by the tenant or their agent, "interested party" notices (Lemonade, MSI), reminders;
 *  - agency correspondence, account / login mail and marketing (labelled "notice").
 *
 * Pure function of (item, text): the only lookups are the Rentec property / tenant matcher and the remembered policy -> property links.
 *
 * @package AJCore_RA
 */

defined( 'ABSPATH' ) || exit;

class AJCore_RA_Mail_Insurance {

	/** Bump when the rules change: stored mail is parsed again. */
	const VERSION = 1;

	/** Carrier / sender name by sender-domain fragment. */
	private static $carriers = array(
		'esign.aoins.com'      => 'Auto-Owners',
		'aoins.com'            => 'Auto-Owners',
		'payerexpress.com'     => 'Auto-Owners',
		'amtrustgroup.com'     => 'AmTrust',
		'msimga.com'           => 'MSI',
		'lemonade.com'         => 'Lemonade',
		'steadily.com'         => 'Steadily',
		'cna.com'              => 'CNA',
		'progressive.com'      => 'Progressive',
		'travelers.com'        => 'Travelers',
		'geicomail.com'        => 'GEICO',
		'libertymutual.com'    => 'Liberty Mutual',
		'erieinsurance.com'    => 'Erie',
		'paymentus.com'        => 'Cincinnati Insurance',
		'rankininsurancegroup.com' => 'Rankin Insurance Group',
		'monacoinsgroup.com'   => 'Monaco Insurance',
		'causeybelleinsurance.com' => 'Causey-Belle Insurance',
		'montgomeryinsurancenc.com' => 'Montgomery Insurance',
		'ncfbins.com'          => 'NC Farm Bureau',
	);

	/** Carriers / agencies: mail from them is not from a tenant. */
	private static $agency_domains = array( 'rankininsurancegroup.com', 'monacoinsgroup.com', 'causeybelleinsurance.com', 'montgomeryinsurancenc.com', 'ncfbins.com' );

	/** Sender domains that never carry tenant data (marketing, account mail). */
	private static $promo_domains = array( 'alliancevirtualoffices.com', 'erieinsurance.com', 'rentreporters.com', 'mail.myappliedproducts.com', 'okta.com', 'mfa.amtrustgroup.com' );

	public static function carrier_of( $domain ) {
		foreach ( self::$carriers as $frag => $name ) {
			if ( '' !== $domain && false !== strpos( $domain, $frag ) ) {
				return $name;
			}
		}
		return '';
	}

	/** Policy numbers found in the text, in the formats these carriers use. */
	public static function policy_numbers( $text ) {
		$t   = preg_replace( '/[\x{2010}-\x{2015}]/u', '-', (string) $text ); // non-breaking hyphens in agency forwards
		$out = array();
		$res = array(
			'/\b(\d{2}-\d{6}-\d{2})\b/',                       // Auto-Owners 55-827237-38
			'/\b(SP\d-[A-Z]{2}-\d{6,9}-\d{2})\b/',            // Steadily
			'/\b(L[PH][0-9A-Z]{8,11})\b/',                   // Lemonade LP9C9A28752
			'/\b(K[A-Z]{2}\d{6,8})\b/',                       // AmTrust workers comp KWC1397152
			'/\b(\d{1,2}-[A-Z]{3}-[A-Z]{2}-\d{2}-\d{2})\b/', // cancellation notice refs
			'/\bpolicy\s*(?:number|no\.?|#)\s*:?\s*#?\s*([A-Z0-9][A-Z0-9\-]{5,24})\b/i',
			'/\bpolicy\s+(\d{6,12})\b/i',                    // "policy 84418303"
		);
		foreach ( $res as $re ) {
			if ( preg_match_all( $re, $t, $m ) ) {
				foreach ( $m[1] as $n ) {
					$n = strtoupper( trim( $n ) );
					if ( ! preg_match( '/^(NUMBER|TYPE|TERM|HOLDER)$/', $n ) && ! in_array( $n, $out, true ) ) {
						$out[] = $n;
					}
				}
			}
		}
		return $out;
	}

	/** The billing / customer account number ("Account Number: 103464176", "Billing Account Number: 104226790"). */
	public static function account_number( $text ) {
		if ( preg_match( '/\b(?:billing\s+)?account\s*(?:number|no\.?|#)\s*:?\s*#?\s*([0-9]{5,14})\b/i', (string) $text, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/** An effective date written after the word effective ("effective 7/22/2026", "effective date: June 30, 2026", "effective 8/14/26"). */
	private static function effective_date( $text ) {
		if ( preg_match( '/\beffective(?:\s+date)?\s*:?\s*((?:[A-Z][a-z]{2,8}\.? \d{1,2},? \d{4})|(?:\d{1,2}\/\d{1,2}\/\d{2,4}))/', (string) $text, $m ) ) {
			return AJCore_RA_Mail_Util::date( $m[1] );
		}
		return '';
	}

	/**
	 * @param array  $item { subject, sender, summary, received_at }
	 * @param string $body Plain text of the email.
	 * @return array Facts: kind, ref_no, amount, due_date, property_tag, property_name, tenant, facts[], label.
	 */
	public static function parse( array $item, $body, $html = '' ) {
		$U       = 'AJCore_RA_Mail_Util';
		$subject = $U::clean( $item['subject'] );
		$from    = (string) $item['sender'];
		$text    = $U::clean( $body );
		$all     = $subject . ' ' . $text;
		$dom     = $U::domain_of( $from );
		$email   = $U::email_of( $from );
		$carrier = self::carrier_of( $dom );
		$agency  = false;
		foreach ( self::$agency_domains as $d ) {
			if ( false !== strpos( $dom, $d ) ) {
				$agency = true;
			}
		}
		$internal = false !== strpos( $dom, 'intlord.com' ) || false !== strpos( $dom, 'itspector.com' ) || false !== strpos( $dom, 'nandaandcompany.com' );
		$sender_tenant = ( ! $agency && ! $internal && '' !== $email && '' === $carrier ) ? AJCore_RA_Property_Match::tenant_by_email( $email ) : null;
		$kind     = 'other';
		$cat      = '';
		$label    = '';
		$policies = self::policy_numbers( $all );
		$account  = self::account_number( $text );
		$amount   = null;
		$due      = '';
		$facts    = array( 'carrier' => $carrier );
		$tenant   = '';
		$addr_src = '';

		// ── what is it? ─────────────────────────────────────────────────────────
		$is_promo = false;
		foreach ( self::$promo_domains as $d ) {
			if ( false !== strpos( $dom, $d ) ) {
				$is_promo = true;
			}
		}
		if ( preg_match( '/one-time passcode|access code|password reset|account (?:confirmation|created)|new mytravelers|verification code/i', $subject . ' ' . substr( $text, 0, 200 ) ) ) {
			$kind  = 'account';
			$label = 'notice';
		} elseif ( $is_promo && ! preg_match( '/policy|certificate|renter/i', $subject ) ) {
			$kind  = 'promo';
			$label = 'notice';
		} elseif ( 'Progressive' === $carrier && preg_match( '/accident response|let us know what you think|survey|all set\b|important notice/i', $subject ) ) {
			$kind  = 'promo';
			$label = 'notice';
			$cat   = 'auto';
		} elseif ( 'Auto-Owners' === $carrier || 'AmTrust' === $carrier || 'CNA' === $carrier || 'Steadily' === $carrier || 'Cincinnati Insurance' === $carrier ) {
			if ( preg_match( '/reinstatement|reinstate/i', $subject ) ) {
				$kind = 'reinstatement';
			} elseif ( preg_match( '/cancellation invoice|premium payment is past due|payment is past due/i', $subject . ' ' . substr( $text, 0, 200 ) ) ) {
				$kind = 'past_due'; // "will be canceled if the Minimum Due is not received by ..."
			} elseif ( preg_match( '/confirmation of cancellation|cancellation notice|notice of cancellation|policy cancellation|cancel status/i', $subject . ' ' . substr( $text, 0, 600 ) ) ) {
				$kind = 'cancel_notice';
			} elseif ( preg_match( '/bill added|premium invoice|reminder for invoice|invoice is ready/i', $subject . ' ' . $text ) ) {
				$kind = 'bill';
			} elseif ( preg_match( '/payment confirmation|eft notice|authorized payment|payment is being processed|online payment/i', $subject . ' ' . substr( $text, 0, 300 ) ) ) {
				$kind = 'payment';
			} elseif ( preg_match( '/application/i', $subject ) ) {
				$kind = 'application';
			} elseif ( preg_match( '/proxy designation/i', $subject ) ) {
				$kind  = 'info';
				$label = 'notice';
			} elseif ( preg_match( '/new or updated policy|new policy|will renew|recently renewed|policy transaction|audit information|policy has been updated/i', $subject . ' ' . $text ) ) {
				$kind = preg_match( '/audit/i', $subject ) ? 'audit' : 'renewal';
			} else {
				$kind = 'policy_notice';
			}
		} elseif ( 'MSI' === $carrier ) {
			$kind = preg_match( '/cancel/i', $text ) ? 'cancel_notice' : 'policy_change';
			$cat  = 'renters';
		} elseif ( 'Lemonade' === $carrier ) {
			$kind = 'interested_party';
			$cat  = 'renters';
		} elseif ( in_array( $carrier, array( 'Progressive', 'Travelers', 'GEICO', 'Liberty Mutual' ), true ) ) {
			if ( preg_match( '/payment (?:confirmation|reminder)|scheduled payment/i', $subject ) ) {
				$kind = preg_match( '/reminder/i', $subject ) ? 'bill' : 'payment';
			} elseif ( preg_match( '/cancel/i', $subject ) ) {
				$kind = 'cancel_notice';
			} elseif ( preg_match( '/policy documents|policy has been updated|policy is all set|finalize your policy/i', $subject ) ) {
				$kind = 'renewal';
			} else {
				$kind  = 'policy_notice';
			}
			$cat = 'auto';
		} elseif ( preg_match( '/renters?\s+insurance\s+required|insurance required by your lease/i', $subject . ' ' . substr( $text, 0, 400 ) ) ) {
			$kind = 'renters_required'; // our reminder to a tenant (or the tenant's reply on that thread)
			$cat  = 'renters';
		} elseif ( preg_match( '/policy cancellation notice|notice of cancellation|cancellation notice/i', $subject ) ) {
			$kind = 'cancel_notice'; // forwarded by the agent
		} elseif ( preg_match( '/\b(?:renters?|rental|tenant)(?:\'s)?\s+(?:liability\s+)?(?:insurance|policy|declaration|dec page|endorsement|coverage|EOC)|evidence of coverage|\bEOC\b|renters?\b/i', $all ) ) {
			$kind = 'renters_proof';
			$cat  = 'renters';
		} elseif ( preg_match( '/\bCOI\b|certificate of insurance|proof of insurance|insurance certificate|proof of (?:renters|coverage|payment)/i', $all ) ) {
			$kind = 'renters_proof';
			$cat  = 'renters';
		} elseif ( preg_match( '/we recently conducted a survey|inspection|repairs\/concerns|underwriter/i', $text ) && $policies ) {
			$kind = 'underwriting'; // the insurer's survey of a property: repairs to make, a reply-by date
			$cat  = 'landlord';
		} elseif ( $sender_tenant ) {
			$kind = 'renters_proof'; // a tenant's own mail to the insurance box: the proof is the attachment (not readable here)
			$cat  = 'renters';
			$facts['attachment_only'] = strlen( $text ) < 80;
		} elseif ( preg_match( '/^(?:(?:Fwd?|Re):\s*)*\d{2,6}[\w\- ]{2,40}\s*:\s*\S/i', $subject ) && $internal ) {
			$kind = 'renters_proof'; // "332-4 Orchard Trace : Vicente Pineda Brito" forwarded by us
			$cat  = 'renters';
		} elseif ( $agency || ( $policies && ! $carrier ) ) {
			$kind = 'agency';
		} elseif ( $internal && '' === $text ) {
			$kind = 'scan';
		}

		// ── category (whose policy is it?) ─────────────────────────────────────
		if ( '' === $cat ) {
			if ( preg_match( '/dwelling fire|landlord|rental property/i', $all ) || 'Steadily' === $carrier ) {
				$cat = 'landlord';
			} elseif ( preg_match( '/personal auto|auto policy|progressive/i', $all ) ) {
				$cat = 'auto';
			} elseif ( 'AmTrust' === $carrier || preg_match( '/workers.? comp/i', $all ) ) {
				$cat = 'workers_comp';
			} elseif ( 'CNA' === $carrier || 'Cincinnati Insurance' === $carrier ) {
				$cat = 'commercial';
			} elseif ( 'Travelers' === $carrier ) {
				$cat = 'personal';
			}
		}

		// ── numbers, dates ──────────────────────────────────────────────────────
		$kind_money = in_array( $kind, array( 'bill', 'past_due', 'cancel_notice', 'payment', 'reinstatement' ), true );
		if ( $kind_money ) {
			if ( 'payment' === $kind ) {
				$amount = $U::amount_after( $text, array( 'Payment Amount', 'Total Amount', 'amount of', 'Amount Paid' ) );
				$due    = $U::date_after( $text, array( 'Payment Date', 'Due Date' ) );
			} else {
				$amount = $U::amount_after( $text, array( 'Minimum Due', 'Amount Due', 'Account Balance', 'Total Due', 'Balance Due' ) );
				$due    = $U::date_after( $text, array( 'Due Date', 'if the Minimum Due is not received by', 'received by', 'not received by' ) );
			}
			if ( in_array( $kind, array( 'cancel_notice', 'past_due' ), true ) && '' === $due ) {
				$due = self::effective_date( $text );
			}
		} elseif ( 'cancel_notice' === $kind ) {
			$due = self::effective_date( $text );
		}
		$facts['effective'] = self::effective_date( $text );
		if ( preg_match( '/\b((?:[A-Z][a-z]{2} \d{1,2}, \d{4}) to (?:[A-Z][a-z]{2} \d{1,2}, \d{4}))\b/', $text, $m ) ) {
			$parts           = explode( ' to ', $m[1] );
			$facts['term']   = array( $U::date( $parts[0] ), $U::date( $parts[1] ) );
		}
		if ( 'underwriting' === $kind ) {
			$due = $U::date_after( $text, array( 'reply by', 'A reply by' ) );
			if ( '' === $due && preg_match( '/A reply by (\d{1,2}\/\d{1,2}\/\d{2,4})/i', $text, $m ) ) {
				$due = $U::date( $m[1] );
			}
			if ( preg_match( '/(?:following repairs\/concerns will need to be addressed|repairs\/concerns will need to be addressed):?\s*(.{10,260}?)(?:Updated photos|Please use|Feel free|We would like)/i', $text, $m ) ) {
				$facts['repairs'] = trim( $m[1] );
			}
			$facts['urgent'] = '' !== $due;
		}
		if ( $policies ) {
			$facts['policy_nos'] = array_slice( $policies, 0, 6 );
		}
		if ( '' !== $account ) {
			$facts['account_no'] = $account;
		}
		if ( preg_match( '/\b(DWELLING FIRE|PERSONAL AUTO|HOMEOWNERS?|WORKERS.? COMP\w*|COMMERCIAL \w+|RENTERS?|CONDO\w*)\b/', $text, $m ) ) {
			$facts['policy_type'] = ucwords( strtolower( $m[1] ) );
		}

		// ── tenant and property ─────────────────────────────────────────────────
		$prop   = null;
		$ten    = null;
		$addr_t = '';
		if ( 'interested_party' === $kind && preg_match( '/inform you that (.+?) who resides at:? (.+?) has added you to their policy/i', $text, $m ) ) {
			$tenant = $U::title_name( $m[1] );
			$addr_t = trim( $m[2] );
		} elseif ( 'cancel_notice' === $kind && 'MSI' === $carrier && preg_match( '/primary named insured (.+?) located at property:? (.+?)(?:\. Any interest|\.\s|$)/i', $text, $m ) ) {
			$tenant = $U::title_name( $m[1] );
			$addr_t = trim( $m[2] );
		} elseif ( preg_match( '/renter\W?s? insurance for (.+?)\s+Location\s*:\s*(.+?)(?:\s+Have a Good|\s+Thank you|\s*$)/i', $text, $m ) ) {
			$tenant = $U::title_name( $m[1] );
			$addr_t = trim( $m[2] );
		} elseif ( preg_match( '/^Tenant\s*:\s*(.+)$/i', $subject, $m ) ) {
			$tenant = $U::title_name( $m[1] );
		} elseif ( preg_match( '/^(?:Fwd?:\s*|Re:\s*)*(\d{2,6}\s+[A-Za-z][A-Za-z0-9 \.\-]{2,40}?)\s*(?:renters? insurance\s*)?:\s*(.{3,50})$/i', $subject, $m ) ) {
			$addr_t = trim( $m[1] );
			$tenant = $U::title_name( $m[2] );
		} elseif ( preg_match( '/^(?:Fwd?:\s*|Re:\s*)*(.{3,45}?)\s+(?:Renters?|Renter\'s)\s*(?:Insurance|Policy)?\s*$/i', $subject, $m ) ) {
			$tenant = $U::title_name( $m[1] );
		}
		if ( '' !== $addr_t ) {
			$prop = AJCore_RA_Property_Match::match_tag( $addr_t );
		}
		// The sender may be the tenant: his e-mail is in Rentec.
		if ( $sender_tenant ) {
			$ten = $sender_tenant;
		}
		if ( ! $ten && in_array( $kind, array( 'renters_proof', 'renters_required', 'interested_party', 'cancel_notice' ), true ) && 'renters' === $cat ) {
			$ten = AJCore_RA_Property_Match::tenant_in_text( $all );
		}
		if ( $ten ) {
			$facts['renter_id'] = $ten['renter_id'];
			if ( '' === $tenant ) {
				$tenant = $ten['name'];
			}
		}
		// An address written in the subject / text (landlord policies name the property: "266 Winston Ln").
		if ( ! $prop && 'auto' !== $cat && 'info' !== $kind && 'promo' !== $kind ) {
			$prop = AJCore_RA_Property_Match::find_in_text( $subject );
			if ( ! $prop && ! in_array( $carrier, array( 'Auto-Owners' ), true ) ) {
				$prop = AJCore_RA_Property_Match::find_in_text( $text );
			}
		}
		if ( ! $prop && $ten && '' !== $ten['property_id'] ) {
			$props = AJCore_RA_Property_Match::properties();
			$prop  = isset( $props[ $ten['property_id'] ] ) ? $props[ $ten['property_id'] ] : null;
		}
		// A policy / account number remembered from an earlier email or set by hand.
		$link = null;
		if ( ! $prop && class_exists( 'AJCore_Mail_Pipelines' ) ) {
			foreach ( array_merge( $policies, '' !== $account ? array( $account ) : array() ) as $key ) {
				$link = AJCore_Mail_Pipelines::link_lookup( 'insurance', 'ref', $key );
				if ( $link ) {
					break;
				}
			}
		}
		$tag   = '';
		$pname = '';
		if ( $prop ) {
			$tag                 = $prop['unit'] ? $prop['label'] : $prop['street'];
			$pname               = $prop['label'];
			$facts['property_id'] = $prop['id'];
		} elseif ( $link ) {
			$tag   = (string) $link['property_tag'];
			$pname = (string) $link['property_name'];
			if ( '' === $tenant && ! empty( $link['tenant'] ) ) {
				$tenant = (string) $link['tenant'];
			}
		} elseif ( '' !== $addr_t ) {
			$tag = $addr_t; // an address that is not in Rentec: shown as written
		}

		// ── the reference number to show ────────────────────────────────────────
		$ref = $policies ? $policies[0] : $account;

		// ── what needs a person ─────────────────────────────────────────────────
		$issues = array();
		$prop_kinds = array( 'bill', 'past_due', 'cancel_notice', 'renewal', 'payment', 'reinstatement', 'application', 'policy_change', 'interested_party', 'renters_proof', 'renters_required', 'audit', 'policy_notice' );
		if ( in_array( $kind, $prop_kinds, true ) && ! in_array( $cat, array( 'auto', 'personal', 'workers_comp', 'commercial' ), true ) && '' === $tag ) {
			$issues[] = 'no_property';
		}
		if ( in_array( $kind, array( 'bill', 'past_due', 'cancel_notice', 'renewal', 'payment', 'reinstatement', 'application', 'policy_change', 'interested_party', 'audit' ), true ) && '' === $ref ) {
			$issues[] = 'no_ref';
		}
		if ( in_array( $kind, array( 'bill', 'past_due', 'payment' ), true ) && null === $amount ) {
			$issues[] = 'no_amount';
		}
		if ( 'renters' === $cat && '' === $tenant && in_array( $kind, array( 'renters_proof', 'interested_party' ), true ) ) {
			$issues[] = 'no_tenant';
		}
		$facts['category'] = $cat;
		if ( $issues ) {
			$facts['issues'] = $issues;
		}
		if ( in_array( $kind, array( 'cancel_notice', 'past_due' ), true ) ) {
			$facts['urgent'] = true;
		}
		$learn = array();
		if ( '' !== $ref && '' !== $tag && ( $prop || '' !== $tenant ) && ! $link ) {
			$learn[] = array( 'type' => 'ref', 'key' => $ref, 'property_tag' => $tag, 'property_name' => $pname, 'tenant' => $tenant );
		}
		return array(
			'learn'         => $learn,
			'kind'          => $kind,
			'ref_no'        => $ref,
			'amount'        => $amount,
			'due_date'      => $due,
			'property_tag'  => $tag,
			'property_name' => $pname,
			'tenant'        => $tenant,
			'facts'         => $facts,
			'label'         => $label,
		);
	}
}
