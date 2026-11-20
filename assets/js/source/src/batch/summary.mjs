// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * One row of the batch overview from a checked invoice, and the overview as CSV and as a printable page.
 *
 *   rowFrom( item, header, report, { serviceVerdict } )   → the row
 *   counts( rows )                                         → { all, accepted, warnings, rejected, unchecked }
 *   batchCsv( rows, t )                                    → text with BOM, ";" and CRLF
 *   batchReportHtml( rows, { t, title, lang, … } )         → a page that stands alone
 *
 * ⚠️ **THE VERDICT COMES FROM THE REPORT, NOT FROM COUNTING.** `Report.rejected()` asks the report first ( KoSIT writes
 * "acceptable" or not, VAMPIRE the same in its KoSIT-shaped report ) and counts errors only when the report says nothing.
 * A batch that counted for itself could call an invoice rejected that the validator accepted, and the overview would
 * contradict the single report one click away.
 *
 * @since 26.10.2026
 */

/**
 * The document type codes that make a document a credit note: the credit note half of BR-CL-01, the same eleven codes as
 * `CREDIT_NOTE_CODES` in `ledger/entry.mjs`.
 *
 * ⚠️ **A COPY, AND `test/batch.test.mjs` COMPARES IT WITH THE ORIGINAL.** Importing the invoice monitor's module would
 * pull its model readers and the EPC QR code into the bundle of the WordPress plugin, which has neither, and would tie the
 * batch check to a module that changes with every round of the monitor. The test fails as soon as the two lists differ.
 */
export const CREDIT_NOTE_CODES = Object.freeze( [ '81', '83', '261', '262', '296', '308', '381', '396', '420', '458', '532' ] );

/**
 * @param {string|{type: string}} v a code such as "381", or a row with `type`
 * @return {boolean}
 */
export function isCreditNote( v ) {

	const code = typeof v === 'object' && v !== null ? v.type : v;

	return CREDIT_NOTE_CODES.includes( String( code || '' ).split( ' - ' )[ 0 ].trim() );

}

/**
 * An amount with the sign of the money flow: an invoice as it stands, a credit note negated, as in the invoice monitor.
 *
 * @param {{type: string}} row
 * @param {number|null} n
 * @return {number|null}
 */
export function signedAmount( row, n ) {

	if ( n === null || n === undefined ) return n;

	return isCreditNote( row ) && n !== 0 ? -n : n;

}

/** The verdicts of a row, from good to bad, then the rows without a verdict. */
export const VERDICTS = [ 'accepted', 'warnings', 'rejected', 'noinvoice', 'error', 'skipped' ];

/** Rows that carry no verdict of a validator. */
export const UNCHECKED = [ 'noinvoice', 'error', 'skipped' ];

/**
 * Replaces `%s`, `%d`, `%1$s` … in a translated text, as `sprintf()` does in PHP.
 *
 * @param {string} text
 * @param {...*} values
 * @return {string}
 */
export function fill( text, ...values ) {

	let i = 0;

	return String( text ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, n ) => String( values[ n ? Number( n ) - 1 : i++ ] ?? '' ) );

}

/**
 * The rule IDs of the findings, most frequent first: errors before warnings, at most `max`.
 *
 * @param {object} report a `Report`
 * @param {number} [max]
 * @return {Array<{rule: string, n: number, severity: string}>}
 */
export function topRules( report, max = 3 ) {

	const tally = new Map();

	for ( const f of ( report && report.findings ) || [] ) {

		const sev = String( f.severity || '' );

		if ( sev !== 'error' && sev !== 'warning' ) continue;

		const rule = String( f.rule || '' ).trim() || '—';
		const key = sev + '|' + rule;
		const e = tally.get( key ) || { rule, n: 0, severity: sev };

		e.n++;
		tally.set( key, e );

	}

	return [ ...tally.values() ]
		.sort( ( a, b ) => ( a.severity === b.severity ? 0 : a.severity === 'error' ? -1 : 1 ) || b.n - a.n || a.rule.localeCompare( b.rule ) )
		.slice( 0, max );

}

