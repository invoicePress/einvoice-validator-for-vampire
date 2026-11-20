<?php
/**
 * The one backend this plugin has: the bundled `vampire-php` library, running in this very
 * PHP process.
 *
 * ⚠️ NO ADAPTER INTERFACE, NO REGISTRY, ON PURPOSE. The sibling plugin
 * ( einvoice-validator-for-peppol-en16931 ) carries four backends - local `java -jar`, KoSIT daemon,
 * generic HTTP, einvoice-api - and needs a picker, a registry and a connection test for them. This
 * plugin has exactly one, it needs no Java, no daemon and no network, and that IS its product
 * promise. An interface with a single implementation would only make the code look as if a second
 * one were coming.
 *
 * ⚠️ `validate()` THROWS ON FAILURE, NEVER RETURNS A FAILURE SHAPE - same contract as the sibling's
 * adapters, so the shortcode/AJAX/REST layer reads the same way in both plugins. A document that is
 * merely INVALID is not a failure: it comes back as a normal report that says "rejected".
 *
 * @package SP_EInvoice_Validator_PHP
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


class SPPV_Validator
{


	/**
	 * Directory of the bundled library - the `baseDir` every path inside `scenarios.json` is
	 * resolved against.
	 *
	 * @return	string
	 * @since	1.0.0
	 */

	public static function lib_dir()
	{
		return( rtrim( SPPV_DIR, '/\\' ) . '/vendor/invoicepress/vampire-php' );
	}


	/**
	 * The folder the rule sets are read from: an uploaded rule package, or the bundled one.
	 *
	 * ⚠️ TWO DIFFERENT FOLDERS, AND ONLY ONE OF THEM HOLDS CODE. The classes always come from
	 * `lib_dir()/src` ( the autoloader in the main file ); a rule package carries no `src/` and never
	 * replaces the engine. This method only chooses where `rulesets/` lives, and that is the `baseDir`
	 * every path in `scenarios.json` is resolved against.
	 *
	 * @return	string
	 * @since	09.10.2026
	 */

	public static function rules_dir()
	{
		$uploaded = SPPV_Rule_Packages::active_dir();

		return( $uploaded !== '' ? $uploaded : self::lib_dir() );
	}


	/**
	 * Whether the bundled engine can write the positive report.
	 *
	 * ⚠️ THE ENGINE IN THE PLUGIN CAN BE OLDER THAN THE PLUGIN CODE. The parameter `ruleReport` and the
	 * constant `ENGINE_NAME` arrived together in the library; an export from before that
	 * has neither, and passing the named argument would be a fatal error on every upload.
	 *
	 * @return	bool
	 * @since	09.10.2026
	 */

	public static function supports_rule_report()
	{
		return( defined( '\EinvoiceValidator\Validator::ENGINE_NAME' ) );
	}


	/**
	 * Whether the multi-edition check can run here.
	 *
	 * ⚠️ TWO CONDITIONS, BOTH MEASURED, NEITHER ASSUMED. The class `Cascade` exists only in a recent engine
	 * export, and the edition packages ( `rulesets/versions/` ) only in an export with `--versions` or
	 * an uploaded rule package that carries them. Without either the setting says "not available" and the
	 * upload does what it always did.
	 *
	 * @return	bool
	 * @since	09.10.2026
	 */

	public static function supports_cascade()
	{
		return( class_exists( '\EinvoiceValidator\Cascade\Cascade' ) && is_file( self::rules_dir() . '/rulesets/versions/cascade.json' ) );
	}


	/**
	 * Run the version cascade, or the full matrix of every edition ( `$full` ).
	 *
	 * ⚠️ RETURNS NULL INSTEAD OF THROWING. The matrix is an addition to the single check; a failure here must not take
	 * the visitor's report away, which the single check has already produced.
	 *
	 * @param	string	$xml
	 * @param	bool	$full
	 * @return	array|null	the result of `Cascade::validateString()`
	 * @since	09.10.2026
	 */

	public function cascade( $xml, $full )
	{
		if ( ! self::supports_cascade() )
			return( null );

		try
		{
			$c = \EinvoiceValidator\Cascade\Cascade::fromPackage( self::rules_dir(), array( 'lang' => self::engine_language() ) );

			return( $c === null ? null : $c->validateString( (string) $xml, (bool) $full ) );
		}
		catch ( \Throwable $e )
		{
			return( null );
		}
	}


	/**
	 * The scenarios of the rule packages in use, as id => name, for a choice such as the slots of the comparison.
	 *
	 * @return	array<string,string>
	 * @since	1.12.1
	 */

	public static function scenarios()
	{
		$file = self::rules_dir() . '/rulesets/scenarios.json';
		$list = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$out  = array();

		foreach ( is_array( $list ) ? $list : array() as $s )
			if ( is_array( $s ) && (string) ( $s[ 'id' ] ?? '' ) !== '' )
				$out[ (string) $s[ 'id' ] ] = (string) ( $s[ 'name' ] ?? $s[ 'id' ] );

		return( $out );
	}


	/**
	 * The scenarios of the edition the cascade chose, or null when that edition checks with the default rule sets.
	 *
	 * ⚠️ ONLY AN EDITION WITH SCENARIOS OF ITS OWN CHECKS DIFFERENTLY. The XRechnung editions carry `scenarios: false`;
	 * the cascade checks them with the default package, so the single check already is their report. The ZUGFeRD and
	 * Factur-X editions carry their own scenarios, and only there does the report of the chosen edition differ.
	 *
	 * @param	array	$cascade	the result of `cascade()`
	 * @return	array|null	array{ version: string, scenarios: array }
	 * @since	1.12.1
	 */

	public static function edition_scenarios( array $cascade )
	{
		$family  = (string) ( $cascade[ 'family' ][ 'id' ] ?? '' );
		$version = (string) ( $cascade[ 'chosen' ][ 'version' ] ?? '' );
		$file    = self::rules_dir() . '/rulesets/versions/cascade.json';

		if ( $family === '' || $version === '' || ! is_file( $file ) )
			return( null );

		$table = json_decode( (string) file_get_contents( $file ), true );

		foreach ( (array) ( $table[ 'families' ] ?? array() ) as $f )
			if ( (string) ( $f[ 'id' ] ?? '' ) === $family )
				foreach ( (array) ( $f[ 'versions' ] ?? array() ) as $v )
					if ( (string) ( $v[ 'version' ] ?? '' ) === $version && is_array( $v[ 'scenarios' ] ?? null ) )
						return( array( 'version' => $version, 'scenarios' => $v[ 'scenarios' ] ) );

		return( null );
	}


	/**
	 * The language the engine writes its own sentences in: German for a German site, English otherwise.
	 *
	 * ⚠️ THE ENGINE KNOWS ONLY 'de' AND 'en'. A French site gets the English sentence, and
	 * `translate_notice()` turns the visible notices into French through the plugin's own translation.
	 *
	 * @return	string	'de' or 'en'
	 * @since	09.10.2026
	 */

	public static function engine_language()
	{
		return( strpos( (string) determine_locale(), 'de' ) === 0 ? 'de' : 'en' );
	}


	/**
	 * A visible notice of a rule set in the language of the site.
	 *
	 * ⚠️ THE NOTICE IS A CONDITION OF THE PUBLISHER, NOT A COURTESY. FeRD wants every report of a check with the
	 * Factur-X / ZUGFeRD rules to say whose publication was applied. The engine writes it in German or English; a
	 * sentence this map does not know is shown as the engine wrote it, never dropped.
	 *
	 * @param	string	$notice	the sentence as the engine wrote it
	 * @return	string
	 * @since	09.10.2026
	 */

	public static function translate_notice( $notice )
	{
		$map = self::notice_map();

		return( isset( $map[ $notice ] ) && $map[ $notice ] !== '' ? (string) $map[ $notice ] : (string) $notice );
	}


	/**
	 * The visible notices of the rule sets as a map "sentence of the engine => translation".
	 *
	 * ⚠️ PUBLIC FOR THE BROWSER CHECK. VAMPIRE JS writes the same sentence as VAMPIRE PHP, and the page translates it with
	 * this map ( `SPPV_Plugin::browser_config()` ), so that a notice is translated in one place for both engines.
	 *
	 * @return	array<string,string>
	 * @since	1.13.0
	 */

	public static function notice_map()
	{
		return( array(
			'This check applies the publication Factur-X / ZUGFeRD of FNFE-MPE and FeRD.' => __( 'This check applies the publication Factur-X / ZUGFeRD of FNFE-MPE and FeRD.', 'einvoice-validator-for-vampire' ),
		) );
	}


	/**
	 * @return	string
	 * @since	1.0.0
	 */

	public function id()
	{
		return( 'php' );
	}


	/**
	 * @return	string
	 * @since	1.0.0
	 */

	public function label()
	{
		return( esc_html__( 'PHP ( bundled, no Java )', 'einvoice-validator-for-vampire' ) );
	}


	/**
	 * Validate one XML document and return its report in both shapes.
	 *
	 * ⚠️ THE XML REPORT IS THE KoSIT-SHAPED ONE ( VARL 1.0.0 ), not an invention of this plugin. That
	 * is what lets `einvoice-report` read it unchanged and the Validation Explainer hook its own
	 * rendering onto it - the same chain the sibling plugin feeds with the real KoSIT validator's
	 * output.
	 *
	 * ⚠️ `rep:engine/rep:name` says THIS library and its version, never "KoSIT Validator". The format
	 * is borrowed, the verdict is ours; a report claiming to come from the reference implementation
	 * would be a statement nobody reading it could check.
	 *
	 * ⚠️ `accepted` STAYS A BOOLEAN, `verdict` CAN BE NULL. Without the rule sets of the scenario the
	 * engine does not check the document at all ( "Regelwerk nicht enthalten", rule 29 ). `accepted` is
	 * false then, because the KoSIT format knows only accept and reject; `verdict` is null, and the view
	 * says "no verdict" instead of "rejected". Every caller that shows a result reads `verdict`.
	 *
	 * @param	string	$xml		the document's raw bytes
	 * @param	array	$options	array{ rule_report?: bool, edition?: array, scenario?: string } - rule_report asks for the
	 *							positive report ( JSON ), edition ( from `edition_scenarios()` ) checks with the scenarios of one
	 *							edition of the cascade, scenario ( an id of `scenarios()` ) checks with that scenario only
	 * @return	array				array{ html: string, xml: string, accepted: bool, verdict: bool|null, notices: string[], missing: string[], edition: string, aborted: string, rule_report: string|null, rule_section: array|null }
	 *                              `edition`: the edition named in BT-24 that this package does not carry ( no verdict );
	 *                              `aborted`: the engine's reason when the check stopped at a rule ( no verdict, no report )
	 * @throws	Exception			when the library cannot run at all ( missing rule packages, broken install )
	 * @since	1.0.0
	 */

	public function validate( $xml, array $options = array() )
	{

		$dir         = self::rules_dir();
		$rule_report = ! empty( $options[ 'rule_report' ] ) && self::supports_rule_report();

		if ( ! is_file( $dir . '/rulesets/scenarios.json' ) )
			throw new \RuntimeException( esc_html__( 'The rule packages are missing from this installation.', 'einvoice-validator-for-vampire' ) );

		try
		{
			$edition  = is_array( $options[ 'edition' ] ?? null ) ? $options[ 'edition' ] : null;
			$resolver = $edition !== null
				? \EinvoiceValidator\Pipeline\ScenarioResolver::fromArray( $edition[ 'scenarios' ] )
				: \EinvoiceValidator\Pipeline\ScenarioResolver::fromJsonFile( $dir . '/rulesets/scenarios.json' );

			/*
			 * ⚠️ AN EDITION OF ITS OWN IS SEARCHED IN ITS OWN FOLDER FIRST, exactly as `Cascade::validator()` builds it: the
			 * edition packages lie in `rulesets/versions/`, their code lists in `rulesets/versions/codedb`. Built any other
			 * way, the report below the matrix would not be the report the matrix counted.
			 */
			$extra = $edition !== null ? array( 'rulesetDirs' => array( $dir . '/rulesets/versions' ), 'documentDir' => 'rulesets/versions/codedb' ) : array();

			/*
			 * The rule profiles of the operator ( SPPV_Rule_Profiles ).
			 *
			 * ⚠️ A PROFILE THAT CANNOT BE LOADED STOPS THE PROFILES, NOT THE CHECK - AND SAYS SO. The engine throws
			 * on purpose, because a profile left out silently would let the operator believe its rules had run. The
			 * visitor still gets the check against the official rule sets, with a notice above the report that the
			 * operator's own rules were not applied. Refusing the whole upload instead would punish the visitor for
			 * a file only the operator can repair; the settings page shows the operator which file it is.
			 */
			$profiles       = array();
			$profile_notice = '';

			try
			{
				$profiles = SPPV_Rule_Profiles::load();
			}
			catch ( \Throwable $e )
			{
				$profile_notice = __( 'The rule profiles of this site could not be loaded. The invoice was checked against the official rule sets only.', 'einvoice-validator-for-vampire' );
			}

			// ⚠️ `ruleReport` ONLY WHEN IT IS WANTED AND KNOWN. An older engine export has no such
			// parameter, and a named argument it does not know is a fatal error, not a warning.
			$args = array( 'scenarioResolver' => $resolver, 'baseDir' => $dir, 'ruleProfiles' => $profiles, 'lang' => self::engine_language() ) + $extra;

			if ( $rule_report )
				$args[ 'ruleReport' ] = true;

			/*
			 * ⚠️ A SCENARIO CHOSEN FROM OUTSIDE IS TAKEN WITHOUT ITS `match` ( the comparison's slots, since 1.12.1 ). The engine
			 * checks the document with exactly that scenario's rule sets, also when the document would match another one;
			 * that is the purpose of choosing it. An id the package does not know stops the check with the engine's own
			 * sentence, and the caller shows it.
			 */
			$scenario = (string) ( $options[ 'scenario' ] ?? '' );

			if ( $scenario !== '' && $edition === null )
				$args[ 'forceScenarioId' ] = $scenario;

			$validator = new \EinvoiceValidator\Validator( ...$args );

			$result = $validator->validateString( (string) $xml );
		}
		catch ( \Throwable $e )
		{
			/*
			 * ⚠️ AN OFFICIAL RULE THAT CANNOT BE EVALUATED IS "NO VERDICT", NOT A BROKEN INSTALLATION. The engine stops
			 * the whole check when the expression of one rule throws ( a construct the interpreter does not know, or
			 * a value it cannot calculate with ). Passed on as an upload error, in the same red line as "the rule
			 * packages are missing", the visitor could not tell "the invoice was not checked" from "the plugin is
			 * broken". Whether the engine will one day skip only that rule is open ( OFFENE-FRAGEN.md ); both ways
			 * end in "no verdict" for a document without another error, so the plugin says exactly that, with the
			 * engine's reason.
			 */
			if ( self::is_rule_abort( $e ) )
				return( self::aborted( $e ) );


			// ⚠️ The message is passed through, not replaced by a generic one. The library's own
			// errors name the file it could not find or the construct it could not evaluate - on a
			// shared host that is the only diagnosis anyone will ever get.
			//
			// ⚠️ Escaped HERE, at the throw site, not wherever it is printed. The message can carry
			// a file path, and whoever catches it prints it somewhere; this is the one place that
			// covers every one of those paths. It is also what the WPCS sniff `ExceptionNotEscaped`
			// asks for. The third argument - the previous exception that makes the chain - is an
			// object, is never printed, and cannot be escaped; dropping it to satisfy the sniff
			// would throw away the cause of the failure.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message IS escaped; the flagged token is the $previous exception of the chain.
			throw new \RuntimeException( esc_html( $e->getMessage() ), 0, $e );
		}

		$report = new \EinvoiceValidator\Report\KositXmlReport(
			'invoicepress/vampire-php',
			SPPV_VERSION
		);

		// ⚠️ `hasVerdict()` and `notices()` exist only in a recent engine. With an older export a
		// document always has a verdict and no rule set carries a notice, which is what that engine did.
		$has_verdict = method_exists( $result, 'hasVerdict' ) ? $result->hasVerdict() : true;
		$notices     = method_exists( $result, 'notices' ) ? array_map( array( __CLASS__, 'translate_notice' ), $result->notices() ) : array();
		$missing     = method_exists( $result, 'missingRulesets' ) ? $result->missingRulesets() : array();

		// The notices of the profiles themselves ( a profile written for another edition, an unknown rule ) need
		// nothing here: the engine writes them as information findings into the step of the profile.
		if ( $profile_notice !== '' )
			$notices[] = $profile_notice;

		/*
		 * ⚠️ THE SECTION ONCE AS AN ARRAY, FOR THE VIEW AND THE PRINT VERSION. Decoding the JSON string again would
		 * cost the same work twice; `RuleReport::toArray()` is what `JsonReport` writes, so the view and the
		 * downloaded file cannot disagree.
		 */
		$rule_obj = $rule_report && method_exists( $result, 'ruleReport' ) ? $result->ruleReport() : null;

		return( array(
			'xml'          => $report->render( $result, (string) $xml ),
			'html'         => ( new \EinvoiceValidator\Report\HtmlReport() )->render( $result ),
			'accepted'     => $result->isAccepted(),
			'verdict'      => $has_verdict ? $result->isAccepted() : null,
			'notices'      => array_values( $notices ),
			'missing'      => array_values( $missing ),
			'edition'      => $has_verdict ? '' : self::edition_not_included( $missing ),
			'aborted'      => '',
			'rule_report'  => $rule_report ? ( new \EinvoiceValidator\Report\JsonReport() )->toJson( $result ) : null,
			'rule_section' => $rule_obj !== null ? $rule_obj->toArray( $result ) : null,
			// For the values of a finding from the rule ( SPPV_Rule_Values ): the packages this check ran, and where they lie.
			'rulesets'     => method_exists( $result, 'rulesets' ) ? array_values( $result->rulesets() ) : array(),
			'ruleset_dirs' => $edition !== null ? array( $dir . '/rulesets/versions', $dir . '/rulesets' ) : array( $dir . '/rulesets' ),
		) );

	}


	/**
	 * The edition a document names in BT-24 when this package carries no rule set for it, or ''.
	 *
	 * Since the library of 09.10.2026 a document naming XRechnung 4.0 or EN 16931-1:2026 gets no verdict, and
	 * `missingRulesets()` carries the edition's LABEL instead of a rule set reference. The panel then has to say
	 * "this edition is not included yet" - "the rule set XRechnung 4.0 is missing from this installation" would
	 * send the operator looking for a file that could be uploaded.
	 *
	 * ⚠️ READ FROM THE LABEL, BECAUSE THE TWO KINDS LOOK DIFFERENT AND THE ENGINE NAMES NO KIND. A rule set reference
	 * has no space and carries an edition after `@` ( `PEPPOL-EN16931-UBL@3.0.21` ); an edition label is exactly what
	 * `Validator::editionNotIncluded()` writes, "XRechnung 4.0" or "EN 16931-1:2026". If the engine ever changes that
	 * label, the panel falls back to the general sentence about a missing rule set - still "no verdict", only less
	 * precise.
	 *
	 * @param	string[]	$missing	what `missingRulesets()` returned
	 * @return	string
	 * @since	11.10.2026
	 */

	public static function edition_not_included( array $missing )
	{

		foreach ( $missing as $label )
			if ( preg_match( '/^(?:XRechnung \d+\.\d+|EN 16931-1:\d{4})$/', (string) $label ) === 1 )
				return( (string) $label );

		return( '' );

	}


	/**
	 * Whether an exception came out of the evaluation of a rule, as opposed to a broken installation.
	 *
	 * ⚠️ DECIDED BY WHERE IT WAS THROWN, NOT BY ITS CLASS ALONE. The interpreter throws its own
	 * `UnsupportedConstructException`, but the runtime throws plain `InvalidArgumentException` ( a value like
	 * "VATEX-EU-132-1A" read as a number ), `DivisionByZeroError` and `LogicException` - the same classes the
	 * loader throws for a damaged rule package. The file tells them apart: everything under
	 * `src/Xpath/Interpreter.php`, `src/Xpath/Runtime/` and `src/Engine/` runs while a rule is evaluated; the AST
	 * classes under `src/Xpath/Ast/` and the rule set codec run while a package is read, and a package that cannot be
	 * read is the operator's problem, not the visitor's.
	 *
	 * @param	\Throwable	$e
	 * @return	bool
	 * @since	11.10.2026
	 */

	public static function is_rule_abort( \Throwable $e )
	{

		// ⚠️ A BROKEN RULE PACKAGE IS NEVER A RULE ABORT, wherever it was thrown. Since VAMPIRE skips a rule that throws on
		// one document, only a package fault still ends the check ( a side document missing for fn:document(), and the like ).
		// It is raised from src/Xpath/Runtime/, so the file test below would call it an abort at a rule; it is the
		// operator's problem, like any other damaged installation.
		if ( is_a( $e, '\EinvoiceValidator\Exception\RulesetBrokenException' ) )
			return( false );

		if ( is_a( $e, '\EinvoiceValidator\Exception\UnsupportedConstructException' ) )
			return( true );

		// ⚠️ Forward slashes: on Windows getFile() has backslashes, and a pattern with "/" would never match there.
		$file = str_replace( '\\', '/', (string) $e->getFile() );

		return( (bool) preg_match( '#/src/(?:Xpath/Interpreter\.php|Xpath/Runtime/|Engine/)#', $file ) );

	}


	/**
	 * The result of a check that stopped at a rule: no verdict, no report, the engine's reason.
	 *
	 * ⚠️ NO KoSIT-SHAPED REPORT, ON PURPOSE. The engine produced no `Result`; inventing a report around the abort
	 * would put a `rep:reject` into the visitor's download for an invoice nobody judged. `xml` is empty, and the
	 * uploader then offers no `report.xml` ( sppv-uploader.js ).
	 *
	 * @param	\Throwable	$e
	 * @return	array	the shape of validate()
	 * @since	11.10.2026
	 */

	private static function aborted( \Throwable $e )
	{

		return( array(
			'xml'          => '',
			'html'         => '',
			'accepted'     => false,
			'verdict'      => null,
			'notices'      => array(),
			'missing'      => array(),
			'edition'      => '',
			// ⚠️ Unescaped here; the panel escapes it where it prints it ( SPPV_Plugin::no_verdict_panel() ).
			'aborted'      => (string) $e->getMessage(),
			'rule_report'  => null,
			'rule_section' => null,
		) );

	}


	/**
	 * Systemcheck lines for the settings page - checked fresh on every call, no caching. Same shape
	 * as the sibling's adapters return, so the settings markup could be shared later.
	 *
	 * ⚠️ MEMORY AND TIME ARE REAL LIMITS HERE, NOT BOILERPLATE. Measured with the library's own
	 * benchmark ( docs/performance.md ): an invoice with 1000 line items needs about 40 seconds and
	 * 174 MB. A 128 MB `memory_limit` cannot check such a document at all - and the failure would
	 * arrive as a blank page, not as a report. Better to say so on the settings page.
	 *
	 * @return	array	list of array{ id: string, label: string, ok: bool, detail: string, next_step: string }
	 * @since	1.0.0
	 */

	public function check()
	{

		$lines = array();

		$lines[] = array(
			'id'        => 'php',
			'label'     => esc_html__( 'PHP version', 'einvoice-validator-for-vampire' ),
			'ok'        => version_compare( PHP_VERSION, '8.2', '>=' ),
			'detail'    => PHP_VERSION,
			'next_step' => version_compare( PHP_VERSION, '8.2', '>=' ) ? '' : esc_html__( 'Ask your host for PHP 8.2 or newer.', 'einvoice-validator-for-vampire' ),
		);

		foreach ( array( 'dom', 'libxml', 'mbstring', 'bcmath' ) as $ext )
		{
			$ok = extension_loaded( $ext );

			$lines[] = array(
				'id'        => 'ext-' . $ext,
				'label'     => sprintf(
					/* translators: %s is the name of a PHP extension, e.g. "bcmath". */
					esc_html__( 'PHP extension %s', 'einvoice-validator-for-vampire' ),
					$ext
				),
				'ok'        => $ok,
				'detail'    => $ok ? esc_html__( 'loaded', 'einvoice-validator-for-vampire' ) : esc_html__( 'missing', 'einvoice-validator-for-vampire' ),
				'next_step' => $ok ? '' : sprintf(
					/* translators: %s is the name of a PHP extension, e.g. "bcmath". */
					esc_html__( 'Ask your host to enable %s. Without it the validator cannot run.', 'einvoice-validator-for-vampire' ),
					$ext
				),
			);
		}

		$limit = self::memory_limit_bytes();
		$enough = ( $limit === -1 || $limit >= 256 * MB_IN_BYTES );

		$lines[] = array(
			'id'        => 'memory',
			'label'     => esc_html__( 'Memory limit', 'einvoice-validator-for-vampire' ),
			'ok'        => $enough,
			'detail'    => ( $limit === -1 ? esc_html__( 'unlimited', 'einvoice-validator-for-vampire' ) : size_format( $limit ) ),
			'next_step' => $enough ? '' : esc_html__( 'Ordinary invoices work from about 128 MB. Very large ones ( several hundred line items ) need 256 MB or more - measured: 1000 line items use about 174 MB.', 'einvoice-validator-for-vampire' ),
		);

		$seconds = (int) ini_get( 'max_execution_time' );
		$time_ok = ( $seconds === 0 || $seconds >= 60 );

		$lines[] = array(
			'id'        => 'time',
			'label'     => esc_html__( 'Maximum execution time', 'einvoice-validator-for-vampire' ),
			'ok'        => $time_ok,
			'detail'    => ( $seconds === 0 ? esc_html__( 'unlimited', 'einvoice-validator-for-vampire' ) : sprintf(
				/* translators: %d is a number of seconds. */
				esc_html__( '%d seconds', 'einvoice-validator-for-vampire' ),
				$seconds
			) ),
			'next_step' => $time_ok ? '' : esc_html__( 'An ordinary invoice is checked in under two seconds. Large ones need longer - measured: 1000 line items take about 40 seconds.', 'einvoice-validator-for-vampire' ),
		);

		// ⚠️ COUNTED BY THE HEADS, NOT BY `*@*.json`. The export writes `EN16931-UBL-1.3.16.json` without
		// the at sign ( Plugin Check refuses it in file names ), so that pattern found nothing in the
		// plugin and the line said "0 found" next to eight working rule sets.
		$count    = count( SPPV_Rule_Packages::read_rulesets( self::rules_dir() ) );
		$uploaded = SPPV_Rule_Packages::installed();

		$lines[] = array(
			'id'        => 'rulesets',
			'label'     => esc_html__( 'Rule packages', 'einvoice-validator-for-vampire' ),
			'ok'        => ( $count > 0 ),
			'detail'    => sprintf(
				/* translators: %d is the number of rule packages found. */
				esc_html__( '%d found', 'einvoice-validator-for-vampire' ),
				$count
			) . ' · ' . ( SPPV_Rule_Packages::active_dir() !== '' && $uploaded !== null
				? sprintf(
					/* translators: %s is the edition of an uploaded rule package, e.g. "2026-10-09". */
					esc_html__( 'uploaded rule package, edition %s', 'einvoice-validator-for-vampire' ),
					esc_html( (string) $uploaded[ 'edition' ] )
				)
				: esc_html__( 'bundled with the plugin', 'einvoice-validator-for-vampire' ) ),
			'next_step' => ( $count > 0 ? '' : esc_html__( 'The rule packages of the validator are missing. Re-install the plugin.', 'einvoice-validator-for-vampire' ) ),
		);

		return( $lines );

	}


	/**
	 * `memory_limit` in bytes, or -1 for "no limit".
	 *
	 * ⚠️ ini_get() returns a SHORTHAND STRING ( "256M" ), not a number. Casting it to int yields 256,
	 * which compares as "far too little" against a byte figure and would show a red line on a
	 * perfectly fine server.
	 *
	 * @return	int
	 * @since	1.0.0
	 */

	private static function memory_limit_bytes()
	{

		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( $raw === '' || $raw === '-1' )
			return( -1 );

		return( (int) wp_convert_hr_to_bytes( $raw ) );

	}


}
