<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Turns a validator's report into the HTML a frontend shows - the one pipeline all four share.
 *
 * @package SpecialPress\EInvoice\FrontendCore
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\FrontendCore;

use SpecialPress\EInvoice\Report\Reader;
use SpecialPress\EInvoice\Report\Frontend\DocumentView;
use SpecialPress\EInvoice\Report\Lite\Renderer;
use Throwable;

// Same direct-access guard the sibling packages use.
if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! class_exists( __NAMESPACE__ . '\\ReportView' ) ) :

/**
 * class: ReportView
 *
 * ============================================================================================
 * From a validation report to its view, the same way in every frontend
 * ============================================================================================
 *
 * The path from report to view is the same in all four frontends:
 *
 *   1. read ( `Reader` picks the format )
 *   2. attach the document, **if the report does not bring one** ( `DocumentView` )
 *   3. render with "Report Lite"
 *   4. offer the filter `einvoice/report_html`, so that the Explainer can take over
 *   5. if step 1 fails, show the raw version instead of nothing
 *
 * ⚠️ **STEP 5 IS NOT A COSMETIC DETAIL BUT THE MOST IMPORTANT LINE.** A report that was just produced
 * successfully is worth more shown raw than not at all. Throwing here would turn a reading problem
 * into a failed validation.
 *
 * ⚠️ **STEP 2 ONLY CONDITIONALLY.** When the report brings its document ( the KoSIT report does ), that
 * tree is **authoritative**: it shows what the validator saw, not what the user sent. The two are
 * almost always identical but need not be; with a ZUGFeRD PDF, for example, the checked document is the
 * **embedded** XML, not the uploaded file.
 *
 * ⚠️ **THE FILTER NAME DELIBERATELY HAS NO PLUGIN PREFIX.** `einvoice/report_html` is shared by several
 * plugins ( see `docs/hook-conventions.md` in `libs/einvoice-report` ). Prefixing it would mean renaming
 * it per plugin and breaking every outside integration.
 *
 * @since 1.0.0
 */

class ReportView
{


	/**
	 * @param	string	$report_xml	The validator's report, raw.
	 * @param	string	$raw_html	Its own HTML version, the last fallback.
	 * @param	string	$css_url	Stylesheet for "Report Lite", '' if none.
	 * @param	string	$document	The checked document, '' if not available.
	 * @return	array				array{ view: 'lite'|'raw'|string, html: string, cssUrl: string|null, notices: string[] }
	 * @since	1.0.0
	 */

	public static function build( $report_xml, $raw_html = '', $css_url = '', $document = '' )
	{
		$notices = array();

		try
		{
			$model = Reader::read( (string) $report_xml );

			if ( ! $model->document && (string) $document !== '' )
			{
				$view = new DocumentView();
				$view->attach( $model, (string) $document );

				foreach ( $view->unresolved() as $u )
					if ( ! empty( $u['reason'] ) && ! in_array( $u['reason'], $notices, true ) )
						$notices[] = (string) $u['reason'];
			}

			$html = Renderer::render( $model, (string) $css_url );
		}
		catch ( Throwable $e )
		{
			return( array(
				'view'    => 'raw',
				'html'    => (string) $raw_html,
				'cssUrl'  => null,
				'notices' => array( $e->getMessage() ),
			) );
		}

		$default = array(
			'view'    => 'lite',
			'html'    => $html,
			'cssUrl'  => (string) $css_url !== '' ? (string) $css_url : null,
			'notices' => $notices,
		);

		if ( ! function_exists( 'apply_filters' ) )
			return( $default );

		/**
		 * Filter: einvoice/report_html
		 *
		 * @param	array	$view		array{ view, html, cssUrl, notices }
		 * @param	string	$report_xml	der Bericht, verbatim.
		 * @since	1.0.0
		 */

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'einvoice/report_html' is a deliberately shared, documented cross-plugin hook ( see docs/hook-conventions.md in libs/einvoice-report ), not the namespace of a single plugin. A prefix here would break every existing integration, such as the Explainer's.
		$filtered = apply_filters( 'einvoice/report_html', $default, (string) $report_xml );

		// ⚠️ A callback that returns nonsense must not empty the view.
		if ( ! is_array( $filtered ) || ! isset( $filtered['html'] ) )
			return( $default );

		$filtered['notices'] = array_values( array_unique( array_merge( $notices, (array) ( $filtered['notices'] ?? array() ) ) ) );

		return( $filtered );
	}

}

endif;
