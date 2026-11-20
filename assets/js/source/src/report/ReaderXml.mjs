// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Reads the XML report of the KoSIT validator (VARL, xmlns:rep="http://www.xoev.de/de/validator/varl/1").
 *
 * ⚠️ LINE-FOR-LINE PORT of libs/einvoice-report/src/ReaderXml.php (PHP). See that
 * file's own doc block for the full schema reference / "why re-parse the embedded explanation as
 * HTML" reasoning - not repeated here.
 *
 * ⚠️ NAMESPACE LOOKUP BY LITERAL PREFIX, NOT NAMESPACE URI - measured against linkedom ( the DOMParser
 * used for this file's own tests, see test/ ): `getElementsByTagNameNS()` does NOT resolve prefixed
 * XML elements correctly there ( returns 0 results against a real "rep:name" element - linkedom's XML
 * mode does not implement true namespace resolution, `localName`/`namespaceURI` come back wrong too ).
 * `getElementsByTagName('rep:name')` - the literal, prefixed string - DOES work, in both linkedom and
 * real browsers, as long as the source document actually uses that exact prefix ( which every KoSIT
 * report does - 'rep' and 's', the same two prefixes ReaderXml.php's own registerNamespace() calls
 * hardcode ). This is a deliberate simplification, not full XML namespace awareness - acceptable here
 * because the prefixes are fixed by the format, not caller-supplied.
 *
 * @since 1.4.0
 */

import { Report } from './Report.mjs';
import { ReaderHtml } from './ReaderHtml.mjs';

export class ReaderXml {

	/**
	 * @param {string} raw
	 * @return {boolean}
	 */

	static detect( raw ) {

		const head = String( raw || '' ).slice( 0, 65536 );
		if ( head.indexOf( '<' ) === -1 ) return false;

		return head.indexOf( 'schematron-output' ) !== -1
			|| head.indexOf( 'failed-assert' ) !== -1
			|| /<(?:[A-Za-z0-9_-]+:)?(?:validationResultsDocument|report)\b/.test( head );

	}


	/**
	 * @param {string} raw
	 * @return {string} 'svrl' | 'kosit' | ''
	 */

	static flavour( raw ) {

		const head = String( raw || '' ).slice( 0, 65536 );

		if ( head.indexOf( 'schematron-output' ) !== -1 || head.indexOf( 'failed-assert' ) !== -1 )
			return 'svrl';

		if ( /<(?:[A-Za-z0-9_-]+:)?(?:validationResultsDocument|report)\b/.test( head ) )
			return 'kosit';

		return '';

	}


	/**
	 * Read a report.
	 *
	 * @param {string} raw
	 * @param {typeof DOMParser} DOMParserImpl see ReaderHtml.read()'s own doc block
	 * @return {Report}
	 * @throws {Error} when the content is not a readable 'kosit'-flavour report
	 */

	static read( raw, DOMParserImpl = globalThis.DOMParser ) {

		raw = String( raw );
		const flavour = ReaderXml.flavour( raw );

		if ( flavour === 'svrl' )
			throw new Error( 'This looks like a plain SVRL Schematron report ( no KoSIT wrapper ). Reading that format is not implemented yet - no real example was available to build it against. Please upload the KoSIT XML or HTML report instead.' );

		if ( flavour !== 'kosit' )
			throw new Error( 'This file was recognised as XML but not as a known validation report format.' );

		const report = new Report();
		report.meta.source = 'xml';

		const doc = ReaderXml.load( raw, DOMParserImpl );

		ReaderXml.readMeta( doc, report );
		ReaderXml.readStepsAndFindings( doc, report );
		ReaderXml.readDocument( doc, report, DOMParserImpl );

		if ( ! report.findings.length && ! report.steps.length )
			throw new Error( 'This file was recognised as a validation report, but neither its findings nor its validation steps could be read. The report layout may have changed.' );

		report.buildPaths();
		report.linkFindings();

		ReaderHtml.checkPlausibility( report );

		return report;

	}


	/**
	 * @param {string} raw
	 * @param {typeof DOMParser} DOMParserImpl
	 * @return {Document}
	 * @throws {Error}
	 */

	static load( raw, DOMParserImpl = globalThis.DOMParser ) {

		if ( raw.trim() === '' )
			throw new Error( 'The file is empty.' );

		if ( ! DOMParserImpl )
			throw new Error( 'No DOMParser available in this environment.' );

		const doc = new DOMParserImpl().parseFromString( raw, 'text/xml' );

		const parseError = doc.getElementsByTagName( 'parsererror' )[ 0 ];
		if ( parseError )
			throw new Error( 'The file could not be parsed as XML.' );

		return doc;

	}


	/**
	 * The report metadata: verdict, engine, timestamp, matched scenario ( + its config version ),
	 * recognised document data.
	 *
	 * @param {Document} doc
	 * @param {Report} report
	 * @return {void}
	 */

	static readMeta( doc, report ) {

		const root = first( doc.getElementsByTagName( 'rep:report' ) );

		/**
		 * ⚠️ **THE RECOMMENDATION DECIDES, NOT THE `valid` ATTRIBUTE — AND THEY DISAGREE IN PRACTICE.**
		 *
		 * A KoSIT report carries both. `valid` is true only when every validation step was clean;
		 * `<rep:assessment>` is the verdict the configuration's `acceptMatch` actually reached.
		 * A single WARNING sets its Schematron step to `valid="false"` and with it the whole report,
		 * while the assessment still says `<rep:accept>`.
		 *
		 * Measured 24.09.2026 on `X10_01_Kleinunternehmer_ohneUStId` against ZUGFeRD 2.5.2: the only
		 * finding is the warning `BR-FXEXT-E-08rev`, `valid="false"`, assessment `<rep:accept>`.
		 * Reading `valid` tells the user their invoice was rejected where the validator accepted it -
		 * the most damaging kind of wrong answer this tool can give.
		 *
		 * ⚠️ THE ATTRIBUTE STAYS AS THE FALLBACK. Not every report carries an assessment ( a
		 * `noScenarioMatched` report does not ), and for those `valid` is the only statement there is.
		 */
		const assessment = first( doc.getElementsByTagName( 'rep:assessment' ) );

		let stated = null;

		if ( assessment ) {

			// ⚠️ A CHILD, NOT A DESCENDANT SEARCH. `<rep:accept>` contains the rendered HTML report,
			// which may well contain the word "reject" in a finding's own text.
			for ( const child of assessment.childNodes || [] ) {

				const name = String( child.nodeName || '' ).toLowerCase();

				if ( name.endsWith( 'accept' ) ) { stated = 'yes'; break; }
				if ( name.endsWith( 'reject' ) ) { stated = 'no'; break; }

			}

		}

		if ( stated !== null )
			report.meta.compliant = stated;
		else if ( root && root.hasAttribute( 'valid' ) )
			report.meta.compliant = root.getAttribute( 'valid' ) === 'true' ? 'yes' : 'no';

		const engine = first( doc.getElementsByTagName( 'rep:name' ) );
		if ( engine ) report.meta.engine = text( engine );

		const timestamp = first( doc.getElementsByTagName( 'rep:timestamp' ) );
		if ( timestamp ) report.meta.validated_at = text( timestamp );

		/**
		 * ⚠️ documentReference IS A LOCAL TEMP FILE PATH, NOT SHOWN - see ReaderXml.php's own comment.
		 * Left unset here too, same reasoning, unchanged.
		 */

		const scenario = first( doc.getElementsByTagName( 's:name' ) );
		if ( scenario ) {

			const name = text( scenario );
			report.meta.document_type = name;

			const syntax = ReaderHtml.syntaxFromText( name );
			if ( syntax !== '' ) report.meta.syntax = syntax;

		}

		/**
		 * ⚠️ **THE SCENARIO NAME IS NOT THE CUSTOMISATION ID, AND ONLY THE ID DECIDES THE PROFILE.**
		 * `document_type` above holds a display name such as "ZUGFeRD 2.5.2 EXTENDED (CII)". The
		 * profile table in `resolve.mjs` is keyed by URNs, so with that name every KoSIT
		 * report would fall back to `en16931` ( measured 06.10.2026 ). The URN itself stands in
		 * the XPath of the matched scenario:
		 * `…/ram:ID/text() = 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended'`.
		 *
		 * ⚠️ Only `<rep:scenarioMatched>` is read, never the configuration's scenario list: the report
		 * names every scenario the validator knows only when none matched. The first quoted `urn:`
		 * string is taken; a scenario that accepts several IDs lists alternatives of one profile.
		 * `ReaderXml.php` does the same.
		 */
		const matched = first( doc.getElementsByTagName( 'rep:scenarioMatched' ) );
		const match   = matched ? first( matched.getElementsByTagName( 's:match' ) ) : null;

		if ( match ) {

			const urn = text( match ).match( /'(urn:[^']+)'/ );

			if ( urn ) report.meta.customization = urn[ 1 ];

		}

		const description = first( doc.getElementsByTagName( 's:description' ) );
		if ( description ) {
			const version = readConfigVersion( text( description ) );
			if ( version !== '' ) report.meta.config_version = version;
		}

		const seller = first( doc.getElementsByTagName( 'seller' ) );
		const id     = first( doc.getElementsByTagName( 'id' ) );
		const date   = first( doc.getElementsByTagName( 'issueDate' ) );

		if ( seller ) report.meta.seller = text( seller );
		if ( id ) report.meta.invoice_number = text( id );
		if ( date ) report.meta.invoice_date = normaliseDate( text( date ) );

	}


	/**
	 * The validation steps and the findings inside them, in one pass over rep:validationStepResult.
	 *
	 * @param {Document} doc
	 * @param {Report} report
	 * @return {void}
	 */

	static readStepsAndFindings( doc, report ) {

		for ( const result of [ ...doc.getElementsByTagName( 'rep:validationStepResult' ) ] ) {

			const name = first( result.getElementsByTagName( 's:name' ) );

			// ⚠️ NOT EVERY STEP NAMES ITSELF - "val-xml" carries no s:resource in every example seen.
			const label = name ? text( name ) : ( result.getAttribute( 'id' ) || '' );

			let errors = 0, warnings = 0, infos = 0;

			for ( const message of childrenByTag( result, 'rep:message' ) ) {

				const lvl = message.getAttribute( 'level' ) || '';

				if ( lvl === 'error' ) errors++;
				else if ( lvl === 'warning' ) warnings++;
				else infos++;

				report.findings.push( {
					position: message.getAttribute( 'id' ) || '',
					rule:     message.getAttribute( 'code' ) || '',
					severity: ( lvl === 'error' || lvl === 'warning' ) ? lvl : 'information',
					message:  text( message ),
					path:     message.getAttribute( 'xpathLocation' ) || '',
					rows:     [],
				} );

			}

			report.steps.push( { step: label, errors, warnings, infos } );

		}

	}


	/**
	 * The document table - delegated to ReaderHtml against the report's embedded explanation HTML.
	 *
	 * @param {Document} doc
	 * @param {Report} report
	 * @param {typeof DOMParser} DOMParserImpl
	 * @return {void}
	 */

	static readDocument( doc, report, DOMParserImpl ) {

		const explanation = first( doc.getElementsByTagName( 'rep:explanation' ) );

		if ( ! explanation ) {
			report.notices.push( 'This report carries no embedded HTML explanation, so the document view could not be read; only the findings are shown.' );
			return;
		}

		// ⚠️ innerHTML, NOT PER-CHILD saveXML() - see this file's own header comment on why a plain
		// string re-parse as HTML is what makes ReaderHtml's unprefixed queries apply.
		const html = explanation.innerHTML || '';

		if ( html.trim() === '' ) {
			report.notices.push( 'The embedded explanation was empty, so the document view could not be read; only the findings are shown.' );
			return;
		}

		try {
			const htmlDoc = ReaderHtml.load( html, DOMParserImpl );
			ReaderHtml.readDocument( htmlDoc, report );
		} catch ( e ) {
			report.notices.push( 'The embedded explanation could not be read as HTML, so the document view is unavailable; only the findings are shown.' );
		}

	}

}


