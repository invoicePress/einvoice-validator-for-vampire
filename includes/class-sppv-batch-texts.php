<?php
/**
 * The texts of the batch check, for the page of this plugin and for the app eInvoice Validator for VAMPIRE JS.
 *
 * @package SP_EInvoice_Validator_PHP
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


/**
 * class: SPPV_Batch_Texts
 *
 * ⚠️ **ONE LIST FOR TWO SHELLS.** The batch module ( `libs/einvoice-core-js/src/batch/`, in this plugin as
 * `assets/js/einvoice-batch.bundle.js` ) passes its English texts to a function `t()`. On the page of this plugin that
 * function looks them up in the map below, which `SPPV_Plugin::enqueue_assets()` hands to the page as `SPPV.batch.texts`;
 * in the app the same texts come from the language tables that `_claude-tools/i18n-build.php` builds from this plugin's
 * `.po`. A text that is missing here stays English in both.
 *
 * ⚠️ **THE ENGLISH TEXT IS THE KEY, AND IT STANDS TWICE IN EVERY LINE ON PURPOSE.** The key is what the module asks
 * for; the literal inside `__()` is what the translation tools collect. A `__( $key )` in a loop would reach no `.pot`.
 *
 * @since 26.10.2026
 */

class SPPV_Batch_Texts
{

	/**
	 * @return array<string,string>
	 */

