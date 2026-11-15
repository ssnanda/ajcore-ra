<?php
/**
 * Property and tenant matching against Rentec, shared by the mailbox sub-pipelines (Insurance, HOA, later Purchases).
 *
 * Rentec models three levels:
 *  - home / building: has its own street address ("6908 Castello Ln", "314 Ardale Dr.");
 *  - unit: no address of its own, a nickname ("1E") and a parent building (sub_of);
 *  - community: no street address, a nickname ("Mallard Forest", "Michaels Landing") that groups homes; matches an HOA by name.
 *
 * - properties(): every addressed home / building / unit as comparable word lists; communities() the named groups.
 * - find_in_text() / match_tag(): the one property whose street address is in free text; units collapse to their building unless the unit is named.
 * - tenant_by_email() / tenant_in_text(): a Rentec tenant with the property of the tenant's current unit or lease.
 * - community_for_association(): the community whose name is in an HOA name.
 *
 * Read-only: nothing here writes to Rentec. All lookups come from the local Rentec copy (AJCore_RA_Rentec::rows_for_sync).
 *
 * @package AJCore_RA
 */

defined( 'ABSPATH' ) || exit;

class AJCore_RA_Property_Match {

	/** @var array|null */
	private static $props = null;
	/** @var array|null */
	private static $communities = null;
	/** @var array|null */
	private static $tenants = null;

	/** Why the lists are empty (shown on screens), '' when loaded. */
	public static $note = '';

