<?php
/**
 * The section "Checked rules" of the report view - the positive validation report of VAMPIRE on screen
 * and as a print version.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report\Explainer;

/**
 * class: RuleReportSection
 *
 * Input is the section `ruleReport` of the JSON report of VAMPIRE PHP or VAMPIRE JS, unchanged, as
 * `vampire-php/docs/developer/report-formats.md`, section "Positiver Prüfbericht", describes it. The section
 * shows one line per rule with one of the four results, the rule sets with their edition, the time of the
 * check, the checksum of the checked file, the steps of the check and a filter by result.
 *
 * ⚠️ "NOT CHECKED" IS NEVER HIDDEN AND NEVER TURNED INTO "PASSED". This requirement has no room at all.
 * Concretely:
 *   - every line of `rules` becomes a row, whatever its result; nothing is filtered on the server;
 *   - a rule set that did not run and has no lines ( `ran: false`, `reason: not-included` ) still stands
 *     in the rule set table with its reason, and the step `rules` says the same;
 *   - the counts are counted from the rows, not copied from `counts`, so a count can never claim a row
 *     the reader cannot find;
 *   - the filter is CSS only and starts at "all"; the print stylesheet shows every row whatever the
 *     filter says, so a print or a PDF can never be a filtered excerpt.
 *
 * ⚠️ A SEPARATE CLASS, NOT MORE KEYS IN `Renderer::defaults()`. The tables of the JavaScript report view
 * ( `libs/einvoice-core-js/src/explainer/i18n/` ) are built from the KoSIT explorer's `.po`, and the test
 * `i18n.test.mjs` requires every key of `Renderer::defaults()` in them. Only the validator for VAMPIRE
 * shows this section, so its texts live in that plugin's `.po`, and their tables are built from there
 * ( `_claude-tools/explainer-strings-build.php`, second pass ). Keys of this class all start with `rules_`.
 *
 * ⚠️ THE JAVASCRIPT TWIN IS `libs/einvoice-core-js/src/explainer/RuleReportSection.mjs`, and both must
 * write the same markup byte for byte ( `test/compare-rule-report.mjs` ). That is why this class has its
 * own escaping and its own placeholder replacement instead of `esc_html()` and `sprintf()`: `esc_html()`
 * does not encode an existing entity a second time and writes `&#039;`, the JavaScript `esc()` of the
 * renderer leaves `'` alone, and rule texts contain both apostrophes and ampersands.
 *
 * ⚠️ THE STYLESHEET TRAVELS WITH THE MARKUP ( a `<style>` element at the start of the section ). The
 * section appears inside the Shadow DOM of the validator plugin, in the apps and in the print version;
 * a separate file would have to reach all three and every plugin copy of this library before the
 * section looked right anywhere. The rules use the tokens of the design kit with fallbacks, so the
 * print version without a kit still reads well.
 *
 * @since 1.15.0
 */

if ( ! class_exists( __NAMESPACE__ . '\\RuleReportSection' ) ) :

class RuleReportSection
{


	/** The four results in the order the filter offers them: what needs attention first. */
	const RESULTS = array( 'failed', 'not-checked', 'passed', 'not-applicable' );

	/** @var array<string,string> */
	private $strings;

	/** @var bool whether every row carries the rule's message text ( switch "Rule text per line" ) */
	private $ruleText;


	/**
	 * @param	array	$strings	translated strings, keys see defaults(); the caller may pass the
	 *								renderer's whole table, unknown keys are ignored
	 * @param	array	$options	`rule_text` ( bool, default false ): the switch "Rule text per line". On,
	 *								the table gets the column "Rule text" with the message of every rule;
	 *								off, the column is left out entirely
	 * @since	1.15.0, `$options` since 01.11.2026
	 */

