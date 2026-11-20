<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Reads a Mustangproject validation report into the shared model.
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


// ⚠️ GUARDED - see Report.php's own comment on this same line for why.

if ( ! class_exists( __NAMESPACE__ . '\\ReaderMustang' ) ) :

/**
 * class: ReaderMustang
 *
 * ============================================================================================
 * Measured on 60 real Mustang reports ( Mustang CLI 2.25.0, 23.09.2026 )
 * ============================================================================================
 *
 * The obvious assumptions about this format are wrong in two places, and both would lead to a
 * failure:
 *
 * 1. ⚠️ **A CLEAN REPORT HAS NO MESSAGES AT ALL.** 57 of the 60 reports stand at
 *    `status="valid"` and contain not a single `<error>`/`<notice>`. A reader that throws on
 *    "no entries the reader recognised" would send every error-free document into the error
 *    handling.
 *
 * 2. ⚠️ **MUSTANG STATES THE VERDICT ITSELF.** `<summary status="valid|invalid"/>` stands both under
 *    `<xml>` and at the top level. Deriving it from the number of errors happens to agree - but
 *    `Report::rejected()` distinguishes explicitly between "the report says it" and "we
 *    counted", and a report that brings a verdict should be asked for it.
 *
 * The structure as it really is:
 *
 *     <validation filename="…" datetime="…">
 *       <xml>
 *         <info><rules><fired>19</fired><failed>3</failed></rules>…</info>
 *         <messages>
 *           <error  type="24" location="…" criterion="…">[BR-CO-10]-Sum of Invoice line …</error>
 *           <notice type="27" location="…" criterion="…">[BR-DE-TMP-32] Eine Rechnung sollte …</notice>
 *         </messages>
 *         <summary status="invalid"/>
 *       </xml>
 *       <messages></messages>      ← the PDF part, empty here
 *       <summary status="invalid"/>
 *     </validation>
 *
 * ⚠️ **`location` COMES IN TWO FORMS IN THE SAME REPORT.** The `<error>` entries write
 * namespace-qualified like KoSIT ( `/*:CrossIndustryInvoice[namespace-uri()='…'][1]/…` ), the
 * `<notice>` entries with a prefix ( `/rsm:CrossIndustryInvoice/…` ). Whoever can resolve only one
 * of the two forms loses half of the locations - `Frontend\DocumentView` registers both
 * namespaces and copes with both.
 *
 * @since 1.4.0
 */

class ReaderMustang
{


	/**
	 * Does the content look like a Mustang report?
	 *
	 * ⚠️ `<validation>` ALONE IS NOT ENOUGH - the name is too common. In addition a `<summary>` or
	 * at least an `<error>`/`<notice>` in it is required, so that an arbitrary
	 * `<validation>` document of another tool does not get caught here.
	 *
	 * @param	string	$raw
	 * @return	bool
	 * @since	1.4.0
	 */

	public static function detect( $raw )
	{
		$raw = (string) $raw;

		if ( stripos( $raw, '<validation' ) === false )
			return( false );

		return( stripos( $raw, '<summary' ) !== false
			|| stripos( $raw, '<error' ) !== false
			|| stripos( $raw, '<notice' ) !== false );
	}


	/**
	 * @param	string	$raw
	 * @return	Report
	 * @throws	Exception
	 * @since	1.4.0
	 */