	/** Words of an address, lower-cased, street types written one way (Road = Rd ...), unit letters joined ("1-E" = "1e"). */
	public static function tokens( $s ) {
		$s   = strtolower( (string) $s );
		$s   = preg_replace( '/\b(\d{1,3})\s*-\s*([a-z])\b/', '$1$2', $s );
		$s   = preg_replace( '/[^a-z0-9 ]+/', ' ', $s );
		$abb = array( 'road' => 'rd', 'street' => 'st', 'drive' => 'dr', 'avenue' => 'ave', 'lane' => 'ln', 'court' => 'ct', 'boulevard' => 'blvd', 'circle' => 'cir', 'place' => 'pl', 'parkway' => 'pkwy', 'highway' => 'hwy', 'terrace' => 'ter', 'trail' => 'trl' );
		$out = array();
		foreach ( preg_split( '/\s+/', trim( (string) $s ) ) as $w ) {
			if ( '' !== $w ) {
				$out[] = isset( $abb[ $w ] ) ? $abb[ $w ] : $w;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Like tokens() without unit words and city / state words. */
	public static function tag_tokens( $tag ) {
		$drop = array( 'apt', 'apartment', 'unit', 'ste', 'suite', 'bldg', 'building', 'no', 'number', 'us', 'nc', 'north', 'carolina', 'charlotte', 'concord', 'salisbury', 'high', 'point' );
		return array_values( array_diff( self::tokens( $tag ), $drop ) );
	}

	private static function rows( $resource ) {
		if ( ! class_exists( 'AJCore_RA_Rentec' ) ) {
			self::$note = 'Rentec is not available';
			return array();
		}
		try {
			$res = AJCore_RA_Rentec::rows_for_sync( $resource );
		} catch ( \Throwable $e ) {
			self::$note = 'Rentec: ' . $e->getMessage();
			return array();
		}
		if ( is_wp_error( $res ) ) {
			self::$note = 'Rentec: ' . $res->get_error_message();
			return array();
		}
		$out = array();
		foreach ( (array) $res as $account ) {
			foreach ( (array) ( isset( $account['rows'] ) ? $account['rows'] : array() ) as $r ) {
				if ( is_array( $r ) ) {
					$out[] = $r;
				}
			}
		}
		return $out;
	}

	private static function sub_of_id( $row ) {
		$s = isset( $row['sub_of'] ) ? $row['sub_of'] : '';
		if ( is_array( $s ) ) {
			$s = isset( $s['property_id'] ) ? $s['property_id'] : '';
		}
		return ( '' === (string) $s || '0' === (string) $s ) ? '' : (string) $s;
	}

	private static function build() {
		self::$props       = array();
		self::$communities = array();
		$raw               = array();
		foreach ( self::rows( 'properties' ) as $p ) {
			if ( ! empty( $p['property_id'] ) ) {
				$raw[ (string) $p['property_id'] ] = $p;
			}
		}
		foreach ( $raw as $id => $p ) {
			$parent_id = self::sub_of_id( $p );
			$parent    = '' !== $parent_id && isset( $raw[ $parent_id ] ) ? $raw[ $parent_id ] : null;
			$addr      = trim( (string) ( isset( $p['address'] ) ? $p['address'] : '' ) );
			$nick      = trim( (string) ( isset( $p['nickname'] ) ? $p['nickname'] : '' ) );
			$unit      = '';
			if ( '' === $addr && $parent && '' !== trim( (string) $parent['address'] ) ) { // a unit: the address is the building's
				$addr = trim( (string) $parent['address'] );
				$unit = $nick;
				$src  = $parent;
			} else {
				$src = $p;
			}
			$has_number = (bool) preg_match( '/\d/', $addr );
			$city       = trim( (string) ( ! empty( $src['city'] ) ? $src['city'] : ( $parent && ! empty( $parent['city'] ) ? $parent['city'] : '' ) ) );
			$state      = trim( (string) ( ! empty( $src['state'] ) ? $src['state'] : '' ) );
			$zip        = trim( (string) ( ! empty( $src['zip'] ) ? $src['zip'] : '' ) );
			if ( '' === $addr || ! $has_number ) { // a community / group: named, no street number
				$name = '' !== $nick ? $nick : $addr;
				if ( '' !== $name ) {
					self::$communities[ $id ] = array( 'id' => $id, 'label' => $name, 'tokens' => self::tokens( $name ), 'children' => 0 );
				}
				continue;
			}
			$label = trim( preg_replace( '/\s+/', ' ', $addr . ( '' !== $city ? ', ' . $city : '' ) . ( '' !== $state ? ', ' . $state : '' ) . ( '' !== $zip ? ' ' . $zip : '' ) ) );
			self::$props[ $id ] = array(
				'id'       => $id,
				'label'    => '' !== $unit ? $label . ' · Unit ' . $unit : $label,
				'street'   => $addr,
				'unit'     => $unit,
				'building' => '' !== $unit && $parent ? $parent_id : $id,
				'parent'   => $parent_id,
				'tokens'   => self::tokens( $label . ' ' . $unit ),
				'owner'    => isset( $p['owner_id'] ) ? (string) $p['owner_id'] : '',
			);
			if ( $parent && '' === trim( (string) $parent['address'] ) && isset( self::$communities[ $parent_id ] ) ) {
				self::$communities[ $parent_id ]['children']++;
			}
		}
		// Homes whose parent is a named community (no address) count towards it, whichever row order Rentec returned.
		foreach ( self::$props as $p ) {
			if ( '' !== $p['parent'] && isset( self::$communities[ $p['parent'] ] ) ) {
				self::$communities[ $p['parent'] ]['children'] = 0;
			}
		}
		foreach ( self::$communities as $cid => &$c ) {
			$c['children'] = 0;
		}
		unset( $c );
		foreach ( self::$props as $p ) {
			if ( '' !== $p['parent'] && isset( self::$communities[ $p['parent'] ] ) ) {
				self::$communities[ $p['parent'] ]['children']++;
			}
		}
	}

	/** Addressed properties: id, label, street, unit, building, tokens. */
	public static function properties() {
		if ( null === self::$props ) {
			self::build();
		}
		return self::$props;
	}

	/** Named groups without a street address (Mallard Forest, Michaels Landing, Nevin Creek ...). */
	public static function communities() {
		if ( null === self::$communities ) {
			self::build();
		}
		return self::$communities;
	}

	/** The community whose name is in an HOA / association name ("Mallard Forest Homeowners Association" -> Mallard Forest), or null. */
	public static function community_for_association( $name ) {
		$hay  = ' ' . implode( ' ', self::tokens( $name ) ) . ' ';
		$hits = array();
		foreach ( self::communities() as $c ) {
			$n = implode( ' ', $c['tokens'] );
			if ( strlen( $n ) >= 5 && false !== strpos( $hay, ' ' . $n . ' ' ) ) {
				$hits[] = $c;
			}
		}
		if ( 1 !== count( $hits ) ) {
			// "Michael's Landing" vs "Michaels Landing": compare without apostrophes / spaces.
			$flat = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) );
			$hits = array();
			foreach ( self::communities() as $c ) {
				$f = preg_replace( '/[^a-z0-9]/', '', strtolower( $c['label'] ) );
				if ( strlen( $f ) >= 6 && false !== strpos( $flat, $f ) ) {
					$hits[] = $c;
				}
			}
		}
		return 1 === count( $hits ) ? $hits[0] : null;
	}

	/** Tenants indexed by e-mail, with the property of the current unit / lease. */
	private static function tenants() {
		if ( null !== self::$tenants ) {
			return self::$tenants;
		}
		self::$tenants = array( 'by_email' => array(), 'all' => array() );
		$leases        = array();
		foreach ( self::rows( 'leases' ) as $l ) {
			if ( isset( $l['renter_id'] ) ) {
				$leases[ (string) $l['renter_id'] ][] = $l;
			}
		}
		foreach ( self::rows( 'tenants' ) as $t ) {
			if ( empty( $t['renter_id'] ) ) {
				continue;
			}
			$rid  = (string) $t['renter_id'];
			$name = trim( ( ! empty( $t['company'] ) ? $t['company'] : trim( ( isset( $t['f_name'] ) ? $t['f_name'] : '' ) . ' ' . ( isset( $t['l_name'] ) ? $t['l_name'] : '' ) ) ) );
			$pid  = '';
			// The tenant record lists where the tenant lives (current = no move-out); the lease list is the fallback.
			$best = '';
			foreach ( (array) ( isset( $t['properties'] ) ? $t['properties'] : array() ) as $tp ) {
				if ( is_array( $tp ) && ! empty( $tp['property_id'] ) && empty( $tp['move_out'] ) && ( '' === $best || strcmp( (string) ( isset( $tp['move_in'] ) ? $tp['move_in'] : '' ), $best ) > 0 ) ) {
					$best = (string) ( isset( $tp['move_in'] ) ? $tp['move_in'] : '' );
					$pid  = (string) $tp['property_id'];
				}
			}
			if ( '' === $pid && ! empty( $leases[ $rid ] ) ) {
				$ls = $leases[ $rid ];
				usort( $ls, static function ( $a, $b ) {
					return strcmp( (string) ( isset( $b['lease_begin'] ) ? $b['lease_begin'] : '' ), (string) ( isset( $a['lease_begin'] ) ? $a['lease_begin'] : '' ) );
				} );
				$pid = isset( $ls[0]['property_id'] ) ? (string) $ls[0]['property_id'] : '';
			}
			$row = array( 'renter_id' => $rid, 'name' => $name, 'person' => trim( ( isset( $t['f_name'] ) ? $t['f_name'] : '' ) . ' ' . ( isset( $t['l_name'] ) ? $t['l_name'] : '' ) ), 'company' => isset( $t['company'] ) ? (string) $t['company'] : '', 'email' => strtolower( (string) ( isset( $t['email'] ) ? $t['email'] : '' ) ), 'property_id' => $pid, 'archived' => ! empty( $t['archived'] ) );
			self::$tenants['all'][ $rid ] = $row;
			foreach ( preg_split( '/[;, ]+/', $row['email'] ) as $e ) {
				if ( '' !== $e && false !== strpos( $e, '@' ) ) {
					self::$tenants['by_email'][ $e ] = $row;
				}
			}
		}
		return self::$tenants;
	}

	/** A tenant by e-mail address: [ renter_id, name, company, email, property_id, property (label) ] or null. */
	public static function tenant_by_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$t     = self::tenants();
		if ( '' === $email || ! isset( $t['by_email'][ $email ] ) ) {
			return null;
		}
		return self::with_property( $t['by_email'][ $email ] );
	}