	public function __construct( array $strings = array(), array $options = array() )
	{
		$this->strings = $strings;

		/*
		 * ⚠️ OFF BY DEFAULT ( Konzept v10.1, 5.5.4 ). The rule text is the one column that wraps: without it every
		 * rule is one line, with it many rules take two or more. Measured on 01.11.2026 with Chrome's "Save as PDF"
		 * on A4 for three invoices of the corpus, the print version grew from 31, 26 and 35 pages to 60, 32 and 40
		 * ( figures in the STATUS.md of this library ). The
		 * JSON report of the engine is NOT shortened by this switch - it is the original report and keeps every
		 * text; the switch governs only what this section draws.
		 *
		 * ⚠️ AN OLDER COPY OF THIS CLASS IGNORES THE SECOND ARGUMENT ( PHP drops surplus arguments of a user
		 * function ) and keeps drawing the column, as it did before. Which copy wins is decided by the order in
		 * which plugins load ( `class_exists()` guard below ), so a site with an old copy in another plugin shows
		 * the text whatever the switch says, until that plugin carries this edition.
		 */
		// ⚠️ Only `true` switches it on, as in the twin: a `'1'` from a form is cast by the caller, not guessed here.
		$this->ruleText = ( $options[ 'rule_text' ] ?? false ) === true;
	}


	/**
	 * The English defaults - at the same time the complete list of keys.
	 *
	 * ⚠️ WHOEVER ADDS A KEY HERE ADDS IT IN THE TWIN AND IN THE PLUGIN, with a literal `__()`, or the line
	 * stays English on every German page without anything failing.
	 *
	 * @return	array<string,string>
	 * @since	1.15.0
	 */

	public static function defaults(): array
	{
		return array(
			'rules_title'                 => 'Checked rules',
			'rules_evidence'              => 'This report is technical evidence of a check against the rule sets in the editions named here. It is not an audit opinion.',
			'rules_total'                 => '%d rules',
			'rules_document'              => 'Checked file',
			'rules_bytes'                 => '%d bytes',
			'rules_sha256'                => 'SHA-256 of the checked file',
			'rules_validated_at'          => 'Time of the check (UTC)',
			'rules_engine'                => 'Engine',
			'rules_scenario'              => 'Scenario',
			'rules_specification'         => 'Specification identifier (BT-24)',
			'rules_rulesets'              => 'Rule sets',
			'rules_col_ruleset'           => 'Rule set',
			'rules_col_title'             => 'Title',
			'rules_col_license'           => 'Licence',
			'rules_col_count'             => 'Rules',
			'rules_col_state'             => 'State',
			'rules_ruleset_ran'           => 'checked',
			'rules_steps'                 => 'Steps of the check',
			'rules_step_wellformed'       => 'Well-formed XML',
			'rules_step_scenario'         => 'Scenario',
			'rules_step_schema'           => 'XML schema',
			'rules_step_rules'            => 'Rules',
			'rules_step_passed'           => 'passed',
			'rules_step_failed'           => 'failed',
			'rules_filter'                => 'Show',
			'rules_filter_all'            => 'all',
			'rules_result_passed'         => 'satisfied',
			'rules_result_failed'         => 'violated',
			'rules_result_not_applicable' => 'not applicable',
			'rules_result_not_checked'    => 'not checked',
			'rules_col_rule'              => 'Rule',
			'rules_col_result'            => 'Result',
			'rules_col_severity'          => 'Severity',
			'rules_col_checked'           => 'Places checked',
			'rules_col_fired'             => 'Fired',
			'rules_col_text'              => 'Rule text',
			'rules_severity_fatal'        => 'error',
			'rules_severity_warning'      => 'warning',
			'rules_severity_information'  => 'information',
			'rules_severity_in_ruleset'   => 'in the rule set: %s',
			'rules_reason_stopped'        => 'An earlier step ended the check.',
			'rules_reason_not_included'   => 'The rule set is not part of this package.',
			'rules_reason_disabled'       => 'Switched off by an operator profile.',
			'rules_reason_error'          => 'The rule could not be evaluated.',
			'rules_reason_not_supported'  => 'This engine cannot carry out this step.',
			'rules_print_title'           => 'Validation report with every checked rule',
			'rules_print_hint'            => 'To keep this report as a PDF, open the print dialogue of the browser and choose “Save as PDF” as the printer. This line does not appear on paper.',
			'rules_verdict_accepted'      => 'Verdict: accepted',
			'rules_verdict_rejected'      => 'Verdict: rejected',
			'rules_verdict_none'          => 'No verdict',
			'rules_download_print'        => 'Print version of the checked rules ( HTML )',
			'rules_download_json'         => 'Report with every rule ( JSON )',
		);
	}


