<?php
/**
 * Pipeline filing rules for NC Secretary of State mail (the part of Pipeline that stays in AJCore-RA).
 *
 * AJCore owns the mailbox, OAuth, log and filing machinery; this class answers its 'ajcore_pipeline_*'
 * filters with the document-type keywords, rename prefixes, categories and flyer handling.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Pipeline_Rules {

	public static function init() {
		add_filter( 'ajcore_pipeline_pdf_is_document', array( __CLASS__, 'pdf_is_document' ), 10, 2 );
		add_filter( 'ajcore_pipeline_suggest_filename', array( __CLASS__, 'suggest_filename' ), 10, 3 );
		add_filter( 'ajcore_pipeline_classify_text', array( __CLASS__, 'classify_text' ), 10, 3 );
		add_filter( 'ajcore_pipeline_filename_labels', array( __CLASS__, 'filename_labels' ) );
		add_filter( 'ajcore_pipeline_default_address', array( __CLASS__, 'default_address' ) );
		add_filter( 'ajcore_pipeline_company_name', array( __CLASS__, 'company_name' ), 10, 3 );
		add_filter( 'ajcore_pipeline_filters', array( __CLASS__, 'mailbox_filter' ), 10, 2 );
		add_filter( 'ajcore_pipeline_rules_summary', array( __CLASS__, 'rules_summary' ) );
	}

	/**
	 * WHICH EMAILS ENTER THE PIPELINE for ncllcagents.com: edit the lists below to change it. Each filled list narrows the
	 * result ('match' => 'all'); a list matches if any one of its words appears (subject, or sender name/address).
	 *  - Zoho: the original NC Secretary of State approval emails ("Congratulations! Your New Business Formation Document...")
	 *    from sosnc.gov.
	 *  - Gmail: the same email forwarded from Zoho; the forward comes from our own address, so only the subject is checked.
	 * Return null instead to fall back to the filter saved in AJCore's settings (blank = every message).
	 */
	public static function mailbox_filter( $filter, $source ) {
		if ( null !== $filter ) {
			return $filter;
		}
		if ( 'zoho' === $source ) {
			return array( 'subject' => array( 'Congratulations' ), 'sender' => array( 'sosnc.gov' ), 'any' => array(), 'advanced' => '', 'match' => 'all' );
		}
		return array( 'subject' => array( 'Congratulations' ), 'sender' => array(), 'any' => array(), 'advanced' => '', 'match' => 'all' );
	}

	private static function entity_suffix() {
		return '(?:LLC|L\.L\.C\.|Inc\.?|Incorporated|Corp\.?|Corporation|LLP|L\.L\.P\.|LP|PLLC|PLC|Co\.)';
	}

	/**
	 * Company a NC SOS notice is about. Subject first ("Filing: {name} (", "Filing: {name}", "... for {name}<suffix>", or the
	 * whole subject when it ends in a suffix), then the body: the approval template's subject is a generic "Congratulations"
	 * and the name only appears as "Document Filed: ARTICLES OF ORGANIZATIONfor {name}" (no space before "for").
	 */
	public static function company_name( $name, $subject, $body_text ) {
		if ( '' !== trim( (string) $name ) ) {
			return $name;
		}
		$subject = trim( (string) $subject );
		$suffix  = self::entity_suffix();
		if ( preg_match( '/Filing:\s*(.+?)\s*\(/i', $subject, $m ) || preg_match( '/Filing:\s*(.+)$/i', $subject, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/\bfor\s+([A-Z0-9][A-Za-z0-9&,.\'\-\s]*?\s*' . $suffix . ')\b/i', $subject, $m ) || preg_match( '/^([A-Z0-9][A-Za-z0-9&,.\'\-\s]*?\s*' . $suffix . ')$/i', $subject, $m ) ) {
			return trim( $m[1] );
		}
		$body_text = (string) $body_text;
		if ( '' === trim( $body_text ) ) {
			return '';
		}
		if ( preg_match( '/Document Filed:\s*.*?for\s+([A-Z0-9][A-Za-z0-9&,.\'\-\s]*?\s*' . $suffix . ')\b/is', $body_text, $m ) || preg_match( '/for\s+([A-Z0-9][A-Za-z0-9&,.\'\-\s]*?\s*' . $suffix . ')\b/i', $body_text, $m ) ) {
			return trim( preg_replace( '/\s+/', ' ', $m[1] ) );
		}
		return '';
	}

	/** Lines for the Pipeline "Filters & rules" panel, describing the rules in this class. */
	public static function rules_summary( $sections ) {
		$sections[] = array( 'title' => __( 'Mailbox filter set by AJCore-RA', 'ajcore-ra' ), 'items' => array(
			__( 'Zoho: subject has "Congratulations" AND sender has "sosnc.gov".', 'ajcore-ra' ),
			__( 'Gmail (forwards): subject has "Congratulations".', 'ajcore-ra' ),
		) );
		$sections[] = array( 'title' => __( 'Company name (AJCore-RA)', 'ajcore-ra' ), 'items' => array(
			__( 'Subject: "Filing: {name} (…" or "Filing: {name}", or "… for {name}" ending in LLC / Inc / Corp / LLP / LP / PLLC / PLC / Co.', 'ajcore-ra' ),
			__( 'Body fallback: "Document Filed: … for {name}" (the approval email has no company in its subject).', 'ajcore-ra' ),
		) );
		$sections[] = array( 'title' => __( 'Which PDFs count as filing documents (AJCore-RA)', 'ajcore-ra' ), 'items' => array(
			__( 'Filename contains: articles, certificate, ein, notice, filing, annual-report, order, letter, statement, formation, authority, organization, dissolution, amendment, reinstatement or application.', 'ajcore-ra' ),
		) );
		$sections[] = array( 'title' => __( 'Classification, from the PDF text, then subject, then body (AJCore-RA)', 'ajcore-ra' ), 'items' => array(
			__( 'Application for Certificate of Authority → "COA Application of {name}"; Articles of Incorporation → "AOI of {name}"; Certificate of Authority → "COA of {name}"; all in Formation Documents.', 'ajcore-ra' ),
			__( 'Articles of Organization → "AOO of {name}" in Formation Documents.', 'ajcore-ra' ),
			__( 'Change of Registered Office and/or Registered Agent → "RA Change Confirmation - {name}" in Change of RA.', 'ajcore-ra' ),
			__( 'Rural Rise, Misleading Mailings, Notice to Employers → SOSNC Flyers: managed under Shared flyers (Files), never filed from mail.', 'ajcore-ra' ),
		) );
		return $sections;
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