	public static function read( $raw )
	{
		$dom = self::load( (string) $raw );
		$xp  = new DOMXPath( $dom );

		$root = $dom->documentElement;

		if ( $root === null || strtolower( $root->localName ) !== 'validation' )
			throw new Exception( 'This does not look like a Mustang validation report ( no <validation> root ).' );

		$report = new Report();

		$report->meta['source'] = 'mustang';

		if ( self::$repaired )
			$report->notices[] = 'This report declared UTF-8 but was not encoded as UTF-8; it was converted from Windows-1252 to be readable. Umlauts may differ from the validator\'s original output.';

		$version = self::one( $xp, '//*[local-name()="validator"]/@version' );

		$report->meta['engine'] = 'Mustangproject' . ( $version !== '' ? ' ' . $version : '' );

		$when = trim( (string) $root->getAttribute( 'datetime' ) );

		if ( $when !== '' )
			$report->meta['validated_at'] = $when;

		$profile = self::one( $xp, '//*[local-name()="info"]/*[local-name()="profile"]' );

		if ( $profile !== '' )
			$report->meta['document_type'] = $profile;

		/**
		 * ⚠️ THE PDF PART IS SKIPPED. Mustang checks PDF/A-3 and XML in one run; for the comparison
		 * check only the XML side counts, otherwise a PDF message would stand next to a rule violation
		 * that KoSIT cannot see at all. Hence the entry through `<xml>` and not through the top level.
		 */

		/**
		 * ============================================================================================
		 * ⚠️ `<exception>` BELONGS IN THE LIST - AND IT IS THE MOST IMPORTANT OF THE THREE
		 * ============================================================================================
		 *
		 * Measured on 23.09.2026 in the triple run of the comparison check. For `01.04a-INVOICE_uncefact.xml`
		 * KoSIT and VAMPIRE PHP said **accept**, Mustang **reject** - with exactly one shared message
		 * all three agreed on. The reason stood in an element a reader without this branch does not know:
		 *
		 *     <exception type="22">Cannot read field "scale" because "val" is null
		 *       <stacktrace>java.lang.NullPointerException: …
		 *
		 * **Mustang crashed on the document** and set `status="invalid"` - because of its own
		 * error, not because of the document. Without this branch the comparison view shows
		 * "one message, full agreement" and keeps quiet that a validator fell over.
		 *
		 * ⚠️ **A TOOL ERROR THAT APPEARS AS A VERDICT ON THE DOCUMENT IS THE MOST DANGEROUS DISPLAY
		 * OF THIS WHOLE PROJECT.** The user would read "invoice invalid" and search their document
		 * for an error that does not exist. The exception is therefore listed as a finding **and**
		 * additionally noted on the report.
		 */

		$nodes = $xp->query( '/*/*[local-name()="xml"]//*[local-name()="error" or local-name()="warning" or local-name()="notice" or local-name()="exception"]' );

		/**
		 * ⚠️ NO MESSAGES IS THE NORMAL CASE, NOT THE ERROR CASE. 57 of 60 reports of the
		 * corpus stand at `valid` and contain not a single one. Throwing here would send every
		 * error-free document into the error handling.
		 */

		$crashed = false;

		if ( $nodes !== false )
			foreach ( $nodes as $node )
			{
				$kind = strtolower( $node->localName );

				$message = trim( (string) $node->textContent );

				if ( $kind === 'exception' )
				{
					$crashed = true;

					/**
					 * ⚠️ THE STACK TRACE IS CUT OFF, THE MESSAGE IS NOT. Mustang appends the
					 * complete Java stack trace into the same element; writing it into an interface
					 * helps nobody. The first line says what happened.
					 */

					$first = trim( (string) preg_replace( '/<stacktrace.*$/s', '', $message ) );

					$report->findings[] = array(
						'position'  => trim( (string) $node->getAttribute( 'type' ) ),
						'rule'      => '',
						'severity'  => 'error',
						'message'   => 'The validator itself failed on this document: ' . ( $first !== '' ? $first : 'no detail given' ),
						'path'      => trim( (string) $node->getAttribute( 'location' ) ),
						'rows'      => array(),
						'criterion' => '',

						// Damit eine Ansicht sie anders behandeln kann als einen Belegfehler.
						'engineFailure' => true,
					);

					continue;
				}

				$report->findings[] = array(
					'position' => trim( (string) $node->getAttribute( 'type' ) ),
					'rule'     => self::rule_from_message( $message ),
					// ⚠️ `<warning>` EXISTS: 5 of the 47 reports under examples/validation_reports/mustang carry one, and Mustang 2.25.0 writes the
					// rules CII-SR-475/476 of FACTUR-X EXTENDED as warnings ( measured 20.10.2026 ). Unknown to the reader they
					// were dropped, and the report said "no findings" next to a step with two failed rules.
					'severity' => $kind === 'error' ? 'error' : ( $kind === 'warning' ? 'warning' : 'information' ),
					'message'  => $message,
					'path'     => trim( (string) $node->getAttribute( 'location' ) ),
					'rows'     => array(),

					// Mustang names the check expression of the rule - KoSIT does not.
					'criterion' => trim( (string) $node->getAttribute( 'criterion' ) ),
				);
			}

		/**
		 * The verdict.
		 *
		 * ⚠️ MUSTANG STATES IT ITSELF - `<summary status="valid|invalid"/>`. Deriving it from the
		 * error count would ignore that the status word is there, and `Report::rejected()`
		 * distinguishes explicitly between "the report says it" and "we counted". It is derived only
		 * when the word is missing.
		 */

		$status = strtolower( self::one( $xp, '/*/*[local-name()="summary"]/@status' ) );

		if ( $status === '' )
			$status = strtolower( self::one( $xp, '//*[local-name()="summary"]/@status' ) );

		/**
		 * ⚠️ AFTER A CRASH THE VERDICT IS NONE. Mustang sets `invalid` when it stumbled
		 * itself - measured on `01.04a-INVOICE_uncefact.xml`, where KoSIT and
		 * VAMPIRE PHP both accept. The word is therefore NOT taken as a statement about the document;
		 * `compliant` stays empty, and `Report::rejected()` then counts the findings.
		 * The crash stands as a finding and as a notice where someone sees it.
		 */

		if ( $crashed )
		{
			$report->notices[] = 'This validator reported an internal error while checking the document. Its verdict says more about the tool than about the invoice.';
		}
		elseif ( $status === 'valid' || $status === 'invalid' )
			$report->meta['compliant'] = $status === 'valid' ? 'yes' : 'no';
		else
		{
			$errors = 0;

			foreach ( $report->findings as $f )
				if ( ( $f['severity'] ?? '' ) === 'error' )
					$errors++;

			$report->meta['compliant'] = $errors > 0 ? 'no' : 'yes';

			$report->notices[] = 'This Mustang report states no verdict of its own; it was derived from the number of errors.';
		}

		/**
		 * The step tally, as far as Mustang keeps one.
		 *
		 * ⚠️ `<fired>` IS NOT THE NUMBER OF MESSAGES, but the number of rules checked -
		 * up to 108 in the corpus with zero findings. Reading it as an error count would be a dramatic
		 * false display.
		 */

		$fired  = self::one( $xp, '//*[local-name()="rules"]/*[local-name()="fired"]' );
		$failed = self::one( $xp, '//*[local-name()="rules"]/*[local-name()="failed"]' );

		/*
		 * ⚠️ `<failed>` COUNTS EVERY RULE THAT FIRED A MESSAGE, warnings and notices included. Taken as the error count
		 * it showed "2 errors" for a report with two warnings and a verdict "valid" ( measured 20.10.2026 ). The
		 * three numbers are therefore counted from the messages; `<failed>` stays only where there are none.
		 */
		$tally = array( 'error' => 0, 'warning' => 0, 'information' => 0 );

		foreach ( $report->findings as $f )
			if ( isset( $tally[ $f['severity'] ?? '' ] ) )
				$tally[ $f['severity'] ]++;

		if ( ! $report->findings && (int) $failed > 0 )
			$tally['error'] = (int) $failed;

		if ( $fired !== '' )
			$report->steps[] = array(
				'step'     => 'Schematron (' . $fired . ' rules checked)',
				'errors'   => $tally['error'],
				'warnings' => $tally['warning'],
				'infos'    => $tally['information'],
			);

		/*
		 * ⚠️ THE ERRORS OF THE PDF PART ARE KEPT APART, NOT AS FINDINGS. The findings stay the XML side only ( see above ),
		 * so the comparison with KoSIT is not touched. But a PDF with eight XMP errors under `<pdf>` and a top-level
		 * `<summary status="valid"/>` was shown as "No findings — the document passed" while the Validator Bridge rated it
		 * invalid ( measured 26.10.2026 with E01_01_1_Teilrechnung_fx.pdf ). The renderer reads `pdf_errors` and shows its
		 * own verdict bar for the PDF part instead of "passed". Same key and order in ReaderMustang.mjs.
		 */
		$pdf_errors = array();

		$nodes = $xp->query( '/*/*[local-name()="pdf"]//*[local-name()="error"]' );

		if ( $nodes !== false )
			foreach ( $nodes as $node )
				$pdf_errors[] = trim( (string) $node->textContent );

		if ( $pdf_errors )
			$report->meta['pdf_errors'] = $pdf_errors;

		return( $report );
	}


