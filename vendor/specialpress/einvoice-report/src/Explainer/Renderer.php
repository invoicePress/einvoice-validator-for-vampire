<?php
/**
 * Turns a parsed report into the HTML the visitor sees - the Explainer's full view.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report\Explainer;

use SpecialPress\EInvoice\Report\Report;

/**
 * class: Renderer
 *
 * ============================================================================================
 * THE ORDER
 * ============================================================================================
 *
 *   1  verdict     red for errors, yellow for warnings, one bar each
 *   2  meta        what the report says about itself and about the document
 *   3  steps       the validator's own per-step tally ( XML Schema, each Schematron rule set, … )
 *   4  findings    the rule once, its fields marked inline with their values, then the affected rows
 *   5  document    the complete document, findings highlighted, the rule repeated in place
 *
 * ⚠️ THE CLASS NAMES ARE THE PROTOTYPE'S, NOT NEW ONES. `spx-explainer.css` is that file verbatim;
 * inventing class names here would mean maintaining a second stylesheet.
 *
 * ============================================================================================
 * ⚠️ THE VERDICT IS A STATEMENT, NOT A PREDICTION
 * ============================================================================================
 *
 * The prototype said "the invoice is not compliant and would be rejected by the official
 * validator". That is wrong twice over: the validator has already decided - the report IS its
 * decision - and it is not this tool's place to predict anything. The wording is therefore past
 * tense and factual.
 *
 * ============================================================================================
 * ⚠️ WHY THIS CLASS LIVES IN THE REPORT PACKAGE
 * ============================================================================================
 *
 * The class is the basis of the KoSIT-style report output, so it lives where every frontend can
 * include it. Two things come in from the caller for that reason:
 *
 * 1. **The rule and field lookup.** It comes in through `Terms` - see there for why.
 *
 * 2. ⚠️ **THE TRANSLATION, AS FINISHED STRINGS, NOT AS A CALLBACK.** The caller passes a map
 *    `array( 'key' => 'translated text' )` and builds it on its side with **literal**
 *    `__( 'Errors', 'its-textdomain' )` calls. That is more cumbersome than a `$t( 'Errors' )`,
 *    and it is intended:
 *
 *    - WordPress's tools find translatable strings by scanning the source for **literal** `__()`
 *      calls. A callback with a variable produces not a single line in the `.pot`, and the existing
 *      German translations of the explainer would be orphaned.
 *    - A generic `t()` wrapper is exactly what the WordPress.org review rejects; the accepted
 *      solution is the same static map as here.
 *    - And a library package has no text domain of its own that WordPress could load - the
 *      strings MUST stay with the plugin.
 *
 *    If a key is missing, the English default text stands there. Never an empty box.
 *
 * @since 1.4.0
 */

if ( ! class_exists( __NAMESPACE__ . '\\Renderer' ) ) :

class Renderer
{


	/** @var Terms */
	private $terms;

	/** @var array<string,string> */
	private $strings;

	/** @var array<int,string>|null row index => BT id, built once per render, see row_terms() */
	private $rowTerms = null;


	/**
	 * @param	Terms	$terms		The caller's rule and field lookup.
	 * @param	array	$strings	Translated strings, keys see defaults().
	 * @since	1.4.0
	 */

	public function __construct( Terms $terms, array $strings = array() )
	{
		$this->terms   = $terms;
		$this->strings = $strings;
	}


	/**
	 * The English default texts - at the same time the complete list of keys.
	 *
	 * ⚠️ Whoever adds a key here must also cover it in the plugin with a literal `__()`,
	 * otherwise the line stays English forever without anyone noticing.
	 *
	 * @return	array<string,string>
	 * @since	1.4.0
	 */

	public static function defaults(): array
	{
		return array(
			'notice_title'            => 'This report could only be read in part',
			'verdict_errors_rejected' => 'Errors — the document was rejected',
			'verdict_errors'          => 'Errors',
			'verdict_errors_desc'     => 'The errors found are listed below with the affected field and the value the document actually carries.',
			'stamp_rejected'          => 'rejected',
			'verdict_warnings'        => 'Warnings',
			// ⚠️ The title above the warnings, the same sentence as `verdict_warnings_title` in Renderer.mjs, so that
			// both sides say the same. `verdict_warnings` stays in the defaults, because the maps of the plugins still
			// carry it. ⚠️ A host map without the new key gets this English sentence, not its own translation of
			// "Warnings"; every map of the family has the key since 13.10.2026.
			'verdict_warnings_title'  => 'Findings — the document has recommendations',
			'verdict_warnings_desc'   => 'The warnings do not block the document. The recipient can process it, but some entries do not follow the recommendation.',
			'stamp_check'             => 'worth checking',
			'verdict_pass'            => 'No findings — the document passed',
			'verdict_pass_desc'       => 'The validator reported neither an error nor a warning for this document.',
			'stamp_accepted'          => 'accepted',
			// ⚠️ Only for a Mustang report whose PDF part has errors while the invoice XML has none ( ReaderMustang, `pdf_errors` ).
			'verdict_pdf_errors'      => 'Errors in the PDF part — the document is invalid',
			'verdict_pdf_errors_desc' => 'The invoice XML has no findings. Mustang found errors in the PDF part, for example in its metadata; its messages are in the original report.',
			'meta_report_format'      => 'Report format',
			'meta_validated_at'       => 'Time of validation',
			'meta_document_type'      => 'Document type',
			'meta_syntax'             => 'Syntax',
			'meta_seller'             => 'Seller',
			'meta_invoice_number'     => 'Invoice number',
			'meta_invoice_date'       => 'Invoice date',
			'meta_reference'          => 'Reference',
			'steps_title'             => 'Validation steps',
			'steps_step'              => 'Step',
			'steps_errors'            => 'Errors',
			'steps_warnings'          => 'Warnings',
			'steps_information'       => 'Information',
			'findings_title'          => 'Findings',
			'findings_count'          => '%1$d errors · %2$d warnings',
			'severity_error'          => 'Error',
			'severity_warning'        => 'Warning',
			'severity_information'    => 'Information',
			'no_rule_id'              => 'no rule id',
			// The same sentence as `views_origin_profile` in ReportViews, so both views name a house rule alike.
			'origin_profile'          => 'House rule of the check profile %s',
			'lang_de'                 => 'German',
			'lang_en'                 => 'English',
			'lang_fr'                 => 'French',
			'no_translation'          => 'No translation is available for this rule text yet - shown in %s, not translated.',
			'machine_translation'     => 'This rule text was translated by machine and has not been reviewed yet.',
			'no_explanation'         => 'No plain-language explanation is available for this rule yet. The message above is the validator\'s own wording.',
			'location_label'          => 'Location in the document',
			'affected_field'          => 'Affected field',
			'not_present'             => 'not present in the document',
			'empty_value'             => 'empty',
			'chars_total'             => '%d characters in total',
			'position'                => 'position %s',
			'further_positions'       => 'and %d further positions',
			'document_title'          => 'Document content',
			'elements_count'          => '%d elements',
			'search_placeholder'      => 'Search BT field, element name or value …',
			'toggle_bt'               => 'BT numbers',
			'toggle_findings'         => 'findings only',
			'chars'                   => '%d characters',
			'attachment'              => 'Attachment',
			'raw_value'               => 'As written in the document: %s',
			'validator_message'       => 'The validator\'s wording',
			'disclaimer'              => 'This tool does not validate anything itself. It reads a validation report that already exists and explains it. The validator\'s report remains authoritative.',
		);
	}


	/**
	 * A string, translated when the caller passed it.
	 *
	 * @param	string	$key
	 * @return	string	UNESCAPED - the call sites escape exactly where the output is written.
	 * @since	1.4.0
	 */

	private function s( string $key ): string
	{
		if ( isset( $this->strings[ $key ] ) && $this->strings[ $key ] !== '' )
			return( (string) $this->strings[ $key ] );

		$d = self::defaults();

		return( (string) ( $d[ $key ] ?? $key ) );
	}


	/**
	 * Render a report.
	 *
	 * ⚠️ THE SECOND ARGUMENT IS OPTIONAL AND COMES FROM THE CALLER, NOT FROM THE READER. The
	 * positive validation report of VAMPIRE is a section of its JSON report; the KoSIT-shaped XML that the
	 * readers parse carries no rule lines. A validator that switched the report on hands the section in,
	 * and the view shows "Checked rules" between the findings and the document. Without it the output
	 * has no such section. A plugin with an older copy of this class passes the argument into
	 * nothing; PHP ignores surplus arguments of a user function, so the call cannot break.
	 *
	 * ⚠️ THE THIRD ARGUMENT CARRIES THE SWITCH "RULE TEXT PER LINE" ( `rule_text`, default false ) on to
	 * `RuleReportSection`. An older copy of this class drops it like the second, and its section keeps the
	 * column of the rule text.
	 *
	 * @param	Report		$report
	 * @param	array|null	$ruleReport	the section `ruleReport` of a VAMPIRE JSON report, or null
	 * @param	array		$options	`rule_text` ( bool ): the switch of the section "Checked rules"
	 * @return	string
	 * @since	1.4.0, `$options` since 01.11.2026
	 */

	public function render( Report $report, ?array $ruleReport = null, array $options = array() ): string
	{
		$this->rowTerms = null;

		$this->mark_referenced_rows( $report );

		$out  = $this->notices( $report );
		$out .= $this->verdict( $report );
		$out .= $this->meta( $report );
		$out .= $this->steps( $report );
		$out .= $this->findings( $report );

		if ( $ruleReport !== null && class_exists( __NAMESPACE__ . '\\RuleReportSection' ) )
			$out .= ( new RuleReportSection( $this->strings, array( 'rule_text' => ( $options[ 'rule_text' ] ?? false ) === true ) ) )->render( $ruleReport );

		$out .= $this->document( $report );
		$out .= $this->disclaimer();

		return( $out );
	}


	/**
	 * Render a report in three views - "Messages", "XML" and "Invoice".
	 *
	 * ⚠️ A METHOD OF ITS OWN, NOT A SWITCH IN render(). render() stays byte for byte as it is, for every
	 * plugin that calls it and for the parity runs that hold it; a caller moves to the views by calling
	 * this method, one plugin at a time. The parts are the same private methods, in the same order, so the
	 * views show exactly what render() shows - only split.
	 *
	 * ⚠️ THE DISCLAIMER STANDS BELOW ALL THREE VIEWS. It says what the tool is, and that holds for every view.
	 *
	 * @param	Report		$report
	 * @param	array|null	$ruleReport	as for render()
	 * @param	array		$options	`default_view` ( 'findings' | 'xml' | 'invoice', see ReportViews::VIEWS ),
	 *                                  `invoice` ( the invoice XML for the view "Invoice" ), `name` ( its file
	 *                                  name ), `original` ( array( name, type, bytes ) - the report as the engine
	 *                                  wrote it, for the download ), `rule_text` ( bool, as for render() )
	 * @return	string
	 * @since	1.15.0
	 */

