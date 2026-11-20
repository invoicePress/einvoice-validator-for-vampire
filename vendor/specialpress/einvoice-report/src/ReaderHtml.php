<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Reads the HTML rendering of a KoSIT validation report.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Exception;

// Same direct-access guard as Report.php in this package - see that file's comment.
if ( ! defined( 'ABSPATH' ) )
	exit;


/**
 * class: ReaderHtml
 *
 * Originally part of einvoice-validation-explainer-for-peppol-en16931 ( class-spx-reader-html.php ) -
 * see Report.php's own doc block for why it lives in this package.
 *
 * ⚠️ NO TRANSLATION WRAPPER ON THE EXCEPTION/NOTICE STRINGS BELOW. This package
 * has no textdomain of its own ( it is consumed by more than one plugin, each with a different one -
 * see libs/einvoice-host-wp for the same approach: zero translatable strings in that shared package
 * either ). A caller that needs a translated message wraps `$e->getMessage()` itself.
 *
 * ============================================================================================
 * WHAT THIS READS
 * ============================================================================================
 *
 *     div.metadata      two <dl> blocks: run data, then recognised document data
 *     p.important       "The checked document contains 2 errors / 0 warnings. It is not compliant …"
 *     table.tbl-errors  FIRST occurrence  - the tally per validation step
 *     table.tbl-errors  SECOND occurrence - the findings themselves
 *     table.document    the document, one <tr class="row"> per element
 *
 * ⚠️ THE FRAGILE ONE - AND IT MUST SAY SO. This searches foreign markup for a layout. Every section
 * that was expected but not found adds a notice, and a report without a findings table is rejected
 * outright rather than returned as a clean bill of health - a "0 errors" from a half-read report
 * would be worse than failing.
 *
 * @since 1.0.0
 */

// ⚠️ GUARDED - see Report.php's own comment on this same line for why.

if ( ! class_exists( __NAMESPACE__ . '\\ReaderHtml' ) ) :

class ReaderHtml
{


	/**
	 * Does this content look like a KoSIT HTML report?
	 *
	 * @param	string	$raw
	 * @return	bool
	 * @since	1.0.0
	 */

	public static function detect( $raw )
	{
		$head = substr( (string) $raw, 0, 65536 );

		if ( stripos( $head, '<html' ) === false )
			return( false );

		return( strpos( $head, 'tbl-errors' ) !== false || strpos( $head, 'class="document"' ) !== false );
	}


	/**
	 * Read a report.
	 *
	 * @param	string	$raw
	 * @return	Report
	 * @throws	Exception	when the content is not a readable report
	 * @since	1.0.0
	 */

	public static function read( $raw )
	{
		$report = new Report();

		$report->meta['source'] = 'html';

		$dom = self::load( (string) $raw );
		$xp  = new DOMXPath( $dom );

		self::read_meta( $xp, $report );
		self::read_steps( $xp, $report );
		self::read_findings( $xp, $report );
		self::read_document( $xp, $report );

		// ⚠️ A CLEAN REPORT ( 0 errors, 0 warnings ) CAN LEGITIMATELY HAVE NEITHER - measured,
		// 10.09.2026: the KoSIT HTML template omits BOTH tbl-errors tables entirely when there is
		// nothing to report, so `read_steps()`/`read_findings()` both come back empty for a document
		// that validated perfectly. Without the `compliant` fallback below, that success case would be
		// indistinguishable from "the report layout changed and nothing could be read" - a passing
		// report would be rejected as unreadable. `read_meta()`'s own verdict paragraph ( "Konformitäts-
		// prüfung: ... Es ist konform" / "Compliance check: ... it is compliant" ) is present on every
		// report regardless of whether it carries findings, so it is the one signal that survives a
		// clean report and still proves the layout was actually understood, not merely absent.
		if ( ! $report->findings && ! $report->steps && ! isset( $report->meta['compliant'] ) )
			throw new Exception( 'This file was recognised as a validation report, but neither its findings nor its validation steps could be read. The report layout may have changed.' );

		$report->build_paths();
		$report->link_findings();

		self::check_plausibility( $report );

		return( $report );
	}


