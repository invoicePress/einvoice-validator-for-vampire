<?php
/**
 * The values of every finding from the rule itself, for the report this plugin has just made.
 *
 * Without this class the explanation of a finding shows, next to every field the rule text names, the value the document
 * carries there. A rule text can name another field than the rule reads: the text of BR-CO-13 names "Σ BT-131", the rule
 * compares BT-109 with BT-106 − BT-107 + BT-108. For the invoice of `ubl-allowance-official-fehler-report.xml` the text
 * path shows 5,900 twice, the rule compares 5,900 with 5,990.
 * `\SpecialPress\EInvoice\Report\Explainer\RuleValues` evaluates the failed comparison of the rule itself on
 * the invoice and stores the numbers on the finding; the renderer shows them before the values from the text.
 *
 * ⚠️ **ONLY HERE, WHERE VAMPIRE RUNS.** The evaluation needs the interpreter of VAMPIRE PHP and the rule packages of the
 * check; this plugin carries both. The Validators for KoSIT and Mustang and the Validation Explorers carry neither and
 * keep the values from the text.
 *
 * ⚠️ **THROUGH THE FILTER `einvoice/report_read`, NOT BY A CALL IN THE VIEW.** With a Validation Explorer active, the
 * explorer redraws the report through `einvoice/report_html` and reads it again from the text; a call in
 * `SPPV_Explainer_View::render()` would reach only the drawing without an explorer. The filter sits in
 * `Reader::read()` of the shared library, which every drawing passes. `remember()` names the report of this request,
 * and the callback answers for exactly that text and for nothing else, so a report a visitor uploads into an explorer
 * keeps its values from the text.
 *
 * ⚠️ **THE PACKAGES OF THIS CHECK, IN ITS ORDER.** `Result::rulesets()` names the references the engine ran; they are
 * looked up in the same folders and spellings as `Validator::resolveRulesetFile()` does it ( literal `@` first, then the
 * hyphen of the delivery ). Picking packages by syntax instead would pass a package that did not run, and the first
 * package whose rule fails at the finding wins.
 *
 * ⚠️ **NOTHING HERE MAY COST THE REPORT.** Every step answers with the report unchanged when something is missing: an older
 * copy of the shared library loaded first ( no `RuleValues` ), an engine export without `AstSerializer`, a package that
 * cannot be read. The values are an improvement of the explanation, never a reason for a report not to appear.
 *
 * @package SP_EInvoice_Validator_PHP
 * @since   1.14.4
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


class SPPV_Rule_Values
{

	/**
	 * The report of this request: its text, the rule set references and the folders to look them up in, and once
	 * computed, the values per finding.
	 *
	 * @var array|null	array{ xml: string, refs: string[], dirs: string[], values: array|null }
	 */
	private static $current = null;

	/** @var array	decoded rule packages by file, for this request */
	private static $packages = array();


	/**
	 * Hooks the filter of the shared reader.
	 *
	 * @return	void
	 */

	public static function register()
	{
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'einvoice/report_read' is the shared hook of libs/einvoice-report ( Reader::read() ), not a hook of this plugin.
		add_filter( 'einvoice/report_read', array( __CLASS__, 'filter' ), 10, 2 );
	}


	/**
	 * Names the report this request has just made.
	 *
	 * ⚠️ BEFORE THE FIRST READ OF THE REPORT, which happens in `ReportView::build()`. Called later, the first drawing would
	 * go without values.
	 *
	 * @param	string		$xml	the report in the KoSIT format, as `SPPV_Validator::validate()` returned it
	 * @param	string[]	$refs	`Result::rulesets()`, e.g. "EN16931-UBL@1.3.17"
	 * @param	string[]	$dirs	the folders of the rule packages, in the order of the engine
	 * @return	void
	 */

	public static function remember( string $xml, array $refs, array $dirs )
	{
		self::$current = array(
			'xml'    => $xml,
			'refs'   => array_values( array_filter( array_map( 'strval', $refs ) ) ),
			'dirs'   => array_values( array_filter( array_map( 'strval', $dirs ) ) ),
			'values' => null,
		);
	}


	/**
	 * Callback of `einvoice/report_read`.
	 *
	 * ⚠️ THE SAME REPORT IS READ SEVERAL TIMES IN ONE REQUEST ( Report Lite, then the explorer view, then the print version ).
	 * The values are computed once and copied by the position of the finding: the same reader gives the same findings in
	 * the same order for the same text.
	 *
	 * @param	mixed	$report		\SpecialPress\EInvoice\Report\Report
	 * @param	string	$content
	 * @return	mixed	the same report
	 */

	public static function filter( $report, $content = '' )
	{
		if ( self::$current === null || ! is_object( $report ) || ! isset( $report->findings ) || ! is_array( $report->findings ) )
			return( $report );

		if ( (string) $content !== self::$current['xml'] )
			return( $report );

		if ( is_array( self::$current['values'] ) )
		{
			if ( count( self::$current['values'] ) === count( $report->findings ) )
				foreach ( self::$current['values'] as $i => $v )
					if ( $v !== null && isset( $report->findings[ $i ] ) )
						$report->findings[ $i ]['ruleValues'] = $v;

			return( $report );
		}

		$report = self::attach( $report );

		self::$current['values'] = array_map( static function ( $f ) { return( is_array( $f ) ? ( $f['ruleValues'] ?? null ) : null ); }, array_values( $report->findings ) );

		return( $report );
	}


	/**
	 * Attaches the values to every finding, or leaves the report as it is.
	 *
	 * @param	\SpecialPress\EInvoice\Report\Report	$report
	 * @return	\SpecialPress\EInvoice\Report\Report
	 */

	private static function attach( $report )
	{
		$class = '\SpecialPress\EInvoice\Report\Explainer\RuleValues';

		// ⚠️ An older copy of einvoice-report from a neighbour plugin, loaded first, has no such class.
		if ( ! class_exists( $class ) || ! class_exists( 'SPPV_Terms' ) || ! SPPV_Rules::available() )
			return( $report );

		try
		{
			$engine = $class::vampire_engine();

			if ( $engine === null )
				return( $report );

			$packages = self::packages( self::$current['refs'], self::$current['dirs'] );

			if ( ! $packages )
				return( $report );

			return( $class::attach( $report, new SPPV_Terms(), array( 'packages' => $packages, 'engine' => $engine ) ) );
		}
		catch ( \Throwable $e )
		{
			// ⚠️ The findings attached before the error keep their values; each finding is decided on its own.
			return( $report );
		}
	}


	/**
	 * The rule packages of the references, decoded, in the order of the references.
	 *
	 * ⚠️ THE TWO SPELLINGS OF `Validator::resolveRulesetFile()`. The work tree names a package `EN16931-UBL@1.3.17.json`, the
	 * delivery `EN16931-UBL-1.3.17.json`, because Plugin Check refuses an `@` in a file name.
	 *
	 * @param	string[]	$refs
	 * @param	string[]	$dirs
	 * @return	array[]
	 */

	private static function packages( array $refs, array $dirs )
	{
		$out = array();

		foreach ( $refs as $ref )
		{
			// The reference becomes part of a path: nothing but the characters of a rule set reference.
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*@[A-Za-z0-9._-]+$/', $ref ) )
				continue;

			foreach ( $dirs as $dir )
			{
				$found = '';

				foreach ( array( $ref, str_replace( '@', '-', $ref ) ) as $name )
					if ( is_file( rtrim( $dir, '/\\' ) . '/' . $name . '.json' ) )
					{
						$found = rtrim( $dir, '/\\' ) . '/' . $name . '.json';
						break;
					}

				if ( $found === '' )
					continue;

				if ( ! array_key_exists( $found, self::$packages ) )
				{
					$pkg = json_decode( (string) file_get_contents( $found ), true );

					self::$packages[ $found ] = is_array( $pkg ) && isset( $pkg['patterns'] ) && is_array( $pkg['patterns'] ) ? $pkg : null;
				}

				if ( self::$packages[ $found ] !== null )
					$out[] = self::$packages[ $found ];

				break;
			}
		}

		return( $out );
	}

}