	public function render_views( Report $report, ?array $ruleReport = null, array $options = array() ): string
	{
		$this->rowTerms = null;

		$this->mark_referenced_rows( $report );

		$findings  = $this->notices( $report );
		$findings .= $this->verdict( $report );
		$findings .= $this->meta( $report );
		$findings .= $this->steps( $report );
		$findings .= $this->findings( $report );

		if ( $ruleReport !== null && class_exists( __NAMESPACE__ . '\\RuleReportSection' ) )
			$findings .= ( new RuleReportSection( $this->strings, array( 'rule_text' => ( $options[ 'rule_text' ] ?? false ) === true ) ) )->render( $ruleReport );

		$placement = FieldPlacement::place( $report, $this->terms );

		$options['lang'] = $this->terms->language();

		return( ( new ReportViews( $this->strings ) )->render( $findings, $this->document( $report ), $placement, $options )
			. $this->disclaimer() );
	}


	/**
	 * Anything the reader could not read.
	 *
	 * ⚠️ THIS COMES FIRST, ABOVE THE VERDICT. A half-read report that shows "0 errors" is the single
	 * most dangerous thing this tool could produce, so the doubt has to be visible before the number
	 * is. Putting it at the bottom would be the same as hiding it.
	 *
	 * @param	Report	$report
	 * @return	string
	 * @since	1.4.0
	 */

	private function notices( Report $report ): string
	{
		if ( ! $report->notices )
			return( '' );

		$out = '';

		foreach ( $report->notices as $notice )
			$out .= '<div class="verdict warn spx-verdict"><div class="verdict-main"><div>'
				. '<div class="verdict-title">' . $this->esc( $this->s( 'notice_title' ) ) . '</div>'
				. '<div class="verdict-desc">' . $this->esc( $this->notice( (string) $notice ) ) . '</div>'
				. '</div></div></div>';

		return( $out );
	}


	/**
	 * A notice of the reader, translated when the caller passed it.
	 *
	 * ⚠️ THE READERS WRITE THEIR NOTICES IN ENGLISH, AND THEY HAVE TO. A library package has no text
	 * domain of its own; an `__()` here would need the domain of a plugin, and the library lives in five
	 * plugins with five domains. The plugin therefore passes, under the key `notices`, a map
	 * "English sentence => translation", built from literal `__()` calls - the same way as for the
	 * other strings of this class, only per sentence instead of per key. Without it every notice
	 * stands in English on the German page.
	 *
	 * ⚠️ A NOTICE CARRIES NUMBERS. "Only 3 of the 5 findings …" is built with `sprintf()` in the reader; as
	 * a finished sentence it would be in no map. It is therefore recognised by its pattern and rebuilt with
	 * the translated sentence frame. The sentence frame is the key in the map.
	 *
	 * @param	string	$notice
	 * @return	string	UNESCAPED
	 * @since	1.10.0
	 */

	private function notice( string $notice ): string
	{
		$map = ( isset( $this->strings[ 'notices' ] ) && is_array( $this->strings[ 'notices' ] ) ) ? $this->strings[ 'notices' ] : array();

		if ( isset( $map[ $notice ] ) && $map[ $notice ] !== '' )
			return( (string) $map[ $notice ] );

		$rahmen = 'Only %1$d of the %2$d findings this report states could be read. The report layout may have changed - please check the original report.';

		if ( isset( $map[ $rahmen ] ) && preg_match( '/^Only (\d+) of the (\d+) findings this report states could be read\./', $notice, $m ) )
			return( sprintf( (string) $map[ $rahmen ], (int) $m[1], (int) $m[2] ) );

		return( $notice );
	}


	/**
	 * The verdict bars.
	 *
	 * @param	Report	$report
	 * @return	string
	 * @since	1.4.0
	 */

	private function verdict( Report $report ): string
	{
		$errors   = $report->count( 'error' );
		$warnings = $report->count( 'warning' );

		$out = '';

		if ( $errors > 0 )
		{
			$out .= '<div class="verdict fail spx-verdict">'
				. '<div class="verdict-main">'
				. '<div class="verdict-count">' . (int) $errors . '</div>'
				. '<div>'
				. '<div class="verdict-title">' . $this->esc(
					$report->rejected()
						? $this->s( 'verdict_errors_rejected' )
						: $this->s( 'verdict_errors' )
				) . '</div>'
				. '<div class="verdict-desc">' . $this->esc( $this->s( 'verdict_errors_desc' ) ) . '</div>'
				. '</div></div>'
				. '<div class="verdict-stamp">' . $this->esc( $this->s( 'stamp_rejected' ) ) . '</div>'
				. '</div>';
		}

		/*
		 * ⚠️ NO FINDING IN THE XML, BUT ERRORS IN THE PDF PART: never "passed". Mustang keeps the PDF messages apart and its own
		 * top-level verdict may still say "valid"; the Validator Bridge counts the PDF part. Same bar in Renderer.mjs.
		 */
		$pdf_errors = isset( $report->meta['pdf_errors'] ) && is_array( $report->meta['pdf_errors'] ) ? count( $report->meta['pdf_errors'] ) : 0;

		if ( $errors === 0 && $pdf_errors > 0 )
		{
			$out .= '<div class="verdict fail spx-verdict">'
				. '<div class="verdict-main">'
				. '<div class="verdict-count">' . (int) $pdf_errors . '</div>'
				. '<div>'
				. '<div class="verdict-title">' . $this->esc( $this->s( 'verdict_pdf_errors' ) ) . '</div>'
				. '<div class="verdict-desc">' . $this->esc( $this->s( 'verdict_pdf_errors_desc' ) ) . '</div>'
				. '</div></div>'
				. '<div class="verdict-stamp">' . $this->esc( $this->s( 'stamp_rejected' ) ) . '</div>'
				. '</div>';
		}

		if ( $warnings > 0 )
		{
			$out .= '<div class="verdict warn spx-verdict">'
				. '<div class="verdict-main">'
				. '<div class="verdict-count">' . (int) $warnings . '</div>'
				. '<div>'
				. '<div class="verdict-title">' . $this->esc( $this->s( 'verdict_warnings_title' ) ) . '</div>'
				. '<div class="verdict-desc">' . $this->esc( $this->s( 'verdict_warnings_desc' ) ) . '</div>'
				. '</div></div>'
				. '<div class="verdict-stamp">' . $this->esc( $this->s( 'stamp_check' ) ) . '</div>'
				. '</div>';
		}

		// A report with neither is the good case, and it deserves to be said out loud.
		if ( $out === '' )
			$out = '<div class="verdict pass spx-verdict">'
				. '<div class="verdict-main"><div>'
				. '<div class="verdict-title">' . $this->esc( $this->s( 'verdict_pass' ) ) . '</div>'
				. '<div class="verdict-desc">' . $this->esc( $this->s( 'verdict_pass_desc' ) ) . '</div>'
				. '</div></div>'
				. '<div class="verdict-stamp">' . $this->esc( $this->s( 'stamp_accepted' ) ) . '</div>'
				. '</div>';

		return( $out );
	}


	/**
	 * Report and document metadata.
	 *
	 * @param	Report	$report
	 * @return	string
	 * @since	1.4.0
	 */

	private function meta( Report $report ): string
	{
		$items = array(
			array( $this->s( 'meta_report_format' ), strtoupper( (string) ( $report->meta['source'] ?? '' ) ) ),
			array( $this->s( 'meta_validated_at' ), $report->meta['validated_at'] ?? '' ),
			array( $this->s( 'meta_document_type' ), $report->meta['document_type'] ?? '' ),
			array( $this->s( 'meta_syntax' ), $report->meta['syntax'] ?? '' ),
			array( $this->s( 'meta_seller' ) . ' · BT-27', $report->meta['seller'] ?? '' ),
			array( $this->s( 'meta_invoice_number' ) . ' · BT-1', $report->meta['invoice_number'] ?? '' ),
			array( $this->s( 'meta_invoice_date' ) . ' · BT-2', $report->meta['invoice_date'] ?? '' ),
			array( $this->s( 'meta_reference' ), $report->meta['reference'] ?? '' ),
		);

		$items = array_values( array_filter( $items, static function ( $item ) { return( trim( (string) $item[1] ) !== '' ); } ) );

		if ( ! $items )
			return( '' );

		$out  = '<div class="meta-card">';
		$last = count( $items ) - 1;

		foreach ( $items as $n => $item )
		{
			$out .= '<div class="meta-item' . $this->meta_span( $n, $last, (string) $item[1] ) . '">'
				. '<div class="meta-label">' . $this->esc( $item[0] ) . '</div>'
				. '<div class="meta-value">' . $this->wbr_slashes( (string) $item[1] ) . '</div>'
				. '</div>';
		}

		return( $out . '</div>' );
	}


	/**
	 * The class that lets the LAST, long value of the meta card stretch over the free columns of its
	 * row.
	 *
	 * ⚠️ COMPUTED HERE, NOT WITH `grid-column: auto / -1` IN CSS. That CSS form spans to the end of the
	 * row from wherever auto-placement put the item - and when the item lands in the fourth column it
	 * spans exactly one column, which squeezes the value. Knowing the position,
	 * the renderer can say "span the two free columns" or, with only one left, "start a row of your own".
	 *
	 * ⚠️ FOUR COLUMNS IS AN ASSUMPTION ABOUT `spx-explainer.css`, which sets `repeat(4,1fr)` on wide
	 * screens. Below 820 px the card has two columns, and the stylesheet lets every span class take the
	 * full row there instead - a span of 3 in a two-column grid would create an implicit third column.
	 *
	 * @param	int		$n		position of the item among the visible ones
	 * @param	int		$last	position of the last visible item
	 * @param	string	$value
	 * @return	string	'' or ' meta-span-2' | ' meta-span-3' | ' meta-span-4' | ' meta-span-row'
	 * @since	1.14.0
	 */

	private function meta_span( int $n, int $last, string $value ): string
	{
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

		if ( $n !== $last || $length <= 24 )
			return( '' );

		$free = 4 - ( $n % 4 );

		if ( $free === 1 )
			return( ' meta-span-row' );

		return( $free >= 2 ? ' meta-span-' . $free : '' );
	}


	/**
	 * Escape a value and allow a line break after every slash.
	 *
	 * ⚠️ `<wbr>`, NOT `overflow-wrap: anywhere` ALONE. A file path or an XPath is one unbroken run; the
	 * browser then breaks it at an arbitrary character ( "…/ubl-allowance-offic | ial-fehler/…" ). After
	 * a slash, the reader sees whole path segments. The stylesheet keeps `overflow-wrap: anywhere` as the
	 * last resort for a single segment that is wider than the column on its own.
	 *
	 * @param	string	$value
	 * @return	string	HTML, already escaped
	 * @since	1.14.0
	 */

