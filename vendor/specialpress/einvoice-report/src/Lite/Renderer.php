<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * "Report Lite" - the plain findings list, no BT/BG mapping, no explanation, no invoice model.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report\Lite;

use SpecialPress\EInvoice\Report\Report;

// Same direct-access guard as Report.php in this package - see that file's comment.
if ( ! defined( 'ABSPATH' ) )
	exit;


/**
 * class: Renderer
 *
 * The Validator's own default view of a report, used whenever nothing more
 * capable ( the Explainer's plain-language, BT-labelled rendering ) is available to replace it - see
 * the `einvoice/report_html` filter this renders through, in einvoice-validator-for-peppol-en16931's
 * class-spkv-plugin.php.
 *
 * ⚠️ DELIBERATELY THE SIMPLEST POSSIBLE VIEW. A findings list, a severity filter, the verdict in the
 * header - nothing this class does needs to know what a BT is. The Explainer's own renderer ( its own
 * plugin, `class-spx-renderer.php` ) is the one that adds business-term labels and plain-language
 * explanations; keeping this one dumb is what makes it safe for the Validator to depend on directly,
 * with no reliance on the Explainer's rule catalogue.
 *
 * Markup is plain, semantic HTML - a `<einvoice-report>` custom element wraps it and adds the
 * severity-filter interactivity ( assets/js/einvoice-report.js ), but the markup underneath is a
 * normal list that reads and prints fine with JavaScript disabled, just without the filter buttons
 * doing anything ( progressive enhancement, same principle as every uploader in this plugin family ).
 *
 * @since 1.0.0
 */

// ⚠️ GUARDED - see Report.php's own comment on this same line for why.

if ( ! class_exists( __NAMESPACE__ . '\\Renderer' ) ) :

class Renderer
{


	/**
	 * @param	Report	$report
	 * @param	string	$css_url	stylesheet for the component's OWN shadow root ( see
	 *							assets/js/einvoice-report.js - a nested shadow root the same way
	 *							einvoice-validator-for-peppol-en16931's spkv-uploader.js already
	 *							nests the Explainer's, so this component's styling cannot leak into,
	 *							or be leaked into by, whatever shadow root it is embedded in ). Left
	 *							unset, the element renders unstyled rather than not at all.
	 * @return	string	HTML
	 * @since	1.0.0
	 */

	public static function render( Report $report, $css_url = '' )
	{

		$rejected = $report->rejected();
		$errors   = $report->count( 'error' );
		$warnings = $report->count( 'warning' );
		$infos    = $report->count( 'information' );

		$out  = '<einvoice-report' . ( $css_url !== '' ? ' data-css="' . self::esc_attr( $css_url ) . '"' : '' ) . '>';
		$out .= '<div class="verdict ' . ( $rejected ? 'fail' : 'pass' ) . '">';
		$out .= '<span class="verdict-title">' . ( $rejected ? self::esc( self::t( 'Rejected' ) ) : self::esc( self::t( 'Accepted' ) ) ) . '</span>';
		$out .= '<span class="verdict-desc">'
			. self::esc( sprintf( self::t( '%1$d error(s), %2$d warning(s), %3$d notice(s)' ), $errors, $warnings, $infos ) )
			. '</span>';

		if ( ( $report->meta['document_type'] ?? '' ) !== '' )
			$out .= '<span class="verdict-stamp">' . self::esc( (string) $report->meta['document_type'] ) . '</span>';

		$out .= '</div>';

		foreach ( $report->notices as $notice )
			$out .= '<p class="note warn">' . self::esc( (string) $notice ) . '</p>';

		$out .= self::steps( $report );

		if ( ! $report->findings )
		{
			$out .= '<p class="empty">' . self::esc( self::t( 'No findings.' ) ) . '</p>';
		}
		else
		{
			$out .= '<div class="filterbar" role="group">';

			foreach ( array( 'error' => 'Errors', 'warning' => 'Warnings', 'information' => 'Notices' ) as $level => $label )
				$out .= '<button type="button" class="chip on ereport-filter-btn" data-severity="' . self::esc_attr( $level ) . '" aria-pressed="true">'
					. self::esc( self::t( $label ) ) . ' (' . $report->count( $level ) . ')</button>';

			$out .= '</div>';

			$out .= '<ul class="findings ereport-list">';

			foreach ( $report->findings as $finding )
			{
				$severity = (string) ( $finding['severity'] ?? 'information' );

				$out .= '<li class="finding ereport-finding ' . self::kante( $severity ) . '" data-severity="' . self::esc_attr( $severity ) . '">';
				$out .= '<span class="sev ' . self::sevname( $severity ) . '">' . self::esc( self::t( $severity ) ) . '</span>';

				if ( (string) ( $finding['rule'] ?? '' ) !== '' )
					$out .= '<span class="finding-rule">' . self::esc( (string) $finding['rule'] ) . '</span>';

				$out .= '<span class="finding-title ereport-span">' . self::esc( (string) ( $finding['message'] ?? '' ) ) . '</span>';

				if ( (string) ( $finding['path'] ?? '' ) !== '' )
					$out .= '<span class="finding-explain ereport-span ereport-path">' . self::esc( (string) $finding['path'] ) . '</span>';

				$out .= '</li>';
			}

			$out .= '</ul>';
		}

		$out .= self::document( $report );

		$out .= '</einvoice-report>';

		return( $out );

	}