	/**
	 * The rule id from the message text.
	 *
	 * ⚠️ MUSTANG DOES NOT CARRY IT AS A FIELD. It stands in the running text, usually in square brackets
	 * at the start - `[BR-16]-...`. The first pattern that looks like an EN 16931 or
	 * XRechnung id is therefore searched for, in this order: in brackets first, otherwise free in the
	 * text.
	 *
	 * ⚠️ The recognition rate is measured on message texts of other validators, not on Mustang's own;
	 * see the pattern below.
	 *
	 * @param	string	$message
	 * @return	string	Empty when nothing looked like an id.
	 * @since	1.4.0
	 */

	public static function rule_from_message( $message )
	{
		$message = (string) $message;

		/**
		 * The common forms: BR-16, BR-CO-10, BR-S-08, BR-DE-15, **BR-DE-TMP-32**,
		 * PEPPOL-EN16931-R040, **PEPPOL-EN16931-P0100**, CII-SR-123, UBL-SR-12, BT-DE-13.
		 *
		 * ⚠️ MEASURED ON 92 REAL MESSAGE TEXTS ( KoSIT and valitool, 23.09.2026 ). A simpler
		 * pattern reached 91.3 % and was wrong for four messages. Two causes, both handled
		 * here:
		 *
		 * 1. **`BR-DE-TMP-32`** has two letter groups before the number, not
		 *    one. A pattern without that fails, and the search falls back to the first thing in the
		 *    running text that looks like an id: `BT-72`. A wrong id is worse
		 *    than none - it sends the reader to the wrong rule.
		 *
		 * 2. **`PEPPOL-EN16931-P0100`** has a `P`, not only `R`.
		 *
		 * ⚠️ **`BT-<number>` ALONE IS NO RULE ID BUT A FIELD REFERENCE** and therefore does
		 * NOT stand in the pattern. All four wrong assignments came from it: almost every
		 * rule text names a `(BT-72)` somewhere. `BT-DE-13` stays - that is a real
		 * XRechnung rule family.
		 */

		$pattern = '/\b('
			. 'PEPPOL-EN16931-[A-Z]+\d+'          // PEPPOL-EN16931-R040, -P0100
			. '|BR-(?:[A-Z]{1,4}-){0,2}\d+'        // BR-16, BR-CO-10, BR-S-08, BR-DE-TMP-32
			. '|(?:CII|UBL)-[A-Z]{2}-\d+'          // CII-SR-123, UBL-SR-12
			. '|BT-[A-Z]{2,3}-\d+'                 // BT-DE-13   ( NICHT das blosse BT-72 )
			. ')\b/';

		// In square brackets takes precedence - that is where it stands when it is marked at all.
		if ( preg_match( '/\[([^\]]{2,40})\]/', $message, $b ) && preg_match( $pattern, $b[1], $m ) )
			return( $m[1] );

		if ( preg_match( $pattern, $message, $m ) )
			return( $m[1] );

		return( '' );
	}


