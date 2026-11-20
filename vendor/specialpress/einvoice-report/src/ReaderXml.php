<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Reads the XML report of the KoSIT validator (VARL, xmlns:rep="http://www.xoev.de/de/validator/varl/1").
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report;

use DOMDocument;
use DOMXPath;
use Exception;

// Same direct-access guard as Report.php in this package - see that file's comment.
if ( ! defined( 'ABSPATH' ) )
	exit;


/**
 * class: ReaderXml
 *
 * Originally part of einvoice-validation-explainer-for-peppol-en16931 ( class-spx-reader-xml.php ) -
 * see Report.php's own doc block for why it lives in this package. read_meta() also reads
 * `rep:engine` and a best-effort configuration version - see read_config_version() - because the
 * report model carries the acceptance verdict, the validator version and the configuration version.
 *
 * ============================================================================================
 * THE KOSIT REPORT SCHEMA ( rep:report, xmlns:rep="http://www.xoev.de/de/validator/varl/1" )
 * ============================================================================================
 *
 *   /rep:report/rep:assessment/rep:accept|rep:reject   THE VERDICT - the scenario's acceptMatch
 *   /rep:report/@valid                        NOT the verdict: "no step reported anything", so a
 *                                               warning alone already makes it 'false'. Fallback only.
 *   /rep:report/rep:engine/rep:name            the validator's own name/version string
 *   /rep:report/rep:timestamp                  when the validator ran
 *   /rep:report//s:scenario/s:name             which profile matched - names CII vs. UBL
 *   /rep:report//s:scenario/s:description      free-text prose about the scenario's own sources -
 *                                               the only place a configuration version appears, see
 *                                               read_config_version()
 *   /rep:report//rep:documentData/(seller|id|issueDate)   the three fields the report itself
 *                                               recognised, unprefixed / no namespace
 *   /rep:report//rep:validationStepResult      one per validation step, @id, @valid, optional
 *                                               s:resource/s:name, zero or more rep:message children
 *   rep:message/@id, @code, @level, @xpathLocation, and its text          one finding each
 *
 * ============================================================================================
 * ⚠️ THE ONE PART THIS SCHEMA DOES NOT CARRY: THE FULL DOCUMENT VIEW
 * ============================================================================================
 *
 * rep:documentData is three fields, not the field-by-field table the document view needs. That table
 * only exists inside `<rep:explanation>`, which embeds the SAME HTML markup ReaderHtml already
 * parses - measured: every report's embedded explanation carries the identical `class="metadata"` /
 * `class="tbl-errors"` / `class="document"` shape as a standalone HTML report. So this reader does
 * not parse that table a second, independent way - it slices the explanation's markup back out and
 * hands it to ReaderHtml::load() + ::read_document().
 *
 * ============================================================================================
 * ⚠️ SVRL IS NOT IMPLEMENTED
 * ============================================================================================
 *
 * flavour() tells 'kosit' ( this schema ) apart from bare 'svrl' ( plain Schematron
 * output, no KoSIT wrapper ). Zero real 'svrl' reports have ever been seen - read() refuses it
 * honestly instead of guessing at a format nobody has actually produced.
 *
 * @since 1.0.0
 */

// ⚠️ GUARDED - see Report.php's own comment on this same line for why.

if ( ! class_exists( __NAMESPACE__ . '\\ReaderXml' ) ) :

class ReaderXml
{


	const NS_REP = 'http://www.xoev.de/de/validator/varl/1';
	const NS_S   = 'http://www.xoev.de/de/validator/framework/1/scenarios';


	/**
	 * @param	string	$raw
	 * @return	bool
	 * @since	1.0.0
	 */

	public static function detect( $raw )
	{
		$head = substr( (string) $raw, 0, 65536 );

		if ( strpos( $head, '<' ) === false )
			return( false );

		return( strpos( $head, 'schematron-output' ) !== false
			|| strpos( $head, 'failed-assert' ) !== false
			|| preg_match( '/<(?:[A-Za-z0-9_-]+:)?(?:validationResultsDocument|report)\b/', $head ) === 1 );
	}


