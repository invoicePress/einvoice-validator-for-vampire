<?php
/**
 * Facade onto the shared report renderer.
 *
 * @package SP_EInvoice_Validation_Explainer
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;

/*
 * ⚠️ PORTED FROM `einvoice-validation-explorer-for-kosit`, AND KEPT IN STEP WITH IT.
 *
 * The validator shows its own report in the same plain-language view as the Validation Explorer,
 * whether or not the explorer is installed. The view itself lives in libs/einvoice-report; what a
 * library cannot carry - the rule lookup and the translatable strings with a literal text domain -
 * has to live in each plugin. A change to the explorer's copy of this file belongs here as well.
 */


/**
 * class: SPPV_Explainer_View
 *
 * ============================================================================================
 * ⚠️ THE OUTPUT LIVES IN `libs/einvoice-report`
 * ============================================================================================
 *
 * The complete renderer is `\SpecialPress\EInvoice\Report\Explainer\Renderer` - it is the base of the
 * report output in KoSIT style, so it must lie where every frontend can include it, not in a single plugin.
 * Over the twenty example reports in `_einvoice-shared-data/examples/validation_reports` its HTML output is
 * **byte for byte** the same as that of the plugin version it was moved from.
 *
 * What stays here are exactly the two things a library package cannot have:
 *
 * 1. **The rule and field lookup** - `SPPV_Terms` below, a thin wrapper around `SPPV_Rules`.
 * 2. ⚠️ **THE TRANSLATABLE STRINGS.** They stand below as a map, each with a **literal**
 *    `__( '…', 'einvoice-validator-for-vampire' )`.
 *
 *    That looks needlessly roundabout and is not. The WordPress tools find translatable texts by searching
 *    the source for literal `__()` calls. A callback ( `$t( 'Errors' )` ) in the package produces **not a
 *    single line** in the `.pot`, the existing German translations of this plugin would be orphaned, and the
 *    WordPress.org review objects to exactly this pattern. Besides, a library package has no text domain of
 *    its own that WordPress could load.
 *
 * @since 1.0.0
 */

class SPPV_Explainer_View
{


	/**
	 * Render a report.
	 *
	 * ⚠️ `$rule_section` IS PASSED ON AS A SECOND ARGUMENT, and an older copy of the shared renderer simply does
	 * not take it. `SPPV_Plugin::report_view()` then appends the section itself.
	 *
	 * @param	\SpecialPress\EInvoice\Report\Report	$report
	 * @param	array|null								$rule_section	the section `ruleReport` of the JSON report, or null
	 * @return	string
	 * @since	1.0.0
	 */

	public static function render( \SpecialPress\EInvoice\Report\Report $report, $rule_section = null )
	{
		$renderer = new \SpecialPress\EInvoice\Report\Explainer\Renderer(
			new SPPV_Terms(),
			self::strings()
		);

		// ⚠️ The third argument carries the switch "Rule text per line"; an older copy of the renderer drops it and shows the texts.
		$html = is_array( $rule_section ) ? $renderer->render( $report, $rule_section, SPPV_Plugin::rule_report_options() ) : $renderer->render( $report );

		/**
		 * ⚠️ AFTER RENDERING, BECAUSE THE RENDERER IS WHAT ASKS. `SPPV_Rules::field_xpath()` notes a
		 * field of an extension ( BT-X-… ) it cannot resolve while the renderer walks the findings; only
		 * then is it known whether this report needed the "Extended Profiles" add-on. The notice goes ABOVE
		 * the report, like the reader's own notices, but not through `$report->notices`: the renderer
		 * puts the heading "This report could only be read in part" over each of those, and this report
		 * was read in full.
		 */
		if ( SPPV_Rules::extension_fields_missing() )
			$html = self::extension_notice() . $html;

		return( $html );
	}


	/**
	 * The notice for a report that names fields of an extension while the add-on is not active.
	 *
	 * ⚠️ IT SAYS WHAT THE READER SEES AND WHAT IS MISSING, NOT THAT SOMETHING WENT WRONG. The report is
	 * complete; only the values of the extension fields cannot be located without their catalogue.
	 * Same wording as the notice of the data loader in the apps, which also speaks of what is shown.
	 *
	 * @return	string
	 * @since	08.10.2026
	 */