	private function wbr_slashes( string $value ): string
	{
		return( str_replace( '/', '/<wbr>', $this->esc( $value ) ) );
	}


	/**
	 * The validator's own step-by-step tally - "Übersicht der Validierungsergebnisse" in the java
	 * KoSIT validator's native report ( XML Schema, one row per Schematron rule set, each with its
	 * own error/warning/information count ). `$report->steps` is filled by both readers; this view
	 * shows it because the KoSIT validator's own report layout does.
	 *
	 * @param	Report	$report
	 * @return	string
	 * @since	1.4.0
	 */

	private function steps( Report $report ): string
	{
		if ( ! $report->steps )
			return( '' );

		$out  = '<div class="panel spx-section spx-steps">';
		$out .= '<div class="panel-head"><h2>' . $this->esc( $this->s( 'steps_title' ) ) . '</h2></div>';
		$out .= '<table class="tbl"><thead><tr>'
			. '<th>' . $this->esc( $this->s( 'steps_step' ) ) . '</th>'
			. '<th>' . $this->esc( $this->s( 'steps_errors' ) ) . '</th>'
			. '<th>' . $this->esc( $this->s( 'steps_warnings' ) ) . '</th>'
			. '<th>' . $this->esc( $this->s( 'steps_information' ) ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $report->steps as $step )
		{
			$errors   = (int) ( $step[ 'errors' ] ?? 0 );
			$warnings = (int) ( $step[ 'warnings' ] ?? 0 );

			$row_class = $errors > 0 ? ' class="edge-err"' : ( $warnings > 0 ? ' class="edge-warn"' : '' );

			$infos = (int) ( $step[ 'infos' ] ?? 0 );

			/**
			 * ⚠️ EVERY NUMBER CARRIES ITS OWN CLASS. The row classes
			 * above colour only the first non-zero count of a row through `:nth-child`, so a step with
			 * errors AND warnings would show the warnings black. A zero is set back as faint, which is what
			 * makes the non-zero numbers stand out in the first place.
			 */

			$out .= '<tr' . $row_class . '>'
				. '<td>' . $this->esc( (string) ( $step[ 'step' ] ?? '' ) ) . '</td>'
				. '<td class="steps-n' . ( $errors > 0 ? ' n-err' : ' n-zero' ) . '">' . $errors . '</td>'
				. '<td class="steps-n' . ( $warnings > 0 ? ' n-warn' : ' n-zero' ) . '">' . $warnings . '</td>'
				. '<td class="steps-n' . ( $infos > 0 ? ' n-info' : ' n-zero' ) . '">' . $infos . '</td>'
				. '</tr>';
		}

		$out .= '</tbody></table></div>';

		return( $out );
	}


	/**
	 * Mark every document row a finding's rule REFERENCES ( BT-31, BT-32, BT-63 … in BR-S-02's own
	 * text ), not just the row the validator's own path pointed at.
	 *
	 * ⚠️ BACKGROUND TINT ONLY, NOT A REPEATED NOTE. row()'s note ( the boxed rule text ) stays
	 * exactly where link_findings() already put it - once, at the location the validator names.
	 * Painting the full explanation onto every referenced field too would mean BT-31's row carries
	 * the entire BR-S-02 text just because the rule happens to mention it, which drowns the one row
	 * that is actually the finding's own location. The `status` flag ( already how error-row/warn-row
	 * work ) is the lighter signal this calls for: "relevant to a nearby finding", not "this is the
	 * finding".
	 *
	 * Called once at the top of render(), before anything reads `$report->document`'s `status`.
	 *
	 * @param	Report	$report
	 * @return	void
	 * @since	1.4.0
	 */

	private function mark_referenced_rows( Report $report ): void
	{
		$syntax = (string) ( $report->meta['syntax'] ?? '' );

		foreach ( $report->findings as $finding )
		{
			$rule = (string) ( $finding['rule'] ?? '' );

			if ( $rule === '' )
				continue;

			$fields = array_values( (array) ( $this->terms->rule( $rule )['fields'] ?? array() ) );

			if ( ! $fields )
				continue;

			$anchor   = ( $finding['rows'] ?? array() )[0] ?? null;
			/*
			 * ⚠️ THREE LEVELS, NOT TWO. Until 19.10.2026 everything that was not an error marked its row as a warning, and an
			 * information ( BR-DE-TMP-32 on a correct XRechnung ) showed an orange row with a red note under a blue finding card
			 * ( Ralf: "wird im Listing dargestellt wie ein Fehler" ).
			 */
			$severity = self::row_status( (string) ( $finding['severity'] ?? '' ) );

			foreach ( $fields as $bt )
			{
				$xpath = $this->terms->fieldXpath( (string) $bt, $syntax );

				if ( $xpath === '' )
					continue;

				// ⚠️ Every row the field addresses, not only an unambiguous one: under BR-CO-10 all line net amounts take
				// part, and until 23.10.2026 only the first line was marked ( see Report::field_rows() ).
				// The stronger statement wins on a row: error before warning before information, the same rule
				// link_findings() applies.
				foreach ( $report->field_rows( $xpath, $anchor ) as $row )
					if ( self::STATUS_RANK[ $severity ] > ( self::STATUS_RANK[ $report->document[ $row ]['status'] ?? '' ] ?? 0 ) )
						$report->document[ $row ]['status'] = $severity;
			}
		}
	}


	/**
	 * Wrap every "(BT-123)" / "(BG-45)" reference inside a text in a small badge, leaving the rest of
	 * the text as plain, escaped prose. Used for the normative rule text, which routinely names
	 * several fields inline ( "…the Seller VAT Identifier (BT-31)…" ) - the badge is what makes those
	 * jump out from a paragraph instead of reading as parenthetical noise.
	 *
	 * ⚠️ ESCAPES FIRST, THEN MATCHES. The pattern has no HTML-significant characters, so escaping the
	 * whole text before inserting markup around the matched substrings is safe and avoids having to
	 * hand-escape the surrounding prose piecewise.
	 *
	 * ⚠️ THE BADGE CARRIES THE FIELD'S VALUE RIGHT BEHIND IT, so the rule stands only once: "Sum of Invoice
	 * line net amount (BT-106)" reads "Sum of Invoice line net amount [BT-106] [5.990,00]". `$values` comes
	 * from explain_finding(); a field without a value found in the document keeps its bare badge.
	 *
	 * ⚠️ THE PATTERN ALSO TAKES `BT-X-…` AND `BG-X-…`. The rule texts of the extensions name their
	 * fields that way; without it they would stay plain text in parentheses.
	 *
	 * @param	string	$text
	 * @param	array	$values	bt => array( value, raw ), see field_value()
	 * @return	string	HTML - already escaped, ready to echo
	 * @since	1.4.0
	 */

	private function mark_text( $text, array $values = array() ): string
	{
		$self = $this;

		return( (string) preg_replace_callback(
			self::BT_REF,
			static function( $m ) use ( $self, $values ) {
				$bt  = self::ref_id( $m );
				$out = $self->badgePublic( $bt, 'badge code finding-bt-inline' );

				// ⚠️ A real space, not only the margin in the stylesheet: the card under a field in the viewer draws
				// inside the viewer's shadow root, where that stylesheet does not apply, and showed "BT-6CZK".
				if ( isset( $values[ $bt ] ) && $values[ $bt ]['value'] !== '' )
					$out .= ' ' . $self->valuePublic( $values[ $bt ]['value'], $values[ $bt ]['raw'], ' finding-bt-value' );

				return( $out );
			},
			$this->esc( (string) $text )
		) );
	}


	/**
	 * A field reference "(BT-123)", "(BG-45)", "(BT-X-12)" inside a rule text or a validator message.
	 *
	 * ⚠️ THE TWIN `BT_REF` IN `Renderer.mjs` MUST STAY THE SAME PATTERN. Both sides decide with it which
	 * fields stand in the text and which go into the short list below it; a difference would put a field
	 * inline on one side and in the list on the other, and `compare-explainer.mjs` would report it.
	 *
	 * @since 1.14.0
	 */

	const BT_REF = '/\((B[TG](?:-X)?-[0-9]+(?:-[0-9]+)*)\)|\b(B[TG](?:-X)?-[0-9]+(?:-[0-9]+)*)\b/';


	/**
	 * The field id of a BT_REF match - the first group for "(BT-106)", the second for a bare "BT-72".
	 *
	 * ⚠️ TWO ALTERNATIVES, NOT ONE PATTERN WITH OPTIONAL PARENTHESES. The German XRechnung texts name
	 * their fields bare ( "entweder BT-72 "Actual delivery date", BG-14 …" ), the EN 16931 texts in
	 * parentheses, and some texts list several in one pair ( "(BT-151, BT-95, BT-102)" ). An optional
	 * "(" and ")" would swallow the opening parenthesis of such a list with its first id and the closing
	 * one with its last. With the alternatives, "(BT-106)" loses its parentheses to the badge, and a list
	 * keeps them around its badges.
	 *
	 * @param	array	$m	a match of BT_REF
	 * @return	string
	 * @since	1.14.0
	 */

	private static function ref_id( array $m ): string
	{
		return( (string) ( ( $m[1] ?? '' ) !== '' ? $m[1] : ( $m[2] ?? '' ) ) );
	}


	/**
	 * A BT/BG badge whose tooltip names the business term.
	 *
	 * @param	string	$bt
	 * @param	string	$class	'finding-bt' | 'badge code finding-bt-inline' | 'badge code doc-elem-bt'
	 * @return	string	HTML
	 * @since	1.14.0
	 */

	private function badge( string $bt, string $class ): string
	{
		$label = $this->term_label( $bt );

		return( '<span class="' . $class . '"' . ( $label !== '' ? ' title="' . $this->escAttr( $label ) . '"' : '' ) . '>'
			. $this->esc( $bt ) . '</span>' );
	}


	/**
	 * A value box; when the shown text differs from the document's own writing ( a formatted amount, a
	 * shortened text ), the tooltip carries the original value of the invoice.
	 *
	 * @param	string	$value	what is shown
	 * @param	string	$raw	what the document says
	 * @param	string	$extra	further classes, with a leading space
	 * @return	string	HTML
	 * @since	1.14.0
	 */

	private function value_span( string $value, string $raw, string $extra = '' ): string
	{
		$title = ( $raw !== '' && $raw !== $value ) ? ' title="' . $this->escAttr( sprintf( $this->s( 'raw_value' ), $raw ) ) . '"' : '';

		return( '<span class="doc-elem-val' . $extra . '"' . $title . '>' . $this->esc( $value ) . '</span>' );
	}


	/**
	 * Only for the callback in mark_text() - an anonymous function cannot reach private methods.
	 *
	 * @since 1.14.0
	 */

	public function badgePublic( string $bt, string $class ): string
	{
		return( $this->badge( $bt, $class ) );
	}


	/**
	 * Only for the callback in mark_text().
	 *
	 * @since 1.14.0
	 */