	/**
	 * One string, translated when the caller passed it.
	 *
	 * @param	string	$key
	 * @return	string	UNESCAPED
	 * @since	1.15.0
	 */

	public function s( string $key ): string
	{
		if ( isset( $this->strings[ $key ] ) && is_string( $this->strings[ $key ] ) && $this->strings[ $key ] !== '' )
			return( $this->strings[ $key ] );

		$d = self::defaults();

		return( (string) ( $d[ $key ] ?? $key ) );
	}


	/**
	 * How many rows carry each result, counted from the rows.
	 *
	 * @param	array	$rr	the section `ruleReport`
	 * @return	array<string,int>	the four results in RESULTS order
	 * @since	1.15.0
	 */

	public static function counts( array $rr ): array
	{
		$n = array_fill_keys( self::RESULTS, 0 );

		foreach ( (array) ( $rr[ 'rules' ] ?? array() ) as $rule )
		{
			$result = (string) ( $rule[ 'result' ] ?? '' );

			// ⚠️ An unknown result counts as "not checked": the engine did not say it passed.
			$n[ isset( $n[ $result ] ) ? $result : 'not-checked' ]++;
		}

		return( $n );
	}


	/**
	 * The section for the report view.
	 *
	 * @param	array	$rr	the section `ruleReport` of the JSON report
	 * @return	string	HTML, '' when `$rr` has no rules list at all
	 * @since	1.15.0
	 */

	public function render( array $rr ): string
	{
		if ( ! isset( $rr[ 'rules' ] ) || ! is_array( $rr[ 'rules' ] ) )
			return( '' );

		return( '<style>' . self::style() . '</style>' . $this->card( $rr ) );
	}


	/**
	 * The print version: a page of its own, to be stored next to the document or saved as PDF from the
	 * browser's print dialog.
	 *
	 * ⚠️ SELF-CONTAINED, NO SCRIPT, NO EXTERNAL REFERENCE. Same reasoning as the engine's own `HtmlReport`:
	 * a report that is filed away must look right without a web server in five years.
	 *
	 * ⚠️ THE PDF IS THE BROWSER'S, NOT A LIBRARY'S ( Konzept v10.1, 5.5.4 ). The page carries a print stylesheet
	 * ( `page_style()` and the `@media print` block of `style()` ) and a line on screen that says how to save it
	 * as PDF; that line is hidden on paper. No button calls `window.print()`, because the page has no script and
	 * must not get one: it is stored next to the invoice and opened again years later.
	 *
	 * @param	array		$rr			the section `ruleReport`
	 * @param	string		$lang		language of the page ( `<html lang>` )
	 * @param	bool|null	$verdict	the overall verdict of the report ( true, false, null = no verdict )
	 * @return	string	a complete HTML document
	 * @since	1.15.0
	 */

	public function document( array $rr, string $lang = 'en', $verdict = null ): string
	{
		$lang = preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : 'en';

		$name = (string) ( $rr[ 'document' ][ 'name' ] ?? '' );

		$verdictKey = $verdict === true ? 'rules_verdict_accepted' : ( $verdict === false ? 'rules_verdict_rejected' : 'rules_verdict_none' );
		$verdictCls = $verdict === true ? 'res-passed' : ( $verdict === false ? 'res-failed' : 'res-not-checked' );

		return( "<!DOCTYPE html>\n<html lang=\"" . $lang . "\">\n<head>\n<meta charset=\"utf-8\">\n"
			. '<title>' . self::esc( $this->s( 'rules_print_title' ) . ( $name !== '' ? ' · ' . $name : '' ) ) . "</title>\n"
			. '<style>' . self::page_style() . self::style() . "</style>\n</head>\n<body>\n"
			. '<p class="rules-print-hint">' . self::esc( $this->s( 'rules_print_hint' ) ) . "</p>\n"
			. '<h1 class="rules-print-title">' . self::esc( $this->s( 'rules_print_title' ) ) . "</h1>\n"
			. '<p class="rules-print-verdict"><span class="res ' . $verdictCls . '">' . self::esc( $this->s( $verdictKey ) ) . "</span></p>\n"
			. $this->card( $rr )
			. "\n</body>\n</html>\n" );
	}


