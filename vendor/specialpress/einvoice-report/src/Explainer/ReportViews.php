<?php
/**
 * The three views of the report output - "Messages", "XML" and "Invoice" - and the download of the original
 * report.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report\Explainer;

if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * class: ReportViews
 *
 * The three views of a report. Twin of `src/explainer/ReportViews.mjs` in `libs/einvoice-core-js`; both
 * write the same markup byte for byte, compared by `test/compare-report-views.mjs`.
 *
 *   Messages   verdict, report data, steps, findings, "Checked rules" - what the report view always showed
 *   XML        the document content of the report, row by row - the panel that stood below the findings
 *   Invoice    the invoice as the viewer draws it, every finding at its field ( FieldPlacement )
 *
 * ⚠️ THE VIEW "INVOICE" IS DRAWN IN THE BROWSER, NOT HERE. This class writes an empty host and the data: the
 * placements, the texts and the invoice. `wireReportViews()` in `libs/einvoice-core-js/src/explainer/
 * wireReportViews.mjs` hands them to `<einvoice-viewer>` through `showReport()` when the view is opened for the
 * first time. A PHP rendering of the invoice would be a second drawing of the same invoice next to the
 * element's, and the two would drift apart - the reason `showResult()` exists.
 *
 * ⚠️ THE VIEW THAT OPENS FIRST IS CHOSEN HERE, NOT IN THE SCRIPT. The markup already carries the
 * selected tab and the hidden panels, so a page without JavaScript shows the operator's choice, and the
 * script has nothing to correct after it loads.
 *
 * ⚠️ THE ORIGINAL REPORT IS A `data:` ADDRESS OF THE ENGINE'S BYTES. It goes out unchanged: no
 * decoding, no re-encoding, no line ending touched. A Mustang report is Windows-1252 although it says UTF-8;
 * the reader repairs that for the view, and the download must not. The caller passes the bytes as they came
 * from the engine, never the text a reader made of them.
 *
 * ⚠️ AN OWN CLASS WITH OWN KEYS, NOT NEW KEYS IN `Renderer::defaults()`. The tables of the JavaScript view are
 * built from the `.po` of the KoSIT explorer, and `i18n.test.mjs` demands every key of `defaults()` there; the
 * `views_` keys get their own tables in a third pass of `_claude-tools/explainer-strings-build.php`, as the
 * `rules_` keys of `RuleReportSection` got theirs.
 *
 * @since 1.15.0
 */

if ( ! class_exists( __NAMESPACE__ . '\\ReportViews' ) ) :

class ReportViews
{


	/**
	 * The three views, in the order of the tabs. The first one is the default.
	 *
	 * ⚠️ THESE ARE THE VALUES OF THE SETTING ( plugin option, `report.defaultView` in `config.js` ). They are
	 * English keys, not the labels; a label changes with the language, a stored setting must not.
	 */
	const VIEWS = array( 'findings', 'xml', 'invoice' );


	/** @var array<string,mixed> */
	private $strings;


	/**
	 * @param	array	$strings	the caller's translated strings - the same table it hands to the renderer
	 * @since	1.15.0
	 */

	public function __construct( array $strings = array() )
	{
		$this->strings = $strings;
	}


	/**
	 * The English defaults - also the complete list of the keys.
	 *
	 * ⚠️ WHOEVER ADDS A KEY HERE ADDS IT TO `ReportViews.mjs`, TO `SPX_Renderer::view_strings()` IN THE KoSIT
	 * EXPLORER WITH A LITERAL `__()`, AND RUNS `explainer-strings-build.php --apply`. A key missing in the `.po`
	 * stays English on every German page, without an error.
	 *
	 * @return	array<string,string>
	 * @since	1.15.0
	 */

	public static function defaults(): array
	{
		return( array(
			'views_label'          => 'Views of the report',
			'views_findings'       => 'Messages',
			'views_xml'            => 'XML',
			'views_invoice'        => 'Invoice',
			'views_download'       => 'Download original report',
			'views_no_document'    => 'The report does not carry the document, so there is nothing to show here.',
			'views_no_invoice'     => 'The view "Invoice" needs the invoice itself, and it was not handed over with this report.',
			'views_not_checked'    => 'Not checked: there is no validation report for this invoice.',
			'views_unplaced'       => 'Messages without a field in the invoice: %d.',
			'views_unplaced_jump'  => 'Show them under "Messages"',
			'views_origin_norm'    => 'Rule of the standard',
			'views_origin_profile' => 'House rule of the check profile %s',
			'views_origin_hint'    => 'Hint of the viewer',
			'views_missing'        => 'Missing in the invoice',
			'views_old_viewer'     => 'The view "Invoice" needs the eInvoice Viewer 2.34.0 or later.',
		) );
	}