	public function valuePublic( string $value, string $raw, string $extra = '' ): string
	{
		return( $this->value_span( $value, $raw, $extra ) );
	}


	/**
	 * The business term of a field in the language of the page, '' when the caller cannot say.
	 *
	 * ⚠️ OPTIONAL METHODS OF THE CALLER, ASKED WITH method_exists(), NOT ADDED TO `Terms`. Four more
	 * plugins implement that interface in their own code ( the Mustang explorer and the three
	 * validators ). A method added to the interface would make each of them fatal with "Class … contains
	 * abstract methods" the moment its copy of this package is updated, before its own class follows.
	 * Without the optional methods the view loses the BT labels, the BT marks in the document and the
	 * amount format, and otherwise works as in 1.13.0.
	 *
	 * @param	string	$bt
	 * @return	string
	 * @since	1.14.0
	 */

	private function term_label( string $bt ): string
	{
		return( method_exists( $this->terms, 'fieldLabel' ) ? (string) $this->terms->fieldLabel( $bt ) : '' );
	}


	/**
	 * The semantic data type of a field ( "Amount", "Unit Price_ Amount", "Date" … ), '' when unknown.
	 *
	 * @param	string	$bt
	 * @return	string
	 * @since	1.14.0
	 */

	private function term_type( string $bt ): string
	{
		return( method_exists( $this->terms, 'fieldType' ) ? (string) $this->terms->fieldType( $bt ) : '' );
	}


	/**
	 * The value of a field as the view shows it.
	 *
	 * ⚠️ AN AMOUNT ALWAYS HAS TWO DECIMALS, IN THE NOTATION OF THE PAGE LANGUAGE. Without this, one finding
	 * shows "5990" next to "4000.00". Measured on 08.10.2026: the invoice itself writes it that
	 * way - `allowance-example_fehler.xml`, line 207, `<cbc:LineExtensionAmount currencyID="EUR">5990<…>`
	 * - so the view does not lose the decimals, it shows the document verbatim. The data type comes from
	 * the field catalogue ( `semanticDataType` "Amount" and "Unit Price_ Amount" ). More decimals than two
	 * stay visible ( a unit price may have four ); the original stands in the tooltip.
	 *
	 * ⚠️ A VALUE THAT IS NOT A PLAIN DECIMAL NUMBER STAYS AS IT IS. "12,60" or "abc" in an amount field
	 * is exactly what a validator complains about, and a formatted version would hide the complaint.
	 *
	 * @param	string	$bt		'' when the field is unknown
	 * @param	string	$raw
	 * @return	string
	 * @since	1.14.0
	 */

	private function format_value( string $bt, string $raw ): string
	{
		if ( $bt !== '' && preg_match( '/amount/i', $this->term_type( $bt ) ) )
		{
			$formatted = self::format_amount( $raw, $this->terms->language() );

			if ( $formatted !== '' )
				return( $formatted );
		}

		return( $raw );
	}


	/**
	 * "5990" -> "5.990,00" ( de ), "5,990.00" ( en ), "5 990,00" ( fr, narrow no-break space ).
	 *
	 * ⚠️ BY HAND, NOT WITH number_format() OR intl. number_format() goes through a float and turns
	 * "0.10000000000000001" or an amount with eighteen digits into something the document does not say;
	 * the string is grouped as it stands. intl is not available on every host.
	 *
	 * @param	string	$raw
	 * @param	string	$lang	'de' | 'en' | 'fr'
	 * @return	string	'' when $raw is not a plain decimal number
	 * @since	1.14.0
	 */

	public static function format_amount( string $raw, string $lang ): string
	{
		if ( ! preg_match( '/^\s*([+-]?)([0-9]+)(?:\.([0-9]*))?\s*$/', $raw, $m ) )
			return( '' );

		$int  = ltrim( $m[2], '0' );
		$int  = $int === '' ? '0' : $int;
		$frac = (string) ( $m[3] ?? '' );
		$frac = str_pad( $frac, max( 2, strlen( $frac ) ), '0' );

		$seps = array( 'de' => array( '.', ',' ), 'fr' => array( "\u{202F}", ',' ) );
		$sep  = $seps[ $lang ] ?? array( ',', '.' );

		return( ( $m[1] === '-' ? '-' : '' ) . preg_replace( '/\B(?=(\d{3})+(?!\d))/', $sep[0], $int ) . $sep[1] . $frac );
	}


	/**
	 * Which business term each document row carries - the BT mark of the document view and the label
	 * of the affected field.
	 *
	 * ⚠️ BY THE FULL PATH OF THE ROW, NEVER BY THE ELEMENT NAME. `ID` exists in thirty places; the path
	 * `Invoice/LegalMonetaryTotal/LineExtensionAmount` exists once. Without these marks the switch
	 * "BT numbers" and the search for "BT-1" do nothing.
	 *
	 * ⚠️ WITHOUT THE INDICES AND WITHOUT THE ROOT. A field path names no position, so `InvoiceLine[3]`
	 * must still match; and the catalogue writes UBL paths under `/Invoice`, while a credit note's rows
	 * start with `CreditNote`.
	 *
	 * ⚠️ NOTHING IS GUESSED. A catalogue path with a condition ( "(TaxScheme/ID=VAT)" ), a predicate
	 * ( "[@schemeID='…']" ), an alternative or an attribute is skipped: the row alone cannot say whether
	 * the condition holds. A path shared by several fields - BT-31 and BT-32 in CII, BT-17 and BT-122 -
	 * marks nothing, except when exactly one of them is a plain id and the others are the suffixed
	 * sub-ids of the Factur-X catalogue ( "BT-24" against "BT-24-00" ).
	 *
	 * @param	Report	$report
	 * @return	array	row index => BT/BG id
	 * @since	1.14.0
	 */

	private function row_terms( Report $report ): array
	{
		if ( $this->rowTerms !== null )
			return( $this->rowTerms );

		$this->rowTerms = array();

		if ( ! method_exists( $this->terms, 'fieldIds' ) || ! $report->document )
			return( $this->rowTerms );

		if ( ! isset( $report->document[0]['path'] ) || $report->document[0]['path'] === '' )
			$report->build_paths();

		$syntax = (string) ( $report->meta['syntax'] ?? '' );
		$byKey  = array();

		foreach ( (array) $this->terms->fieldIds() as $id )
		{
			$xpath = trim( $this->terms->fieldXpath( (string) $id, $syntax ) );

			/*
			 * ⚠️ A CONDITION ON THE PATH IS REMOVED, NOT A REASON TO DROP THE FIELD ( since 1.16.0 ). The catalogue writes
			 * "…/cbc:CompanyID (TaxScheme/ID=VAT)" or "…/cac:AllowanceCharge[cbc:ChargeIndicator = false()]"; until then
			 * every such field was skipped, and BT-31, BG-20, BG-21 and their kin never got a mark. Without the condition
			 * two fields share a path, and resolve_shared_path() decides between them from the document.
			 */
			$xpath = trim( (string) preg_replace( '/\s+\([^()]*\)\s*$/', '', $xpath ) );
			$xpath = (string) preg_replace( '/\[[^\]]*[^0-9\]][^\]]*\]/', '', $xpath );

			if ( $xpath === '' || preg_match( '/\||@|\s/', $xpath ) )
				continue;

			/*
			 * ⚠️ A RELATIVE PATH GETS A ROOT IN FRONT ( since 1.16.0 ). The catalogue writes some groups without the root,
			 * "cac:InvoiceLine/cac:Item" for BG-31, and generic_path() always drops the first segment as the root. It
			 * dropped "InvoiceLine" instead, and Item, Price and ClassifiedTaxCategory of every line stayed unmarked.
			 */
			if ( $xpath[0] !== '/' )
				$xpath = '/root/' . $xpath;

			$key = self::generic_path( $xpath );

			if ( $key !== '' )
				$byKey[ $key ][] = (string) $id;
		}

		$chosen    = array();
		$ambiguous = array();

		foreach ( $byKey as $key => $ids )
		{
			if ( count( $ids ) === 1 )
			{
				$chosen[ $key ] = $ids[0];
				continue;
			}

			$plain = array_values( array_filter( $ids, static function ( $id ) { return( (bool) preg_match( '/^B[TG]-[0-9]+$/', $id ) ); } ) );

			if ( count( $plain ) === 1 )
				$chosen[ $key ] = $plain[0];
			else
				$ambiguous[ $key ] = $ids;
		}

		foreach ( $report->document as $i => $row )
		{
			$key = self::generic_path( (string) ( $row['path'] ?? '' ) );

			if ( isset( $chosen[ $key ] ) )
				$this->rowTerms[ $i ] = $chosen[ $key ];
			elseif ( isset( $ambiguous[ $key ] ) )
			{
				$id = self::resolve_shared_path( $ambiguous[ $key ], $key, (int) $i, $report );

				if ( $id !== '' )
					$this->rowTerms[ $i ] = $id;
			}
		}

		return( $this->rowTerms );
	}


	/**
	 * Which of several fields with the SAME path a document row is, decided by what the document says around it.
	 *
	 * ⚠️ WITHOUT THIS, WHOLE GROUPS OF FIELDS STAYED WITHOUT A BT MARK ( measured 18.10.2026 ): a document level
	 * allowance and a charge are both `AllowanceCharge` with the same children, the seller's VAT identifier and tax
	 * number are both `PartyTaxScheme/CompanyID` ( CII: `SpecifiedTaxRegistration/ID` ), the VAT total in invoice
	 * currency and in accounting currency are both `TaxTotal/TaxAmount`. The catalogue writes the distinguishing
	 * condition next to the path ( "ChargeIndicator=false", "TaxScheme/ID=VAT" ) or not at all, so the decision is
	 * taken here from the document: the charge indicator inside the same element, the tax scheme or the scheme
	 * attribute, the currency against the document currency, the document type code of a referenced document.
	 *
	 * ⚠️ THE ORDER OF THE PAIRS IS THE ORDER OF EN 16931: the allowance comes before the charge ( BT-92 before
	 * BT-99, BG-20 before BG-21 ), the VAT identifier before the tax number ( BT-31 before BT-32 ), the amount in
	 * invoice currency before the one in accounting currency ( BT-110 before BT-111 ). A case this method does
	 * not recognise returns '' - no mark is better than a wrong one.
	 *
	 * @param	array<int,string>	$ids	the fields that share the path
	 * @param	string				$key	the generic path ( generic_path() )
	 * @param	int					$index	the document row
	 * @param	Report				$report
	 * @return	string						the field, '' when undecided
	 * @since	1.16.0
	 */

