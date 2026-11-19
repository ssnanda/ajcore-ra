<?php
/**
 * AJCore-RA built-in E-Sign PDF forms.
 *
 * Add a PDF beside this file and a deterministic ajt_* entry below. Existing
 * installed forms are never overwritten, so administrators keep local layout
 * edits after plugin upgrades.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

return array(
	'ajt_virtual_office_agreement' => array(
		'title'    => 'Virtual Office and Business Address Service Agreement',
		'kind'     => 'doc',
		'asset'    => 'virtual-office-business-address-agreement.html',
		'filename' => 'virtual-office-business-address-service-agreement.pdf',
		'signers'  => array( 'Client', 'Provider' ),
		'page'     => 9,
		'layout'   => array(
			array( 'type' => 'initials', 'party' => 1, 'page' => 1, 'x' => 0.82, 'y' => 0.84, 'w' => 0.10, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'initials', 'party' => 1, 'page' => 3, 'x' => 0.82, 'y' => 0.84, 'w' => 0.10, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'initials', 'party' => 1, 'page' => 4, 'x' => 0.82, 'y' => 0.84, 'w' => 0.10, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'initials', 'party' => 1, 'page' => 5, 'x' => 0.82, 'y' => 0.84, 'w' => 0.10, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'initials', 'party' => 1, 'page' => 6, 'x' => 0.82, 'y' => 0.84, 'w' => 0.10, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'initials', 'party' => 1, 'page' => 7, 'x' => 0.82, 'y' => 0.84, 'w' => 0.10, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'signature', 'party' => 1, 'page' => 9, 'x' => 0.08, 'y' => 0.32, 'w' => 0.42, 'h' => 0.065, 'fill' => '' ),
			array( 'type' => 'date', 'party' => 1, 'page' => 9, 'x' => 0.56, 'y' => 0.32, 'w' => 0.22, 'h' => 0.04, 'fill' => '' ),
			array( 'type' => 'signature', 'party' => 2, 'page' => 9, 'x' => 0.08, 'y' => 0.55, 'w' => 0.42, 'h' => 0.065, 'fill' => '' ),
			array( 'type' => 'date', 'party' => 2, 'page' => 9, 'x' => 0.56, 'y' => 0.55, 'w' => 0.22, 'h' => 0.04, 'fill' => '' ),
		),
	),
);