	private static function extension_notice()
	{
		return( '<div class="verdict warn"><div class="verdict-main"><div>'
			. '<div class="verdict-title">' . esc_html__( 'This report names fields of an extension', 'einvoice-validator-for-vampire' ) . '</div>'
			. '<div class="verdict-desc">' . esc_html__( 'The fields of the extension (BT-X) are shown by their number only, without their value from the document. The Extended Profiles add-on brings the catalogue that finds these values.', 'einvoice-validator-for-vampire' ) . '</div>'
			. '</div></div></div>' );
	}


	/**
	 * The notices of the report readers, translated - as a map "English sentence => translation".
	 *
	 * ⚠️ THE LIBRARY'S READERS WRITE THEIR NOTICES IN ENGLISH. They have no text domain of their own; the
	 * translation therefore happens here, with literal `__()` calls, and `Renderer::notice()` looks every
	 * notice up in this map. A sentence missing here stays English. Whoever changes or adds a notice in a
	 * reader changes it here in the same step, and in all five plugins that show the report view.
	 *
	 * ⚠️ THE SENTENCE WITH `%1$d` AND `%2$d` IS A SENTENCE FRAME. The reader puts in the numbers before the
	 * sentence arrives here; `Renderer::notice()` recognises it by the pattern and puts the numbers into the
	 * translation again.
	 *
	 * @return	array<string,string>
	 */

	private static function notices()
	{
		return array(
			'The report header could not be read; the document details are missing.' => __( 'The report header could not be read; the document details are missing.', 'einvoice-validator-for-vampire' ),
			'The report contains a summary but no detailed findings table.' => __( 'The report contains a summary but no detailed findings table.', 'einvoice-validator-for-vampire' ),
			'The document view could not be read from the report; only the findings are shown.' => __( 'The document view could not be read from the report; only the findings are shown.', 'einvoice-validator-for-vampire' ),
			/* translators: 1: number of findings read, 2: number of findings the report states. */
			'Only %1$d of the %2$d findings this report states could be read. The report layout may have changed - please check the original report.' => __( 'Only %1$d of the %2$d findings this report states could be read. The report layout may have changed - please check the original report.', 'einvoice-validator-for-vampire' ),
			'None of the findings could be located in the document view, although the report names a position for them. The values shown per finding may be incomplete.' => __( 'None of the findings could be located in the document view, although the report names a position for them. The values shown per finding may be incomplete.', 'einvoice-validator-for-vampire' ),
			'This report declared UTF-8 but was not encoded as UTF-8; it was converted from Windows-1252 to be readable. Umlauts may differ from the validator\'s original output.' => __( 'This report declared UTF-8 but was not encoded as UTF-8; it was converted from Windows-1252 to be readable. Umlauts may differ from the validator\'s original output.', 'einvoice-validator-for-vampire' ),
			'This validator reported an internal error while checking the document. Its verdict says more about the tool than about the invoice.' => __( 'This validator reported an internal error while checking the document. Its result says more about the tool than about the invoice.', 'einvoice-validator-for-vampire' ),
			'This Mustang report states no verdict of its own; it was derived from the number of errors.' => __( 'This Mustang report states no result of its own; it was derived from the number of errors.', 'einvoice-validator-for-vampire' ),
			'This report format lists findings with their XPath, line and column, but does not carry the document itself. The document view stays empty.' => __( 'This report format lists findings with their XPath, line and column, but does not carry the document itself. The document view stays empty.', 'einvoice-validator-for-vampire' ),
			'The validator found no scenario for this document and therefore did not check it against any rule set. Its verdict says nothing about the document\'s content.' => __( 'The validator found no scenario for this document and therefore did not check it against any rule set. Its result says nothing about the document\'s content.', 'einvoice-validator-for-vampire' ),
			'This report carries no embedded HTML explanation, so the document view could not be read; only the findings are shown.' => __( 'This report carries no embedded HTML explanation, so the document view could not be read; only the findings are shown.', 'einvoice-validator-for-vampire' ),
			'The embedded explanation was empty, so the document view could not be read; only the findings are shown.' => __( 'The embedded explanation was empty, so the document view could not be read; only the findings are shown.', 'einvoice-validator-for-vampire' ),
			'The embedded explanation could not be read as HTML, so the document view is unavailable; only the findings are shown.' => __( 'The embedded explanation could not be read as HTML, so the document view is unavailable; only the findings are shown.', 'einvoice-validator-for-vampire' ),
		);
	}


