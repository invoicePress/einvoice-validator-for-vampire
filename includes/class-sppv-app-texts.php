<?php
/**
 * The texts of the app eInvoice Validator for VAMPIRE JS, listed for the translation tools.
 *
 * @package SP_EInvoice_Validator_PHP
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


/**
 * class: SPPV_App_Texts
 *
 * ⚠️ **THIS CLASS IS NEVER CALLED, AND IT DOES NOT NEED TO BE.** The app `apps/einvoice-validator-for-vampire-js` ( eInvoice Validator for VAMPIRE JS, since 28.10.2026 a product of its own ) runs without WordPress. Its page
 * translates itself from the language tables `i18n/<lang>.json.js`, which `_claude-tools/i18n-build.php` generates from
 * this plugin's `.po`. A text gets into the `.po` only if a literal `__()` with this plugin's text domain stands in its
 * PHP source, so here it stands: app and plugin are one product and share one `.po`.
 *
 * ⚠️ **THE ENGLISH TEXT IS THE KEY.** `src/page.html` of the app carries exactly these strings in its `data-i18n` elements,
 * and `src/app.mjs` and `apps/shared/validator-page.mjs` pass exactly these to `t()`. A text changed there without the same
 * change here falls back to English, silently.
 *
 * ⚠️ **SOME ENTRIES CARRY MARKUP** ( `<strong>`, `<code>`, `<em>` ); the page sets them with `innerHTML`. The tables come
 * from our own `.po`, never from a visitor.
 *
 * ⚠️ **THE LAST FOUR ARE NOT TEXTS OF THE APP ITSELF.** The data loader writes "No package for <key>." and the two sentences
 * about the extensions in English, and the document view writes the reasons why a finding has no place; the page translates
 * them through `tNotice()` in `apps/shared/page-i18n.mjs`, so they must stand letter for letter as those modules write them.
 *
 * @since 25.10.2026
 */

class SPPV_App_Texts
{

	/**
	 * @return array<int,string>
	 */