	/**
	 * Parse the HTML into a DOM.
	 *
	 * ⚠️ PUBLIC, FOR ReaderXml - the native XML report embeds the IDENTICAL HTML markup this class
	 * already parses. ReaderXml reads the robust XML parts itself and reuses THIS loader plus
	 * read_document() below for the one part only available as embedded HTML.
	 *
	 * @param	string	$raw
	 * @return	DOMDocument
	 * @throws	Exception
	 * @since	1.0.0
	 */

	public static function load( $raw )
	{
		if ( trim( $raw ) === '' )
			throw new Exception( 'The file is empty.' );

		$dom = new DOMDocument();

		$previous = libxml_use_internal_errors( true );

		// ⚠️ THE META TAG IS NOT DECORATION. loadHTML() assumes ISO-8859-1 unless the markup declares
		// otherwise, and then every umlaut in a German seller name arrives broken.
		$ok = $dom->loadHTML(
			'<?xml encoding="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $raw,
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $ok )
			throw new Exception( 'The file could not be parsed as HTML.' );

		return( $dom );
	}


	/**
	 * The metadata block: two definition lists.
	 *
	 * ⚠️ LABELS MATCHED LOOSELY - all examples measured were English, but the KoSIT XSLT ships German
	 * too ( "Erkannter Verkäufer" ).
	 *
	 * @param	DOMXPath	$xp
	 * @param	Report		$report
	 * @return	void
	 * @since	1.0.0
	 */

	private static function read_meta( DOMXPath $xp, Report $report )
	{
		$pairs = array();

		foreach ( $xp->query( '//*[contains(@class,"metadata")]//dt' ) as $dt )
		{
			$dd = $dt->nextSibling;

			while ( $dd && $dd->nodeType !== XML_ELEMENT_NODE )
				$dd = $dd->nextSibling;

			if ( ! $dd || strtolower( $dd->nodeName ) !== 'dd' )
				continue;

			$pairs[] = array( self::text( $dt ), self::text( $dd ) );
		}

		if ( ! $pairs )
			$report->notices[] = 'The report header could not be read; the document details are missing.';

		$map = array(
			'reference'      => array( 'reference', 'referenz' ),
			'validated_at'   => array( 'time of validation', 'zeitpunkt' ),
			'document_type'  => array( 'document type', 'dokumenttyp', 'dokumententyp' ),
			'seller'         => array( 'seller', 'verkäufer', 'verkaeufer' ),
			'invoice_number' => array( 'invoice number', 'rechnungsnummer' ),
			'invoice_date'   => array( 'invoice date', 'rechnungsdatum' ),
		);

		foreach ( $pairs as $pair )
		{
			$label = strtolower( $pair[0] );

			foreach ( $map as $key => $needles )
			{
				if ( isset( $report->meta[ $key ] ) )
					continue;

				foreach ( $needles as $needle )
				{
					if ( strpos( $label, $needle ) !== false )
					{
						$report->meta[ $key ] = $pair[1];
						break 2;
					}
				}
			}
		}

		foreach ( $xp->query( '//p[contains(@class,"important")]' ) as $p )
		{
			$text = strtolower( self::text( $p ) );

			if ( strpos( $text, 'compliant' ) === false && strpos( $text, 'konform' ) === false )
				continue;

			$report->meta['compliant'] = ( strpos( $text, 'not compliant' ) !== false || strpos( $text, 'nicht konform' ) !== false )
				? 'no'
				: 'yes';

			break;
		}

		$syntax = self::syntax_from_text( (string) ( $report->meta['document_type'] ?? '' ) );

		if ( $syntax !== '' )
			$report->meta['syntax'] = $syntax;
	}