	/**
	 * A stored or configured view, checked against the list - the first view for anything else.
	 *
	 * ⚠️ A SETTING IS FOREIGN INPUT. An option written by an older version, a typo in `config.js` or a German
	 * "Beleg" must not leave every panel hidden; it falls back to "Messages", the default of step 4.
	 *
	 * @param	mixed	$value
	 * @return	string
	 * @since	1.15.0
	 */

	public static function view( $value ): string
	{
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return( in_array( $value, self::VIEWS, true ) ? $value : self::VIEWS[0] );
	}


	/**
	 * One string, UNESCAPED: the caller's table, then the defaults here, then the renderer's defaults ( for the
	 * three severity names, which the view "Invoice" repeats at the fields ).
	 *
	 * @param	string	$key
	 * @return	string
	 * @since	1.15.0
	 */

	public function s( string $key ): string
	{
		if ( isset( $this->strings[ $key ] ) && is_string( $this->strings[ $key ] ) && $this->strings[ $key ] !== '' )
			return( $this->strings[ $key ] );

		$d = self::defaults();

		if ( isset( $d[ $key ] ) )
			return( $d[ $key ] );

		$r = Renderer::defaults();

		return( (string) ( $r[ $key ] ?? $key ) );
	}


	/**
	 * The texts the element `<einvoice-viewer>` writes at the fields, already in the language of the page.
	 *
	 * ⚠️ HANDED TO THE ELEMENT, NOT LOOKED UP BY IT. The element translates through the viewer's own tables,
	 * which belong to the viewer plugin; these sentences belong to the report output. Passing them in keeps
	 * every sentence in one place.
	 *
	 * @return	array<string,string>
	 * @since	1.15.0
	 */

	public function labels(): array
	{
		return( array(
			'error'       => $this->s( 'severity_error' ),
			'warning'     => $this->s( 'severity_warning' ),
			'information' => $this->s( 'severity_information' ),
			'norm'        => $this->s( 'views_origin_norm' ),
			'profile'     => $this->s( 'views_origin_profile' ),
			'hint'        => $this->s( 'views_origin_hint' ),
			'missing'     => $this->s( 'views_missing' ),
			'notChecked'  => $this->s( 'views_not_checked' ),
			'unplaced'    => $this->s( 'views_unplaced' ),
			'jump'        => $this->s( 'views_unplaced_jump' ),
		) );
	}


	/**
	 * The three views around the parts the renderer wrote.
	 *
	 * @param	string		$findings	the view "Messages", ready HTML
	 * @param	string		$xml		the view "XML", ready HTML, '' when the report carries no document
	 * @param	array|null	$placement	FieldPlacement::place(), or null when there is no report
	 * @param	array		$options	`default_view`, `invoice` ( the invoice XML ), `name` ( its file name ),
	 *                                  `lang`, `original` ( array( name, type, bytes ) )
	 * @return	string
	 * @since	1.15.0
	 */

	public function render( string $findings, string $xml, ?array $placement, array $options = array() ): string
	{
		$view = self::view( $options['default_view'] ?? '' );

		$tabs = '';

		foreach ( self::VIEWS as $v )
			$tabs .= '<button type="button" role="tab" class="spx-view-tab' . ( $v === $view ? ' on' : '' ) . '" data-spx-view="' . $v . '" aria-selected="' . ( $v === $view ? 'true' : 'false' ) . '">'
				. self::esc( $this->s( 'views_' . $v ) ) . '</button>';

		$out = '<div class="spx-views" data-default-view="' . $view . '">'
			. '<div class="spx-views-bar" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin-bottom:16px">'
			. '<div class="tabs spx-views-switch" role="tablist" aria-label="' . self::esc( $this->s( 'views_label' ) ) . '" style="flex:1 1 auto">' . $tabs . '</div>'
			. $this->original( $options['original'] ?? null )
			. '</div>';

		$out .= $this->panel( 'findings', $view, $findings );
		$out .= $this->panel( 'xml', $view, $xml !== '' ? $xml : '<div class="empty-note">' . self::esc( $this->s( 'views_no_document' ) ) . '</div>' );
		$out .= $this->panel( 'invoice', $view, $this->invoice( $placement, $options ) );

		return( $out . '</div>' );
	}


	/**
	 * One panel; every panel but the selected one is hidden.
	 *
	 * @since	1.15.0
	 */

	private function panel( string $name, string $view, string $html ): string
	{
		return( '<div class="spx-view" data-spx-view-panel="' . $name . '"' . ( $name === $view ? '' : ' hidden' ) . '>' . $html . '</div>' );
	}


	/**
	 * The button "Download original report" - nothing when the caller has no original.
	 *
	 * ⚠️ THE MEDIA TYPE IS CHECKED, THE NAME ESCAPED. Both come from the caller, the name possibly from an upload;
	 * a type with a quote or a semicolon would end the address early.
	 *
	 * @param	mixed	$original	array( 'name' => string, 'type' => string, 'bytes' => string )
	 * @return	string
	 * @since	1.15.0
	 */