/**
 * The row of a checked invoice.
 *
 * ⚠️ **THE VERDICT OF THE SERVICE CAN BE STRICTER THAN THE REPORT.** Mustang writes the messages of the PDF part outside
 * the part the reader takes, and its report still says "valid"; the bridge answers `X-Validation-Result: invalid`. The
 * row then says "rejected" with the note `pdf`, the same as the single view says in its notice
 * ( apps/einvoice-validator-for-mustang/src/lies.mjs ).
 *
 * @param {object} item an item of `prepareItems()` after the queue
 * @param {object} header `invoiceHeader()` of its XML
 * @param {object|null} report a `Report`, or null when the check did not run
 * @param {{serviceVerdict?: string, message?: string}} [extra]
 * @return {object}
 */
export function rowFrom( item, header, report, extra = {} ) {

	const h = header || {};
	const row = {
		id: item.id,
		name: item.name,
		path: item.path,
		kind: item.kind,
		number: h.number || '',
		type: h.type || '',
		creditNote: isCreditNote( h.type || '' ),
		issueDate: h.issueDate || '',
		seller: h.seller || '',
		total: h.total ?? null,
		currency: h.currency || '',
		verdict: 'skipped',
		errors: 0,
		warnings: 0,
		infos: 0,
		rules: [],
		note: '',
		message: extra.message || '',
		ms: item.ms || 0,
	};

	if ( item.kind === 'none' ) { row.verdict = 'noinvoice'; row.note = item.reason || ''; return row; }

	if ( ! report ) {
		row.verdict = item.state === 'error' ? 'error' : 'skipped';
		row.note = item.state === 'skipped' ? ( item.skipReason || '' ) : '';
		return row;
	}

	row.errors = report.count( 'error' );
	row.warnings = report.count( 'warning' );
	row.infos = report.count( 'information' );
	row.rules = topRules( report );

	if ( report.rejected() ) row.verdict = 'rejected';
	else if ( String( extra.serviceVerdict || '' ).toLowerCase() === 'invalid' ) { row.verdict = 'rejected'; row.note = 'pdf'; }
	else row.verdict = row.warnings > 0 ? 'warnings' : 'accepted';

	return row;

}

/**
 * @param {object[]} rows
 * @return {{all: number, accepted: number, warnings: number, rejected: number, unchecked: number}}
 */
export function counts( rows ) {

	const c = { all: 0, accepted: 0, warnings: 0, rejected: 0, unchecked: 0 };

	for ( const r of rows || [] ) {
		c.all++;
		if ( UNCHECKED.includes( r.verdict ) ) c.unchecked++;
		else c[ r.verdict ]++;
	}

	return c;

}

/**
 * The verdict of a row as a sentence part, in the language of the page.
 *
 * @param {object} row
 * @param {(s: string) => string} t
 * @return {string}
 */
export function verdictLabel( row, t ) {

	switch ( row.verdict ) {
		case 'accepted': return t( 'Accepted' );
		case 'warnings': return t( 'Accepted with warnings' );
		case 'rejected': return t( 'Rejected' );
		case 'noinvoice': return t( 'No e-invoice' );
		case 'error': return t( 'Not checked: error' );
		default: return t( 'Not checked' );
	}

}

/**
 * Why a row has no verdict, or a note on its verdict, as a sentence.
 *
 * @param {object} row
 * @param {(s: string) => string} t
 * @return {string}
 */
export function noteLabel( row, t ) {

	switch ( row.note ) {
		case 'no-xml-in-pdf': return t( 'This PDF carries no invoice XML.' );
		case 'not-an-invoice': return t( 'This file is no invoice XML.' );
		case 'too-many': return t( 'Not checked, because the batch holds too many files.' );
		case 'cancelled': return t( 'Not checked, because the batch was cancelled.' );
		case 'stopped': return t( 'Not checked, because the validation service refused further checks.' );
		case 'pdf': return t( 'Errors in the PDF part of the file' );
		default: return row.message || '';
	}

}