	/**
	 * The translated strings of the report output.
	 *
	 * ⚠️ The keys are those from `Renderer::defaults()`. If one is missing here, the English default text
	 * appears - never an empty box, but never a translation either. Whoever adds a key in the package must
	 * fill it here.
	 *
	 * @return	array<string,string>
	 * @since	1.3.0
	 */

	/**
	 * The same strings for the browser check, which draws the report with the JavaScript twin of the renderer.
	 *
	 * ⚠️ ONE TABLE FOR BOTH DRAWINGS. The keys are those of `Renderer::defaults()` in PHP and in JavaScript; a second table
	 * for the browser would be translated twice and drift.
	 *
	 * @return	array<string,mixed>
	 * @since	1.13.0
	 */

	public static function browser_strings()
	{
		return( self::strings() );
	}


	private static function strings()
	{
		return array(
			'notices'                 => self::notices(),

			'notice_title'            => __( 'This report could only be read in part', 'einvoice-validator-for-vampire' ),
			'verdict_errors_rejected' => __( 'Errors — the document was rejected', 'einvoice-validator-for-vampire' ),
			'verdict_errors'          => __( 'Errors', 'einvoice-validator-for-vampire' ),
			// ⚠️ The sentence has a subject ( "Each one is listed below …" had none ), and PHP and JavaScript
			// must show the same sentence.
			'verdict_errors_desc'     => __( 'The errors found are listed below with the affected field and the value the document actually carries.', 'einvoice-validator-for-vampire' ),
			'stamp_rejected'          => __( 'rejected', 'einvoice-validator-for-vampire' ),
			'verdict_warnings'        => __( 'Warnings', 'einvoice-validator-for-vampire' ),
			// ⚠️ The title above the warnings, the same as in Renderer.mjs.
			'verdict_warnings_title'  => __( 'Findings — the document has recommendations', 'einvoice-validator-for-vampire' ),
			'verdict_warnings_desc'   => __( 'The warnings do not block the document. The recipient can process it, but some entries do not follow the recommendation.', 'einvoice-validator-for-vampire' ),
			'stamp_check'             => __( 'worth checking', 'einvoice-validator-for-vampire' ),
			'verdict_pass'            => __( 'No findings — the document passed', 'einvoice-validator-for-vampire' ),
			'verdict_pass_desc'       => __( 'The validator reported neither an error nor a warning for this document.', 'einvoice-validator-for-vampire' ),
			'stamp_accepted'          => __( 'accepted', 'einvoice-validator-for-vampire' ),
			'verdict_pdf_errors'      => __( 'Errors in the PDF part — the document is invalid', 'einvoice-validator-for-vampire' ),
			'verdict_pdf_errors_desc' => __( 'The invoice XML has no findings. Mustang found errors in the PDF part, for example in its metadata; its messages are in the original report.', 'einvoice-validator-for-vampire' ),
			'meta_report_format'      => __( 'Report format', 'einvoice-validator-for-vampire' ),
			'meta_validated_at'       => __( 'Time of validation', 'einvoice-validator-for-vampire' ),
			'meta_document_type'      => __( 'Document type', 'einvoice-validator-for-vampire' ),
			'meta_syntax'             => __( 'Syntax', 'einvoice-validator-for-vampire' ),
			'meta_seller'             => __( 'Seller', 'einvoice-validator-for-vampire' ),
			'meta_invoice_number'     => __( 'Invoice number', 'einvoice-validator-for-vampire' ),
			'meta_invoice_date'       => __( 'Invoice date', 'einvoice-validator-for-vampire' ),
			'meta_reference'          => __( 'Reference', 'einvoice-validator-for-vampire' ),
			'steps_title'             => __( 'Validation steps', 'einvoice-validator-for-vampire' ),
			'steps_step'              => __( 'Step', 'einvoice-validator-for-vampire' ),
			'steps_errors'            => __( 'Errors', 'einvoice-validator-for-vampire' ),
			'steps_warnings'          => __( 'Warnings', 'einvoice-validator-for-vampire' ),
			'steps_information'       => __( 'Information', 'einvoice-validator-for-vampire' ),
			'findings_title'          => __( 'Findings', 'einvoice-validator-for-vampire' ),

			/* translators: 1: number of errors, 2: number of warnings. */
			'findings_count'          => __( '%1$d errors · %2$d warnings', 'einvoice-validator-for-vampire' ),

			'severity_error'          => __( 'Error', 'einvoice-validator-for-vampire' ),
			'severity_warning'        => __( 'Warning', 'einvoice-validator-for-vampire' ),
			'severity_information'    => __( 'Information', 'einvoice-validator-for-vampire' ),
			'no_rule_id'              => __( 'no rule id', 'einvoice-validator-for-vampire' ),
			/* translators: %s is the rule profile, e.g. "kunde-ust@1.0.0". */
			'origin_profile'          => __( 'House rule of the check profile %s', 'einvoice-validator-for-vampire' ),
			'lang_de'                 => __( 'German', 'einvoice-validator-for-vampire' ),
			'lang_en'                 => __( 'English', 'einvoice-validator-for-vampire' ),
			'lang_fr'                 => __( 'French', 'einvoice-validator-for-vampire' ),

			/* translators: %s: language name, e.g. "English" */
			'no_translation'          => __( 'No translation is available for this rule text yet - shown in %s, not translated.', 'einvoice-validator-for-vampire' ),

			// Below a rule text that was translated by machine and is not reviewed yet.
			'machine_translation'     => __( 'This rule text was translated by machine and has not been reviewed yet.', 'einvoice-validator-for-vampire' ),

			/* translators: %d is the number of elements directly below a group element of the document. */

			/* translators: %s is a value exactly as the document writes it, e.g. "5990" for an amount shown as "5,990.00". */
			'raw_value'               => __( 'As written in the document: %s', 'einvoice-validator-for-vampire' ),

			'validator_message'       => __( 'The validator\'s wording', 'einvoice-validator-for-vampire' ),

			'no_explanation'          => __( 'No plain-language explanation is available for this rule yet. The message above is the validator\'s own wording.', 'einvoice-validator-for-vampire' ),
			'location_label'          => __( 'Location in the document', 'einvoice-validator-for-vampire' ),
			'affected_field'          => __( 'Affected field', 'einvoice-validator-for-vampire' ),
			'not_present'             => __( 'not present in the document', 'einvoice-validator-for-vampire' ),
			'empty_value'             => __( 'empty', 'einvoice-validator-for-vampire' ),

			/* translators: %d is the total number of characters of the value. */
			'chars_total'             => __( '%d characters in total', 'einvoice-validator-for-vampire' ),

			/* translators: %s is the position of the element inside the document. */
			'position'                => __( 'position %s', 'einvoice-validator-for-vampire' ),

			/* translators: %d is the number of further affected positions. */
			'further_positions'       => __( 'and %d further positions', 'einvoice-validator-for-vampire' ),

			'document_title'          => __( 'Document content', 'einvoice-validator-for-vampire' ),

			/* translators: %d is the number of elements in the document. */
			'elements_count'          => __( '%d elements', 'einvoice-validator-for-vampire' ),

			'search_placeholder'      => __( 'Search BT field, element name or value …', 'einvoice-validator-for-vampire' ),
			'toggle_bt'               => __( 'BT numbers', 'einvoice-validator-for-vampire' ),
			'toggle_findings'         => __( 'findings only', 'einvoice-validator-for-vampire' ),

			/* translators: %d is the total number of characters of the value. */
			'chars'                   => __( '%d characters', 'einvoice-validator-for-vampire' ),

			// ⚠️ NOT THE EXPLORER'S SENTENCE. The shared renderer's default says the tool "does not validate anything itself"; that is
			// true for the Validation Explorer and false here, where the plugin IS the validator ( Ralf, 19.10.2026 ).
			'disclaimer'              => __( 'The explanations under the findings help you read them. What counts is the validator\'s report itself, which you can save with "Download report.xml".', 'einvoice-validator-for-vampire' ),

			// The label in front of the attachment a BG-24 finding is about ( einvoice-report 1.12.0 ).
			// ⚠️ Without this key the shared renderer falls back to its English default on every site.
			'attachment'              => __( 'Attachment', 'einvoice-validator-for-vampire' ),
		) + self::rule_report_strings();
	}


