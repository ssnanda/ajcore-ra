<?php
/**
 * AJCore-RA built-in E-Sign PDF forms.
 *
 * Add a PDF (or HTML doc) beside this file and a deterministic ajt_* entry below.
 * Bump 'rev' to push a changed body/layout to sites; a form an administrator has
 * edited is never overwritten, and a deleted one is never reinstalled.
 *
 * Initials boxes sit just under each "Client initials" label as measured in the
 * dompdf render (labels span x 0.794-0.906). Page 1 is the only page whose
 * length varies with customer data (label at 0.80, up to 0.84 with a long name
 * and address), so its box sits at 0.855 to stay clear in the worst case.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

return array(
	'ajt_virtual_office_agreement' => array(
		'rev'      => 2,
		'title'    => 'Virtual Office and Business Address Service Agreement',
		'kind'     => 'doc',
		'asset'    => 'virtual-office-business-address-agreement.html',
		'filename' => 'virtual-office-business-address-service-agreement.pdf',
		'signers'  => array( 'Client', 'Provider' ),
		'page'     => 9,
		'layout'   => array(
			array( 'type' => 'initials', 'party' => 1, 'page' => 1, 'x' => 0.80, 'y' => 0.855, 'w' => 0.106, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'initials', 'party' => 1, 'page' => 3, 'x' => 0.80, 'y' => 0.743, 'w' => 0.106, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'initials', 'party' => 1, 'page' => 4, 'x' => 0.80, 'y' => 0.645, 'w' => 0.106, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'initials', 'party' => 1, 'page' => 5, 'x' => 0.80, 'y' => 0.654, 'w' => 0.106, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'initials', 'party' => 1, 'page' => 6, 'x' => 0.80, 'y' => 0.273, 'w' => 0.106, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'initials', 'party' => 1, 'page' => 7, 'x' => 0.80, 'y' => 0.674, 'w' => 0.106, 'h' => 0.035, 'fill' => '' ),
			array( 'type' => 'signature', 'party' => 1, 'page' => 9, 'x' => 0.08, 'y' => 0.32, 'w' => 0.42, 'h' => 0.065, 'fill' => '' ),
			array( 'type' => 'date', 'party' => 1, 'page' => 9, 'x' => 0.56, 'y' => 0.32, 'w' => 0.22, 'h' => 0.04, 'fill' => '' ),
			array( 'type' => 'signature', 'party' => 2, 'page' => 9, 'x' => 0.08, 'y' => 0.55, 'w' => 0.42, 'h' => 0.065, 'fill' => '' ),
			array( 'type' => 'date', 'party' => 2, 'page' => 9, 'x' => 0.56, 'y' => 0.55, 'w' => 0.22, 'h' => 0.04, 'fill' => '' ),
		),
	),
);