	/**
	 * Guess the syntax family from a piece of text that names it - a document type, a scenario name.
	 * Public and shared with ReaderXml.
	 *
	 * @param	string	$text
	 * @return	string	'CII' | 'UBL' | ''
	 * @since	1.0.0
	 */

	public static function syntax_from_text( $text )
	{
		$type = strtolower( (string) $text );

		if ( strpos( $type, 'ubl' ) !== false )
			return( 'UBL' );

		if ( strpos( $type, 'cii' ) !== false || strpos( $type, 'cross industry' ) !== false || strpos( $type, 'zugferd' ) !== false || strpos( $type, 'factur' ) !== false )
			return( 'CII' );

		return( '' );
	}


	/**
	 * The first tbl-errors: one row per validation step with its tallies.
	 *
	 * @param	DOMXPath	$xp
	 * @param	Report		$report
	 * @return	void
	 * @since	1.0.0
	 */

	private static function read_steps( DOMXPath $xp, Report $report )
	{
		$tables = $xp->query( '//table[contains(@class,"tbl-errors")]' );

		if ( ! $tables->length )
			return;

		foreach ( $xp->query( './/tbody/tr', $tables->item( 0 ) ) as $tr )
		{
			$cells = $xp->query( './td', $tr );

			if ( $cells->length !== 4 )
				continue;

			$report->steps[] = array(
				'step'     => self::text( $cells->item( 0 ) ),
				'errors'   => (int) self::text( $cells->item( 1 ) ),
				'warnings' => (int) self::text( $cells->item( 2 ) ),
				'infos'    => (int) self::text( $cells->item( 3 ) ),
			);
		}
	}


	/**
	 * The second tbl-errors: the findings.
	 *
	 * ⚠️ ONE FINDING SPANS TWO TABLE ROWS - the first carries position/rule/severity + message, the
	 * second only "Path: …". A row whose first cell has no rowspan is the continuation of the one
	 * before it.
	 *
	 * @param	DOMXPath	$xp
	 * @param	Report		$report
	 * @return	void
	 * @since	1.0.0
	 */

	private static function read_findings( DOMXPath $xp, Report $report )
	{
		$tables = $xp->query( '//table[contains(@class,"tbl-errors")]' );

		if ( $tables->length < 2 )
		{
			if ( $tables->length )
				$report->notices[] = 'The report contains a summary but no detailed findings table.';

			return;
		}

		$current = null;

		foreach ( $xp->query( './/tbody/tr', $tables->item( 1 ) ) as $tr )
		{
			$cells = $xp->query( './td', $tr );

			if ( ! $cells->length )
				continue;

			if ( $cells->length === 1 && $current !== null )
			{
				$text = self::text( $cells->item( 0 ) );

				if ( preg_match( '/^\s*(?:Path|Pfad)\s*:\s*(.+)$/iu', $text, $m ) )
					$report->findings[ $current ]['path'] = trim( $m[1] );
				elseif ( $text !== '' )
					$report->findings[ $current ]['message'] = trim( $report->findings[ $current ]['message'] . ' ' . $text );

				continue;
			}

			if ( $cells->length < 4 )
				continue;

			$severity = strtolower( self::text( $cells->item( 2 ) ) );

			$report->findings[] = array(
				'position' => self::text( $cells->item( 0 ) ),
				'rule'     => self::text( $cells->item( 1 ) ),
				'severity' => self::severity( $severity, $tr ),
				'message'  => self::text( $cells->item( 3 ) ),
				'path'     => '',
				'rows'     => array(),
			);

			$current = count( $report->findings ) - 1;
		}
	}


	/**
	 * Normalise the severity - the cell is authoritative, the row's CSS class is the fallback.
	 *
	 * @param	string		$cell
	 * @param	DOMElement	$tr
	 * @return	string
	 * @since	1.0.0
	 */

