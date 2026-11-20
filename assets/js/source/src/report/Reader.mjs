// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Picks the right reader for a validation report and reads it - the one entry point a caller needs.
 *
 * ⚠️ PORT of libs/einvoice-report/src/Reader.php (PHP).
 *
 * @since 1.4.0
 */

import { ReaderXml } from './ReaderXml.mjs';
import { ReaderVampire } from './ReaderVampire.mjs';
import { ReaderHtml } from './ReaderHtml.mjs';

export class Reader {

	/**
	 * ⚠️ XML IS TRIED FIRST - see Reader.php's own comment on why.
	 *
	 * @param {string} content
	 * @param {typeof DOMParser} [DOMParserImpl] defaults to the global DOMParser ( real browsers ) -
	 *		pass linkedom's ( or any other ) implementation explicitly under Node.
	 * @return {import('./Report.mjs').Report}
	 * @throws {Error}
	 */

	static read( content, DOMParserImpl = globalThis.DOMParser ) {

		// A JSON report of VAMPIRE first: it is not XML, and no other reader would take it ( see Reader.php ).
		if ( ReaderVampire.detect( content ) )
			return ReaderVampire.read( content );

		if ( ReaderXml.detect( content ) )
			return ReaderXml.read( content, DOMParserImpl );

		if ( ReaderHtml.detect( content ) )
			return ReaderHtml.read( content, DOMParserImpl );

		throw new Error( 'This file was not recognised as a validation report. Please upload the report the validator produced, not the invoice itself.' );

	}

}
