<?php
/**
 * Gmail Intake filing rules for NC Secretary of State mail (the part of Gmail Intake that stays in AJCore-RA).
 *
 * AJCore owns the mailbox, OAuth, log and filing machinery; this class answers its 'ajcore_gmail_intake_*'
 * filters with the document-type keywords, rename prefixes, categories and flyer handling.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Gmail_Rules {

	public static function init() {
		add_filter( 'ajcore_gmail_intake_pdf_is_document', array( __CLASS__, 'pdf_is_document' ), 10, 2 );
		add_filter( 'ajcore_gmail_intake_suggest_filename', array( __CLASS__, 'suggest_filename' ), 10, 3 );
		add_filter( 'ajcore_gmail_intake_classify_text', array( __CLASS__, 'classify_text' ), 10, 3 );
		add_filter( 'ajcore_gmail_intake_filename_labels', array( __CLASS__, 'filename_labels' ) );
		add_filter( 'ajcore_gmail_intake_default_address', array( __CLASS__, 'default_address' ) );
	}

	public static function default_address( $address ) {
		return '' !== (string) $address ? $address : 'universityplaceofficesuites@gmail.com';
	}

	/**
	 * Heuristic: a PDF is a real filing document (filed to the customer) if its filename contains one of these
	 * keywords; anything else (informational flyers bundled into the same email) is skipped.
	 */
	public static function pdf_is_document( $default, $filename ) {
		$name     = strtolower( (string) $filename );
		$keywords = array( 'articles', 'certificate', 'ein', 'notice', 'filing', 'annual-report', 'annual_report', 'order', 'letter', 'statement', 'formation', 'authority', 'organization', 'dissolution', 'amendment', 'reinstatement', 'application' );
		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $name, $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	/** Rename field in the preview/file panel, e.g. "AOO {Company Name}.pdf"; the original name when no rule matches. */
	public static function suggest_filename( $suggested, $original_filename, $company_name ) {
		$lower        = strtolower( (string) $original_filename );
		$company_name = trim( (string) $company_name );
		$rules        = array(
			'application for certificate of authority' => 'COA Application',
			'articles of incorporation'                => 'AOI',
			'certificate of authority'                 => 'COA',
			'articles'                                 => 'AOO',
		);
		if ( '' !== $company_name ) {
			foreach ( $rules as $keyword => $prefix ) {
				if ( false !== strpos( $lower, $keyword ) ) {
					return $prefix . ' ' . $company_name . '.pdf';
				}
			}
		}
		return $original_filename;
	}

	/** Keyword rules run against the PDF's own text, then the email subject, then the body. */
	public static function classify_text( $result, $haystack, $company_name ) {
		if ( null !== $result ) {
			return $result;
		}
		$lower        = strtolower( (string) $haystack );
		$company_name = trim( (string) $company_name );
		if ( '' === $lower ) {
			return null;
		}

		// Other formation filings: one generic category, a short name per document type. Checked before Articles of
		// Organization because a multi-page application can mention other filings. Order matters: the application
		// (whose text also says "certificate of authority") must be tested before the certificate itself.
		$formation_rules = array(
			'application for certificate of authority' => 'COA Application of ',
			'articles of incorporation'                => 'AOI of ',
			'certificate of authority'                 => 'COA of ',
		);
		if ( '' !== $company_name ) {
			foreach ( $formation_rules as $keyword => $prefix ) {
				if ( false !== strpos( $lower, $keyword ) ) {
					return array( 'filename' => $prefix . $company_name, 'category' => 'Formation Documents' );
				}
			}
		}

		if ( '' !== $company_name && false !== strpos( $lower, 'articles of organization' ) ) {
			return array( 'filename' => 'AOO of ' . $company_name, 'category' => 'Formation Documents' );
		}

		// SOS/NC's confirmation that a Change of Registered Office/Agent filing went through: the proof document for the
		// "RA Change Filed with SOS/NC" service status. Own category ("Change of RA"); see the
		// ajcore_get_portal_file_settings() backfill that makes sure it exists.
		if ( '' !== $company_name && false !== strpos( $lower, 'change of registered office and/or registered agent' ) ) {
			return array( 'filename' => 'RA Change Confirmation - ' . $company_name, 'category' => 'Change of RA' );
		}

		$flyer_rules = array(
			'rural rise'          => 'Rural Rise',
			'misleading mailings' => 'Misleading Mailings Targeting New Companies',
			'notice to employers' => 'Important Notice to Employers',
		);
		foreach ( $flyer_rules as $keyword => $label ) {
			if ( false !== strpos( $lower, $keyword ) ) {
				return array(
					'filename'          => 'Flyer ' . $label,
					'category'          => 'SOSNC Flyers',
					'link'              => '',
					'managed_elsewhere' => true,
					'managed_note'      => __( 'Flyer — managed under Shared flyers, not filed', 'ajcore-ra' ),
				);
			}
		}
		return null;
	}

	/** Friendly label used when building the auto filename from the attachment's own name. */
	public static function filename_labels( $labels ) {
		return array_merge( (array) $labels, array(
			'articles'      => 'Articles of Organization',
			'certificate'   => 'Certificate',
			'ein'           => 'EIN Letter',
			'annual-report' => 'Annual Report',
			'annual_report' => 'Annual Report',
			'formation'     => 'Formation Document',
			'authority'     => 'Certificate of Authority',
			'organization'  => 'Articles of Organization',
			'dissolution'   => 'Dissolution',
			'amendment'     => 'Amendment',
			'reinstatement' => 'Reinstatement',
		) );
	}
}
