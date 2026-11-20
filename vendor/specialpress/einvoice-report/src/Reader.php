<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Picks the right reader for a validation report and reads it - the one entry point a caller needs.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report;

use Exception;

// Same direct-access guard as Report.php in this package - see that file's comment.
if ( ! defined( 'ABSPATH' ) )
	exit;


// ⚠️ GUARDED - see Report.php's own comment on this same line for why.

if ( ! class_exists( __NAMESPACE__ . '\\Reader' ) ) :

class Reader
{


	/**
	 * ⚠️ XML IS TRIED FIRST. When a file could be read either way, the normed format is the better
	 * one - the HTML reader searches foreign markup for a layout and is the one that breaks when a
	 * provider restyles their report.
	 *
	 * @param	string	$content
	 * @return	Report
	 * @throws	Exception
	 * @since	1.0.0
	 */

	public static function read( $content )
	{
		$report = self::pick( $content );

		/**
		 * Filter: einvoice/report_read - the report right after reading, before anyone draws it.
		 *
		 * The one user is the Validator for VAMPIRE PHP ( `SPPV_Rule_Values` ): for the report it has just made in this request it
		 * attaches to every finding the values of the rule itself ( `Explainer\RuleValues` ). A Validation Explorer that
		 * redraws that report through `einvoice/report_html` reads it here again, and the values come along without the
		 * explorer knowing VAMPIRE. Tools without VAMPIRE hook nothing and keep the values from the rule text.
		 *
		 * ⚠️ **A FILTER IN THE READER, BECAUSE THE READER IS THE ONE PLACE EVERY DRAWING PASSES.** The explorers get only the
		 * report text from `einvoice/report_html` and read it themselves; a hook in a plugin would have to be called by
		 * every explorer. Outside WordPress ( the test harnesses ) there is no `apply_filters()`, and the report stays as read.
		 *
		 * ⚠️ **WHICH COPY OF THIS FILE RUNS DECIDES WHETHER THE FILTER EXISTS.** Every plugin bundles its own copy, and the
		 * first one loaded wins. Composer puts the autoloader of the plugin loaded last in front, and WordPress loads the
		 * plugins in alphabetical order, so the Validator for VAMPIRE PHP, last among the plugins that carry this package, brings
		 * its copy whenever it is active. An older copy simply calls no filter, and the report shows the values from the text.
		 *
		 * @param	Report	$report		as read
		 * @param	string	$content	the report as text, for a callback to recognise its own report
		 */
		if ( function_exists( 'apply_filters' ) )
		{
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'einvoice/report_read' is a deliberately shared cross-plugin hook of this library, like 'einvoice/report_html' in einvoice-frontend-core; a plugin prefix would tie it to one plugin.
			$filtered = apply_filters( 'einvoice/report_read', $report, (string) $content );

			// ⚠️ A callback that returns something else must not cost the report.
			if ( $filtered instanceof Report )
				$report = $filtered;
		}

		return( $report );
	}


	/**
	 * The reader that fits the content, and its report, without the filter of `read()`.
	 *
	 * @param	string	$content
	 * @return	Report
	 * @throws	Exception
	 * @since	1.18.0
	 */

	private static function pick( $content )
	{
		/**
		 * ⚠️ VALITOOL FIRST, AND BEFORE ReaderXml.
		 *
		 * `ReaderXml::detect()` checks whether the content looks like an XML report at all, and
		 * would accept a valitool report, only to stop while reading with "not a known validation
		 * report format" - measured on 23.09.2026 over the corpus with the other order: 0 of 46 read.
		 * `ReaderValitool::detect()`, by contrast, checks the namespace and is
		 * unambiguous; it cannot wrongly pick up a KoSIT report.
		 */

		/*
		 * A JSON report of VAMPIRE first: it is not XML, and the XML readers would take it for a broken document.
		 *
		 * ⚠️ ONLY WHEN THE CLASS IS LOADED. Three plugins and two test harnesses require the readers one by one
		 * ( einvoice-comparison, both Validation Explorers ); a copy of this file next to their older list would end in a
		 * fatal error on the first upload. `class_exists()` with autoloading finds it where Composer's classmap knows it.
		 */
		if ( class_exists( __NAMESPACE__ . '\ReaderVampire' ) && ReaderVampire::detect( $content ) )
			return( ReaderVampire::read( $content ) );

		if ( ReaderValitool::detect( $content ) )
			return( ReaderValitool::read( $content ) );

		if ( ReaderXml::detect( $content ) )
			return( ReaderXml::read( $content ) );

		if ( ReaderHtml::detect( $content ) )
			return( ReaderHtml::read( $content ) );

		/**
		 * ⚠️ MUSTANG LAST, AND ON PURPOSE. Its `detect()` is the least sharp of the four -
		 * `<validation>` plus an `<error>`/`<notice>` somewhere. Measured on 23.09.2026 against all
		 * 233 real KoSIT and valitool reports of the corpus: **0 false hits**. Still it only gets its
		 * turn when none of the three unambiguous readers has responded - an order that costs
		 * nothing and rules out a future false hit structurally.
		 *
		 * ⚠️ How the reader was measured against real Mustang reports is described in its class
		 * docblock.
		 */

		if ( ReaderMustang::detect( $content ) )
			return( ReaderMustang::read( $content ) );

		throw new Exception( 'This file was not recognised as a validation report. Please upload the report the validator produced, not the invoice itself.' );
	}

}

endif;
