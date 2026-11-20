<?php
/**
 * The messages on top, below them a switch between the invoice and the XML listing.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report\Explainer;

if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * class: SwitchViews
 *
 * Ralf on 21.10.2026: the messages of a report stand on top, and below them the reader switches between the invoice
 * with the findings at its fields and the XML listing. `ReportViews` writes three tabs instead ( "Messages", "XML",
 * "Invoice" ); this class rearranges that output.
 *
 * ⚠️ A NEW CLASS THAT REARRANGES, NOT A CHANGE OF `ReportViews` OR `Renderer`. Every plugin carries its own copy of this
 * library, the copies are guarded with `class_exists()`, and the first one loaded wins: measured on the test site on
 * 21.10.2026, `ReportViews` came from the Validator for VAMPIRE PHP while `Renderer` came from the explorer. A changed
 * `ReportViews` therefore reached the page only when the right plugin happened to load first, and at a customer with
 * mixed versions it would depend on chance. This class exists only in 1.17.0 and later, so whoever calls it gets this
 * copy, and it reads the markup `ReportViews::render()` has written byte for byte since 1.15.0, whichever copy wrote
 * it.
 *
 * ⚠️ OUTPUT IT DOES NOT RECOGNISE IS RETURNED UNCHANGED. A future layout of `ReportViews` must not be cut apart by
 * string positions that no longer fit; the three tabs are still a usable page.
 *
 * ⚠️ THE CONTENT OF THE PANELS IS NOT TOUCHED. Messages, XML listing and the data of the view "Invoice" go through
 * unchanged, so they stay identical to what the validators show.
 *
 * @since 1.17.0
 */

if ( ! class_exists( __NAMESPACE__ . '\\SwitchViews' ) ) :

class SwitchViews
{


	/** The two positions of the switch, in the order of its buttons; the first is the default. */
	const SWITCH = array( 'invoice', 'xml' );


	/**
	 * Rearranges the output of `Renderer::render_views()`.
	 *
	 * @param	string	$html		what `render_views()` returned, disclaimer included
	 * @param	array	$options	`viewer` ( bool: a viewer can draw the invoice; without it there is no switch, only
	 *                              the XML listing below the messages ), `view` ( 'invoice' | 'xml', the position of the
	 *                              switch ), `strings` ( the caller's translated `views_` texts )
	 * @return	string
	 * @since	1.17.0
	 */

	public static function arrange( string $html, array $options = array() ): string
	{
		$open = '<div class="spx-views" data-default-view="';
		$tab  = '<div class="tabs spx-views-switch"';
		$pf   = '<div class="spx-view" data-spx-view-panel="findings"';
		$px   = '<div class="spx-view" data-spx-view-panel="xml"';
		$pi   = '<div class="spx-view" data-spx-view-panel="invoice"';
		$end  = '</script></div></div></div>';

		$a = strpos( $html, $open );
		$t = strpos( $html, $tab );
		$f = strpos( $html, $pf );
		$x = strpos( $html, $px );
		$i = strpos( $html, $pi );

		if ( $a === false || $t === false || $f === false || $x === false || $i === false || ! ( $a < $t && $t < $f && $f < $x && $x < $i ) )
			return( $html );

		$e = strpos( $html, $end, $i );

		if ( $e === false )
			return( $html );

		// The download of the original report stands in the bar behind the tabs: after `</button></div>`, before the
		// bar's own `</div>` in front of the first panel.
		$tabsEnd  = strpos( $html, '</button></div>', $t );
		$original = $tabsEnd !== false && $tabsEnd < $f ? substr( $html, $tabsEnd + 15, $f - ( $tabsEnd + 15 ) - 6 ) : '';

		$findings = self::inner( $html, $f, $x );
		$xml      = self::inner( $html, $x, $i );
		$invoice  = substr( $html, strpos( $html, '>', $i ) + 1, ( $e + strlen( '</script></div>' ) ) - ( strpos( $html, '>', $i ) + 1 ) );
		$rest     = substr( $html, $e + strlen( $end ) );

		$strings = isset( $options['strings'] ) && is_array( $options['strings'] ) ? $options['strings'] : array();
		$viewer  = ! array_key_exists( 'viewer', $options ) || ! empty( $options['viewer'] );
		$view    = $viewer ? self::view( $options['view'] ?? '' ) : 'xml';

		$out = '<div class="spx-views spx-views-switched" data-default-view="' . $view . '">';

		if ( trim( $original ) !== '' )
			$out .= '<div class="spx-views-bar" style="display:flex;justify-content:flex-end;margin-bottom:16px">' . $original . '</div>';

		$out .= '<div class="spx-view spx-view-findings" data-spx-view-panel="findings">' . $findings . '</div>';

		if ( $viewer )
		{
			$tabs = '';

			foreach ( self::SWITCH as $v )
				$tabs .= '<button type="button" role="tab" class="spx-view-tab' . ( $v === $view ? ' on' : '' ) . '" data-spx-view="' . $v . '" aria-selected="' . ( $v === $view ? 'true' : 'false' ) . '">'
					. ReportViews::esc( self::s( $strings, 'views_' . $v ) ) . '</button>';

			$out .= '<div class="spx-views-bar" style="margin:16px 0">'
				. '<div class="tabs spx-views-switch" role="tablist" aria-label="' . ReportViews::esc( self::s( $strings, 'views_label' ) ) . '">' . $tabs . '</div>'
				. '</div>'
				. '<div class="spx-view" data-spx-view-panel="invoice"' . ( $view === 'invoice' ? '' : ' hidden' ) . '>' . $invoice . '</div>';
		}

		$out .= '<div class="spx-view" data-spx-view-panel="xml"' . ( $view === 'xml' ? '' : ' hidden' ) . '>' . $xml . '</div>';

		return( $out . '</div>' . $rest );
	}


	/**
	 * The position of the switch; anything else, the old "findings" included, is the invoice.
	 *
	 * @param	mixed	$value
	 * @return	string
	 * @since	1.17.0
	 */

	public static function view( $value ): string
	{
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return( in_array( $value, self::SWITCH, true ) ? $value : self::SWITCH[0] );
	}


	/**
	 * The content of one panel: after its opening tag, before the `</div>` in front of the next panel.
	 *
	 * @since	1.17.0
	 */

	private static function inner( string $html, int $from, int $next ): string
	{
		$start = strpos( $html, '>', $from ) + 1;

		return( substr( $html, $start, ( $next - 6 ) - $start ) );
	}


	/** A text: the caller's, else the English default of `ReportViews`. */

	private static function s( array $strings, string $key ): string
	{
		if ( isset( $strings[ $key ] ) && is_string( $strings[ $key ] ) && $strings[ $key ] !== '' )
			return( $strings[ $key ] );

		$d = ReportViews::defaults();

		return( (string) ( $d[ $key ] ?? $key ) );
	}

}

endif;