	private static function resolve_shared_path( array $ids, string $key, int $index, Report $report ): string
	{
		// Sorted by the number of the base field, so [0] is the first of a pair in EN 16931.
		usort( $ids, static function ( $a, $b ) {
			return( ( (int) preg_replace( '/^B[TG]-([0-9]+).*$/', '$1', $a ) ) <=> ( (int) preg_replace( '/^B[TG]-([0-9]+).*$/', '$1', $b ) ) );
		} );

		$base = static function ( $id ) { return( (string) preg_replace( '/^(B[TG]-[0-9]+).*$/', '$1', $id ) ); };
		$pick = static function ( $first ) use ( $ids ) { return( count( $ids ) === 2 ? $ids[ $first ? 0 : 1 ] : '' ); };

		// A free text note, not the subject code inside it; the card number, not the group of the card.
		foreach ( array( 'BT-22', 'BT-87' ) as $only )
			if ( in_array( $only, $ids, true ) )
				return( $only );

		// 1. Allowance or charge: the indicator inside the nearest AllowanceCharge element.
		if ( preg_match( '#(^|/)(AllowanceCharge|SpecifiedTradeAllowanceCharge)(/|$)#', $key ) )
		{
			$owner = self::ancestor( $report, $index, '/^(AllowanceCharge|SpecifiedTradeAllowanceCharge)$/' );

			if ( $owner === null )
				return( '' );

			foreach ( self::subtree( $report, $owner ) as $k )
			{
				$name = self::local_name( (string) $report->document[ $k ]['name'] );

				if ( $name === 'ChargeIndicator' || $name === 'Indicator' )
				{
					$v = strtolower( trim( (string) $report->document[ $k ]['value'] ) );

					if ( $v === 'true' || $v === 'false' )
						return( $pick( $v === 'false' ) );
				}
			}

			return( '' );
		}

		// 2. VAT identifier or tax number: the scheme attribute ( CII "VA" / "FC" ) or the tax scheme ( UBL "VAT" ).
		if ( preg_match( '#(PartyTaxScheme|SpecifiedTaxRegistration)#', $key ) )
		{
			$owner = self::ancestor( $report, $index, '/^(PartyTaxScheme|SpecifiedTaxRegistration)$/' );

			if ( $owner === null )
				return( '' );

			foreach ( self::subtree( $report, $owner ) as $k )
			{
				$row  = $report->document[ $k ];
				$name = self::local_name( (string) $row['name'] );

				if ( $name === 'ID' && preg_match( '/(^|\s|·)(VA|FC)(\s|·|$)/', ' ' . (string) $row['attr'] . ' ', $m ) )
					return( $pick( $m[2] === 'VA' ) );

				if ( $name === 'ID' && strpos( (string) $row['path'], 'TaxScheme' ) !== false && trim( (string) $row['value'] ) !== '' )
					return( $pick( strtoupper( trim( (string) $row['value'] ) ) === 'VAT' ) );
			}

			return( '' );
		}

		// 3. VAT total in invoice currency or in accounting currency: the currency against the document currency.
		if ( preg_match( '#(TaxTotal/TaxAmount|TaxTotalAmount)$#', $key ) )
		{
			$currency = '';

			foreach ( $report->document as $row )
				if ( in_array( self::local_name( (string) $row['name'] ), array( 'DocumentCurrencyCode', 'InvoiceCurrencyCode' ), true ) )
				{
					$currency = strtoupper( trim( (string) $row['value'] ) );
					break;
				}

			$attr = strtoupper( trim( (string) $report->document[ $index ]['attr'] ) );

			if ( $currency === '' || $attr === '' )
				return( $pick( true ) );

			return( $pick( strpos( $attr, $currency ) !== false ) );
		}

		// 4. A referenced document: its type code. 50 is the tender or lot ( BT-17 ), 130 the invoiced object
		//    ( BT-18 ), anything else a supporting document ( BT-122, group BG-24 ).
		if ( preg_match( '#(AdditionalDocumentReference|AdditionalReferencedDocument)#', $key ) )
		{
			$owner = self::ancestor( $report, $index, '/^(AdditionalDocumentReference|AdditionalReferencedDocument)$/' );

			if ( $owner === null )
				return( '' );

			$code = '';

			foreach ( self::subtree( $report, $owner ) as $k )
				if ( in_array( self::local_name( (string) $report->document[ $k ]['name'] ), array( 'TypeCode', 'DocumentTypeCode' ), true ) )
				{
					$code = trim( (string) $report->document[ $k ]['value'] );
					break;
				}

			$want = $code === '50' ? 'BT-17' : ( $code === '130' ? 'BT-18' : 'BT-122' );

			foreach ( $ids as $id )
				if ( $base( $id ) === $want )
					return( $id );

			// The element itself in CII: BG-24 stands for the supporting document.
			return( $want === 'BT-122' && in_array( 'BG-24', $ids, true ) ? 'BG-24' : '' );
		}

		return( '' );
	}


	/**
	 * The row itself or the nearest row above it whose local name matches, walking up the levels.
	 *
	 * @param	Report	$report
	 * @param	int		$index
	 * @param	string	$pattern	a regular expression for the local name
	 * @return	int|null
	 * @since	1.16.0
	 */

	private static function ancestor( Report $report, int $index, string $pattern )
	{
		$level = PHP_INT_MAX;

		for ( $k = $index; $k >= 0 && isset( $report->document[ $k ] ); $k-- )
		{
			$l = (int) $report->document[ $k ]['level'];

			if ( $k !== $index && $l >= $level )
				continue;

			$level = $l;

			if ( preg_match( $pattern, self::local_name( (string) $report->document[ $k ]['name'] ) ) )
				return( $k );
		}

		return( null );
	}


	/**
	 * The local name of an element, without its prefix ( `cbc:ID` → `ID` ).
	 *
	 * ⚠️ A COPY OF `Report::local_part()`, WHICH IS PRIVATE THERE. Making that one public would change the class every
	 * plugin vendors; a second copy of five characters of regular expression is the smaller risk.
	 *
	 * @param	string	$name
	 * @return	string
	 * @since	1.16.0
	 */

	private static function local_name( $name ): string
	{
		return( (string) preg_replace( '/^.*:/', '', trim( (string) $name ) ) );
	}


	/**
	 * The rows below a row, up to the next row on its own level or above.
	 *
	 * @param	Report	$report
	 * @param	int		$index
	 * @return	array<int,int>
	 * @since	1.16.0
	 */

	private static function subtree( Report $report, int $index ): array
	{
		$level = (int) $report->document[ $index ]['level'];
		$out   = array();

		for ( $k = $index + 1; isset( $report->document[ $k ] ) && (int) $report->document[ $k ]['level'] > $level; $k++ )
			$out[] = $k;

		return( $out );
	}


	/**
	 * A path without prefixes, without indices and without its root element.
	 *
	 * @param	string	$path
	 * @return	string	'' for the root itself
	 * @since	1.14.0
	 */

	private static function generic_path( string $path ): string
	{
		$p    = (string) preg_replace( '/\[[0-9]+\]/', '', Report::normalise_path( $path ) );
		$segs = explode( '/', ltrim( $p, '/' ) );

		array_shift( $segs );

		return( implode( '/', $segs ) );
	}


	/**
	 * How many elements sit directly below a row.
	 *
	 * @param	Report	$report
	 * @param	int		$index
	 * @return	int
	 * @since	1.14.0
	 */

	private static function children( Report $report, int $index ): int
	{
		$level = (int) ( $report->document[ $index ]['level'] ?? 0 );
		$n     = 0;

		for ( $k = $index + 1; isset( $report->document[ $k ] ) && (int) $report->document[ $k ]['level'] > $level; $k++ )
			if ( (int) $report->document[ $k ]['level'] === $level + 1 )
				$n++;

		return( $n );
	}


	/**
	 * The value of one field for a finding: found near the finding's own row, formatted for the view.
	 *
	 * ⚠️ **ONE ROW GIVES ITS VALUE; SEVERAL GIVE THE SUM OR NOTHING.** Where the rule text puts "Σ" in front
	 * of the field ( "= Σ Invoice line net amount (BT-131)" ), the values of all rows are added up. Without
	 * "Σ" several rows mean the field cannot be pinned to one value, and none is shown: a single line's
	 * value next to "Σ" read as the sum until 23.10.2026 ( see Report::field_rows() ).
	 *
	 * @param	string		$bt
	 * @param	Report		$report
	 * @param	int|null	$anchor
	 * @param	bool		$sum		the rule text puts "Σ" in front of the field
	 * @return	array		array( value => shown text, raw => the document's text ); both '' when not found
	 * @since	1.14.0
	 */

	private function field_value( string $bt, Report $report, $anchor, bool $sum = false ): array
	{
		$xpath = $this->terms->fieldXpath( $bt, (string) ( $report->meta['syntax'] ?? '' ) );
		$rows  = $xpath !== '' ? $report->field_rows( $xpath, $anchor ) : array();
		$vals  = array();

		foreach ( $rows as $j )
			$vals[] = (string) ( $report->document[ $j ]['value'] ?? '' );

		$raw = '';

		if ( count( $vals ) === 1 )
			$raw = $vals[0];
		elseif ( count( $vals ) > 1 && $sum )
			$raw = self::sum_amounts( $vals );

		return( $this->shown_value( $bt, $raw ) );
	}


	/**
	 * A raw value as the view shows it, or empty - twin of `shownValue()` in `Renderer.mjs`.
	 *
	 * @param	string	$bt
	 * @param	string	$raw
	 * @return	array	array( value => shown text, raw => the raw text ); both '' when raw is ''
	 * @since	1.18.0
	 */

	private function shown_value( string $bt, string $raw ): array
	{
		if ( $raw === '' )
			return( array( 'value' => '', 'raw' => '' ) );

		$value = $this->format_value( $bt, $raw );

		if ( $value === $raw )
			$value = function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $raw, 0, 60, '…' ) : substr( $raw, 0, 60 );