	/**
	 * The card itself, without the stylesheet.
	 *
	 * @param	array	$rr
	 * @return	string
	 * @since	1.15.0
	 */

	private function card( array $rr ): string
	{
		$rules  = (array) $rr[ 'rules' ];
		$counts = self::counts( $rr );

		$out  = '<div class="rules-card">';
		$out .= '<div class="rules-head"><div class="rules-title">' . self::esc( $this->s( 'rules_title' ) ) . '</div>'
			. '<div class="rules-total">' . self::esc( self::fmt( $this->s( 'rules_total' ), (string) count( $rules ) ) ) . '</div></div>';

		// ⚠️ The sentence of step 9 stands at the top, before any number: whoever reads the counts has read it.
		$out .= '<p class="rules-evidence">' . self::esc( $this->s( 'rules_evidence' ) ) . '</p>';

		$out .= $this->meta( $rr );
		$out .= $this->rulesets( $rr );
		$out .= $this->steps( $rr );
		$out .= $this->filter( $counts, count( $rules ) );
		$out .= $this->table( $rules );

		return( $out . '</div>' );
	}


	/**
	 * File, checksum, time, engine, scenario and BT-24.
	 *
	 * @param	array	$rr
	 * @return	string
	 * @since	1.15.0
	 */

	private function meta( array $rr ): string
	{
		$doc = (array) ( $rr[ 'document' ] ?? array() );

		$items = array();

		$name  = (string) ( $doc[ 'name' ] ?? '' );
		$bytes = isset( $doc[ 'bytes' ] ) && $doc[ 'bytes' ] !== null ? self::fmt( $this->s( 'rules_bytes' ), (string) (int) $doc[ 'bytes' ] ) : '';

		if ( $name !== '' || $bytes !== '' )
			$items[] = array( 'rules_document', trim( $name . ( $name !== '' && $bytes !== '' ? ' · ' : '' ) . $bytes ), false );

		/*
		 * ⚠️ THE CHECKSUM IS SHOWN EVEN WHEN IT IS MISSING. `validateDom()` has no bytes and writes null; a
		 * row that silently disappeared would look like an oversight, a dash says "there is none".
		 */
		$items[] = array( 'rules_sha256', (string) ( $doc[ 'sha256' ] ?? '' ) !== '' ? (string) $doc[ 'sha256' ] : '–', true );
		$items[] = array( 'rules_validated_at', (string) ( $rr[ 'validatedAt' ] ?? '' ), true );

		$engine = trim( (string) ( $rr[ 'engine' ][ 'name' ] ?? '' ) . ' ' . (string) ( $rr[ 'engine' ][ 'version' ] ?? '' ) );

		if ( $engine !== '' )
			$items[] = array( 'rules_engine', $engine, false );

		$scenario = (string) ( $rr[ 'scenario' ][ 'name' ] ?? '' );

		if ( $scenario !== '' )
			$items[] = array( 'rules_scenario', $scenario, false );

		$spec = (string) ( $doc[ 'specificationId' ] ?? '' );

		if ( $spec !== '' )
			$items[] = array( 'rules_specification', $spec, true );

		$out = '<dl class="rules-meta">';

		foreach ( $items as $item )
			$out .= '<dt>' . self::esc( $this->s( $item[0] ) ) . '</dt><dd' . ( $item[2] ? ' class="rules-mono"' : '' ) . '>' . self::esc( $item[1] ) . '</dd>';

		return( $out . '</dl>' );
	}


	/**
	 * Every rule set of the run, also one that did not run.
	 *
	 * @param	array	$rr
	 * @return	string
	 * @since	1.15.0
	 */