	/**
	 * The validator's own per-step tally - the "Übersicht der Validierungsergebnisse" of the java KoSIT
	 * validator's native report (XML Schema, one row per Schematron rule set, each with its own
	 * error/warning/information count). Both readers fill $report->steps, and the KoSIT validator's own
	 * report layout shows it together with the document view below.
	 * This is the VALIDATOR'S OWN default view (no Explainer needed - see this file's own class doc
	 * block on why it stays "dumb"), so both parts belong here, not only in the Explainer's
	 * richer renderer.
	 *
	 * @param	Report	$report
	 * @return	string
	 * @since	1.1.0
	 */

	private static function steps( Report $report )
	{
		if ( ! $report->steps )
			return( '' );

		$out  = '<table class="tbl">';
		$out .= '<thead><tr>'
			. '<th>' . self::esc( self::t( 'Validation step' ) ) . '</th>'
			. '<th>' . self::esc( self::t( 'Errors' ) ) . '</th>'
			. '<th>' . self::esc( self::t( 'Warnings' ) ) . '</th>'
			. '<th>' . self::esc( self::t( 'Information' ) ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $report->steps as $step )
		{
			$errors   = (int) ( $step['errors'] ?? 0 );
			$warnings = (int) ( $step['warnings'] ?? 0 );

			$row_class = $errors > 0 ? ' class="edge-err"' : ( $warnings > 0 ? ' class="edge-warn"' : '' );

			$out .= '<tr' . $row_class . '>'
				. '<td>' . self::esc( (string) ( $step['step'] ?? '' ) ) . '</td>'
				. '<td>' . $errors . '</td>'
				. '<td>' . $warnings . '</td>'
				. '<td>' . (int) ( $step['infos'] ?? 0 ) . '</td>'
				. '</tr>';
		}

		$out .= '</tbody></table>';

		return( $out );
	}


	/**
	 * The checked document itself - the "Inhalt des Rechnungsdokuments" of the java KoSIT validator's own
	 * report, the flat element-by-element listing $report->document already holds ( position,
	 * indentation depth, element name, value ). The KoSIT validator's own report shows it, and the checked
	 * XML is exactly what a visitor without the Explainer still needs to see. No BT badges, no
	 * highlighting beyond a finding's own row - that part of "stay dumb" still holds; this only adds
	 * the plain content the reader already parsed.
	 *
	 * @param	Report	$report
	 * @return	string
	 * @since	1.1.0
	 */

	private static function document( Report $report )
	{
		if ( ! $report->document )
			return( '' );

		$out  = '<div class="eyebrow">' . self::esc( self::t( 'Content of the invoice document' ) ) . '</div>';
		// ⚠️ `zebra` is a class of its own in the kit, not a default of `.tbl` - without it the
		// rows stay one colour, and a document with forty fields reads badly.
		$out .= '<table class="tbl tree zebra">';
		$out .= '<thead><tr>'
			. '<th>' . self::esc( self::t( 'Pos.' ) ) . '</th>'
			. '<th>' . self::esc( self::t( 'Element' ) ) . '</th>'
			. '<th>' . self::esc( self::t( 'Value' ) ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $report->document as $row )
		{
			$level  = max( 1, (int) ( $row['level'] ?? 1 ) );
			$status = (string) ( $row['status'] ?? '' );
			$class  = $status !== '' ? ' class="' . self::kante( $status ) . '"' : '';
			$name   = str_repeat( "\u{00A0}\u{00A0}", $level - 1 ) . (string) ( $row['name'] ?? '' );

			$out .= '<tr' . $class . '>'
				. '<td class="pos">' . self::esc( (string) ( $row['pos'] ?? '' ) ) . '</td>'
				. '<td class="name">' . self::esc( $name ) . '</td>'
				. '<td class="value ereport-path">' . self::esc( (string) ( $row['value'] ?? '' ) ) . '</td>'
				. '</tr>';
		}

		$out .= '</tbody></table>';

		return( $out );
	}