/** A field for a CSV line: quoted when it holds the separator, a quote or a line break. */
function cell( v ) {

	const s = v === null || v === undefined ? '' : String( v );

	return /[";\r\n]/.test( s ) ? '"' + s.replace( /"/g, '""' ) + '"' : s;

}

/** 592.87 → "592,87": the decimal comma that a German spreadsheet reads as a number. */
const comma = ( n ) => ( n === null || n === undefined ? '' : n.toFixed( 2 ).replace( '.', ',' ) );

/**
 * The overview as CSV.
 *
 * ⚠️ **BOM, SEMICOLON, CRLF, DECIMAL COMMA**, like the CSV of the invoice monitor ( `ledger/entry.mjs::toCsv()` ): that is
 * what Excel on a German Windows opens without an import dialog.
 *
 * ⚠️ **A CREDIT NOTE CARRIES A MINUS AND SAYS SO**, for the same reason as there: the column "Document type" names it, and
 * the amount has the sign of the money flow, so that a column sum in the spreadsheet is right.
 *
 * @param {object[]} rows
 * @param {(s: string) => string} t
 * @return {string}
 */
export function batchCsv( rows, t ) {

	const head = [ t( 'File' ), t( 'Folder or archive' ), t( 'Invoice number' ), t( 'Document type' ), t( 'Seller' ), t( 'Issue date' ),
		t( 'Amount' ), t( 'Currency' ), t( 'Verdict' ), t( 'Errors' ), t( 'Warnings' ), t( 'Most frequent rules' ), t( 'Note' ) ];

	const lines = [ head.map( cell ).join( ';' ) ];

	for ( const r of rows || [] ) {

		const folder = String( r.path || '' ).split( '/' ).slice( 0, -1 ).join( '/' );
		const kind = r.type ? ( r.creditNote ? t( 'Credit note' ) : t( 'Invoice' ) ) + ' (' + r.type + ')' : '';

		lines.push( [
			r.name,
			folder,
			r.number,
			kind,
			r.seller,
			r.issueDate,
			comma( signedAmount( { type: r.type }, r.total ) ),
			r.currency,
			verdictLabel( r, t ),
			UNCHECKED.includes( r.verdict ) ? '' : r.errors,
			UNCHECKED.includes( r.verdict ) ? '' : r.warnings,
			r.rules.map( ( x ) => x.n > 1 ? x.rule + ' (' + x.n + ')' : x.rule ).join( ', ' ),
			noteLabel( r, t ),
		].map( cell ).join( ';' ) );

	}

	return '﻿' + lines.join( '\r\n' ) + '\r\n';

}

/** HTML-escaped text. */
export function esc( s ) {

	return String( s ?? '' ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );

}

/**
 * An amount in the notation of the page language: "1.234,56" in German and French, "1,234.56" in English.
 *
 * @param {number|null} n
 * @param {string} lang
 * @return {string}
 */
export function money( n, lang ) {

	if ( n === null || n === undefined ) return '';

	try {
		return new Intl.NumberFormat( lang || 'en', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ).format( n );
	} catch ( e ) {
		return n.toFixed( 2 );
	}

}

/**
 * The overview as a page that stands alone: to print, to save as PDF from the print dialog, or to file next to the
 * invoices of the month.
 *
 * ⚠️ **ITS STYLE STANDS INSIDE, AND IT LOADS NOTHING.** The file is opened later from a disk, without the app around it
 * and maybe without a connection; a page that needed the kit from a website would print without layout.
 *
 * @param {object[]} rows
 * @param {{t: Function, title: string, lang?: string, tool?: string, generated?: Date, totalMs?: number}} p
 * @return {string}
 */
export function batchReportHtml( rows, p ) {

	const t = p.t;
	const lang = p.lang || 'en';
	const c = counts( rows );
	const when = ( p.generated || new Date() );

	let stamp;
	try { stamp = new Intl.DateTimeFormat( lang, { dateStyle: 'long', timeStyle: 'short' } ).format( when ); } catch ( e ) { stamp = when.toISOString(); }

	const badge = { accepted: 'ok', warnings: 'warn', rejected: 'err' };

	const body = ( rows || [] ).map( ( r ) => '<tr>'
		+ '<td>' + esc( r.name ) + ( r.path && r.path !== r.name ? '<div class="sub">' + esc( r.path ) + '</div>' : '' ) + '</td>'
		+ '<td>' + esc( r.number ) + '</td>'
		+ '<td>' + esc( r.seller ) + '</td>'
		+ '<td class="nowrap">' + esc( r.issueDate ) + '</td>'
		+ '<td class="num">' + esc( money( signedAmount( { type: r.type }, r.total ), lang ) ) + ( r.currency ? ' ' + esc( r.currency ) : '' ) + '</td>'
		+ '<td><span class="v ' + ( badge[ r.verdict ] || 'mute' ) + '">' + esc( verdictLabel( r, t ) ) + '</span></td>'
		+ '<td class="num">' + ( UNCHECKED.includes( r.verdict ) ? '' : esc( r.errors ) ) + '</td>'
		+ '<td>' + esc( r.rules.map( ( x ) => x.n > 1 ? x.rule + ' (' + x.n + ')' : x.rule ).join( ', ' ) )
		+ ( noteLabel( r, t ) ? '<div class="sub">' + esc( noteLabel( r, t ) ) + '</div>' : '' ) + '</td>'
		+ '</tr>' ).join( '\n' );

	return '<!DOCTYPE html>\n<html lang="' + esc( lang ) + '">\n<head>\n<meta charset="utf-8">\n'
		+ '<meta name="viewport" content="width=device-width, initial-scale=1">\n'
		+ '<title>' + esc( p.title ) + '</title>\n<style>\n'
		+ 'body{margin:24px;font:13px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#1d232b;background:#fff}\n'
		+ 'h1{font-size:20px;margin:0 0 4px}.meta{color:#5b6573;margin:0 0 16px}\n'
		+ '.sum{display:flex;flex-wrap:wrap;gap:8px 18px;margin:0 0 18px;padding:10px 12px;border:1px solid #d9dee5;border-radius:6px}\n'
		+ '.sum b{font-size:16px;margin-right:4px}\n'
		+ 'table{width:100%;border-collapse:collapse}th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5b6573;border-bottom:2px solid #d9dee5;padding:6px 8px}\n'
		+ 'td{padding:6px 8px;border-bottom:1px solid #e6e9ee;vertical-align:top}.num{text-align:right;font-variant-numeric:tabular-nums}.nowrap{white-space:nowrap}\n'
		+ '.sub{color:#5b6573;font-size:11px;word-break:break-all}\n'
		+ '.v{display:inline-block;padding:1px 7px;border-radius:9px;font-size:12px;white-space:nowrap;background:#eef1f4;color:#46505c}\n'
		+ '.v.ok{background:#e3f4ea;color:#17703f}.v.warn{background:#fdf1dc;color:#8a5a00}.v.err{background:#fbe5e5;color:#a32121}\n'
		+ '@media print{body{margin:0}tr{break-inside:avoid}}\n'
		+ '</style>\n</head>\n<body>\n'
		+ '<h1>' + esc( p.title ) + '</h1>\n'
		+ '<p class="meta">' + esc( fill( t( 'Created on %1$s with %2$s.' ), stamp, p.tool || '' ) ) + '</p>\n'
		+ '<div class="sum">'
		+ '<span><b>' + c.all + '</b>' + esc( t( 'files' ) ) + '</span>'
		+ '<span><b>' + c.accepted + '</b>' + esc( t( 'accepted' ) ) + '</span>'
		+ '<span><b>' + c.warnings + '</b>' + esc( t( 'accepted with warnings' ) ) + '</span>'
		+ '<span><b>' + c.rejected + '</b>' + esc( t( 'rejected' ) ) + '</span>'
		+ '<span><b>' + c.unchecked + '</b>' + esc( t( 'not checked' ) ) + '</span>'
		+ '</div>\n'
		+ '<table>\n<thead><tr><th>' + esc( t( 'File' ) ) + '</th><th>' + esc( t( 'Invoice number' ) ) + '</th><th>' + esc( t( 'Seller' ) ) + '</th><th>'
		+ esc( t( 'Issue date' ) ) + '</th><th class="num">' + esc( t( 'Amount' ) ) + '</th><th>' + esc( t( 'Verdict' ) ) + '</th><th class="num">'
		+ esc( t( 'Errors' ) ) + '</th><th>' + esc( t( 'Most frequent rules' ) ) + '</th></tr></thead>\n<tbody>\n' + body + '\n</tbody>\n</table>\n'
		+ '<p class="meta">' + esc( t( 'This overview lists the verdict of the validator for every file. The reasons stand in the single report of each invoice.' ) ) + '</p>\n'
		+ '</body>\n</html>\n';

}