	private function rulesets( array $rr ): string
	{
		$sets = (array) ( $rr[ 'rulesets' ] ?? array() );

		if ( ! $sets )
			return( '' );

		$out = '<div class="rules-sub">' . self::esc( $this->s( 'rules_rulesets' ) ) . '</div>'
			. '<table class="rules-sets"><thead><tr>'
			. '<th>' . self::esc( $this->s( 'rules_col_ruleset' ) ) . '</th>'
			. '<th>' . self::esc( $this->s( 'rules_col_title' ) ) . '</th>'
			. '<th>' . self::esc( $this->s( 'rules_col_license' ) ) . '</th>'
			. '<th class="rules-n">' . self::esc( $this->s( 'rules_col_count' ) ) . '</th>'
			. '<th>' . self::esc( $this->s( 'rules_col_state' ) ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $sets as $set )
		{
			$ran   = ! empty( $set[ 'ran' ] );
			$state = $ran
				? '<span class="res res-passed">' . self::esc( $this->s( 'rules_ruleset_ran' ) ) . '</span>'
				: '<span class="res res-not-checked">' . self::esc( $this->s( 'rules_result_not_checked' ) ) . '</span>' . $this->reason( $set[ 'reason' ] ?? null );

			$out .= '<tr' . ( $ran ? '' : ' class="r-not-checked"' ) . '>'
				. '<td class="rules-mono">' . self::esc( (string) ( $set[ 'ref' ] ?? '' ) ) . '</td>'
				. '<td>' . self::esc( (string) ( $set[ 'title' ] ?? '' ) ) . '</td>'
				. '<td class="rules-mono">' . self::esc( (string) ( $set[ 'license' ] ?? '' ) !== '' ? (string) $set[ 'license' ] : '–' ) . '</td>'
				. '<td class="rules-n">' . self::esc( isset( $set[ 'rules' ] ) && $set[ 'rules' ] !== null ? (string) (int) $set[ 'rules' ] : '–' ) . '</td>'
				. '<td>' . $state . '</td>'
				. '</tr>';
		}

		return( $out . '</tbody></table>' );
	}


	/**
	 * The cascade of the check in the order it ran.
	 *
	 * @param	array	$rr
	 * @return	string
	 * @since	1.15.0
	 */

	private function steps( array $rr ): string
	{
		$steps = (array) ( $rr[ 'steps' ] ?? array() );

		if ( ! $steps )
			return( '' );

		$out = '<div class="rules-sub">' . self::esc( $this->s( 'rules_steps' ) ) . '</div><ul class="rules-steps">';

		foreach ( $steps as $step )
		{
			$id     = (string) ( $step[ 'step' ] ?? '' );
			$result = (string) ( $step[ 'result' ] ?? '' );

			$label = in_array( $id, array( 'wellformed', 'scenario', 'schema', 'rules' ), true ) ? $this->s( 'rules_step_' . $id ) : $id;

			if ( $result === 'passed' )
				$res = '<span class="res res-passed">' . self::esc( $this->s( 'rules_step_passed' ) ) . '</span>';
			elseif ( $result === 'failed' )
				$res = '<span class="res res-failed">' . self::esc( $this->s( 'rules_step_failed' ) ) . '</span>';
			else
				// ⚠️ Anything else is "not checked", never "passed" - a step without a result did not run.
				$res = '<span class="res res-not-checked">' . self::esc( $this->s( 'rules_result_not_checked' ) ) . '</span>';

			$out .= '<li><span class="rules-step">' . self::esc( $label ) . '</span> ' . $res . $this->reason( $step[ 'reason' ] ?? null ) . '</li>';
		}

		return( $out . '</ul>' );
	}


	/**
	 * The filter by result, CSS only.
	 *
	 * ⚠️ RADIO BUTTONS AND `:has()`, NO SCRIPT. The section is inserted into a Shadow DOM with
	 * `innerHTML`, and a script inside it would not run; the print version must not contain one at all.
	 * A browser without `:has()` simply shows every row, which is the safe failure.
	 *
	 * @param	array<string,int>	$counts
	 * @param	int					$total
	 * @return	string
	 * @since	1.15.0
	 */

	private function filter( array $counts, int $total ): string
	{
		$out = '<fieldset class="rules-filter"><legend>' . self::esc( $this->s( 'rules_filter' ) ) . '</legend>'
			. '<label><input type="radio" name="rules-filter" value="all" checked> ' . self::esc( $this->s( 'rules_filter_all' ) ) . ' <span class="rules-n">' . $total . '</span></label>';

		foreach ( self::RESULTS as $result )
			$out .= '<label><input type="radio" name="rules-filter" value="' . $result . '"> <span class="res res-' . $result . '">'
				. self::esc( $this->s( self::result_key( $result ) ) ) . '</span> <span class="rules-n">' . (int) $counts[ $result ] . '</span></label>';

		return( $out . '</fieldset>' );
	}


