// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The head of an invoice for one row of the batch overview: number, type, date, seller, amount and currency.
 *
 *   invoiceHeader( xml, DOMParser )
 *   → { syntax: 'cii'|'ubl'|'', number, type, issueDate, seller, currency, total, due }
 *
 * ⚠️ **NOT THE MODEL OF THE VIEWER.** The model ( `model/TreeReader.mjs` with `cii-nodes.json` and `ubl-nodes.json` ) reads
 * every field of an invoice and weighs several hundred kilobytes with its node tables; the three validator apps and the
 * batch script of the WordPress plugin do not carry it. An overview row needs six fields, and their places are fixed by
 * the syntax bindings of EN 16931. Six paths per syntax are therefore read here directly, and nothing else.
 *
 * ⚠️ **BY LOCAL NAME, NOT BY PREFIX.** A sender may bind the CII namespaces to any prefix ( `rsm:`, `ram:`, none at all ),
 * and `getElementsByTagName( 'ram:ID' )` would find nothing in an invoice that writes `<ID>` with a default namespace. The
 * walk below compares local names and checks the namespace of the root only.
 *
 * ⚠️ **ONLY DIRECT CHILDREN ALONG THE PATH.** `cbc:ID` stands at a dozen places of a UBL invoice ( line, party, tax scheme );
 * a search through the whole tree would take the first one it meets, which in some invoices is the ID of an order
 * reference. Each step goes one level down.
 *
 * @since 26.10.2026
 */

const NS_CII = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';

/**
 * The local name of a node, also for parsers that keep the prefix in `nodeName` only.
 *
 * @param {Node} n
 * @return {string}
 */
function local( n ) {

	return String( n.localName || n.nodeName || '' ).split( ':' ).pop();

}

/**
 * The first child element with this local name, or null.
 *
 * @param {Element|null} el
 * @param {string} name
 * @return {Element|null}
 */
function child( el, name ) {

	if ( ! el ) return null;

	for ( let n = el.firstChild; n; n = n.nextSibling )
		if ( n.nodeType === 1 && local( n ) === name ) return n;

	return null;

}

/**
 * Follows a path of local names from an element and returns the trimmed text at its end.
 *
 * @param {Element|null} el
 * @param {string[]} path
 * @return {string}
 */
function text( el, path ) {

	let cur = el;

	for ( const step of path ) cur = child( cur, step );

	return cur ? String( cur.textContent || '' ).replace( /\s+/g, ' ' ).trim() : '';

}

/**
 * "20260305" ( CII format 102 ) and "2026-03-05" both become "2026-03-05"; anything else stays as it came.
 *
 * @param {string} v
 * @return {string}
 */
export function isoDate( v ) {

	const s = String( v || '' ).trim();
	const m = s.match( /^(\d{4})-?(\d{2})-?(\d{2})/ );

	return m ? m[ 1 ] + '-' + m[ 2 ] + '-' + m[ 3 ] : s;

}

/**
 * An amount of the XML as a number, or null.
 *
 * ⚠️ THE XML WRITES A DECIMAL POINT AND NOTHING ELSE ( xs:decimal ). A comma is no German notation here but an error of
 * the sender, and the number is then left out instead of being guessed.
 *
 * @param {string} v
 * @return {number|null}
 */
export function amount( v ) {

	const s = String( v || '' ).trim();

	if ( ! /^[+-]?\d+(\.\d+)?$/.test( s ) ) return null;

	return Math.round( Number( s ) * 100 ) / 100;

}

/**
 * @param {string} xml the invoice XML
 * @param {typeof DOMParser} Parser the DOMParser of the browser, or that of linkedom in the tests
 * @return {{syntax: string, number: string, type: string, issueDate: string, seller: string, currency: string, total: number|null, due: number|null}}
 */
export function invoiceHeader( xml, Parser ) {

	const leer = { syntax: '', number: '', type: '', issueDate: '', seller: '', currency: '', total: null, due: null };

	let doc;

	try {
		doc = new Parser().parseFromString( String( xml || '' ).replace( /^﻿/, '' ), 'text/xml' );
	} catch ( e ) {
		return leer;
	}

	const root = doc && doc.documentElement;

	if ( ! root || local( root ) === 'parsererror' || doc.getElementsByTagName( 'parsererror' ).length ) return leer;

	const name = local( root );

	if ( name === 'CrossIndustryInvoice' || root.namespaceURI === NS_CII ) {

		const kopf = child( root, 'ExchangedDocument' );
		const tx = child( root, 'SupplyChainTradeTransaction' );
		const settlement = child( tx, 'ApplicableHeaderTradeSettlement' );
		const summe = child( settlement, 'SpecifiedTradeSettlementHeaderMonetarySummation' );

		return {
			syntax: 'cii',
			number: text( kopf, [ 'ID' ] ),
			type: text( kopf, [ 'TypeCode' ] ),
			issueDate: isoDate( text( kopf, [ 'IssueDateTime', 'DateTimeString' ] ) ),
			seller: text( tx, [ 'ApplicableHeaderTradeAgreement', 'SellerTradeParty', 'Name' ] ),
			currency: text( settlement, [ 'InvoiceCurrencyCode' ] ),
			total: amount( text( summe, [ 'GrandTotalAmount' ] ) ),
			due: amount( text( summe, [ 'DuePayableAmount' ] ) ),
		};

	}

	if ( name === 'Invoice' || name === 'CreditNote' ) {

		const party = child( child( root, 'AccountingSupplierParty' ), 'Party' );
		const summe = child( root, 'LegalMonetaryTotal' );

		return {
			syntax: 'ubl',
			number: text( root, [ 'ID' ] ),
			type: text( root, [ name === 'CreditNote' ? 'CreditNoteTypeCode' : 'InvoiceTypeCode' ] ) || ( name === 'CreditNote' ? '381' : '' ),
			issueDate: isoDate( text( root, [ 'IssueDate' ] ) ),
			// ⚠️ BT-27 is the legal name and stands in PartyLegalEntity; the trading name BT-28 only when the legal name is missing.
			seller: text( party, [ 'PartyLegalEntity', 'RegistrationName' ] ) || text( party, [ 'PartyName', 'Name' ] ),
			currency: text( root, [ 'DocumentCurrencyCode' ] ),
			total: amount( text( summe, [ 'TaxInclusiveAmount' ] ) ),
			due: amount( text( summe, [ 'PayableAmount' ] ) ),
		};

	}

	return leer;

}