	/**
	 * HTML-escape, without a WordPress dependency - this package has none ( see Report.php's own note
	 * on why the shared classes carry no translation wrapper either ). `esc_html()` is used instead
	 * wherever this renderer is actually invoked from inside WordPress, by wrapping the call site, not
	 * here - but a plain htmlspecialchars() default keeps this class usable if it is ever called
	 * before WordPress has finished loading ( e.g. very early on an AJAX request ).
	 *
	 * @param	string	$s
	 * @return	string
	 * @since	1.0.0
	 */

	private static function esc( $s )
	{
		if ( function_exists( 'esc_html' ) )
			return( esc_html( (string) $s ) );

		return( htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
	}


	/**
	 * Same fallback reasoning as esc() above, for attribute-context escaping specifically.
	 *
	 * @param	string	$s
	 * @return	string
	 * @since	1.0.0
	 */

	private static function esc_attr( $s )
	{
		if ( function_exists( 'esc_attr' ) )
			return( esc_attr( (string) $s ) );

		return( htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
	}




	/**
	 * The kit's signal edge for a severity.
	 *
	 * ⚠️ **THE WORDS ARE NOT THE SAME.** A report knows `error`, `warning` and
	 * `information`; the kit says `edge-err`, `edge-warn` and `edge-info`. Whoever assembles the class
	 * produces `edge-error` - and a selector that hits nothing is no error and does not
	 * report itself. The edge would thus fail silently.
	 *
	 * @param	string	$severity	error | warning | information
	 * @return	string	edge-err | edge-warn | edge-info
	 */

	/**
	 * A text of Report Lite in the language of the page.
	 *
	 * ⚠️ **THE TRANSLATION COMES FROM THE PLUGIN, THROUGH A FILTER** ( since 1.16.0 ). This library has no text domain of
	 * its own; a `__()` here could not be found by any `.pot`. Each validator therefore answers the filter
	 * `einvoice/report_lite_texts` with an array of English text => translation, made with literal `__()` calls in its own
	 * text domain. Until then Report Lite stood in English on every German page ( Ralf, 18.10.2026: "Validation step",
	 * "Content of the invoice document" ). A text the filter does not name stays English.
	 *
	 * ⚠️ THE TABLE IS ASKED FOR ONCE PER REQUEST. A report has a few hundred document rows, and the filter would otherwise
	 * run for every badge.
	 *
	 * @param	string	$text	the English text, also the key
	 * @return	string
	 * @since	1.16.0
	 */

	private static function t( $text )
	{

		static $texts = null;

		if ( $texts === null )
		{
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a filter of the shared report library, answered by every validator in its own text domain; a plugin prefix would tie it to one of them.
			$texts = function_exists( 'apply_filters' ) ? (array) apply_filters( 'einvoice/report_lite_texts', array() ) : array();
		}

		return( isset( $texts[ $text ] ) && is_string( $texts[ $text ] ) && $texts[ $text ] !== '' ? $texts[ $text ] : (string) $text );

	}


	private static function kante( $severity )
	{

		switch( (string) $severity )
		{
			case 'error':   return( 'edge-err' );
			case 'warning': return( 'edge-warn' );
		}

		return( 'edge-info' );

	}



	/**
	 * The variant of the kit's severity badge.
	 *
	 * ⚠️ Here `error` is the only word that matches; `warning` is `warn` in the kit and
	 * `information` is `info`.
	 *
	 * @param	string	$severity	error | warning | information
	 * @return	string	error | warn | info
	 */

	private static function sevname( $severity )
	{

		switch( (string) $severity )
		{
			case 'error':   return( 'error' );
			case 'warning': return( 'warn' );
		}

		return( 'info' );

	}

}

endif;