	/**
	 * @param	string	$raw
	 * @return	string	'svrl' | 'kosit' | ''
	 * @since	1.0.0
	 */

	public static function flavour( $raw )
	{
		$head = substr( (string) $raw, 0, 65536 );

		if ( strpos( $head, 'schematron-output' ) !== false || strpos( $head, 'failed-assert' ) !== false )
			return( 'svrl' );

		if ( preg_match( '/<(?:[A-Za-z0-9_-]+:)?(?:validationResultsDocument|report)\b/', $head ) === 1 )
			return( 'kosit' );

		return( '' );
	}


	/**
	 * Read a report.
	 *
	 * @param	string	$raw
	 * @return	Report
	 * @throws	Exception	when the content is not a readable 'kosit'-flavour report
	 * @since	1.0.0
	 */

	public static function read( $raw )
	{
		$raw     = (string) $raw;
		$flavour = self::flavour( $raw );

		if ( $flavour === 'svrl' )
			throw new Exception( 'This looks like a plain SVRL Schematron report ( no KoSIT wrapper ). Reading that format is not implemented yet - no real example was available to build it against. Please upload the KoSIT XML or HTML report instead.' );

		if ( $flavour !== 'kosit' )
			throw new Exception( 'This file was recognised as XML but not as a known validation report format.' );

		$report = new Report();

		$report->meta['source'] = 'xml';

		$dom = self::load( $raw );
		$xp  = new DOMXPath( $dom );
		$xp->registerNamespace( 'rep', self::NS_REP );
		$xp->registerNamespace( 's', self::NS_S );

		self::read_meta( $xp, $report );
		self::read_steps_and_findings( $xp, $report );
		self::read_document( $xp, $dom, $report );

		if ( ! $report->findings && ! $report->steps )
			throw new Exception( 'This file was recognised as a validation report, but neither its findings nor its validation steps could be read. The report layout may have changed.' );

		$report->build_paths();
		$report->link_findings();

		ReaderHtml::check_plausibility( $report );

		return( $report );
	}


	/**
	 * @param	string	$raw
	 * @return	DOMDocument
	 * @throws	Exception
	 * @since	1.0.0
	 */

	private static function load( $raw )
	{
		if ( trim( $raw ) === '' )
			throw new Exception( 'The file is empty.' );

		$dom = new DOMDocument();

		$previous = libxml_use_internal_errors( true );

		$ok = $dom->loadXML( $raw, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $ok )
			throw new Exception( 'The file could not be parsed as XML.' );

		return( $dom );
	}


	/**
	 * The report metadata: verdict, engine, timestamp, matched scenario ( + its config version ),
	 * recognised document data.
	 *
	 * @param	DOMXPath	$xp
	 * @param	Report		$report
	 * @return	void
	 * @since	1.0.0
	 */