	/**
	 * The texts of the section "Checked rules", keys of
	 * `\SpecialPress\EInvoice\Report\Explainer\RuleReportSection::defaults()`.
	 *
	 * ⚠️ THIS PLUGIN'S `.po` IS THE ONE SOURCE OF THESE TRANSLATIONS. The JavaScript twin in `libs/einvoice-core-js`
	 * reads tables built from it by `_claude-tools/explainer-strings-build.php`; a text changed here must be
	 * rebuilt there, or the apps say something else than this plugin.
	 *
	 * ⚠️ PUBLIC, BECAUSE THE PRINT VERSION AND THE DOWNLOAD BUTTONS NEED THE SAME TEXTS ( `SPPV_Plugin` ).
	 *
	 * @return	array<string,string>
	 * @since	09.10.2026
	 */

	public static function rule_report_strings()
	{
		return array(
			'rules_title'                 => __( 'Checked rules', 'einvoice-validator-for-vampire' ),
			'rules_evidence'              => __( 'This report is technical evidence of a check against the rule sets in the editions named here. It is not an audit opinion.', 'einvoice-validator-for-vampire' ),
			/* translators: %d is the number of rules listed. */
			'rules_total'                 => __( '%d rules', 'einvoice-validator-for-vampire' ),
			'rules_document'              => __( 'Checked file', 'einvoice-validator-for-vampire' ),
			/* translators: %d is the size of the checked file in bytes. */
			'rules_bytes'                 => __( '%d bytes', 'einvoice-validator-for-vampire' ),
			'rules_sha256'                => __( 'SHA-256 of the checked file', 'einvoice-validator-for-vampire' ),
			'rules_validated_at'          => __( 'Time of the check (UTC)', 'einvoice-validator-for-vampire' ),
			'rules_engine'                => __( 'Engine', 'einvoice-validator-for-vampire' ),
			'rules_scenario'              => __( 'Scenario', 'einvoice-validator-for-vampire' ),
			'rules_specification'         => __( 'Specification identifier (BT-24)', 'einvoice-validator-for-vampire' ),
			'rules_rulesets'              => __( 'Rule sets', 'einvoice-validator-for-vampire' ),
			'rules_col_ruleset'           => __( 'Rule set', 'einvoice-validator-for-vampire' ),
			'rules_col_title'             => __( 'Title', 'einvoice-validator-for-vampire' ),
			'rules_col_license'           => __( 'Licence', 'einvoice-validator-for-vampire' ),
			'rules_col_count'             => __( 'Rules', 'einvoice-validator-for-vampire' ),
			'rules_col_state'             => __( 'State', 'einvoice-validator-for-vampire' ),
			'rules_ruleset_ran'           => __( 'checked', 'einvoice-validator-for-vampire' ),
			'rules_steps'                 => __( 'Steps of the check', 'einvoice-validator-for-vampire' ),
			'rules_step_wellformed'       => __( 'Well-formed XML', 'einvoice-validator-for-vampire' ),
			'rules_step_scenario'         => __( 'Scenario', 'einvoice-validator-for-vampire' ),
			'rules_step_schema'           => __( 'XML schema', 'einvoice-validator-for-vampire' ),
			'rules_step_rules'            => __( 'Rules', 'einvoice-validator-for-vampire' ),
			'rules_step_passed'           => __( 'passed', 'einvoice-validator-for-vampire' ),
			'rules_step_failed'           => __( 'failed', 'einvoice-validator-for-vampire' ),
			'rules_filter'                => __( 'Show', 'einvoice-validator-for-vampire' ),
			'rules_filter_all'            => __( 'all', 'einvoice-validator-for-vampire' ),
			'rules_result_passed'         => __( 'satisfied', 'einvoice-validator-for-vampire' ),
			'rules_result_failed'         => __( 'violated', 'einvoice-validator-for-vampire' ),
			'rules_result_not_applicable' => __( 'not applicable', 'einvoice-validator-for-vampire' ),
			'rules_result_not_checked'    => __( 'not checked', 'einvoice-validator-for-vampire' ),
			'rules_col_rule'              => __( 'Rule', 'einvoice-validator-for-vampire' ),
			'rules_col_result'            => __( 'Result', 'einvoice-validator-for-vampire' ),
			'rules_col_severity'          => __( 'Severity', 'einvoice-validator-for-vampire' ),
			'rules_col_checked'           => __( 'Places checked', 'einvoice-validator-for-vampire' ),
			'rules_col_fired'             => __( 'Fired', 'einvoice-validator-for-vampire' ),
			'rules_col_text'              => __( 'Rule text', 'einvoice-validator-for-vampire' ),
			'rules_severity_fatal'        => __( 'error', 'einvoice-validator-for-vampire' ),
			'rules_severity_warning'      => __( 'warning', 'einvoice-validator-for-vampire' ),
			'rules_severity_information'  => __( 'information', 'einvoice-validator-for-vampire' ),
			/* translators: %s is a severity, e.g. "error". */
			'rules_severity_in_ruleset'   => __( 'in the rule set: %s', 'einvoice-validator-for-vampire' ),
			'rules_reason_stopped'        => __( 'An earlier step ended the check.', 'einvoice-validator-for-vampire' ),
			'rules_reason_not_included'   => __( 'The rule set is not part of this package.', 'einvoice-validator-for-vampire' ),
			'rules_reason_disabled'       => __( 'Switched off by an operator profile.', 'einvoice-validator-for-vampire' ),
			'rules_reason_error'          => __( 'The rule could not be evaluated.', 'einvoice-validator-for-vampire' ),
			'rules_reason_not_supported'  => __( 'This engine cannot carry out this step.', 'einvoice-validator-for-vampire' ),
			'rules_print_title'           => __( 'Validation report with every checked rule', 'einvoice-validator-for-vampire' ),
			'rules_print_hint'            => __( 'To keep this report as a PDF, open the print dialogue of the browser and choose “Save as PDF” as the printer. This line does not appear on paper.', 'einvoice-validator-for-vampire' ),
			'rules_verdict_accepted'      => __( 'Result: accepted', 'einvoice-validator-for-vampire' ),
			'rules_verdict_rejected'      => __( 'Result: rejected', 'einvoice-validator-for-vampire' ),
			'rules_verdict_none'          => __( 'No result', 'einvoice-validator-for-vampire' ),
			'rules_download_print'        => __( 'Print version of the checked rules ( HTML )', 'einvoice-validator-for-vampire' ),
			'rules_download_json'         => __( 'Report with every rule ( JSON )', 'einvoice-validator-for-vampire' ),
		);
	}

}


