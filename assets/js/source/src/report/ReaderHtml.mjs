// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Reads the HTML rendering of a KoSIT validation report.
 *
 * ⚠️ LINE-FOR-LINE PORT of libs/einvoice-report/src/ReaderHtml.php (PHP). See that
 * file's own doc block for the full "what this reads" / "the fragile one" reasoning - not repeated
 * here. XPath queries translated to CSS selectors + manual child-filtering where the PHP used `./` (
 * direct child ) rather than `.//` ( any descendant ) - see childrenByTag() below.
 *
 * ⚠️ DOMParser DIFFERENCE FROM PHP: unlike DOMDocument::loadHTML(), the browser's ( and linkedom's )
 * DOMParser needs no explicit UTF-8 meta-tag workaround - it is handed a JS string, already decoded,
 * never raw bytes with an ambiguous encoding. The PHP `<meta charset>` prepend trick ( loadHTML()'s
 * own ISO-8859-1 default ) has no equivalent problem here and is intentionally NOT ported.
 *
 * @since 1.4.0
 */

import { Report } from './Report.mjs';

export class ReaderHtml {

	/**
	 * Does this content look like a KoSIT HTML report?
	 *
	 * @param {string} raw
	 * @return {boolean}
	 */

	static detect( raw ) {
		const head = String( raw || '' ).slice( 0, 65536 );
		if ( head.toLowerCase().indexOf( '<html' ) === -1 ) return false;
		return head.indexOf( 'tbl-errors' ) !== -1 || head.indexOf( 'class="document"' ) !== -1;
	}


	/**
	 * Read a report.
	 *
	 * @param {string} raw
	 * @param {typeof DOMParser} DOMParserImpl injected so this runs identically in a real browser (
	 *		global DOMParser ) and under Node test tooling ( linkedom's DOMParser - no global exists
	 *		there ). Production callers never pass this - see index.mjs's own default.
	 * @return {Report}
	 * @throws {Error} when the content is not a readable report
	 */

	static read( raw, DOMParserImpl = globalThis.DOMParser ) {

		const report = new Report();
		report.meta.source = 'html';

		const doc = ReaderHtml.load( String( raw ), DOMParserImpl );

		ReaderHtml.readMeta( doc, report );
		ReaderHtml.readSteps( doc, report );
		ReaderHtml.readFindings( doc, report );
		ReaderHtml.readDocument( doc, report );

		// ⚠️ A CLEAN REPORT ( 0 errors, 0 warnings ) CAN LEGITIMATELY HAVE NEITHER - see the PHP
		// ReaderHtml.php's own comment on this same check ( kept in sync by hand ). readMeta()'s own
		// verdict paragraph is present on every report regardless of findings, so it is the signal
		// that survives a clean report and still proves the layout was actually understood.
		if ( ! report.findings.length && ! report.steps.length && report.meta.compliant === undefined )
			throw new Error( 'This file was recognised as a validation report, but neither its findings nor its validation steps could be read. The report layout may have changed.' );

		report.buildPaths();
		report.linkFindings();

		ReaderHtml.checkPlausibility( report );

		return report;

	}


	/**
	 * Parse the HTML into a DOM.
	 *
	 * ⚠️ PUBLIC, FOR ReaderXml - the native XML report embeds the IDENTICAL HTML markup this class
	 * already parses.
	 *
	 * @param {string} raw
	 * @param {typeof DOMParser} DOMParserImpl
	 * @return {Document}
	 * @throws {Error}
	 */

	static load( raw, DOMParserImpl = globalThis.DOMParser ) {

		if ( String( raw ).trim() === '' )
			throw new Error( 'The file is empty.' );

		if ( ! DOMParserImpl )
			throw new Error( 'No DOMParser available in this environment.' );

		const doc = new DOMParserImpl().parseFromString( raw, 'text/html' );

		return doc;

	}


	/**
	 * The metadata block: two definition lists.
	 *
	 * ⚠️ LABELS MATCHED LOOSELY - all examples measured were English, but the KoSIT XSLT ships German
	 * too ("Erkannter Verkäufer").
	 *
	 * @param {Document} doc
	 * @param {Report} report
	 * @return {void}
	 */