	private static function severity( $cell, $tr )
	{
		$value = $cell !== '' ? $cell : strtolower( (string) $tr->getAttribute( 'class' ) );

		if ( strpos( $value, 'error' ) !== false || strpos( $value, 'fehler' ) !== false )
			return( 'error' );

		if ( strpos( $value, 'warn' ) !== false )
			return( 'warning' );

		return( 'information' );
	}


	/**
	 * The document table.
	 *
	 * ⚠️ THE ELEMENT NAME IS IN THE title ATTRIBUTE, not in the cell text - a container element is
	 * self-closed and holds no text at all.
	 *
	 * @param	DOMXPath	$xp
	 * @param	Report		$report
	 * @return	void
	 * @since	1.0.0
	 */

	public static function read_document( DOMXPath $xp, Report $report )
	{
		$rows = $xp->query( '//table[contains(@class,"document")]//tr[contains(@class,"row")]' );

		if ( ! $rows->length )
		{
			$report->notices[] = 'The document view could not be read from the report; only the findings are shown.';
			return;
		}

		foreach ( $rows as $tr )
		{
			$element = $xp->query( './td[contains(@class,"element")]', $tr )->item( 0 );

			if ( ! $element )
				continue;

			$pos = $xp->query( './td[contains(@class,"pos")]', $tr )->item( 0 );
			$val = $xp->query( './/*[contains(@class,"val")]', $element )->item( 0 );

			$attrs = array();

			foreach ( $xp->query( './/*[contains(@class,"attribute")]', $element ) as $a )
			{
				$text = self::text( $a );

				if ( $text !== '' )
					$attrs[] = $text;
			}

			$report->document[] = array(
				'pos'    => $pos ? self::text( $pos ) : (string) $tr->getAttribute( 'id' ),
				'level'  => self::level( (string) $element->getAttribute( 'class' ) ),
				'name'   => trim( (string) $element->getAttribute( 'title' ) ),
				'value'  => $val ? self::text( $val ) : '',
				'attr'   => implode( ' · ', $attrs ),
				'path'   => '',
				'status' => '',
			);
		}
	}


	/**
	 * Nesting depth out of the class list: "element level3" -> 3.
	 *
	 * @param	string	$class
	 * @return	int
	 * @since	1.0.0
	 */

	private static function level( $class )
	{
		return( preg_match( '/\blevel(\d+)\b/', $class, $m ) ? max( 1, (int) $m[1] ) : 1 );
	}


	/**
	 * Compare what was read against what the report says about itself - the guard against a silent
	 * half-read. Public since ReaderXml reuses it verbatim ( entirely format-agnostic, only ever reads
	 * $report->steps/findings/document ).
	 *
	 * @param	Report	$report
	 * @return	void
	 * @since	1.0.0
	 */

	public static function check_plausibility( Report $report )
	{
		$stated = 0;

		foreach ( $report->steps as $step )
			$stated += (int) $step['errors'] + (int) $step['warnings'];

		if ( ! $stated )
			return;

		$found = $report->count( 'error' ) + $report->count( 'warning' );

		if ( $found < $stated )
			$report->notices[] = sprintf(
				'Only %1$d of the %2$d findings this report states could be read. The report layout may have changed - please check the original report.',
				$found,
				$stated
			);

		// ⚠️ A FINDING WITHOUT A PATH IS NOT A FAILED LOOKUP - XSD errors name no document location at
		// all. Only findings that DO carry a path are counted here.

		$withPath = 0;
		$linked   = 0;

		foreach ( $report->findings as $f )
		{
			if ( (string) ( $f['path'] ?? '' ) === '' )
				continue;

			$withPath++;

			if ( ! empty( $f['rows'] ) )
				$linked++;
		}

		if ( $report->document && $withPath && ! $linked )
			$report->notices[] = 'None of the findings could be located in the document view, although the report names a position for them. The values shown per finding may be incomplete.';
	}


	/**
	 * Collapsed text of a node.
	 *
	 * @param	DOMNode|null	$node
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