	private static function read_meta( DOMXPath $xp, Report $report )
	{
		/**
		 * ⚠️ THE VERDICT IS rep:assessment, NOT /rep:report/@valid. They are different statements and
		 * they disagree on exactly the everyday case: a document with warnings and no error.
		 *
		 * `@valid` is written by KoSIT's `default-report.xsl` as "no validation step reported a
		 * problem" - a single WARNING already makes its step invalid, and with it the whole report.
		 * The recommendation the visitor is actually being given comes from the scenario's
		 * `<acceptMatch>`, which resolves to `rep:assessment/rep:accept` or `.../rep:reject`; the
		 * same XSL emits `accept` for "errors: none, other messages: some".
		 *
		 * Measured 21.09.2026 on a ZUGFeRD EXTENDED invoice through the real KoSIT validator 1.6.3:
		 * `valid="false"`, three warnings, no error - and `<rep:assessment><rep:accept>`. Reading the
		 * attribute would show the visitor REJECTED for a document the validator recommends accepting.
		 *
		 * The attribute stays as the fallback: a report from some other producer may carry no
		 * assessment at all, and then "did anything at all go wrong" is the best answer available.
		 */

		$accept = $xp->query( '//rep:assessment/rep:accept' )->item( 0 );
		$reject = $xp->query( '//rep:assessment/rep:reject' )->item( 0 );

		if ( $accept || $reject )
			$report->meta['compliant'] = $accept ? 'yes' : 'no';
		else
		{
			$root = $xp->query( '/rep:report' )->item( 0 );

			/**
			 * ⚠️ **THE RECOMMENDATION DECIDES, NOT THE `valid` ATTRIBUTE - and in practice the two
			 * contradict each other.**
			 *
			 * A KoSIT report carries both. `valid` is only true when **every** check step was
			 * clean; `<rep:assessment>` is the verdict the configuration's `acceptMatch` actually
			 * reached. A single **warning** sets its Schematron step to `valid="false"` and with it
			 * the whole report - the recommendation is still `<rep:accept>`.
			 *
			 * Measured on 24.09.2026 on `X10_01_Kleinunternehmer_ohneUStId` against ZUGFeRD 2.5.2: the
			 * only finding is the warning `BR-FXEXT-E-08rev`, `valid="false"`, recommendation
			 * `<rep:accept>`. Whoever reads `valid` tells the user their invoice was rejected where
			 * the validator accepts it.
			 *
			 * ⚠️ THE ATTRIBUTE STAYS THE FALLBACK. Not every report carries a recommendation - a
			 * `noScenarioMatched` report, for instance, does not -, and there `valid` is the only statement.
			 *
			 * ⚠️ ONLY THE DIRECT CHILDREN OF THE RECOMMENDATION. `<rep:accept>` contains the rendered
			 * HTML report; a search over all descendants would find the word in a message text.
			 */
			$stated = null;

			$assessment = $xp->query( '/rep:report/rep:assessment' )->item( 0 );

			if ( $assessment instanceof \DOMElement )
			{
				foreach ( $assessment->childNodes as $child )
				{
					if ( ! ( $child instanceof \DOMElement ) ) continue;

					if ( $child->localName === 'accept' ) { $stated = 'yes'; break; }
					if ( $child->localName === 'reject' ) { $stated = 'no';  break; }
				}
			}

			if ( $stated !== null )
				$report->meta['compliant'] = $stated;
			elseif ( $root && $root->hasAttribute( 'valid' ) )
				$report->meta['compliant'] = ( $root->getAttribute( 'valid' ) === 'true' ) ? 'yes' : 'no';
		}

		$engine = $xp->query( '//rep:engine/rep:name' )->item( 0 );

		if ( $engine )
			$report->meta['engine'] = self::text( $engine );

		$timestamp = $xp->query( '//rep:timestamp' )->item( 0 );

		if ( $timestamp )
			$report->meta['validated_at'] = self::text( $timestamp );

		/**
		 * ⚠️ documentReference IS A LOCAL TEMP FILE PATH, NOT SHOWN.
		 *
		 * Measured: `/C:/Users/…/AppData/Local/Temp/spkv-…/upload.xml` for the `local` validator
		 * adapter, or the fixed literal `upload.xml` for `kosit-daemon` - neither is
		 * meaningful to an admin reading the rendered report. 'reference' is left unset for
		 * XML-sourced reports rather than surfacing either.
		 */

		$scenario = $xp->query( '//s:scenario/s:name' )->item( 0 );

		if ( $scenario )
		{
			$name = self::text( $scenario );

			$report->meta['document_type'] = $name;

			$syntax = ReaderHtml::syntax_from_text( $name );

			if ( $syntax !== '' )
				$report->meta['syntax'] = $syntax;
		}

		/**
		 * ⚠️ THE SCENARIO NAME IS NOT THE CUSTOMISATION ID, AND ONLY THE ID DECIDES THE PROFILE.
		 * `document_type` holds a display name such as "ZUGFeRD 2.5.2 EXTENDED (CII)"; the profile
		 * table is keyed by URNs, so with the name every KoSIT report would fall back to `en16931`
		 * ( measured 06.10.2026 ). The URN stands in the XPath of the matched scenario. Only
		 * `<rep:scenarioMatched>` is read, and the first quoted `urn:` string is taken - word for word
		 * as in `ReaderXml.mjs`.
		 */
		$match = $xp->query( '//rep:scenarioMatched//s:match' )->item( 0 );

		if ( $match && preg_match( "/'(urn:[^']+)'/", self::text( $match ), $urn ) === 1 )
			$report->meta['customization'] = $urn[ 1 ];

		/**
		 * ============================================================================================
		 * ⚠️ `<rep:noScenarioMatched>` - "REJECTED" MEANS "NOT CHECKED" HERE
		 * ============================================================================================
		 *
		 * If the KoSIT validator finds no matching scenario, it writes a complete report with
		 * `<rep:reject>` and **zero** rule messages. Whoever only counts the messages reads
		 * "the validator objected to nothing" from it - the exact reverse of what happened.
		 *
		 * Measured on 23.09.2026 in the triple run of the comparison check: with the mutated demo documents,
		 * `kosit=no` stood row after row in the comparison table next to `mustang=error` and `php=error`.
		 * That looked like a dramatic difference and was none - KoSIT had simply not checked these
		 * documents.
		 *
		 * `meta['scenario']` = 'none' makes the difference visible; `Frontend\Comparison` treats
		 * such a report like a **missing** one, not like an empty one.
		 */

		if ( $xp->query( '//rep:noScenarioMatched' )->length > 0 )
		{
			$report->meta['scenario'] = 'none';

			$report->notices[] = 'The validator found no scenario for this document and therefore did not check it against any rule set. Its verdict says nothing about the document\'s content.';
		}

		$description = $xp->query( '//s:scenario/s:description' )->item( 0 );

		if ( $description )
		{
			$version = self::read_config_version( self::text( $description ) );

			if ( $version !== '' )
				$report->meta['config_version'] = $version;
		}

		$seller = $xp->query( '//rep:documentData/seller' )->item( 0 );
		$id     = $xp->query( '//rep:documentData/id' )->item( 0 );
		$date   = $xp->query( '//rep:documentData/issueDate' )->item( 0 );

		if ( $seller )
			$report->meta['seller'] = self::text( $seller );

		if ( $id )
			$report->meta['invoice_number'] = self::text( $id );

		if ( $date )
			$report->meta['invoice_date'] = self::normalise_date( self::text( $date ) );
	}