	public static function all()
	{
		return array(
			'Single check' => __( 'Single check', 'einvoice-validator-for-vampire' ),
			'Batch check' => __( 'Batch check', 'einvoice-validator-for-vampire' ),
			'Kind of check' => __( 'Kind of check', 'einvoice-validator-for-vampire' ),
			'Accepted' => __( 'Accepted', 'einvoice-validator-for-vampire' ),
			'Accepted with warnings' => __( 'Accepted with warnings', 'einvoice-validator-for-vampire' ),
			'Rejected' => __( 'Rejected', 'einvoice-validator-for-vampire' ),
			'No e-invoice' => __( 'No e-invoice', 'einvoice-validator-for-vampire' ),
			'Not checked: error' => __( 'Not checked: error', 'einvoice-validator-for-vampire' ),
			'Not checked' => __( 'Not checked', 'einvoice-validator-for-vampire' ),
			'This PDF carries no invoice XML.' => __( 'This PDF carries no invoice XML.', 'einvoice-validator-for-vampire' ),
			'This file is no invoice XML.' => __( 'This file is no invoice XML.', 'einvoice-validator-for-vampire' ),
			'Not checked, because the batch holds too many files.' => __( 'Not checked, because the batch holds too many files.', 'einvoice-validator-for-vampire' ),
			'Not checked, because the batch was cancelled.' => __( 'Not checked, because the batch was cancelled.', 'einvoice-validator-for-vampire' ),
			'Not checked, because the validation service refused further checks.' => __( 'Not checked, because the validation service refused further checks.', 'einvoice-validator-for-vampire' ),
			'Errors in the PDF part of the file' => __( 'Errors in the PDF part of the file', 'einvoice-validator-for-vampire' ),
			'File' => __( 'File', 'einvoice-validator-for-vampire' ),
			'Folder or archive' => __( 'Folder or archive', 'einvoice-validator-for-vampire' ),
			'Invoice number' => __( 'Invoice number', 'einvoice-validator-for-vampire' ),
			'Document type' => __( 'Document type', 'einvoice-validator-for-vampire' ),
			'Seller' => __( 'Seller', 'einvoice-validator-for-vampire' ),
			'Issue date' => __( 'Issue date', 'einvoice-validator-for-vampire' ),
			'Amount' => __( 'Amount', 'einvoice-validator-for-vampire' ),
			'Currency' => __( 'Currency', 'einvoice-validator-for-vampire' ),
			'Verdict' => __( 'Verdict', 'einvoice-validator-for-vampire' ),
			'Errors' => __( 'Errors', 'einvoice-validator-for-vampire' ),
			'Warnings' => __( 'Warnings', 'einvoice-validator-for-vampire' ),
			'Most frequent rules' => __( 'Most frequent rules', 'einvoice-validator-for-vampire' ),
			'Note' => __( 'Note', 'einvoice-validator-for-vampire' ),
			'Credit note' => __( 'Credit note', 'einvoice-validator-for-vampire' ),
			'Invoice' => __( 'Invoice', 'einvoice-validator-for-vampire' ),
			/* translators: %1$s is a date and time, %2$s the name of the tool. */
			'Created on %1$s with %2$s.' => __( 'Created on %1$s with %2$s.', 'einvoice-validator-for-vampire' ),
			'files' => __( 'files', 'einvoice-validator-for-vampire' ),
			'accepted' => __( 'accepted', 'einvoice-validator-for-vampire' ),
			'accepted with warnings' => __( 'accepted with warnings', 'einvoice-validator-for-vampire' ),
			'rejected' => __( 'rejected', 'einvoice-validator-for-vampire' ),
			'not checked' => __( 'not checked', 'einvoice-validator-for-vampire' ),
			'This overview lists the verdict of the validator for every file. The reasons stand in the single report of each invoice.' => __( 'This overview lists the verdict of the validator for every file. The reasons stand in the single report of each invoice.', 'einvoice-validator-for-vampire' ),
			'Drag invoices, a folder or a ZIP here, or click to choose files' => __( 'Drag invoices, a folder or a ZIP here, or click to choose files', 'einvoice-validator-for-vampire' ),
			'XML, ZUGFeRD / Factur-X PDF, ZIP or e-mail ( .eml ), as many as you like' => __( 'XML, ZUGFeRD / Factur-X PDF, ZIP or e-mail ( .eml ), as many as you like', 'einvoice-validator-for-vampire' ),
			'Choose invoices for the batch check' => __( 'Choose invoices for the batch check', 'einvoice-validator-for-vampire' ),
			'Choose a folder' => __( 'Choose a folder', 'einvoice-validator-for-vampire' ),
			'Cancel' => __( 'Cancel', 'einvoice-validator-for-vampire' ),
			'Search file, number, seller or rule' => __( 'Search file, number, seller or rule', 'einvoice-validator-for-vampire' ),
			'Show' => __( 'Show', 'einvoice-validator-for-vampire' ),
			'Download overview ( CSV )' => __( 'Download overview ( CSV )', 'einvoice-validator-for-vampire' ),
			'Summary report ( HTML )' => __( 'Summary report ( HTML )', 'einvoice-validator-for-vampire' ),
			'Reset' => __( 'Reset', 'einvoice-validator-for-vampire' ),
			'No file matches this filter.' => __( 'No file matches this filter.', 'einvoice-validator-for-vampire' ),
			'Checking …' => __( 'Checking …', 'einvoice-validator-for-vampire' ),
			'Waiting' => __( 'Waiting', 'einvoice-validator-for-vampire' ),
			'Show the report of this invoice' => __( 'Show the report of this invoice', 'einvoice-validator-for-vampire' ),
			/* translators: %1$d is the number of invoices checked so far, %2$d the number of invoices in the batch. */
			'%1$d of %2$d invoices checked' => __( '%1$d of %2$d invoices checked', 'einvoice-validator-for-vampire' ),
			/* translators: %1$d is the number of invoices, %2$s the seconds of the whole batch, %3$s the seconds per invoice. */
			'%1$d invoices checked in %2$s seconds, %3$s seconds per invoice on average.' => __( '%1$d invoices checked in %2$s seconds, %3$s seconds per invoice on average.', 'einvoice-validator-for-vampire' ),
			'Reading the files …' => __( 'Reading the files …', 'einvoice-validator-for-vampire' ),
			'No file was found.' => __( 'No file was found.', 'einvoice-validator-for-vampire' ),
			/* translators: %d is a number of seconds. */
			'The validation service asks for a pause; the batch goes on in %d seconds.' => __( 'The validation service asks for a pause; the batch goes on in %d seconds.', 'einvoice-validator-for-vampire' ),
			/* translators: %s is the message of the validation service. */
			'The batch was stopped: %s' => __( 'The batch was stopped: %s', 'einvoice-validator-for-vampire' ),
			'The batch was cancelled. The invoices checked so far stay in the overview.' => __( 'The batch was cancelled. The invoices checked so far stay in the overview.', 'einvoice-validator-for-vampire' ),
			'Batch check: overview' => __( 'Batch check: overview', 'einvoice-validator-for-vampire' ),
			'All' => __( 'All', 'einvoice-validator-for-vampire' ),
			'With warnings' => __( 'With warnings', 'einvoice-validator-for-vampire' ),
			/* translators: %s is the file name of the invoice. */
			'Report of %s' => __( 'Report of %s', 'einvoice-validator-for-vampire' ),
			// Only on the page of this plugin: the check ended without a report ( a rule set is missing, or a rule stopped it ).
			'No result for this document. The single check names the reason.' => __( 'No result for this document. The single check names the reason.', 'einvoice-validator-for-vampire' ),
			'Check many invoices at once: a month folder, a ZIP or a selection of files. VAMPIRE JS checks them one after the other in this browser, and the overview lists the verdict of every file. Nothing leaves the computer.' => __( 'Check many invoices at once: a month folder, a ZIP or a selection of files. VAMPIRE JS checks them one after the other in this browser, and the overview lists the verdict of every file. Nothing leaves the computer.', 'einvoice-validator-for-vampire' ),
			'Check many invoices at once: a month folder, a ZIP or a selection of files. Each invoice is sent to this website on its own, one after the other, checked there and not stored, and the overview lists the verdict of every file.' => __( 'Check many invoices at once: a month folder, a ZIP or a selection of files. Each invoice is sent to this website on its own, one after the other, checked there and not stored, and the overview lists the verdict of every file.', 'einvoice-validator-for-vampire' ),
		);
	}

}
