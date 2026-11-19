<?php
/**
 * AJCore-RA built-in E-Sign forms.
 *
 * Add a PDF (or HTML doc) beside this file and a deterministic ajt_* entry below.
 * Bump 'rev' to push a changed body/layout to sites; a form an administrator has
 * edited is never overwritten, and a deleted one is never reinstalled.
 *
 * HTML docs place signer boxes with anchors in the text (see build_html_pdf()):
 * [[signature:1]] [[initials:1]] [[date:2]] [[text:1]] [[initials-every-page:1]].
 * Boxes are measured from the rendered PDF, so text can flow across any number of
 * pages. A fixed 'layout' is only needed for uploaded PDF forms.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

return array(
	'ajt_virtual_office_agreement' => array(
		'rev'             => 5,
		// Text fingerprint of the 0.5.13 document (installed before edit tracking) so
		// an untouched copy of it can still be upgraded.
		'legacy_text_md5' => 'ec5a9a3b85bafafe1b9fe7e610938c48',
		'title'           => 'Virtual Office and Business Address Service Agreement',
		'kind'            => 'doc',
		'asset'           => 'virtual-office-business-address-agreement.html',
		'filename'        => 'virtual-office-business-address-service-agreement.pdf',
		'signers'         => array( 'Client', 'Provider' ),
		'page'            => 0,
		// No fixed layout: signer boxes come from [[signature:1]]-style anchors in the HTML.
		'layout'          => array(),
	),
);