	/**
	 * A version-looking token out of the scenario's free-text description - the configuration version
	 * of the report model.
	 *
	 * ⚠️ BEST EFFORT, NOT A SCHEMA FIELD. Measured against a real report ( XRechnung/CII, 09.09.2026 ):
	 * the description is human prose - "Uses UN/CEFACT … version D16B XML Schema, Schematron rules
	 * from EN16931:2017, and XRechnung Schematron rules version 3.0.2" - no dedicated element carries
	 * "the" configuration version, because a scenario can name several ( D16B, EN16931:2017, 3.0.2 all
	 * in the same sentence ). This picks the LAST dotted-number version mentioned after the word
	 * "version" ( case-insensitive ), which is the CIUS/national-rules version in every example seen
	 * ( XRechnung's own "3.0.2", not the underlying EN16931 or D16B release ) - the most specific one,
	 * and the one an admin comparing two runs would actually want. Returns '' rather than guess when
	 * no such token exists, same "no field beats a wrong field" rule the Explainer's own resolve_field()
	 * already applies.
	 *
	 * @param	string	$description
	 * @return	string
	 * @since	1.0.0
	 */

	private static function read_config_version( $description )
	{
		if ( preg_match_all( '/version\s+(\d+(?:\.\d+)+)/i', (string) $description, $m ) )
			return( (string) end( $m[1] ) );

		return( '' );
	}


	/**
	 * ⚠️ CII AND UBL WRITE THE ISSUE DATE DIFFERENTLY, MEASURED, NOT ASSUMED - CII's is the raw
	 * UN/CEFACT format-102 string ( "20160404" ), UBL's is already ISO 8601. Only the bare eight-digit
	 * form is touched.
	 *
	 * @param	string	$text
	 * @return	string
	 * @since	1.0.0
	 */