	private function original( $original ): string
	{
		if ( ! is_array( $original ) || ! isset( $original['bytes'] ) || ! is_string( $original['bytes'] ) || $original['bytes'] === '' )
			return( '' );

		$type = strtolower( (string) ( $original['type'] ?? '' ) );

		if ( ! preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $type ) )
			$type = 'application/octet-stream';

		$name = (string) ( $original['name'] ?? '' );
		$name = $name !== '' ? $name : 'report';

		return( '<a class="btn small spx-original" href="data:' . $type . ';base64,' . base64_encode( $original['bytes'] ) . '" download="' . self::esc( $name ) . '">'
			. self::esc( $this->s( 'views_download' ) ) . '</a>' );
	}


	/**
	 * The view "Invoice": the counter of the findings without a field, the host for the element and its
	 * data.
	 *
	 * ⚠️ THE COUNTER JUMPS TO THE FIRST FINDING WITHOUT A FIELD, not only to the view: the reader came for exactly
	 * those. `#spx-fN` is the id the renderer gives each finding.
	 *
	 * ⚠️ WITHOUT A REPORT THE ELEMENT SAYS "NOT CHECKED", not this class. The same element shows an invoice
	 * without a report in the viewer, and the sentence must be the same in both places.
	 *
	 * @param	array|null	$placement
	 * @param	array		$options
	 * @return	string
	 * @since	1.15.0
	 */

	private function invoice( ?array $placement, array $options ): string
	{
		$invoice  = isset( $options['invoice'] ) && is_string( $options['invoice'] ) ? (string) preg_replace( '/^\xEF\xBB\xBF/', '', $options['invoice'] ) : '';
		$unplaced = $placement !== null ? array_values( (array) ( $placement['unplaced'] ?? array() ) ) : array();

		// ⚠️ Hidden until `wireReportViews()` in the browser finds an older copy of the element on the page; see there.
		$out = '<div class="spx-beleg">'
			. '<p class="note warn einvoice-requires-viewer spx-beleg-old-viewer" hidden>' . self::esc( $this->s( 'views_old_viewer' ) ) . '</p>';

		if ( $unplaced )
			$out .= '<div class="note info spx-beleg-unplaced"><span class="spx-beleg-unplaced-text">' . self::esc( sprintf( $this->s( 'views_unplaced' ), count( $unplaced ) ) ) . '</span>'
				. ' <a href="#spx-f' . (int) $unplaced[0] . '" class="spx-view-jump" data-spx-view="findings">' . self::esc( $this->s( 'views_unplaced_jump' ) ) . '</a></div>';

		if ( $invoice === '' )
			$out .= '<div class="note warn spx-beleg-no-invoice">' . self::esc( $this->s( 'views_no_invoice' ) ) . '</div>';

		$data = array(
			'lang'    => (string) ( $options['lang'] ?? '' ),
			'name'    => (string) ( $options['name'] ?? '' ),
			'invoice' => $invoice !== '' ? $invoice : null,
			'labels'  => $this->labels(),
			'report'  => $placement,
		);

		return( $out . '<div class="spx-beleg-host"></div>'
			. '<script type="application/json" class="spx-beleg-data">' . self::json( $data ) . '</script>'
			. '</div>' );
	}


	/**
	 * JSON that may stand inside a `<script>` element, written the same way as `json()` in `ReportViews.mjs`.
	 *
	 * ⚠️ `<`, `>` AND `&` AS `<`, `>`, `&`. Otherwise an invoice with `</script>` in a note would
	 * end the element and run the rest as markup. The upper-case hex is PHP's own form for these three; the
	 * JavaScript twin writes the same.
	 *
	 * ⚠️ U+2028 AND U+2029 ESCAPED, AS PHP DOES WITHOUT `JSON_UNESCAPED_LINE_TERMINATORS`. `JSON.stringify()` leaves
	 * them raw, so the twin replaces them; otherwise the two sides differ on an invoice with such a character.
	 *
	 * @param	mixed	$value
	 * @return	string
	 * @since	1.15.0
	 */

	public static function json( $value ): string
	{
		return( (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE ) );
	}


	/**
	 * ⚠️ EXACTLY THE FIVE CHARACTERS `& < > " '`, NOT `esc_html()` - the same choice as in `RuleReportSection`:
	 * `esc_html()` does not encode an existing entity again and writes `&#039;`; both sides must write the same.
	 *
	 * @param	string	$s
	 * @return	string
	 * @since	1.15.0
	 */

	public static function esc( string $s ): string
	{
		return( str_replace( array( '&', '<', '>', '"', "'" ), array( '&amp;', '&lt;', '&gt;', '&quot;', '&#039;' ), $s ) );
	}

}

endif;