/**
 * A version-looking token out of the scenario's free-text description - see ReaderXml.php's own
 * doc block for the full "best effort, not a schema field" reasoning.
 *
 * @param {string} description
 * @return {string}
 */

function readConfigVersion( description ) {
	const m = [ ...String( description || '' ).matchAll( /version\s+(\d+(?:\.\d+)+)/gi ) ];
	return m.length ? m[ m.length - 1 ][ 1 ] : '';
}


/**
 * ⚠️ CII AND UBL WRITE THE ISSUE DATE DIFFERENTLY - see ReaderXml.php's own doc block.
 *
 * @param {string} t
 * @return {string}
 */

function normaliseDate( t ) {
	t = String( t || '' ).trim();
	if ( /^\d{8}$/.test( t ) )
		return t.slice( 0, 4 ) + '-' + t.slice( 4, 6 ) + '-' + t.slice( 6, 8 );
	return t;
}


/**
 * @param {HTMLCollection|Element[]} list
 * @return {Element|null}
 */

function first( list ) {
	return list && list.length ? list[ 0 ] : null;
}


/**
 * Direct children only, matching the PHP's `./rep:message` - see ReaderHtml.mjs's own childrenByTag()
 * for the same reasoning ( CSS/DOM has no native "direct child by prefixed tag name" query ).
 *
 * @param {Element} parent
 * @param {string} tag literal prefixed tag name, e.g. 'rep:message'
 * @return {Element[]}
 */

function childrenByTag( parent, tag ) {
	return [ ...parent.children ].filter( el => el.tagName === tag );
}


/**
 * @param {Element|null} node
 * @return {string}
 */

function text( node ) {
	if ( ! node ) return '';
	return String( node.textContent || '' ).replace( /\s+/g, ' ' ).trim();
}