	/** A tenant whose name (person or company) is in the text, when exactly one is ("Trauma Assessment and Treatment Center, PLLC"). */
	public static function tenant_in_text( $text ) {
		$hay  = ' ' . implode( ' ', self::tokens( $text ) ) . ' ';
		$hits = array();
		foreach ( self::tenants()['all'] as $row ) {
			foreach ( array( $row['company'], $row['person'] ) as $n ) {
				$tok = self::tokens( $n );
				if ( count( $tok ) >= 2 && strlen( implode( ' ', $tok ) ) >= 8 && false !== strpos( $hay, ' ' . implode( ' ', $tok ) . ' ' ) ) {
					$hits[ $row['renter_id'] ] = $row;
				}
			}
		}
		return 1 === count( $hits ) ? self::with_property( array_values( $hits )[0] ) : null;
	}

	/** A tenant by name in either order ("Louinis Georges" / "George Louinis"), when exactly one matches closely. */
	public static function tenant_by_name( $name ) {
		$want = self::tokens( $name );
		if ( count( $want ) < 2 ) {
			return null;
		}
		$hits = array();
		foreach ( self::tenants()['all'] as $row ) {
			$have = self::tokens( $row['person'] . ' ' . $row['company'] );
			if ( count( $have ) < 2 ) {
				continue;
			}
			$same = 0;
			foreach ( $want as $w ) {
				foreach ( $have as $h ) {
					if ( $w === $h || ( strlen( $w ) >= 5 && strlen( $h ) >= 5 && ( 0 === strpos( $w, substr( $h, 0, 5 ) ) ) && levenshtein( $w, $h ) <= 2 ) ) {
						$same++;
						break;
					}
				}
			}
			if ( $same >= 2 ) {
				$hits[ $row['renter_id'] ] = $row;
			}
		}
		return 1 === count( $hits ) ? self::with_property( array_values( $hits )[0] ) : null;
	}