	/**
	 * One row per rule, in the order of the engine ( rule set by rule set ).
	 *
	 * @param	array	$rules
	 * @return	string
	 * @since	1.15.0
	 */

	private function table( array $rules ): string
	{
		$out = '<div class="rules-scroll"><table class="rules-table"><thead><tr>'
			. '<th>' . self::esc( $this->s( 'rules_col_rule' ) ) . '</th>'
			. '<th>' . self::esc( $this->s( 'rules_col_ruleset' ) ) . '</th>'
			. '<th>' . self::esc( $this->s( 'rules_col_result' ) ) . '</th>'
			. '<th class="rules-sev">' . self::esc( $this->s( 'rules_col_severity' ) ) . '</th>'
			. '<th class="rules-n">' . self::esc( $this->s( 'rules_col_checked' ) ) . '</th>'
			. '<th class="rules-n">' . self::esc( $this->s( 'rules_col_fired' ) ) . '</th>'
			// ⚠️ The column exists only with the switch; an empty column would still take its share of the width.
			. ( $this->ruleText ? '<th>' . self::esc( $this->s( 'rules_col_text' ) ) . '</th>' : '' )
			. '</tr></thead><tbody>';

		foreach ( $rules as $rule )
		{
			$result = (string) ( $rule[ 'result' ] ?? '' );
			$result = in_array( $result, self::RESULTS, true ) ? $result : 'not-checked';

			$severity = (string) ( $rule[ 'severity' ] ?? '' );
			$native   = (string) ( $rule[ 'ruleSeverity' ] ?? '' );

			$sev = self::esc( $this->severity( $severity ) );

			if ( $native !== '' && $native !== $severity )
				$sev .= '<span class="rules-reason">' . self::esc( self::fmt( $this->s( 'rules_severity_in_ruleset' ), $this->severity( $native ) ) ) . '</span>';

			$out .= '<tr class="rule-row r-' . $result . '">'
				. '<td class="rules-mono">' . self::esc( (string) ( $rule[ 'key' ] ?? $rule[ 'rule' ] ?? '' ) ) . '</td>'
				. '<td class="rules-mono">' . self::esc( (string) ( $rule[ 'ruleset' ] ?? '' ) ) . '</td>'
				. '<td><span class="res res-' . $result . '">' . self::esc( $this->s( self::result_key( $result ) ) ) . '</span>'
					. ( $result === 'not-checked' ? $this->reason( $rule[ 'reason' ] ?? null ) : '' ) . '</td>'
				. '<td class="rules-sev">' . $sev . '</td>'
				. '<td class="rules-n">' . (int) ( $rule[ 'checked' ] ?? 0 ) . '</td>'
				. '<td class="rules-n">' . (int) ( $rule[ 'failed' ] ?? 0 ) . '</td>'
				. ( $this->ruleText ? '<td>' . self::esc( (string) ( $rule[ 'text' ] ?? '' ) ) . '</td>' : '' )
				. '</tr>';
		}

		return( $out . '</tbody></table></div>' );
	}


	/**
	 * The reason of a "not checked", as a line under the mark.
	 *
	 * @param	mixed	$reason	`stopped`, `not-included`, `disabled`, `error`, `not-supported` or null
	 * @return	string	'' without a reason
	 * @since	1.15.0
	 */

	private function reason( $reason ): string
	{
		if ( ! is_string( $reason ) || $reason === '' )
			return( '' );

		$key  = 'rules_reason_' . str_replace( '-', '_', $reason );
		$text = array_key_exists( $key, self::defaults() ) ? $this->s( $key ) : $reason;

		return( '<span class="rules-reason">' . self::esc( $text ) . '</span>' );
	}


	/**
	 * @param	string	$severity	`fatal`, `warning`, `information`
	 * @return	string	UNESCAPED
	 * @since	1.15.0
	 */