/**
 * class: SPPV_Terms
 *
 * The rule and field lookup the shared renderer is handed. Pure forwarding to `SPPV_Rules` - the interface
 * exists so the report package keeps its own boundary ( "No BT/BG mapping, no explanation text - that stays
 * in the eInvoice Validation Explainer" ).
 *
 * @since 1.3.0
 */

class SPPV_Terms implements \SpecialPress\EInvoice\Report\Explainer\Terms
{

	public function rule( string $id ): array
	{
		return( (array) SPPV_Rules::rule( $id ) );
	}

	public function fieldXpath( string $id, string $syntax ): string
	{
		return( (string) SPPV_Rules::field_xpath( $id, $syntax ) );
	}

	public function text( $value ): string
	{
		return( (string) SPPV_Rules::text( $value ) );
	}

	public function textLanguage( $value, string $preferred ): string
	{
		return( (string) SPPV_Rules::text_language( $value, $preferred ) );
	}

	public function language(): string
	{
		return( (string) SPPV_Rules::language() );
	}

	/*
	 * ⚠️ THE THREE METHODS BELOW ARE NOT PART OF THE INTERFACE, ON PURPOSE. The renderer asks for them with
	 * method_exists(), so an older copy of `einvoice-report` keeps working. Without them the report has no BT
	 * marks in the document, no business term as the label of an affected field and no amount format; that
	 * was the state of the validators until 18.10.2026.
	 */

	/** Every BT / BG id the catalogue knows - the BT marks of the document view. */
	public function fieldIds(): array
	{
		return( SPPV_Rules::field_ids() );
	}

	/** The business term in the site language - the tooltip of a BT mark, the label of an affected field. */
	public function fieldLabel( string $id ): string
	{
		return( (string) SPPV_Rules::term( $id ) );
	}

	/** The semantic data type - an "Amount" is shown with two decimals in the site's notation. */
	public function fieldType( string $id ): string
	{
		return( (string) SPPV_Rules::datatype( $id ) );
	}

}
