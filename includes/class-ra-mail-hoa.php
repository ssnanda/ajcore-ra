<?php
/**
 * HOA mailbox parser: turns one email into facts (what it is, association, account number, amount, due date, property).
 *
 * The mailbox mixes two roles:
 *  - owner: the owner's own properties receive charges, statements, payment confirmations and violation notices
 *    (PayHOA, Cedar / CMG, Associa / TownSq, Red Rock / CINC, Western Alliance ePay);
 *  - board: the owner sits on boards (ARC requests, board tasks, management threads) - not tied to one of the owner's properties.
 * Community mail (newsletters, events, app announcements) is labelled "notice".
 *
 * Kinds: charge, past_due, statement, payment, upcoming_payment, recurring_payment, violation, arc, work_order, board_task,
 *        request, board_thread, account, info.
 *
 * @package AJCore_RA
 */

defined( 'ABSPATH' ) || exit;

class AJCore_RA_Mail_HOA {

	/** Bump when the rules change: stored mail is parsed again. */
	const VERSION = 1;

	/** Association name found in the subject / text; '' when none. */
	public static function association( $subject, $text ) {
		$U = 'AJCore_RA_Mail_Util';
		$s = $U::clean( $subject );
		$t = $U::clean( $text );
		$pats = array(
			'/Association Name:\s*([A-Z0-9][A-Za-z0-9 &\.\',\-]{4,80}?)\s*\/\s*\d*/' => $t,
			'/\bAssociation:\s*(?:The\s+)?([A-Z][A-Za-z0-9 &\.\',\-]{4,70}?(?:Association|HOA)(?:,? Inc\.?)?)(?=\s+Homeowner|\s+Property|\s*$)/' => $t,
			'/(?:for|to your|with|from|of|-|\bat)\s+(?:The\s+)?([A-Z][A-Za-z0-9 &\.\']{3,60}?\s+(?:Homeowners?|Home Owners?|Townehome Homeowners?|Condominium(?:s)? (?:Owners )?|Neighborhood|Residential|Townhomes? Homeowner\S*)\s*Association(?:,? Inc\.?)?)/' => $s,
			'/\b([A-Z][A-Za-z0-9 &\.\']{3,50}?\s+(?:Homeowners?|Townehome Homeowners?|Condominium(?:s)? Owners?|Neighborhood|Residential|Townhomes? Homeowner\S*)\s+Association(?:,? Inc\.?)?)/' => $s,
			'/\b([A-Z][A-Za-z0-9 \']{3,40}\s+HOA)\b/' => $s,
			'/(?:community|at community)\s+([A-Z][A-Za-z\' ]{3,40}?)(?:\.| TITLE| REQUEST)/' => $t,
			'/\b([A-Z][A-Za-z0-9 &\.\']{3,50}?\s+(?:Homeowners?|Townehome Homeowners?)\s+Association(?:,? Inc\.?)?)/' => $t,
			'/(?:for|of|to)\s+((?:The\s+)?[A-Z][A-Za-z0-9 &\.\']{3,60}?\s+Association(?:,? Inc\.?)?)/' => $s,
		);
		foreach ( $pats as $re => $hay ) {
			if ( preg_match( $re, $hay, $m ) ) {
				$n = trim( preg_replace( '/\s+/', ' ', $m[1] ) );
				$n = preg_replace( '/^(?:The\s+)+/i', 'The ', $n );
				$n = preg_replace( '/^The The\b/i', 'The', $n );
				$n = trim( $n, " .,-" );
				if ( strlen( $n ) > 6 ) {
					return $n;
				}
			}
		}
		return '';
	}

	/** A stable key for an association name: lower case words without "the", "inc", punctuation ("GRENELEFE TOWNEHOME HOMEOWNERS ASSOCIATION INC" = "Grenelefe Townehome Homeowners Association, Inc."). */
	public static function assoc_key( $name ) {
		$n = strtolower( preg_replace( '/[^a-z0-9 ]+/i', ' ', (string) $name ) );
		$n = preg_replace( '/\b(?:the|inc|llc|of)\b/', ' ', $n );
		return trim( preg_replace( '/\s+/', ' ', $n ) );
	}

