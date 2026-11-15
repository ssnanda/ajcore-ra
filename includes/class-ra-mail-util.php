<?php
/**
 * Small text helpers shared by the Insurance and HOA parsers (pure functions, no I/O).
 *
 * @package AJCore_RA
 */

defined( 'ABSPATH' ) || exit;

class AJCore_RA_Mail_Util {

	/** Plain, single-spaced text: entities decoded, zero-width and non-breaking characters removed. */
	public static function clean( $text ) {
		$t = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$t = str_replace( "\xc2\xa0", ' ', $t );
		$t = preg_replace( '/[\x{034F}\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00AD}]/u', '', (string) $t );
		$t = preg_replace( '/\s+/u', ' ', (string) $t );
		return trim( (string) $t );
	}

	/** "$1,234.56" / "1234.5" -> 1234.56, null when there is no number. */
	public static function money( $s ) {
		if ( ! preg_match( '/-?\(?\$?\s*([\d,]+(?:\.\d{1,2})?)\)?/', (string) $s, $m ) ) {
			return null;
		}
		$v = (float) str_replace( ',', '', $m[1] );
		return $v;
	}

	/** The dollar amount that follows one of the labels ("Minimum Due: $243.40"); null when none. Labels are regex fragments. */
	public static function amount_after( $text, array $labels ) {
		foreach ( $labels as $label ) {
			if ( preg_match( '/' . $label . '\s*[:\-]?\s*\$\s*([\d,]+(?:\.\d{2})?)/i', (string) $text, $m ) ) {
				return (float) str_replace( ',', '', $m[1] );
			}
		}
		return null;
	}

	/** A date written in common US forms -> Y-m-d, '' when not a date. */
	public static function date( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s ) {
			return '';
		}
		if ( preg_match( '#^(\d{4})-(\d{2})-(\d{2})#', $s, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? "{$m[1]}-{$m[2]}-{$m[3]}" : '';
		}
		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $s, $m ) ) {
			$y = (int) $m[3];
			$y = $y < 100 ? 2000 + $y : $y;
			return checkdate( (int) $m[1], (int) $m[2], $y ) ? sprintf( '%04d-%02d-%02d', $y, $m[1], $m[2] ) : '';
		}
		$t = strtotime( $s . ' UTC' );
		return $t && (int) gmdate( 'Y', $t ) >= 2000 && (int) gmdate( 'Y', $t ) <= 2100 ? gmdate( 'Y-m-d', $t ) : '';
	}

	/** The first date that follows one of the labels ("Due Date: Sep 10, 2026"). Labels are regex fragments. */
	public static function date_after( $text, array $labels ) {
		foreach ( $labels as $label ) {
			if ( preg_match( '/' . $label . '\s*[:\-]?\s*((?:[A-Z][a-z]{2,8}\.? \d{1,2},? \d{4})|(?:\d{1,2}\/\d{1,2}\/\d{2,4})|(?:\d{4}-\d{2}-\d{2}))/', (string) $text, $m ) ) {
				$d = self::date( str_replace( ',', ',', $m[1] ) );
				if ( '' !== $d ) {
					return $d;
				}
			}
		}
		return '';
	}

	/** The e-mail address in "Name <a@b.com>" (lower case), '' when none. */
	public static function email_of( $sender ) {
		if ( preg_match( '/<([^>]+@[^>]+)>/', (string) $sender, $m ) ) {
			return strtolower( trim( $m[1] ) );
		}
		return preg_match( '/^[^\s@]+@[^\s@]+$/', trim( (string) $sender ) ) ? strtolower( trim( (string) $sender ) ) : '';
	}

	/** Domain of a sender, lower case. */
	public static function domain_of( $sender ) {
		$e = self::email_of( $sender );
		return false !== strpos( $e, '@' ) ? substr( $e, strrpos( $e, '@' ) + 1 ) : '';
	}

	/** "SANDIP NANDA" / "mansa singh llc" -> "Sandip Nanda" / "Mansa Singh LLC". */
	public static function title_name( $s ) {
		$s = trim( preg_replace( '/\s+/', ' ', (string) $s ) );
		if ( '' === $s ) {
			return '';
		}
		$words = explode( ' ', $s );
		foreach ( $words as &$w ) {
			if ( in_array( strtolower( $w ), array( 'llc', 'inc', 'pllc', 'lp', 'llp', 'hoa' ), true ) ) {
				$w = strtoupper( $w );
			} else {
				$w = ucfirst( strtolower( $w ) );
			}
		}
		return implode( ' ', $words );
	}
}