	/**
	 * @param	string	$raw
	 * @return	DOMDocument
	 * @throws	Exception
	 * @since	1.4.0
	 */

	private static function load( $raw )
	{
		$previous = libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		$ok  = $dom->loadXML( $raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		/**
		 * ============================================================================================
		 * ⚠️ MUSTANG WRITES `encoding="UTF-8"` AND DELIVERS NO UTF-8
		 * ============================================================================================
		 *
		 * Measured on 23.09.2026 over the ZUGFeRD examples: **12 of 12** reports fail in the
		 * XML parser, although they look complete and well-formed. The cause is the encoding -
		 * `mb_check_encoding( …, 'UTF-8' )` says **no** for all twelve.
		 *
		 * It is **not** the console or the pipe: neither `chcp 65001` nor
		 * `-Dfile.encoding=UTF-8` changes anything. Mustang writes its **German** message texts
		 * ( "…entsprechen", "Rechnungsposition" ) in the platform's single-byte character set and still
		 * sets `encoding="UTF-8"` in the header. The English reports of the XRechnung test suite
		 * therefore do not stand out - no character above 127 occurs there.
		 *
		 * ⚠️ **HERE THE TEXT IS CONVERTED, NOT GUESSED - AND IT IS SAID.** Windows-1252 is the only
		 * realistic source on this platform, and the alternative would be to reject a quarter
		 * of all real Mustang reports. The repair stands as a notice in the report, so that
		 * nobody takes it for the truth about the file.
		 */

		$repaired = false;

		if ( ( ! $ok || $dom->documentElement === null ) && ! mb_check_encoding( (string) $raw, 'UTF-8' ) )
		{
			$fixed = @mb_convert_encoding( (string) $raw, 'UTF-8', 'Windows-1252' );

			if ( is_string( $fixed ) && $fixed !== '' )
			{
				$dom = new DOMDocument();
				$ok  = $dom->loadXML( $fixed, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

				$repaired = $ok && $dom->documentElement !== null;
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $ok || $dom->documentElement === null )
			throw new Exception( 'This Mustang report could not be parsed as XML.' );

		self::$repaired = $repaired;

		return( $dom );
	}


	/** ⚠️ Zustand zwischen load() und read() - siehe load(). */
	private static $repaired = false;


	/**
	 * @param	DOMXPath	$xp
	 * @param	string		$path
	 * @return	string
	 * @since	1.4.0
	 */

	private static function one( DOMXPath $xp, $path )
	{
		$nodes = $xp->query( $path );

		if ( $nodes === false || $nodes->length === 0 )
			return( '' );

		return( trim( (string) $nodes->item( 0 )->textContent ) );
	}

}

endif;