	public static function all()
	{
		return array(
			__( 'Language', 'einvoice-validator-for-vampire' ),
			// The supplier pre-check page of a recipient ( pruefseite.js from the Rule Studio next to the app ).
			/* translators: %s is the name of the recipient of the invoice. */
			__( 'Check your invoice to %s here before you send it. It is checked in your browser and is not sent to anyone, not to us either.', 'einvoice-validator-for-vampire' ),
			__( 'Information sheet with the requirements', 'einvoice-validator-for-vampire' ),
			__( 'Rule package for the KoSIT validator', 'einvoice-validator-for-vampire' ),
			/* translators: %s is the name of the recipient of the invoice. */
			__( 'This invoice is not addressed to %s. It was checked against the official rules only.', 'einvoice-validator-for-vampire' ),
			/* translators: %s is the name of the recipient of the invoice. */
			__( 'The invoice is complete according to the requirements of %s.', 'einvoice-validator-for-vampire' ),
			/* translators: %s is the name of the recipient of the invoice. */
			__( 'The invoice does not yet meet the requirements of %s. The findings are listed below.', 'einvoice-validator-for-vampire' ),
			/* translators: %s is the name of the recipient of the invoice. */
			__( 'The requirements of %s could not be loaded, so this invoice was checked against the official rules only. VAMPIRE JS must lie as the folder vampire/ next to this page, with the folder vampire/profiles/ inside it.', 'einvoice-validator-for-vampire' ),
			/* translators: %s is the name of the recipient of the invoice. */
			__( 'The requirements of %s apply to invoices in another syntax. This invoice was checked against the official rules only.', 'einvoice-validator-for-vampire' ),
			__( 'A tool by {siteLink}.', 'einvoice-validator-for-vampire' ),
			__( 'Drag in an e-invoice — XRechnung, ZUGFeRD, Factur-X or PEPPOL, as XML or PDF. VAMPIRE JS checks it right here in the browser, and the report appears below with an explanation for every finding.', 'einvoice-validator-for-vampire' ),
			__( 'Drag an invoice here or click to choose a file', 'einvoice-validator-for-vampire' ),
			__( '<code>.xml</code> or a ZUGFeRD / Factur-X <code>.pdf</code>', 'einvoice-validator-for-vampire' ),
			__( 'Invoice:', 'einvoice-validator-for-vampire' ),
			__( 'Download report.xml', 'einvoice-validator-for-vampire' ),
			__( 'Reset', 'einvoice-validator-for-vampire' ),
			__( 'Findings without a place in the document:', 'einvoice-validator-for-vampire' ),
			__( 'This page checks the invoice in this browser with VAMPIRE JS. The invoice is <strong>not sent</strong>.', 'einvoice-validator-for-vampire' ),
			__( '⚠️ <strong>VAMPIRE JS is an add-on.</strong> It lies as the folder vampire/ or as the file vampire-js.js next to the start page of this app. Without it the page cannot check anything and says so.', 'einvoice-validator-for-vampire' ),
			__( '⚠️ <strong>What this page loads from outside is set in config.js.</strong> As delivered, the folder loads nothing from a website: the fonts and the rule catalogue lie inside it.', 'einvoice-validator-for-vampire' ),
			__( '⚠️ <strong>This single file loads data from {site} but sends nothing from the invoice there.</strong> German and French with their rule catalogues, the code lists, Kits A and S, the fonts and the manual come from there; English and its rule catalogue are inside the file. Without a connection it shows English.', 'einvoice-validator-for-vampire' ),
			__( '⚠️ <strong>Without the rule catalogue there are no plain-language explanations.</strong> If it cannot be loaded, the report shows the validator\'s own messages — complete, but in the validator\'s language.', 'einvoice-validator-for-vampire' ),
			__( 'Choose an invoice', 'einvoice-validator-for-vampire' ),
			__( 'Checking …', 'einvoice-validator-for-vampire' ),
			__( 'This PDF carries no invoice XML. Only ZUGFeRD and Factur-X PDFs can be checked.', 'einvoice-validator-for-vampire' ),
			__( 'The report could not be read.', 'einvoice-validator-for-vampire' ),
			__( 'The rule catalogue could not be loaded. Every finding is shown, but without field names and without a plain-language explanation.', 'einvoice-validator-for-vampire' ),
			__( 'Profile', 'einvoice-validator-for-vampire' ),
			__( 'Data:', 'einvoice-validator-for-vampire' ),
			__( 'without a rule ID', 'einvoice-validator-for-vampire' ),
			__( 'No package for', 'einvoice-validator-for-vampire' ),
			/* translators: %s: the names of the profile extensions, for example "zugferd-extended, factur-x-extended". */
			__( 'This document uses an extension ( %s ). The core fields are shown; the fields and rules of the extension come with Extended Profiles.', 'einvoice-validator-for-vampire' ),
			/* translators: %s: the names of the profile extensions, for example "zugferd-extended". */
			__( 'The Extended Profiles packages for %s could not be loaded; the core fields are shown.', 'einvoice-validator-for-vampire' ),
			__( 'Findings — the document has recommendations', 'einvoice-validator-for-vampire' ),
			__( 'The document could not be parsed as XML, so no finding could be placed in it.', 'einvoice-validator-for-vampire' ),
			__( 'The report gives no location for this finding - it points at the schema or the document as a whole, not at a node.', 'einvoice-validator-for-vampire' ),
			__( 'The location in the report does not match any node of this document ( neither the path itself nor its parent ).', 'einvoice-validator-for-vampire' ),
			__( 'VAMPIRE JS is not next to this page. This app checks with the add-on VAMPIRE JS; put its folder vampire/ or its file vampire-js.js next to the start page.', 'einvoice-validator-for-vampire' ),
			__( 'The check with VAMPIRE JS failed.', 'einvoice-validator-for-vampire' ),
			__( 'No result: no rule set of VAMPIRE JS covers this document.', 'einvoice-validator-for-vampire' ),
		);
	}

}