		return( array( 'value' => $value, 'raw' => $raw ) );
	}


	/**
	 * The sum of decimal strings, as a decimal string - twin of `sumAmounts()` in `Renderer.mjs`.
	 *
	 * ⚠️ ON THE STRINGS, NEVER THROUGH A FLOAT, for the reason at format_amount(). Every value is scaled to
	 * the most decimals any of them has and added as an integer. A value that is no plain decimal makes the
	 * whole sum '', because a sum that skipped a line would be wrong in exactly the way this fixes.
	 *
	 * @param	string[]	$values
	 * @return	string
	 * @since	1.17.1
	 */

	private static function sum_amounts( array $values ): string
	{
		$parts = array();

		foreach ( $values as $v )
		{
			if ( ! preg_match( '/^\s*([+-]?)([0-9]+)(?:\.([0-9]*))?\s*$/', (string) $v, $m ) )
				return( '' );

			$parts[] = $m;
		}

		$d = 0;

		foreach ( $parts as $m )
			$d = max( $d, strlen( $m[3] ?? '' ) );

		$total = 0;

		foreach ( $parts as $m )
			$total += ( $m[1] === '-' ? -1 : 1 ) * (int) ( $m[2] . str_pad( $m[3] ?? '', $d, '0' ) );

		$neg    = $total < 0;
		$digits = str_pad( (string) abs( $total ), $d + 1, '0', STR_PAD_LEFT );

		return( ( $neg ? '-' : '' ) . ( $d ? substr( $digits, 0, -$d ) . '.' . substr( $digits, -$d ) : $digits ) );
	}


	/**
	 * The fields the text puts "Σ" in front of - twin of `sumMarked()` in `Renderer.mjs`.
	 *
	 * ⚠️ **"IN FRONT" MEANS IN THE SAME TERM.** The text before a reference is cut at the last "=", "+",
	 * " - ", " – ", "," or ";", and only that piece is searched for "Σ". "BT-110 = Σ VAT category tax amount
	 * (BT-117)" sums BT-117 but not BT-110. A bare hyphen does not cut, because rule ids like "BR-CO-10"
	 * carry hyphens. The offsets are bytes here and UTF-16 units in JavaScript; both cut at the same
	 * characters, so the pieces are the same text.
	 *
	 * @param	string	$text
	 * @return	string[]
	 * @since	1.17.1
	 */

	private static function sum_marked( string $text ): array
	{
		$out = array();

		preg_match_all( self::BT_REF, $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		foreach ( $mm as $m )
		{
			$before = substr( $text, 0, $m[0][1] );
			$cut    = 0;

			foreach ( array( '=', '+', ' - ', ' – ', ',', ';' ) as $s )
			{
				$i = strrpos( $before, $s );

				if ( $i !== false )
					$cut = max( $cut, $i + strlen( $s ) );
			}

			if ( strpos( substr( $before, $cut ), 'Σ' ) !== false )
				$out[] = self::ref_id( array( $m[0][0], $m[1][0] ?? '', $m[2][0] ?? '' ) );
		}

		return( array_values( array_unique( $out ) ) );
	}


	/**
	 * Everything the view says about one finding, as data - the twin of `explainOneFinding()` in
	 * `Renderer.mjs`.
	 *
	 * ⚠️ ONE COMPUTATION FOR THE HTML AND FOR THE PARITY RUN. `test/dump-php-explainer.php` in
	 * `libs/einvoice-core-js` reads explain(), not a recomputation of its own; a recomputation would let
	 * a change to the renderer pass the comparison unseen.
	 *
	 * The keys:
	 *
	 *   text         the one text that stands as the finding's statement: the explanation when there is
	 *                one, otherwise the validator's message
	 *   inline       the fields the text names, in text order, with their values
	 *   btList       the rule's further fields, which the text does not name ( at most eight )
	 *   more         whether the rule names more further fields than that
	 *   showMessage  whether the validator's wording differs from the explanation and is shown below it
	 *
	 * @param	array	$finding
	 * @param	Report	$report
	 * @return	array
	 * @since	1.14.0
	 */

	private function explain_finding( array $finding, Report $report ): array
	{
		$rule    = (string) ( $finding['rule'] ?? '' );
		$known   = $rule !== '' ? $this->terms->rule( $rule ) : array();
		$fields  = array_values( array_map( 'strval', (array) ( $known['fields'] ?? array() ) ) );
		$anchor  = ( $finding['rows'] ?? array() )[0] ?? null;
		$message = (string) ( $finding['message'] ?? '' );

		$explanation = $this->terms->text( $known['description'] ?? '' );
		$text        = $explanation !== '' ? $explanation : $message;

		preg_match_all( self::BT_REF, $text, $mm, PREG_SET_ORDER );

		$mentioned = array_values( array_unique( array_map( static function ( $m ) { return( self::ref_id( $m ) ); }, $mm ) ) );
		$summed    = self::sum_marked( $text );
		$inline    = array();

		/*
		 * ⚠️ **A VALUE FROM THE RULE WINS OVER THE VALUE FROM THE TEXT.** `RuleValues::attach()` evaluated the rule
		 * itself and stored on the finding what the validator compared; the reasons stand at the same block in
		 * `explainOneFinding()` of `Renderer.mjs`. A field the rule reads but neither the text nor the catalogue
		 * entry names joins the short list.
		 */
		$rv        = $finding['ruleValues'] ?? null;
		$from_rule = is_array( $rv ) && ( $rv['source'] ?? '' ) === 'rule' && is_array( $rv['values'] ?? null ) ? $rv['values'] : array();

		foreach ( $mentioned as $bt )
			$inline[] = array( 'bt' => $bt ) + ( isset( $from_rule[ $bt ] )
				? $this->shown_value( $bt, (string) $from_rule[ $bt ] )
				: $this->field_value( $bt, $report, $anchor, in_array( $bt, $summed, true ) ) );

		$extra  = array_values( array_filter( array_map( 'strval', array_keys( $from_rule ) ), static function ( $bt ) use ( $fields ) { return( ! in_array( $bt, $fields, true ) ); } ) );
		$rest   = array_values( array_filter( array_merge( $fields, $extra ), static function ( $bt ) use ( $mentioned ) { return( ! in_array( $bt, $mentioned, true ) ); } ) );
		$btList = array();

		foreach ( array_slice( $rest, 0, 8 ) as $bt )
			$btList[] = array( 'bt' => $bt ) + ( isset( $from_rule[ $bt ] ) ? $this->shown_value( $bt, (string) $from_rule[ $bt ] ) : $this->field_value( $bt, $report, $anchor ) );

		$labels = array();

		foreach ( $report->attachments_for( $finding, $fields ) as $a )
			if ( (string) $a['label'] !== '' )
				$labels[] = (string) $a['label'];

		$explanationLanguage = $this->terms->textLanguage( $known['description'] ?? '', $this->terms->language() );

		return( array(
			'rule'                => $rule,
			'severity'            => (string) ( $finding['severity'] ?? '' ),
			'fields'              => $fields,
			'explanation'         => $explanation,
			'explanationLanguage' => $explanationLanguage,
			// ⚠️ Right after `explanationLanguage`, in the same place as in `Renderer.mjs`: the parity run
			// compares the JSON text, and a key in another position counts as a difference.
			'machineTranslation'  => $explanation !== '' && self::machine_translated( $known['translation'] ?? null, $explanationLanguage ),
			'text'                => $text,
			'inline'              => $inline,
			'btList'              => $btList,
			'more'                => count( $rest ) > 8,
			'attachments'         => $labels,
			'showMessage'         => $explanation !== '' && $message !== '' && ! self::says_the_same( $message, $explanation ),
			// ⚠️ Last, in the same place as in `Renderer.mjs`; only for the parity run and for debugging.
			'valueSource'         => $from_rule ? 'rule' : 'text',
		) );
	}


	/**
	 * Whether the rule text shown is a machine translation that nobody has reviewed yet.
	 *
	 * ⚠️ WHY THE VIEW HAS TO SAY IT. The German rule packages carry machine translations
	 * of EN 16931, Factur-X and Peppol, each row marked `translation: { source: "machine", reviewed:
	 * false }`. Without a marker such a sentence reads like the wording of the official source, and a
	 * mistranslated rule would be taken for the rule itself. The marker stands below the text, in the
	 * same place and style as the "not translated" notice, and disappears once a review sets
	 * `reviewed` to true.
	 *
	 * ⚠️ TWO SHAPES, AND THE LANGUAGE DETERMINES WHICH MARK COUNTS.
	 *
	 *   flat           `array( 'source' => 'machine', 'reviewed' => false )` - a package row, whose text is
	 *                  already in one language ( the JavaScript adapter `rulesFromPackages()` )
	 *   per language   `array( 'de' => array( 'source' => …, 'reviewed' => … ) )` - next to a { de, en, fr }
	 *                  block, as the Rules classes of the plugins rebuild it from the add-on's packages
	 *
	 * In the second shape only the mark of the language the text was actually taken from counts. A
	 * German mark must not label the English fallback text, which is no translation at all.
	 *
	 * ⚠️ `reviewed !== true`, NOT `empty( reviewed )` THE OTHER WAY ROUND: only an explicit true removes the
	 * marker. A missing or malformed flag keeps it, because claiming a review that did not happen is the
	 * worse error. The twin `machineTranslated()` in `Renderer.mjs` answers the same way.
	 *
	 * ⚠️ NOT A METHOD OF `Terms`. The mark comes in through rule(), as an optional key next to
	 * `description`. A new interface method would make every plugin that implements `Terms` fatal as soon
	 * as its copy of this package is updated - the same reason fieldIds() is asked for with method_exists().
	 *
	 * @param	mixed	$translation	the rule's `translation`, or null
	 * @param	string	$textLang		what textLanguage() answered - '' for a plain string
	 * @return	bool
	 * @since	09.10.2026
	 */

	private static function machine_translated( $translation, string $textLang ): bool
	{
		if ( ! is_array( $translation ) )
			return( false );

		$mark = isset( $translation['source'] ) && is_string( $translation['source'] )
			? $translation
			: ( $textLang !== '' ? ( $translation[ $textLang ] ?? null ) : null );

		return( is_array( $mark ) && ( $mark['source'] ?? '' ) === 'machine' && ( $mark['reviewed'] ?? false ) !== true );
	}


	/**
	 * Whether the validator's message says nothing the explanation does not already say.
	 *
	 * ⚠️ NOT ONLY "THE SAME TEXT". The explanations of the extension rules carry the validator's message
	 * plus a prefix of their own; measured on the report X10 with the add-on, rule BR-FXEXT-E-08rev: the
	 * explanation begins "[BR-FXEXT-E-08rev] - warning - Code : , rate : , …" and then repeats the
	 * message almost word for word. A plain comparison showed the rule a second time under "The
	 * validator's wording", which is a duplication. The message
	 * counts as said when at least four fifths of its words occur in the explanation. A German
	 * explanation of an English message shares few words, and the original stays visible there.
	 *
	 * ⚠️ THE TWIN `saysTheSame()` IN `Renderer.mjs` USES THE SAME WORD SPLIT AND THE SAME RATIO, in
	 * integers, so no rounding can tip one side and not the other.
	 *
	 * @param	string	$message
	 * @param	string	$explanation
	 * @return	bool
	 * @since	1.14.0
	 */

	private static function says_the_same( string $message, string $explanation ): bool
	{
		if ( self::same_text( $message ) === self::same_text( $explanation ) )
			return( true );

		$words = static function ( string $s ): array {
			$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
			return( array_values( array_unique( array_filter( (array) preg_split( '/[^\p{L}\p{N}]+/u', $s ), 'strlen' ) ) ) );
		};

		$said = $words( $message );

		if ( ! $said )
			return( true );

		$known  = array_flip( $words( $explanation ) );
		$common = 0;

		foreach ( $said as $w )
			if ( isset( $known[ $w ] ) )
				$common++;

		return( $common * 5 >= count( $said ) * 4 );
	}


	/**
	 * A text reduced to what a comparison should see: lower case, single spaces, no closing full stop.
	 *
	 * @param	string	$s
	 * @return	string
	 * @since	1.14.0
	 */

	private static function same_text( string $s ): string
	{
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );

		return( rtrim( trim( (string) preg_replace( '/\s+/u', ' ', $s ) ), '.' ) );
	}


	/**
	 * The structured data of every finding - for the parity run against `Renderer.explainFindings()`.
	 *
	 * ⚠️ IT MARKS THE REFERENCED ROWS FIRST, like render(). The row statuses are part of what the
	 * comparison reads; JavaScript calls `Renderer.markReferencedRows()` before `explainFindings()` for
	 * the same reason. Marking twice changes nothing.
	 *
	 * @param	Report	$report
	 * @return	array
	 * @since	1.14.0
	 */

	public function explain( Report $report ): array
	{
		$this->rowTerms = null;

		$this->mark_referenced_rows( $report );

		$out = array();

		foreach ( $report->findings as $finding )
			$out[] = $this->explain_finding( $finding, $report );

		return( $out );
	}


	/**
	 * The findings.
	 *
	 * @param	Report	$report
	 * @return	string
	 * @since	1.4.0
	 */

	private function findings( Report $report ): string
	{
		if ( ! $report->findings )
			return( '' );

		$out = '<div class="panel spx-section">'
			. '<div class="panel-head"><h2>' . $this->esc( $this->s( 'findings_title' ) ) . '</h2>'
			. '<span>' . $this->esc( sprintf(
				$this->s( 'findings_count' ),
				$report->count( 'error' ),
				$report->count( 'warning' )
			) ) . '</span></div>';

		/*
		 * ⚠️ A HOUSE RULE MUST NEVER LOOK LIKE A RULE OF THE STANDARD. VAMPIRE writes the findings of a
		 * rule profile into a step of their own, `profile:<name>`, and FieldPlacement reads that back. The view
		 * "Invoice" marks them; without this, the view "Messages" would show a house rule like a violation of
		 * EN 16931 ( measured on 13.10.2026 with the sample profile `muster-ust` in the Validator for VAMPIRE PHP ).
		 *
		 * ⚠️ LOADED BY HAND WHEN NO AUTOLOADER KNOWS IT. The explorers require the files of this library one by one,
		 * and a plugin's composer class map may predate FieldPlacement; a missing class here would break every
		 * report, not only the ones with a profile.
		 */
		if ( ! class_exists( FieldPlacement::class ) && is_file( __DIR__ . '/FieldPlacement.php' ) )
			require_once( __DIR__ . '/FieldPlacement.php' );

		$profiles = class_exists( FieldPlacement::class ) ? FieldPlacement::profiles( $report ) : array();

		foreach ( $report->findings as $index => $finding )
			$out .= $this->finding( $finding, $index, $report, (string) ( $profiles[ $index ] ?? '' ) );

		return( $out . '</div>' );
	}


	/**
	 * One finding.
	 *
	 * @param	array	$finding
	 * @param	int		$index
	 * @param	Report	$report
	 * @param	string	$profile	the rule profile the finding comes from, '' for a rule of a standard
	 * @return	string
	 * @since	1.4.0
	 */

	private function finding( array $finding, $index, Report $report, string $profile = '' ): string
	{
		$severity = (string) ( $finding['severity'] ?? 'information' );
		$rule     = (string) ( $finding['rule'] ?? '' );
		$message  = (string) ( $finding['message'] ?? '' );
		$e        = $this->explain_finding( $finding, $report );

		$class = ( $severity === 'error' ) ? 'error' : ( $severity === 'warning' ? 'warn' : 'info' );

		/**
		 * ⚠️ THE TINT COMES FROM THE KIT'S SIGNAL EDGES ( `edge-err`, `edge-warn` ), NOT FROM NEW COLOURS.
		 * An error gets a reddish, a warning a yellowish background, a hint stays neutral. The kit already has the light tint plus the 3 px edge for exactly this.
		 * `spx-explainer.css` keeps the tint on hover, where the kit's `.finding:hover` would turn it grey.
		 */

		$edge = ( $severity === 'error' ) ? ' edge-err' : ( $severity === 'warning' ? ' edge-warn' : '' );

		$label = ( $severity === 'error' )
			? $this->s( 'severity_error' )
			: ( $severity === 'warning'
				? $this->s( 'severity_warning' )
				: $this->s( 'severity_information' ) );

		$values = array();

		foreach ( $e['inline'] as $f )
			$values[ $f['bt'] ] = $f;

		$out = '<div class="finding static' . $edge . '" id="spx-f' . (int) $index . '">'
			. '<div class="finding-head">'
			. '<span class="finding-rule">' . $this->esc( $rule !== '' ? $rule : $this->s( 'no_rule_id' ) ) . '</span>'
			. '<span class="sev ' . $this->escAttr( $class ) . '">' . $this->esc( $label ) . '</span>'
			. ( $profile !== '' ? '<span class="badge mute finding-origin">' . $this->esc( sprintf( $this->s( 'origin_profile' ), $profile ) ) . '</span>' : '' )
			. '</div>';

		/**
		 * ⚠️ THE RULE STANDS ONCE. The explanation is the statement, with every field it names marked and
		 * its value behind it. Showing the validator's message as the title and the explanation below it
		 * would repeat the same rule, because for most rules of EN 16931 the two texts are identical.
		 *
		 * ⚠️ WITHOUT AN EXPLANATION THE MESSAGE IS NOT A HEADING. A raw validator message can run over
		 * nine lines; set in the heading face it reads like a title. It stands as body text
		 * ( `finding-message` ), and the sentence below says that it is the validator's own wording.
		 */

		if ( $e['explanation'] !== '' )
		{
			$out .= '<div class="finding-title">' . $this->mark_text( $e['explanation'], $values ) . '</div>';

			/**
			 * Show which language the text actually is when it is not the site's
			 * own - a silent de->en->fr fallback would present an English sentence as if it were a
			 * translation. The public catalogue pages treat this data the same way.
			 */

			$resolvedLang = $e['explanationLanguage'];

			if ( $resolvedLang !== '' && $resolvedLang !== $this->terms->language() )
			{
				$langNames = array(
					'de' => $this->s( 'lang_de' ),
					'en' => $this->s( 'lang_en' ),
					'fr' => $this->s( 'lang_fr' ),
				);

				$out .= '<div class="finding-explain-notice">' . $this->esc( sprintf(
					$this->s( 'no_translation' ),
					$langNames[ $resolvedLang ] ?? $resolvedLang
				) ) . '</div>';
			}

			/**
			 * A machine translation nobody has reviewed says so, below the text - see
			 * machine_translated(). ⚠️ The class `finding-explain-notice` gives it the look of the notice
			 * above; `finding-machine-translation` only names it, has no style of its own, and is the same
			 * on the JavaScript side, because the review compares the class sets of both outputs.
			 */

			if ( $e['machineTranslation'] )
				$out .= '<div class="finding-explain-notice finding-machine-translation">' . $this->esc( $this->s( 'machine_translation' ) ) . '</div>';
		}
		elseif ( $message !== '' )
			$out .= '<div class="finding-message">' . $this->mark_text( $message, $values ) . '</div>';

		/**
		 * The rule's further fields, which its text does not name - a short list.
		 *
		 * ⚠️ NO VALUE SHOWN IS NOT AN ERROR STATE HERE. Unlike values() below - which is about the
		 * finding's OWN location as the validator names it, and so treats "no row" as "not present in the
		 * document" - this is a field the rule merely NAMES. It may share a path with a sibling field
		 * ( BT-31/BT-32, see resolve_field()'s docblock ) and resolve ambiguously, or simply not apply to
		 * this document. Silence, not a "missing" badge, is the honest statement in both cases.
		 */

		if ( $e['btList'] )
		{
			$out .= '<div class="finding-bt-list">';

			foreach ( $e['btList'] as $f )
			{
				$out .= $this->badge( $f['bt'], 'finding-bt' );

				if ( $f['value'] !== '' )
					$out .= $this->value_span( $f['value'], $f['raw'], ' finding-bt-value' );
			}

			if ( $e['more'] )
				$out .= '<span class="faint">…</span>';

			$out .= '</div>';
		}

		$out .= $this->attachment_names( $e['attachments'] );

		$out .= $this->values( $finding, $report );

		if ( $e['explanation'] === '' && $rule !== '' )
			$out .= '<div class="finding-explain"><em>'
				. $this->esc( $this->s( 'no_explanation' ) )
				. '</em></div>';

		// The validator's own wording, when it says something else than the explanation above.
		if ( $e['showMessage'] )
			$out .= '<div class="finding-said">'
				. '<div class="finding-original-label">' . $this->esc( $this->s( 'validator_message' ) ) . '</div>'
				. $this->mark_text( $message )
				. '</div>';

		$path = (string) ( $finding['path'] ?? '' );

		if ( $path !== '' )
			$out .= '<div class="finding-original">'
				. '<div class="finding-original-label">' . $this->esc( $this->s( 'location_label' ) ) . '</div>'
				. $this->wbr_slashes( $path )
				. '</div>';

		return( $out . '</div>' );
	}


	/**
	 * The attachments a finding is about, by name.
	 *
	 * ⚠️ A FINDING ON BG-24 WITHOUT A NAME IS USELESS TO THE READER. "BR-DE-22: file names must be
	 * unique" or a type-code error on `AdditionalReferencedDocument[3]` says which rule broke, not which
	 * of several attachments to look at. `Report::attachments_for()` determines which attachments a finding
	 * concerns ( see explain_finding() ); the label is the file name, else the description, else the
	 * reference.
	 *
	 * ⚠️ THE SAME CLASSES AS values() BELOW - `finding-values`, `finding-value-row`, `finding-value-name`,
	 * `doc-elem-val`. A class of its own would need a rule in `spx-explainer.css` and in the kit, and a
	 * copy without it would show the name as unformatted text.
	 *
	 * @param	array	$labels
	 * @return	string
	 * @since	1.12.0
	 */

	private function attachment_names( array $labels ): string
	{
		$rows = '';

		foreach ( array_slice( $labels, 0, 12 ) as $label )
			$rows .= '<div class="finding-value-row">'
				. '<span class="finding-value-name">' . $this->esc( $this->s( 'attachment' ) ) . '</span>'
				. '<span class="doc-elem-val">' . $this->esc( (string) $label ) . '</span>'
				. '</div>';

		return( $rows !== '' ? '<div class="finding-values">' . $rows . '</div>' : '' );
	}


	/**
	 * The affected fields with their actual value - the point of the whole exercise.
	 *
	 * ⚠️ THE ROW IS NAMED BY ITS BUSINESS TERM WHERE ONE IS KNOWN, AND A GROUP IS NOT "EMPTY". Named by the
	 * element name of the XML, a row would read "ApplicableTradeTax | empty | position 0082", and "empty" would
	 * stand for an element that holds the VAT breakdown. The row reads "BG-23 VAT BREAKDOWN | group with
	 * 6 sub-elements | position 0082", with the element name in the tooltip. "empty" stays for an element
	 * that really has neither a value nor children.
	 *
	 * @param	array	$finding
	 * @param	Report	$report
	 * @return	string
	 * @since	1.4.0
	 */

	private function values( array $finding, Report $report ): string
	{
		$rows = array_values( (array) ( $finding['rows'] ?? array() ) );

		/**
		 * ⚠️ A FINDING WITHOUT A LOCATION IS NOT A FAILED LOOKUP. XSD errors ( cvc-* ) name no node -
		 * they point at the schema. 28 of 126 findings in the examples are of that kind. Showing an
		 * empty value box for them would suggest the field exists and is blank, which is a different
		 * and wrong statement.
		 */

		if ( ! $rows )
		{
			if ( (string) ( $finding['path'] ?? '' ) === '' )
				return( '' );

			return( '<div class="finding-values"><div class="finding-value-row">'
				. '<span class="finding-value-name">' . $this->esc( $this->s( 'affected_field' ) ) . '</span>'
				. '<span class="doc-elem-val is-missing">' . $this->esc( $this->s( 'not_present' ) ) . '</span>'
				. '</div></div>' );
		}

		$terms = $this->row_terms( $report );
		$out   = '<div class="finding-values">';

		foreach ( array_slice( $rows, 0, 12 ) as $i )
		{
			$row = $report->document[ $i ] ?? null;

			if ( ! $row )
				continue;

			$name  = (string) $row['name'];
			$value = (string) $row['value'];
			$bt    = $terms[ $i ] ?? '';

			if ( $bt !== '' )
			{
				$term = $this->term_label( $bt );

				$out .= '<div class="finding-value-row">'
					. '<span class="finding-value-name" title="' . $this->escAttr( $name ) . '">'
					. $this->badge( $bt, 'badge code finding-bt-inline' ) . ' ' . $this->esc( $term !== '' ? $term : $name )
					. '</span>';
			}
			else
				$out .= '<div class="finding-value-row">'
					. '<span class="finding-value-name">' . $this->esc( $name ) . '</span>';

			/**
			 * ⚠️ TRUNCATE LONG VALUES, BUT SAY BY HOW MUCH. A free-text description can be several
			 * hundred characters and would push the card apart. Cutting silently would make the reader
			 * believe that is the whole value.
			 */

			$children = $value === '' ? self::children( $report, (int) $i ) : 0;

			/*
			 * A group ( a party, a line ) has no value of its own, only fields below it. It shows its name and nothing
			 * beside it: "empty" would read as a missing value, and the count of sub-elements that stood here until
			 * 27.10.2026 told the reader nothing he could act on ( Ralf: "den Hinweis mit den Unterelementen bitte weglassen" ).
			 */
			if ( $value === '' )
			{
				if ( $children === 0 )
					$out .= '<span class="doc-elem-val is-missing">' . $this->esc( $this->s( 'empty_value' ) ) . '</span>';
			}
			elseif ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > 100 )
				$out .= '<span class="doc-elem-val">' . $this->esc( mb_substr( $value, 0, 100 ) ) . '…</span>'
					. '<span class="faint">' . $this->esc( sprintf(
						$this->s( 'chars_total' ),
						mb_strlen( $value )
					) ) . '</span>';
			else
				$out .= $this->value_span( $this->format_value( $bt, $value ), $value );

			$out .= '<a class="finding-value-jump" href="#spx-r' . (int) $i . '">'
				. $this->esc( sprintf(
					$this->s( 'position' ),
					$row['pos']
				) ) . '</a>'
				. '</div>';
		}

		if ( count( $rows ) > 12 )
			$out .= '<div class="finding-value-row"><span class="faint">' . $this->esc( sprintf(
				$this->s( 'further_positions' ),
				count( $rows ) - 12
			) ) . '</span></div>';

		return( $out . '</div>' );
	}


	/**
	 * The document.
	 *
	 * @param	Report	$report
	 * @return	string
	 * @since	1.4.0
	 */

	private function document( Report $report ): string
	{
		if ( ! $report->document )
			return( '' );

		// Which finding sits on which row - so the rule can be repeated in place.
		$notes = array();

		foreach ( $report->findings as $finding )
			foreach ( (array) ( $finding['rows'] ?? array() ) as $i )
				if ( ! isset( $notes[ $i ] ) )
					$notes[ $i ] = $finding;

		$out = '<div class="panel">'
			. '<div class="panel-head"><h2>' . $this->esc( $this->s( 'document_title' ) ) . '</h2>'
			. '<span>' . $this->esc( sprintf(
				$this->s( 'elements_count' ),
				count( $report->document )
			) ) . '</span></div>'
			. '<div class="panel-search spx-doc-tools">'
			. '<input type="text" class="inp spx-doc-search" placeholder="' . $this->escAttr( $this->s( 'search_placeholder' ) ) . '">'
			. '<div class="doc-toggle"><label><input type="checkbox" class="spx-toggle-bt" checked> ' . $this->esc( $this->s( 'toggle_bt' ) ) . '</label></div>'
			. '<div class="doc-toggle"><label><input type="checkbox" class="spx-toggle-err"> ' . $this->esc( $this->s( 'toggle_findings' ) ) . '</label></div>'
			. '</div>'
			. '<div class="doc-body">';

		$terms = $this->row_terms( $report );

		foreach ( $report->document as $i => $row )
			$out .= $this->row( $row, $i, $notes[ $i ] ?? null, $terms[ $i ] ?? '' );

		return( $out . '</div></div>' );
	}


	/**
	 * One document row: position, indented name, BT badge, value.
	 *
	 * @param	array		$row
	 * @param	int			$index
	 * @param	array|null	$note
	 * @param	string		$bt		the business term of the row, '' when none is known ( row_terms() )
	 * @return	string
	 * @since	1.4.0
	 */

	/** The order of the row levels: the stronger statement wins on a row. */
	const STATUS_RANK = array( '' => 0, 'info' => 1, 'warning' => 2, 'error' => 3 );


	/**
	 * The level of a finding as the status of a row: `error`, `warning` or `info`.
	 *
	 * @param	string	$severity	`error`, `warning`, `information` ( or `info` )
	 * @return	string
	 */

	private static function row_status( string $severity ): string
	{
		return( $severity === 'error' ? 'error' : ( $severity === 'warning' ? 'warning' : 'info' ) );
	}


	private function row( array $row, $index, $note, string $bt = '' ): string
	{
		$status = (string) ( $row['status'] ?? '' );
		$level  = max( 1, min( 8, (int) $row['level'] ) );

		$class = 'tree-row doc-row'
			. ( $status === 'error' ? ' error-row edge-err' : ( $status === 'warning' ? ' warn-row edge-warn' : ( $status === 'info' ? ' info-row edge-info' : '' ) ) );

		$out = '<div class="' . $class . '" id="spx-r' . (int) $index . '">'
			. '<div class="tree-pos">' . $this->esc( (string) $row['pos'] ) . '</div>'
			. '<div class="row mono spx-element lvl-' . ( $level - 1 ) . '">'
			. '<span class="doc-elem-name muted">' . $this->esc( (string) $row['name'] ) . '</span>';

		/**
		 * Looking the BT number up by ELEMENT NAME is NOT possible - the same local name exists in
		 * several places. It comes from the full path of the row ( row_terms() ) and is left out when the
		 * path is not unique to one field. Guessing here would put a wrong business term on a right value.
		 *
		 * ⚠️ THE MARK MUST REALLY BE WRITTEN. Without it the switch "BT numbers" hides nothing and the
		 * search for "BT-1" finds no row; the probe of the view counts the marks for that reason.
		 */

		if ( $bt !== '' )
			$out .= $this->badge( $bt, 'badge code doc-elem-bt' );

		$value = (string) $row['value'];

		if ( $value !== '' )
		{
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > 100 )
				$out .= '<span class="doc-elem-val">' . $this->esc( mb_substr( $value, 0, 100 ) ) . '…</span>'
					. '<span class="faint">' . $this->esc( sprintf(
						$this->s( 'chars' ),
						mb_strlen( $value )
					) ) . '</span>';
			else
				$out .= '<span class="doc-elem-val">' . $this->esc( $value ) . '</span>';
		}

		if ( (string) $row['attr'] !== '' )
			$out .= '<span class="badge code">' . $this->esc( (string) $row['attr'] ) . '</span>';

		$out .= '</div>';

		if ( $note )
		{
			/*
			 * ⚠️ ONE NOTE KIND PER LEVEL; an information got the red error note until 19.10.2026. The note is the kit's
			 * `.note` with `.note-mark` for the rule. ⚠️ THE RULE STANDS IN AN INNER SPAN: the kit fills
			 * `.note-mark` with the text colour and sets only `.note-mark span` in the surface colour; without the inner span the
			 * mark was a red block without readable text ( screenshot of 19.10.2026 ). ⚠️ THE MESSAGE IN A SPAN OF ITS OWN: the kit lays `.note` out as a
			 * flex row, and the badges inside the message would otherwise each become a column of that row.
			 */
			$kind = array( 'error' => 'err', 'warning' => 'warn', 'info' => 'info' )[ self::row_status( (string) ( $note['severity'] ?? '' ) ) ];

			$out .= '<div class="note ' . $kind . ' spx-row-note">'
				. '<span class="note-mark"><span>'
				. $this->esc( (string) ( $note['rule'] ?? '' ) ) . '</span></span>'
				. '<span>' . $this->mark_text( (string) ( $note['message'] ?? '' ) ) . '</span>'
				. '</div>';
		}

		return( $out . '</div>' );
	}


	/**
	 * The scope disclaimer.
	 *
	 * ⚠️ NOT OPTIONAL. The tool does not validate; it explains someone else's report. Saying so
	 * plainly is what keeps a wrong explanation from reading as a wrong verdict.
	 *
	 * @return	string
	 * @since	1.4.0
	 */

	private function disclaimer(): string
	{
		return( '<div class="empty spx-closing">'
			. $this->esc( $this->s( 'disclaimer' ) )
			. '</div>' );
	}


	/**
	 * ⚠️ WordPress' esc_html() WHEN IT EXISTS, OTHERWISE htmlspecialchars(). The same choice as in
	 * `Lite\Renderer` - the package must stay usable without WordPress ( tests, CLI ), but under
	 * WordPress it must not escape past WordPress's filter chain.
	 *
	 * @param	mixed	$s
	 * @return	string
	 * @since	1.4.0
	 */

	private function esc( $s ): string
	{
		return( function_exists( 'esc_html' ) ? esc_html( (string) $s ) : htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
	}


	/**
	 * @param	mixed	$s
	 * @return	string
	 * @since	1.4.0
	 */

	private function escAttr( $s ): string
	{
		return( function_exists( 'esc_attr' ) ? esc_attr( (string) $s ) : htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
	}

}

endif;
