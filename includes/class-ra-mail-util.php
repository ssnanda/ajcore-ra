<?php
/**
 * Insurance / HOA parser helpers: delegates to AJCore's AJCore_Mail_Util (kept under this name so the RA parsers read the same).
 *
 * @package AJCore_RA
 */

defined( 'ABSPATH' ) || exit;

class AJCore_RA_Mail_Util {
	public static function __callStatic( $name, $args ) {
		return call_user_func_array( array( 'AJCore_Mail_Util', $name ), $args );
	}
}
