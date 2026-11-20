// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Entry of `dist/einvoice-batch.bundle.js`: the batch check for the WordPress plugin eInvoice Validator for VAMPIRE PHP.
 *
 *   const { mountBatch, readReport } = await import( SPPV.batchBundleUrl );
 *
 * ⚠️ **AN ES MODULE, LOADED ONLY WHEN THE VISITOR OPENS THE BATCH CHECK.** `sppv-uploader.js` imports it on the first click
 * on "Batch check", the same way it loads the model bundle only for a PDF; a visitor who checks one invoice never loads it.
 *
 * ⚠️ **THE REPORT IS READ HERE ONLY FOR THE ROW.** The plugin answers each invoice with its report XML and its own drawing
 * of the explanation ( `data.html` ). The overview needs the verdict, the counts and the rule IDs, so it reads the XML with
 * `Reader`, the same reader the plugin's PHP twin ports line for line; the single report shown on a click is the plugin's
 * own drawing, unchanged.
 *
 * @since 26.10.2026
 */

import { Reader } from '../report/Reader.mjs';

export { mountBatch } from './ui.mjs';
export { extractPdfXmlPlain } from '../inbound/pdfxml.mjs';

/**
 * @param {string} xml the report XML of the plugin ( KoSIT-shaped )
 * @return {object} a `Report`
 */
export function readReport( xml ) {

	return Reader.read( String( xml || '' ), window.DOMParser );

}