	/** The association's account number for the property ("Property Account Number: 712924", "Account #: 991266WL"). */
	public static function account_number( $text ) {
		if ( preg_match( '/\b(?:Property |Homeowner |Your |Billing )?Account\s*(?:Number|No\.?|#)\s*:?\s*#?\s*([0-9][0-9A-Z\-]{3,16}|[A-Z]{1,3}[0-9][0-9A-Z\-]{3,14})\b/', (string) $text, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * @param array  $item { subject, sender, summary, received_at }
	 * @param string $body Plain text of the email.
	 * @return array Facts for the engine (kind, ref_no, amount, due_date, property_tag, property_name, tenant, facts[], label, learn).
	 */
	public static function parse( array $item, $body, $html = '' ) {
		$U       = 'AJCore_RA_Mail_Util';
		$subject = $U::clean( $item['subject'] );
		$from    = (string) $item['sender'];
		$text    = $U::clean( $body );
		$all     = $subject . ' ' . $text;
		$dom     = $U::domain_of( $from );
		$email   = $U::email_of( $from );
		$kind    = 'info';
		$role    = 'owner';
		$label   = '';
		$amount  = null;
		$due     = '';
		$facts   = array();
		$assoc   = self::association( $subject, $text );
		$account = self::account_number( $text );
		$internal = false !== strpos( $dom, 'intlord.com' ) || false !== strpos( $dom, 'itspector.com' );
		$managers = false !== strpos( $dom, 'cincsystems' ) || false !== strpos( $dom, 'gowithredrock' ) || false !== strpos( $dom, 'mycmg' ) || false !== strpos( $dom, 'vantaca' ) || false !== strpos( $dom, 'camsmgt' ) || false !== strpos( $dom, 'townsq' ) || false !== strpos( $dom, 'payhoa' ) || false !== strpos( $dom, 'pmi' ) || false !== strpos( $dom, 'ccsend' );

		// ── what is it? ─────────────────────────────────────────────────────────
		if ( ! $managers && ! $internal && false === strpos( $dom, 'westernalliancebank' ) && false === strpos( $dom, 'stripe' ) && false === strpos( $dom, 'atgpay' ) ) {
			$kind = 'board_thread'; // a person writing (board members, managers, vendors)
			$role = 'board';
		} elseif ( preg_match( '/^(?:Re|Fwd?):/i', $subject ) ) {
			$kind = 'board_thread';
			$role = 'board';
		} elseif ( false !== strpos( $dom, 'westernalliancebank' ) || false !== strpos( $dom, 'atgpay' ) ) {
			if ( preg_match( '/upcoming payment/i', $subject ) ) {
				$kind = 'upcoming_payment';
			} elseif ( preg_match( '/confirmation of payment|payment confirmation/i', $subject ) ) {
				$kind = 'payment';
			} else {
				$kind = 'account';
			}
		} elseif ( false !== strpos( $dom, 'stripe.com' ) && preg_match( '/direct debit/i', $subject ) ) {
			$kind  = 'account';
			$label = 'notice';
		} elseif ( preg_match( '/payment (?:receipt|received)|thank you for your payment|payment confirmation/i', $subject . ' ' . substr( $text, 0, 160 ) ) ) {
			$kind = 'payment';
		} elseif ( preg_match( '/recurring payment (?:notice|stopped)/i', $subject ) ) {
			$kind = preg_match( '/stopped/i', $subject ) ? 'account' : 'recurring_payment';
		} elseif ( preg_match( '/charges past due|payment reminder|past due/i', $subject ) ) {
			$kind = 'past_due';
		} elseif ( preg_match( '/new charge added|outstanding charge|statement/i', $subject ) && ! preg_match( '/preference/i', $subject ) ) {
			$kind = preg_match( '/new charge/i', $subject ) ? 'charge' : 'statement';
		} elseif ( preg_match( '/action required at your property|non-?compliance|violation notice|notice of violation|violation letter|second notice|final notice|hearing/i', $subject . ' ' . substr( $text, 0, 240 ) ) && $managers ) {
			$kind = ( preg_match( '/^(?:Re|Fwd?):/i', $subject ) || preg_match( '/Hello Board|Dear Board/i', substr( $text, 0, 80 ) ) ) ? 'board_thread' : 'violation';
		} elseif ( preg_match( '/architectural request|arc committee comment|arc response|update to architectural request|arc request/i', $subject ) ) {
			$kind = 'arc';
			$role = 'board';
		} elseif ( preg_match( '/work order/i', $subject ) ) {
			$kind = 'work_order';
			$role = 'board';
		} elseif ( preg_match( '/board task|action item|portfolio board/i', $subject ) ) {
			$kind = 'board_task';
			$role = 'board';
		} elseif ( false !== strpos( $dom, 'townsq' ) && preg_match( '/new request|request completed|new comment on request/i', $subject ) ) {
			$kind = 'request';
		} elseif ( preg_match( '/^(?:Re|Fwd?):/i', $subject ) || ( ! $managers && ! $internal ) ) {
			$kind = 'board_thread';
			$role = 'board';
		} elseif ( preg_match( '/set your statement preference|welcome to|register for|added you to their|app is live|youve been added|you.ve been added|portal update/i', $subject ) ) {
			$kind  = 'account';
			$label = 'notice';
		}
		if ( 'info' === $kind && $managers ) {
			$label = 'notice'; // newsletters, events, announcements, "realtors of ..."
		}
		if ( in_array( $kind, array( 'board_thread', 'arc', 'board_task', 'work_order' ), true ) ) {
			$role = 'board';
		}

		// ── amounts and dates ───────────────────────────────────────────────────
		if ( in_array( $kind, array( 'charge', 'past_due', 'statement', 'payment', 'upcoming_payment', 'recurring_payment' ), true ) ) {
			$amount = $U::amount_after( $text, array( 'Amount Due', 'Payment Total', 'Payment Amount', 'Total Payment Amount', 'payment of', 'Amount', 'Balance Due', 'Total Due' ) );
			if ( preg_match( '#\b(\d{2}/\d{2}/\d{4})\s+\$\s*[\d,]+\.\d{2}\s+Amount Due#', $text, $m ) ) {
				$due = $U::date( $m[1] ); // the charge's due date, from the charge line
			}
			if ( '' === $due ) {
				$due = $U::date_after( $text, array( 'Payment Date', 'Due Date', 'Invoice Date', 'will be processed on', 'scheduled to be processed on' ) );
			}
			if ( '' === $due && preg_match( '/\bpayment of \$[\d,\.]+ on ([A-Z][a-z]+ \d{1,2}, \d{4})/', $text, $m ) ) {
				$due = $U::date( $m[1] );
			}
			if ( preg_match( '/late fee of \$\s*([\d,]+(?:\.\d{2})?)/i', $text, $m ) ) {
				$facts['late_fee'] = (float) str_replace( ',', '', $m[1] );
			}
		}

		// ── property ────────────────────────────────────────────────────────────
		$prop = null;
		$tag  = '';
		$cands = array();
		if ( preg_match( '/\b(\d{2,6}\s+(?:[A-Z][A-Za-z\.\']*\s+){1,4}(?:Dr|Drive|St|Street|Ln|Lane|Rd|Road|Ct|Court|Pl|Place|Way|Ave|Avenue|Blvd|Cir|Circle|Pkwy|Trl|Ter)\b\.?)/', $text, $m ) && ! in_array( $kind, array( 'arc', 'board_thread', 'request' ), true ) ) {
			$cands[] = $m[1];
		}
		if ( 'request' === $kind && preg_match( '/(?:request[: ]+|TITLE\s+)(\d{3,6})\b/', $all, $m ) ) {
			$cands[] = $m[1]; // TownSq request titles start with the house number: "6408 Overgrown Weeds..."
		}
		if ( 'violation' === $kind && preg_match( '/property at ([A-Z][A-Za-z\' ]{3,40}?)\s*\./', $text, $m ) ) {
			$cands[] = trim( $m[1] ); // "non-compliance issue at your property at Ansley Park Place ."
		}
		if ( preg_match( '/payment due (\d{3,6})\b/i', $text, $m ) ) {
			$cands[] = $m[1]; // PayHOA charge descriptions end with the unit number
		}
		$prop_kinds = array( 'charge', 'past_due', 'statement', 'payment', 'upcoming_payment', 'recurring_payment', 'violation', 'request' );
		if ( 'owner' === $role && ! in_array( $kind, $prop_kinds, true ) ) {
			$cands = array();
		}
		if ( 'owner' === $role && in_array( $kind, $prop_kinds, true ) ) {
			foreach ( $cands as $c ) {
				$prop = AJCore_RA_Property_Match::match_tag( $c );
				if ( $prop ) {
					break;
				}
			}
			if ( ! $prop && 'request' !== $kind ) {
				$prop = AJCore_RA_Property_Match::find_in_text( $subject . ' ' . $text );
			}
		}
		$link = null;
		if ( ! $prop && class_exists( 'AJCore_Mail_Pipelines' ) && in_array( $kind, $prop_kinds, true ) ) {
			if ( '' !== $account ) {
				$link = AJCore_Mail_Pipelines::link_lookup( 'hoa', 'ref', $account );
			}
			if ( ( ! $link || '' === (string) $link['property_tag'] ) && '' !== $assoc ) {
				$link = AJCore_Mail_Pipelines::link_lookup( 'hoa', 'assoc', self::assoc_key( $assoc ) ); // one property in that association
			}
			if ( $link && '' === (string) $link['property_tag'] ) {
				$link = null;
			}
		}
		$pname = '';
		$comm  = null;
		if ( ! $prop && ! $link && 'owner' === $role && '' !== $assoc && in_array( $kind, $prop_kinds, true ) ) {
			$comm = AJCore_RA_Property_Match::community_for_association( $assoc ); // "Mallard Forest HOA" -> the Mallard Forest group in Rentec
		}
		if ( $prop ) {
			$tag                  = $prop['unit'] ? $prop['label'] : $prop['street'];
			$pname                = $prop['label'];
			$facts['property_id'] = $prop['id'];
		} elseif ( $link ) {
			$tag   = (string) $link['property_tag'];
			$pname = (string) $link['property_name'];
		} elseif ( $comm ) {
			$tag                    = $comm['label'];
			$pname                  = $comm['label'] . ' (community)';
			$facts['community_id']  = $comm['id'];
		} elseif ( $cands && 'owner' === $role ) {
			$tag = $cands[0]; // an address that is not in Rentec: shown as written
		}

		// ── issues and extra facts ─────────────────────────────────────────────
		$issues = array();
		$ref    = $account;
		if ( 'owner' === $role && in_array( $kind, array( 'charge', 'past_due', 'statement', 'payment', 'upcoming_payment', 'recurring_payment', 'violation', 'request' ), true ) && '' === $tag ) {
			$issues[] = 'no_property';
		}
		if ( in_array( $kind, array( 'charge', 'past_due', 'payment', 'upcoming_payment', 'recurring_payment' ), true ) && null === $amount ) {
			$issues[] = 'no_amount';
		}
		if ( in_array( $kind, array( 'payment', 'upcoming_payment', 'recurring_payment', 'statement' ), true ) && '' === $ref && 'statement' !== $kind ) {
			$issues[] = 'no_ref';
		}
		if ( '' !== $assoc ) {
			$facts['association'] = $assoc;
		}
		$facts['role'] = $role;
		if ( 'violation' === $kind ) {
			$facts['urgent'] = true;
			$facts['note']   = 'The violation details are in the attached letter / portal.';
		}
		if ( 'arc' === $kind && preg_match( '/Request Type:\s*([A-Za-z ]{3,40}?)\s+(?:Request Details|Comment|$)/', $text, $m ) ) {
			$facts['request_type'] = trim( $m[1] );
		}
		if ( 'arc' === $kind && preg_match( '/Committee Note:\s*([A-Za-z ,\.\-]{2,60}?)\s+Please DO NOT/', $text, $m ) ) {
			$facts['decision'] = trim( $m[1] );
		}
		if ( 'arc' === $kind && preg_match( '/Property Address:\s*(.+?)\s+(?:Home Phone|Mobile Phone)/', $text, $m ) ) {
			$facts['homeowner_property'] = trim( $m[1] );
		}
		if ( $issues ) {
			$facts['issues'] = $issues;
		}
		$learn = array();
		if ( $prop && ! $link ) {
			if ( '' !== $account ) {
				$learn[] = array( 'type' => 'ref', 'key' => $account, 'property_tag' => $tag, 'property_name' => $pname, 'tenant' => '' );
			}
			if ( '' !== $assoc ) {
				$learn[] = array( 'type' => 'assoc', 'key' => self::assoc_key( $assoc ), 'property_tag' => $tag, 'property_name' => $pname, 'tenant' => '' );
			}
		}
		return array(
			'learn'         => $learn,
			'kind'          => $kind,
			'ref_no'        => $ref,
			'amount'        => $amount,
			'due_date'      => $due,
			'property_tag'  => $tag,
			'property_name' => $pname,
			'tenant'        => '',
			'facts'         => $facts,
			'label'         => $label,
		);
	}
}