	static readMeta( doc, report ) {

		const pairs = [];

		for ( const dt of doc.querySelectorAll( '[class*="metadata"] dt' ) ) {

			let dd = dt.nextElementSibling;
			while ( dd && dd.tagName.toLowerCase() !== 'dd' )
				dd = dd.nextElementSibling;

			if ( ! dd ) continue;

			pairs.push( [ text( dt ), text( dd ) ] );

		}

		if ( ! pairs.length )
			report.notices.push( 'The report header could not be read; the document details are missing.' );

		const map = {
			reference:       [ 'reference', 'referenz' ],
			validated_at:    [ 'time of validation', 'zeitpunkt' ],
			document_type:   [ 'document type', 'dokumenttyp', 'dokumententyp' ],
			seller:          [ 'seller', 'verkäufer', 'verkaeufer' ],
			invoice_number:  [ 'invoice number', 'rechnungsnummer' ],
			invoice_date:    [ 'invoice date', 'rechnungsdatum' ],
		};

		for ( const [ label, value ] of pairs ) {

			const lower = label.toLowerCase();

			outer: for ( const key in map ) {

				if ( report.meta[ key ] !== undefined ) continue;

				for ( const needle of map[ key ] )
					if ( lower.indexOf( needle ) !== -1 ) {
						report.meta[ key ] = value;
						break outer;
					}

			}

		}

		for ( const p of doc.querySelectorAll( 'p[class*="important"]' ) ) {

			const t = text( p ).toLowerCase();
			if ( t.indexOf( 'compliant' ) === -1 && t.indexOf( 'konform' ) === -1 ) continue;

			report.meta.compliant = ( t.indexOf( 'not compliant' ) !== -1 || t.indexOf( 'nicht konform' ) !== -1 ) ? 'no' : 'yes';
			break;

		}

		const syntax = ReaderHtml.syntaxFromText( String( report.meta.document_type || '' ) );
		if ( syntax !== '' ) report.meta.syntax = syntax;

	}


	/**
	 * Guess the syntax family from a piece of text that names it - a document type, a scenario name.
	 * Public and shared with ReaderXml.
	 *
	 * @param {string} text
	 * @return {string} 'CII' | 'UBL' | ''
	 */

	static syntaxFromText( text ) {

		const type = String( text || '' ).toLowerCase();

		if ( type.indexOf( 'ubl' ) !== -1 ) return 'UBL';

		if ( type.indexOf( 'cii' ) !== -1 || type.indexOf( 'cross industry' ) !== -1
			|| type.indexOf( 'zugferd' ) !== -1 || type.indexOf( 'factur' ) !== -1 )
			return 'CII';

		return '';

	}


	/**
	 * The first tbl-errors: one row per validation step with its tallies.
	 *
	 * @param {Document} doc
	 * @param {Report} report
	 * @return {void}
	 */

	static readSteps( doc, report ) {

		const tables = doc.querySelectorAll( 'table[class*="tbl-errors"]' );
		if ( ! tables.length ) return;

		for ( const tr of childrenByTag( tables[ 0 ].querySelector( 'tbody' ), 'tr' ) ) {

			const cells = childrenByTag( tr, 'td' );
			if ( cells.length !== 4 ) continue;

			report.steps.push( {
				step:     text( cells[ 0 ] ),
				errors:   parseInt( text( cells[ 1 ] ), 10 ) || 0,
				warnings: parseInt( text( cells[ 2 ] ), 10 ) || 0,
				infos:    parseInt( text( cells[ 3 ] ), 10 ) || 0,
			} );

		}

	}


	/**
	 * The second tbl-errors: the findings.
	 *
	 * ⚠️ ONE FINDING SPANS TWO TABLE ROWS - the first carries position/rule/severity + message, the
	 * second only "Path: …". A row whose first cell has no rowspan is the continuation of the one
	 * before it.
	 *
	 * @param {Document} doc
	 * @param {Report} report
	 * @return {void}
	 */

	static readFindings( doc, report ) {

		const tables = doc.querySelectorAll( 'table[class*="tbl-errors"]' );

		if ( tables.length < 2 ) {
			if ( tables.length )
				report.notices.push( 'The report contains a summary but no detailed findings table.' );
			return;
		}

		let current = null;

		for ( const tr of childrenByTag( tables[ 1 ].querySelector( 'tbody' ), 'tr' ) ) {

			const cells = childrenByTag( tr, 'td' );
			if ( ! cells.length ) continue;

			if ( cells.length === 1 && current !== null ) {

				const t = text( cells[ 0 ] );
				const m = t.match( /^\s*(?:Path|Pfad)\s*:\s*(.+)$/i );

				if ( m ) report.findings[ current ].path = m[ 1 ].trim();
				else if ( t !== '' ) report.findings[ current ].message = ( report.findings[ current ].message + ' ' + t ).trim();

				continue;

			}

			if ( cells.length < 4 ) continue;

			const severityCell = text( cells[ 2 ] ).toLowerCase();

			report.findings.push( {
				position: text( cells[ 0 ] ),
				rule:     text( cells[ 1 ] ),
				severity: severity( severityCell, tr ),
				message:  text( cells[ 3 ] ),
				path:     '',
				rows:     [],
			} );

			current = report.findings.length - 1;

		}

	}