	private function severity( string $severity ): string
	{
		return( in_array( $severity, array( 'fatal', 'warning', 'information' ), true ) ? $this->s( 'rules_severity_' . $severity ) : $severity );
	}


	/**
	 * @param	string	$result
	 * @return	string	the key of the display text
	 * @since	1.15.0
	 */

	private static function result_key( string $result ): string
	{
		return( 'rules_result_' . str_replace( '-', '_', $result ) );
	}


	/**
	 * Puts one value into the first `%d` or `%s` of a pattern.
	 *
	 * ⚠️ NOT `sprintf()`. A translation that writes `%1$d` or drops the placeholder must not throw; the
	 * twin does the same with a plain replacement.
	 *
	 * @param	string	$pattern
	 * @param	string	$value
	 * @return	string
	 * @since	1.15.0
	 */

	private static function fmt( string $pattern, string $value ): string
	{
		return( (string) preg_replace( '/%(?:1\$)?[ds]/', str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $value ), $pattern, 1 ) );
	}


	/**
	 * Escape for text and attribute alike - the same five characters as the twin.
	 *
	 * @param	string	$s
	 * @return	string
	 * @since	1.15.0
	 */

	private static function esc( string $s ): string
	{
		return( str_replace( array( '&', '<', '>', '"', "'" ), array( '&amp;', '&lt;', '&gt;', '&quot;', '&#039;' ), $s ) );
	}


	/**
	 * The stylesheet of the section.
	 *
	 * ⚠️ THE FILTER RULES NAME EACH RESULT. A rule like `:has(input:checked)` with an attribute taken from
	 * the input is not possible in CSS, so every result has its own line here. The print block comes last
	 * and wins with `!important` over the filter.
	 *
	 * @return	string
	 * @since	1.15.0
	 */

	public static function style(): string
	{
		return( '.rules-card{margin:24px 0;padding:var(--pad,16px);border:1px solid var(--border,#d5d8de);background:var(--surface,#fff);color:var(--text,#0b1220)}'
			. '.rules-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 16px;margin:0 0 8px}'
			. '.rules-title{font-family:var(--font-title,inherit);font-size:var(--fs-h2,17px);font-weight:600}'
			. '.rules-total{color:var(--muted,#4a5468);font-size:var(--fs-sm,13.5px)}'
			. '.rules-evidence{margin:0 0 12px;padding:8px 12px;border-left:3px solid var(--info,#175cd3);background:var(--info-soft,#eff8ff)}'
			. '.rules-meta{display:grid;grid-template-columns:max-content minmax(0,1fr);gap:2px 16px;margin:0 0 12px;font-size:var(--fs-sm,13.5px)}'
			. '.rules-meta dt{color:var(--muted,#4a5468)}.rules-meta dd{margin:0;overflow-wrap:anywhere}'
			. '.rules-mono{font-family:var(--mono,ui-monospace,monospace);font-size:.92em;overflow-wrap:anywhere}'
			. '.rules-sub{margin:16px 0 4px;font-weight:600;font-size:var(--fs-sm,13.5px)}'
			. '.rules-sets,.rules-table{width:100%;border-collapse:collapse;font-size:var(--fs-sm,13.5px)}'
			. '.rules-sets th,.rules-sets td,.rules-table th,.rules-table td{padding:4px 8px;border-bottom:1px solid var(--border,#d5d8de);text-align:left;vertical-align:top}'
			. '.rules-sets th,.rules-table th{background:var(--surface-2,#f7f8fa);font-weight:600}'
			. '.rules-n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}'
			. '.rules-scroll{overflow-x:auto}.rules-table td.rules-mono{white-space:nowrap;overflow-wrap:normal}'
			// ⚠️ The column of the rule text takes all the width it can, and without this "warning" broke letter by letter.
			. '.rules-table .rules-sev{white-space:nowrap;overflow-wrap:normal}'
			. '.rules-steps{display:flex;flex-wrap:wrap;gap:4px 20px;margin:0 0 12px;padding:0;list-style:none;font-size:var(--fs-sm,13.5px)}'
			. '.res{display:inline-block;padding:0 6px;font-size:var(--fs-xs,11px);font-weight:600;line-height:1.7;white-space:nowrap}'
			. '.res-passed{background:var(--ok-soft,#ecfdf3);color:var(--ok,#067647)}'
			. '.res-failed{background:var(--err-soft,#fef3f2);color:var(--err,#b42318)}'
			. '.res-not-applicable{background:var(--surface-2,#f7f8fa);color:var(--muted,#4a5468)}'
			. '.res-not-checked{background:var(--warn-soft,#fffaeb);color:var(--warn,#b54708)}'
			. '.rules-reason{display:block;color:var(--muted,#4a5468);font-size:var(--fs-xs,11px)}'
			. '.rules-filter{display:flex;flex-wrap:wrap;align-items:center;gap:6px 16px;margin:16px 0 8px;padding:0;border:0}'
			. '.rules-filter legend{float:left;margin-right:8px;padding:0;font-weight:600;font-size:var(--fs-sm,13.5px)}'
			. '.rules-filter label{cursor:pointer;white-space:nowrap;font-size:var(--fs-sm,13.5px)}'
			. '.rules-table tr.r-failed td{background:var(--err-soft,#fef3f2)}'
			. '.rules-table tr.r-not-checked td,.rules-sets tr.r-not-checked td{background:var(--warn-soft,#fffaeb)}'
			. '.rules-card:has(input[value="failed"]:checked) tr.rule-row:not(.r-failed),'
			. '.rules-card:has(input[value="not-checked"]:checked) tr.rule-row:not(.r-not-checked),'
			. '.rules-card:has(input[value="passed"]:checked) tr.rule-row:not(.r-passed),'
			. '.rules-card:has(input[value="not-applicable"]:checked) tr.rule-row:not(.r-not-applicable){display:none}'
			/*
			 * ⚠️ THE PRINT BLOCK. Four things in it are not cosmetic:
			 *   - `overflow:visible` on the scroll box: with `overflow-x:auto` Chrome prints only the part of a wide
			 *     table that fits, and the columns on the right are cut off without a word;
			 *   - `print-color-adjust:exact`: Chrome and Firefox drop background colours when printing unless the
			 *     reader ticks "Background graphics", and then "violated" and "not checked" look alike;
			 *   - every row shown whatever the filter says, so a PDF is never a filtered excerpt;
			 *   - the table head repeated on every page, and a heading kept with what follows it.
			 */
			. '@media print{.rules-filter{display:none}.rules-card tr.rule-row{display:table-row!important}.rules-table thead,.rules-sets thead{display:table-header-group}.rules-table tr,.rules-sets tr{break-inside:avoid}.rules-card{margin:0;border:0;padding:0}'
			. '.rules-scroll{overflow:visible}.rules-sub,.rules-head{break-after:avoid}.rules-evidence,.rules-meta{break-inside:avoid}'
			. '.rules-card,.res,.rules-table td,.rules-sets td,.rules-evidence{-webkit-print-color-adjust:exact;print-color-adjust:exact}}' );
	}


	/**
	 * The page rules of the print version, in front of style().
	 *
	 * ⚠️ `@page` SETS A4 AND THE MARGINS, AND THE BODY LOSES ITS OWN MARGIN ON PAPER. Without `@page` the printer
	 * dialogue starts with the margins of the browser and a US Letter page on many systems; with the body margin
	 * on top the table loses another 2rem on each side. The font gets smaller on paper so that a row of the rule
	 * table stays one line where it can; the page count is measured in the STATUS.md of this library.
	 *
	 * @return	string
	 * @since	1.15.0
	 */

	private static function page_style(): string
	{
		return( 'body{margin:2rem;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;font-size:14px;line-height:1.45;color:#0b1220;background:#fff}'
			. '.rules-print-title{margin:0 0 .4rem;font-size:1.5rem}.rules-print-verdict{margin:0 0 1rem}.rules-print-verdict .res{font-size:13px;padding:2px 8px}'
			. '.rules-print-hint{margin:0 0 1rem;padding:6px 10px;border:1px dashed #d5d8de;color:#4a5468;font-size:13px}'
			. '@page{size:A4;margin:14mm 12mm}'
			. '@media print{body{margin:0;font-size:11px}.rules-print-hint{display:none}}' );
	}

}

endif;