	private static function with_property( array $row ) {
		$props           = self::properties();
		$row['property'] = ( '' !== $row['property_id'] && isset( $props[ $row['property_id'] ] ) ) ? $props[ $row['property_id'] ]['label'] : '';
		return $row;
	}

	/** The property for a Rentec property id, or null. */
	public static function by_id( $id ) {
		$p = self::properties();
		return isset( $p[ (string) $id ] ) ? $p[ (string) $id ] : null;
	}

	/**
	 * Collapses several hits to one answer: hits in the same building give the building (or the unit when a unit is named in $unit_hay);
	 * hits in different buildings are ambiguous (null).
	 */
	private static function collapse( array $hits, $hay ) {
		if ( ! $hits ) {
			return null;
		}
		$buildings = array();
		foreach ( $hits as $h ) {
			$buildings[ $h['building'] ][] = $h;
		}
		if ( 1 !== count( $buildings ) ) {
			return null;
		}
		$group = array_values( $buildings )[0];
		if ( 1 === count( $group ) ) {
			return $group[0];
		}
		// Several units of one building: the one whose unit name is in the text, else the building itself.
		$named = array();
		foreach ( $group as $h ) {
			if ( '' !== $h['unit'] ) {
				$u = ' ' . implode( ' ', self::tokens( $h['unit'] ) ) . ' ';
				if ( false !== strpos( $hay, $u ) ) {
					$named[] = $h;
				}
			}
		}
		if ( 1 === count( $named ) ) {
			return $named[0];
		}
		$all = self::properties();
		$bid = $group[0]['building'];
		if ( isset( $all[ $bid ] ) ) {
			return $all[ $bid ];
		}
		$g = $group[0];
		return array( 'id' => $g['building'], 'label' => $g['street'], 'street' => $g['street'], 'unit' => '', 'building' => $g['building'], 'parent' => '', 'tokens' => self::tokens( $g['street'] ), 'owner' => $g['owner'] );
	}

	/** The one property whose words hold every word of the tag ("266 Winston Ln"); a lone 3+ digit street number is enough. null when none or several. */
	public static function match_tag( $tag ) {
		$want = self::tag_tokens( $tag );
		if ( ! $want || preg_match( '#^\d{1,2}/\d{1,2}(/\d{2,4})?$#', trim( (string) $tag ) ) ) {
			return null;
		}
		$hay  = ' ' . implode( ' ', self::tokens( $tag ) ) . ' ';
		$hits = array();
		foreach ( self::properties() as $p ) {
			if ( ! array_diff( $want, $p['tokens'] ) ) {
				$hits[] = $p;
			}
		}
		$one = self::collapse( $hits, $hay );
		if ( $one ) {
			return $one;
		}
		if ( ctype_digit( (string) $want[0] ) && strlen( $want[0] ) >= 3 && ! $hits ) {
			$by_no = array();
			foreach ( self::properties() as $p ) {
				if ( isset( $p['tokens'][0] ) && $p['tokens'][0] === $want[0] ) {
					$by_no[] = $p;
				}
			}
			return self::collapse( $by_no, $hay );
		}
		return null;
	}

	/**
	 * Street addresses written in free text: "1914 J N Pease Pl", "2802 Hosta Dr". Returns candidate strings, best first
	 * (a number, up to 4 words, then a street type).
	 */
	public static function addresses_in_text( $text ) {
		$text = (string) $text;
		$out  = array();
		if ( preg_match_all( '/\b(\d{2,6})\s+((?:[A-Za-z][A-Za-z\.\']*\s+){0,4}?(?:Road|Rd|Street|St|Drive|Dr|Avenue|Ave|Lane|Ln|Court|Ct|Boulevard|Blvd|Circle|Cir|Place|Pl|Parkway|Pkwy|Way|Trail|Trl|Terrace|Ter|Highway|Hwy)\b\.?)/i', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$out[] = trim( preg_replace( '/\s+/', ' ', $x[1] . ' ' . $x[2] ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The one Rentec property (or building) whose street address appears in the text, or null when none / several different ones.
	 */
	public static function find_in_text( $text ) {
		$hay  = ' ' . implode( ' ', self::tokens( $text ) ) . ' ';
		$hits = array();
		foreach ( self::properties() as $p ) {
			$st = ' ' . implode( ' ', self::tokens( $p['street'] ) ) . ' ';
			if ( strlen( $st ) > 8 && false !== strpos( $hay, $st ) ) {
				$hits[] = $p;
			}
		}
		if ( $hits ) {
			return self::collapse( $hits, $hay );
		}
		foreach ( self::addresses_in_text( $text ) as $cand ) {
			$m = self::match_tag( $cand );
			if ( $m ) {
				return $m;
			}
		}
		return null;
	}
}