	/**
	 * The document table.
	 *
	 * ⚠️ THE ELEMENT NAME IS IN THE title ATTRIBUTE, not in the cell text - a container element is
	 * self-closed and holds no text at all.
	 *
	 * @param {Document} doc
	 * @param {Report} report
	 * @return {void}
	 */

	static readDocument( doc, report ) {

		const rows = doc.querySelectorAll( 'table[class*="document"] tr[class*="row"]' );

		if ( ! rows.length ) {
			report.notices.push( 'The document view could not be read from the report; only the findings are shown.' );
			return;
		}

		for ( const tr of rows ) {

			const element = [ ...childrenByTag( tr, 'td' ) ].find( td => ( td.getAttribute( 'class' ) || '' ).indexOf( 'element' ) !== -1 );
			if ( ! element ) continue;

			const posCell = [ ...childrenByTag( tr, 'td' ) ].find( td => ( td.getAttribute( 'class' ) || '' ).indexOf( 'pos' ) !== -1 );
			const valEl   = element.querySelector( '[class*="val"]' );

			const attrs = [];
			for ( const a of element.querySelectorAll( '[class*="attribute"]' ) ) {
				const t = text( a );
				if ( t !== '' ) attrs.push( t );
			}

			report.document.push( {
				pos:    posCell ? text( posCell ) : ( tr.getAttribute( 'id' ) || '' ),
				level:  level( element.getAttribute( 'class' ) || '' ),
				name:   ( element.getAttribute( 'title' ) || '' ).trim(),
				value:  valEl ? text( valEl ) : '',
				attr:   attrs.join( ' · ' ),
				path:   '',
				status: '',
			} );

		}

	}


	/**
	 * Compare what was read against what the report says about itself - the guard against a silent
	 * half-read. Public since ReaderXml reuses it verbatim ( entirely format-agnostic, only ever reads
	 * report.steps/findings/document ).
	 *
	 * @param {Report} report
	 * @return {void}
	 */

	static checkPlausibility( report ) {

		let stated = 0;
		for ( const step of report.steps )
			stated += Number( step.errors ) + Number( step.warnings );

		if ( ! stated ) return;

		const found = report.count( 'error' ) + report.count( 'warning' );

		if ( found < stated )
			report.notices.push( `Only ${ found } of the ${ stated } findings this report states could be read. The report layout may have changed - please check the original report.` );

		let withPath = 0;
		let linked   = 0;

		for ( const f of report.findings ) {
			if ( String( f.path || '' ) === '' ) continue;
			withPath++;
			if ( f.rows && f.rows.length ) linked++;
		}

		if ( report.document.length && withPath && ! linked )
			report.notices.push( 'None of the findings could be located in the document view, although the report names a position for them. The values shown per finding may be incomplete.' );

	}

}


/**
 * Normalise the severity - the cell is authoritative, the row's CSS class is the fallback.
 *
 * @param {string} cell
 * @param {Element} tr
 * @return {string}
 */

function severity( cell, tr ) {

	const value = cell !== '' ? cell : ( tr.getAttribute( 'class' ) || '' ).toLowerCase();

	if ( value.indexOf( 'error' ) !== -1 || value.indexOf( 'fehler' ) !== -1 ) return 'error';
	if ( value.indexOf( 'warn' ) !== -1 ) return 'warning';
	return 'information';

}


/**
 * Nesting depth out of the class list: "element level3" -> 3.
 *
 * @param {string} className
 * @return {number}
 */

function level( className ) {
	const m = className.match( /\blevel(\d+)\b/ );
	return m ? Math.max( 1, parseInt( m[ 1 ], 10 ) ) : 1;
}


/**
 * Collapsed text of a node.
 *
 * @param {Element|null} node
 * @return {string}
 */

function text( node ) {
	if ( ! node ) return '';
	return String( node.textContent || '' ).replace( /\s+/g, ' ' ).trim();
}


/**
 * ⚠️ DIRECT CHILDREN ONLY, LIKE THE PHP's `./tag` - not `querySelectorAll('tag')`, which would also
 * match nested tables ( a finding's message could in principle embed one ). Mirrors PHP's `./td` /
 * `.//tbody/tr` distinction from `.//td` that this port has to make explicit, since CSS has no
 * native "direct child of X, anywhere below X" combinator equivalent to a leading `./`.
 *
 * @param {Element|null} parent
 * @param {string} tag lowercase tag name
 * @return {Element[]}
 */

function childrenByTag( parent, tag ) {
	if ( ! parent ) return [];
	return [ ...parent.children ].filter( el => el.tagName.toLowerCase() === tag );
}