	private static function normalise_date( $text )
	{
		$text = trim( (string) $text );

		if ( preg_match( '/^\d{8}$/', $text ) )
			return( substr( $text, 0, 4 ) . '-' . substr( $text, 4, 2 ) . '-' . substr( $text, 6, 2 ) );

		return( $text );
	}


	/**
	 * The validation steps and the findings inside them, in one pass over rep:validationStepResult.
	 *
	 * @param	DOMXPath	$xp
	 * @param	Report		$report
	 * @return	void
	 * @since	1.0.0
	 */

	private static function read_steps_and_findings( DOMXPath $xp, Report $report )
	{
		foreach ( $xp->query( '//rep:validationStepResult' ) as $result )
		{
			$name = $xp->query( './s:resource/s:name', $result )->item( 0 );

			// ⚠️ NOT EVERY STEP NAMES ITSELF - "val-xml" carries no s:resource in every example seen,
			// falling back to its @id keeps the tally honest instead of silently losing one row of it.
			$label = $name ? self::text( $name ) : (string) $result->getAttribute( 'id' );

			$errors = $warnings = $infos = 0;

			foreach ( $xp->query( './rep:message', $result ) as $message )
			{
				$level = (string) $message->getAttribute( 'level' );

				if ( $level === 'error' )
					$errors++;
				elseif ( $level === 'warning' )
					$warnings++;
				else
					$infos++;

				$report->findings[] = array(
					'position' => (string) $message->getAttribute( 'id' ),
					'rule'     => (string) $message->getAttribute( 'code' ),
					'severity' => ( $level === 'error' || $level === 'warning' ) ? $level : 'information',
					'message'  => self::text( $message ),
					'path'     => (string) $message->getAttribute( 'xpathLocation' ),
					'rows'     => array(),
				);
			}

			$report->steps[] = array(
				'step'     => $label,
				'errors'   => $errors,
				'warnings' => $warnings,
				'infos'    => $infos,
			);
		}
	}


	/**
	 * The document table - delegated to ReaderHtml against the report's embedded explanation HTML.
	 *
	 * @param	DOMXPath	$xp
	 * @param	DOMDocument	$dom
	 * @param	Report		$report
	 * @return	void
	 * @since	1.0.0
	 */

	private static function read_document( DOMXPath $xp, DOMDocument $dom, Report $report )
	{
		$explanation = $xp->query( '//rep:explanation' )->item( 0 );

		if ( ! $explanation )
		{
			$report->notices[] = 'This report carries no embedded HTML explanation, so the document view could not be read; only the findings are shown.';

			return;
		}

		/**
		 * ⚠️ RE-SERIALISED AND RE-PARSED AS HTML, NOT QUERIED IN PLACE - the explanation's <html>
		 * element sits in the xhtml namespace when parsed as XML, and ReaderHtml::read_document()'s
		 * unprefixed queries match nothing against a namespace-aware DOM. Serialising back to a string
		 * and re-parsing with loadHTML() ( namespace-oblivious ) is what makes those queries apply.
		 */

		$html = '';

		foreach ( $explanation->childNodes as $child )
			$html .= $dom->saveXML( $child );

		if ( trim( $html ) === '' )
		{
			$report->notices[] = 'The embedded explanation was empty, so the document view could not be read; only the findings are shown.';

			return;
		}

		try
		{
			$htmlDom = ReaderHtml::load( $html );
			$htmlXp  = new DOMXPath( $htmlDom );

			ReaderHtml::read_document( $htmlXp, $report );
		}
		catch ( \Throwable $e )
		{
			$report->notices[] = 'The embedded explanation could not be read as HTML, so the document view is unavailable; only the findings are shown.';
		}
	}


	/**
	 * @param	\DOMNode|null	$node
	 * @return	string
	 * @since	1.0.0
	 */

	private static function text( $node )
	{
		if ( ! $node )
			return( '' );

		return( trim( preg_replace( '/\s+/u', ' ', (string) $node->textContent ) ) );
	}

}

endif;
