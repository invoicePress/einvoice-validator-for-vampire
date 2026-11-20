<?php
/**
 * Translation anchors for the strings the JavaScript bundle shows.
 *
 * ⚠️ **GENERATED FILE - DO NOT EDIT.** Produced by `_claude-tools/js-texts-to-php.php` from the
 * `t( '…' )` calls in the JavaScript source. Change the text in the JavaScript source and run the
 * tool again; editing here would create a second English original that silently drifts away from the
 * one the browser looks up.
 *
 * ⚠️ **NOTHING CALLS THIS METHOD, AND THAT IS THE POINT.** The browser does not read these strings
 * from here - it loads them from `i18n/<lang>.json.js`, which the build generates from this
 * plugin\'s own `.po`. They exist so a PHP text scanner ( `wp i18n make-pot`,
 * `_claude-tools/makepot.php` ) can see them, because no scanner reads JavaScript, and the `.pot`
 * is the file translate.wordpress.org offers to translators. Without this file every string that
 * only the browser shows would be invisible there.
 *
 * ⚠️ **THE LOOP IS CLOSED, WHICH IT WAS NOT BEFORE.** A translator edits the string on
 * translate.wordpress.org, it arrives in the `.po`, `_claude-tools/i18n-build.php` turns the `.po`
 * into the table the browser loads, and the browser shows it. An earlier version of this file was
 * withdrawn on the grounds that the browser never reads what PHP registers - true in itself, and
 * the wrong conclusion, because the path does not run through PHP at runtime but through the `.po`
 * at build time.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


class SPPV_Js_Texts
{

	/**
	 * The 29 strings of the JavaScript user interface, each with its source file named above it.
	 *
	 * The array is returned rather than assigned to a variable nobody reads, so no code checker has to
	 * decide whether an unused local is a mistake.
	 *
	 * @return string[]
	 */

	public static function anchors(): array
	{

		return array(
			// ModelAssembler.mjs
			__( 'Account holder', 'einvoice-validator-for-vampire' ),
			// ModelAssembler.mjs
			__( 'Allowance', 'einvoice-validator-for-vampire' ),
			// ModelAssembler.mjs
			__( 'Allowances & charges', 'einvoice-validator-for-vampire' ),
			// QubaTemplate.mjs
			__( 'Amount due', 'einvoice-validator-for-vampire' ),
			// ModelAssembler.mjs
			__( 'Charge', 'einvoice-validator-for-vampire' ),
			// QubaTemplate.mjs
			__( 'Currency', 'einvoice-validator-for-vampire' ),
			// Codelists.mjs
			__( 'Electronic mail', 'einvoice-validator-for-vampire' ),
			// QubaTemplate.mjs
			__( 'Invoice date', 'einvoice-validator-for-vampire' ),
			// ModelAssembler.mjs
			__( 'Item', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'Missing, although EN 16931 requires this field here (%s).', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'Missing: EN 16931 requires a reason or a reason code here (%s).', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'Missing: with VAT category %1$s, EN 16931 requires the buyer VAT identifier (%2$s).', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'Missing: with VAT category %1$s, EN 16931 requires the buyer VAT identifier or the buyer legal registration identifier (%2$s).', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'Missing: with VAT category %1$s, EN 16931 requires the seller VAT identifier or the tax representative VAT identifier (%2$s).', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'Missing: with VAT category %1$s, EN 16931 requires the seller VAT identifier, the seller tax registration identifier or the tax representative VAT identifier (%2$s).', 'einvoice-validator-for-vampire' ),
			// ModelAssembler.mjs
			__( 'No liability is assumed for the accuracy of the data.', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			__( 'Note: this is not a full validation. The viewer only checks mandatory fields, the VAT category rules, the order of the dates and whether the totals add up. A validator checks all rules.', 'einvoice-validator-for-vampire' ),
			// Codelists.mjs
			__( 'Related document', 'einvoice-validator-for-vampire' ),
			// ModelAssembler.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'Stated %1$s, calculated %2$s.', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'The end date is before the start date (%s).', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			__( 'The payment due date is before the invoice date.', 'einvoice-validator-for-vampire' ),
			// ModelAssembler.mjs
			__( 'Totals', 'einvoice-validator-for-vampire' ),
			// ModelAssembler.mjs
			__( 'VAT breakdown', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'With VAT category %1$s the VAT rate must be 0 (%2$s).', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'With VAT category %1$s the VAT rate must be greater than zero (%2$s).', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'With VAT category %1$s the tax amount must be 0 (%2$s).', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'With VAT category %1$s there must be no exemption reason and no exemption reason code (%2$s).', 'einvoice-validator-for-vampire' ),
			// InvoiceHints.mjs
			/* translators: the placeholders are values the JavaScript fills in at run time; see the source file named above. */
			__( 'With VAT category %1$s, EN 16931 requires an exemption reason or an exemption reason code (%2$s).', 'einvoice-validator-for-vampire' ),
			// ModelAssembler.mjs
			__( 'yes', 'einvoice-validator-for-vampire' ),
		);

	}

}
