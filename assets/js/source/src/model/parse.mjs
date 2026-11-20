// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The file-reading/parsing helpers `<einvoice-viewer>` uses internally - pulled out into their own
 * module so a PLAIN, non-custom-element consumer (a classic `<script>`, not a Web
 * Component) can parse an invoice in the browser too, without duplicating this logic a second time.
 * `einvoice-viewer.mjs` imports from here instead of declaring these itself - single source of
 * truth for both consumers.
 *
 * Why this exists at all: the WordPress Viewer plugin's shortcode reads and renders entirely in the
 * browser - but its toolbar (KoSIT validate, Export to Excel, Visualizer, Identifier Check, Inbound
 * Check buttons) is NOT part of the Web Component; it lives in the shortcode's own markup, driven by
 * `assets/js/spev-uploader.js`. That script is a classic IIFE script, not a Custom Element, so it needs
 * these same building blocks as plain importable functions - dynamic `import()` of a bundle built from
 * this file - rather than the whole `<einvoice-viewer>` element (which would force restructuring the
 * shortcode's Shadow DOM around the component's own, fixed toolbar-less markup).
 */

import { TreeReader } from './TreeReader.mjs';

/**
 * ⚠️ THE ONLY DEPENDENCY FROM `model/` TO `inbound/`, and it points this way on purpose. The
 * narrow PDF reader was written for the inbound check, but it serves anyone who needs an embedded XML;
 * copying it here would mean maintaining two versions. The file itself has no
 * imports of its own, so it pulls nothing further in.
 */
import { extractPdfXmlPlain } from '../inbound/pdfxml.mjs';

const readerCache = new Map(); // nodesUrl -> Promise<TreeReader>, shared across every instance on the page
const tooltipCache = new Map(); // fieldsUrl -> Promise<Map<code, tooltipText>>

/**
 * @param {string} nodesUrl
 * @return {Promise<TreeReader|null>}
 */

export function loadReader( nodesUrl ) {

	if ( ! nodesUrl ) return Promise.resolve( null );

	if ( ! readerCache.has( nodesUrl ) )
		readerCache.set( nodesUrl, fetch( nodesUrl )
			.then( ( r ) => r.json() )
			.then( ( nodes ) => new TreeReader( nodes ) )
			.catch( () => null ) );

	return readerCache.get( nodesUrl );

}


/**
 * @param {string} fieldsUrl
 * @param {string} [lang] 'de' | 'en' | 'fr' - falls back to 'en' when the requested language is
 *					missing for a given field ( einvoice-reference-data's own fields.json carries all
 *					three per field, but a future field added there without every translation yet
 *					should still show SOMETHING rather than a blank tooltip ).
 * @return {Promise<Map<string,string>|null>}
 */

export function loadTooltips( fieldsUrl, lang = 'en' ) {

	if ( ! fieldsUrl ) return Promise.resolve( null );

	const cacheKey = fieldsUrl + '|' + lang;

	if ( ! tooltipCache.has( cacheKey ) )
		tooltipCache.set( cacheKey, fetch( fieldsUrl )
			.then( ( r ) => r.json() )
			.then( ( fields ) => {

				const map = new Map();

				for ( const field of fields ) {

					const name = ( field.businessTerm && ( field.businessTerm[ lang ] || field.businessTerm.en ) ) || '';
					const desc = ( field.description && ( field.description[ lang ] || field.description.en ) ) || '';

					if ( ! name ) continue;

					map.set( field.id, desc ? name + ' — ' + desc : name );

				}

				return map;

			} )
			.catch( () => null ) );

	return tooltipCache.get( cacheKey );

}


const fieldRowsCache = new Map(); // fieldsUrl -> Promise<Array|null>

/**
 * The rows of the field catalogue as they are, for the BT marks of the tab "XML".
 *
 * ⚠️ A SECOND REQUEST FOR THE SAME FILE AS `loadTooltips()`, ANSWERED FROM THE BROWSER'S CACHE. The tooltip map keeps only
 * names and descriptions; the marks need the paths, which it drops.
 *
 * @param {string} fieldsUrl
 * @return {Promise<Array<object>|null>}
 */

export function loadFieldRows( fieldsUrl ) {

	if ( ! fieldsUrl ) return Promise.resolve( null );

	if ( ! fieldRowsCache.has( fieldsUrl ) )
		fieldRowsCache.set( fieldsUrl, fetch( fieldsUrl )
			.then( ( r ) => r.json() )
			.then( ( rows ) => ( Array.isArray( rows ) ? rows : null ) )
			.catch( () => null ) );

	return fieldRowsCache.get( fieldsUrl );

}


/**
 * The two questions `rowTerms()` of `explainer/DocumentListing.mjs` asks, answered from the viewer's field catalogue,
 * and the name of a field for the tooltip of its mark.
 *
 * ⚠️ THE VIEWER'S CATALOGUE IS NOT THE EXPLORERS' `fields.mjs`. It writes the CII path under `xpath` and the UBL path
 * under `ublPath`, where `fields.mjs` has `xpathUnCefact22B.xpath`. "N/A – kein UBL-Pendant …" under `ublPath` is a
 * statement, not a path, and is read as none, as `Rules.indexTerms()` does.
 *
 * @param {Array<object>|null} rows
 * @param {string} lang
 * @return {{fieldIds: function(): string[], fieldXpath: function(string, string): string, fieldLabel: function(string): string}|null}
 */

export function fieldPaths( rows, lang = 'en' ) {

	if ( ! Array.isArray( rows ) ) return null;

	const byId = new Map();

	for ( const row of rows ) {

		const id = String( row && row.id || '' ).trim();

		if ( id === '' ) continue;

		let ubl = typeof row.ublPath === 'string' ? row.ublPath : '';

		if ( ubl.toLowerCase().startsWith( 'n/a' ) ) ubl = '';

		const name = ( row.businessTerm && ( row.businessTerm[ lang ] || row.businessTerm.en ) ) || '';

		byId.set( id, { cii: String( row.xpath || ( row.xpathUnCefact22B && row.xpathUnCefact22B.xpath ) || '' ), ubl, name: String( name ) } );

	}

	return {
		fieldIds: () => [ ...byId.keys() ],
		fieldXpath: ( id, syntax ) => {
			const f = byId.get( String( id ).trim() );
			return f ? ( String( syntax ).toUpperCase() === 'UBL' ? f.ubl : f.cii ) : '';
		},
		fieldLabel: ( id ) => ( byId.get( String( id ).trim() ) || { name: '' } ).name,
	};

}


/**
 * @param {ArrayBuffer} buf
 * @return {string} 'pdf' | 'xml'
 */

export function sniff( buf ) {

	const head = new Uint8Array( buf.slice( 0, 5 ) );
	const text = String.fromCharCode( ...head );

	if ( text.startsWith( '%PDF' ) ) return 'pdf';

	return 'xml'; // anything else is handed to DOMParser, which reports its own errors

}


/**
 * @param {string} localName Lowercased root element local name.
 * @return {'cii'|'ubl'|null}
 */

export function detectSyntax( localName ) {

	if ( localName === 'crossindustryinvoice' ) return 'cii';
	if ( localName === 'invoice' || localName === 'creditnote' ) return 'ubl';

	return null;

}


/**
 * Loads pdf.js on demand and returns it, or `null` when no source is configured or loading fails.
 *
 * Two ways, chosen by what the embedder configured:
 *
 *   `url` ( + `workerUrl` )            an ES module, loaded with `import()` - the WordPress plugin.
 *   `script` ( + `workerScript` )      classic scripts that define `globalThis.pdfjsLib` and
 *                                      `globalThis.pdfjsWorker` - the app package.
 *
 * ⚠️ **THE SECOND WAY EXISTS BECAUSE OF `file://`.** The app package is meant to run from a USB stick
 * by double click, and from `file://` every browser refuses `import()` of a neighbouring file ( origin
 * `null` ) and refuses to start a `Worker` from it. A `<script>` element is not subject to either
 * rule - the same reason `DataLoader._loadProtectedScript()` loads the add-on packages that way. With
 * `globalThis.pdfjsWorker` defined, pdf.js runs its worker code on the main thread instead of in a
 * worker; slower for a large PDF, but it works where nothing else does.
 *
 * ⚠️ **ONLY ON DEMAND.** pdf.js weighs 448 KB plus a 1.2-MB worker and is loaded the
 * moment somebody needs it - a PDF the narrow reader cannot open, or an attachment someone wants to
 * see - and never with the page.
 *
 * ⚠️ **THE WORKER SCRIPT FIRST.** pdf.js determines at its first `getDocument()` whether a main-thread
 * worker is available; if the library is there before `pdfjsWorker` is, it tries a real worker and
 * fails on `file://`.
 *
 * @param {{url?: string, workerUrl?: string, script?: string, workerScript?: string}} [pdfjs]
 * @return {Promise<object|null>} `window.pdfjsLib`, or null.
 */

export async function loadPdfjs( pdfjs ) {

	if ( typeof window === 'undefined' ) return null;

	const opts = pdfjs || {};

	// ⚠️ Before the early return: pdf.js may have been loaded by another caller that knew no wasm folder.
	rememberWasmUrl( opts.url || opts.script || '' );

	if ( window.pdfjsLib ) return window.pdfjsLib;

	if ( opts.script && typeof document !== 'undefined' ) {

		try {

			if ( opts.workerScript && ! globalThis.pdfjsWorker ) await loadClassicScript( opts.workerScript );

			await loadClassicScript( opts.script );

		} catch ( e ) {

			// Not reachable - the caller says so in its own words.

		}

		if ( window.pdfjsLib ) return window.pdfjsLib;

	}

	if ( opts.url ) {

		try {

			const ns = await import( /* webpackIgnore: true */ opts.url );

			if ( opts.workerUrl && ns.GlobalWorkerOptions )
				ns.GlobalWorkerOptions.workerSrc = opts.workerUrl;

			window.pdfjsLib = ns;

		} catch ( e ) {

			// Not reachable or not a module - the caller's message is the same as without pdf.js.

		}

	}

	return window.pdfjsLib || null;

}


/**
 * The folder with the WebAssembly decoders of pdf.js, or '' when none is known.
 *
 * ⚠️ **SINCE pdf.js 5 SOME IMAGE TYPES ARE DECODED ONLY BY WEBASSEMBLY.** `jbig2.wasm` decodes JBIG2 *and*
 * CCITT fax images, `openjpeg.wasm` decodes JPEG 2000, `qcms_bg.wasm` converts ICC colours. Without
 * `wasmUrl` in `getDocument()` pdf.js does not fail: it logs "Dependent image isn't ready yet" and draws
 * the page **white**. Measured on 20.10.2026 with a Factur-X invoice whose five attachments are scans
 * with `/CCITTFaxDecode`: 0 dark pixels without `wasmUrl`, 22 675 with it on the first page. Scanned
 * attachments are exactly this kind of PDF, so the preview of a scan stayed empty since pdf.js 6.3.289
 * came in on 18.09.2026.
 *
 * ⚠️ **THE FOLDER IS `wasm/` NEXT TO pdf.js**, in the plugins ( `assets/js/pdfjs/wasm/` ) as in the
 * app packages ( `assets/pdfjs/wasm/` ). It is derived from the address pdf.js was loaded from, so an
 * embedder configures nothing new. A query string such as `?ver=…` is dropped by the URL resolution.
 *
 * ⚠️ From `file://` the browser refuses the `fetch()` of the `.wasm` file, and a scan stays white there.
 * A text-only PDF is not affected.
 *
 * @return {string}
 */

let wasmUrl = '';

function rememberWasmUrl( from ) {

	if ( wasmUrl || ! from ) return;

	try {

		wasmUrl = new URL( 'wasm/', new URL( from, document.baseURI ) ).href;

	} catch ( e ) {

		// An address the browser cannot resolve: pdf.js then works as before, without the decoders.

	}

}

export function pdfjsWasmUrl() {

	return wasmUrl;

}


/**
 * Loads one classic script and waits for it.
 *
 * ⚠️ **THE SAME ADDRESS IS LOADED ONCE.** Two attachments opened in quick succession would otherwise
 * insert the 1.2-MB worker twice and run it twice.
 *
 * @param {string} src
 * @return {Promise<void>}
 */

const scriptLoads = new Map();

function loadClassicScript( src ) {

	if ( scriptLoads.has( src ) ) return scriptLoads.get( src );

	const p = new Promise( ( resolve, reject ) => {

		const s = document.createElement( 'script' );
		s.src = src;
		s.async = true;
		s.onload = () => resolve();
		s.onerror = () => { scriptLoads.delete( src ); reject( new Error( 'script failed: ' + src ) ); };
		document.head.appendChild( s );

	} );

	scriptLoads.set( src, p );

	return p;

}


/**
 * Extracts the embedded XML from a ZUGFeRD/Factur-X PDF via window.pdfjsLib, when present.
 *
 * @param {ArrayBuffer} buf
 * @param {{url?: string, workerUrl?: string, script?: string, workerScript?: string}} [pdfjs]
 *        Addresses from which pdf.js may be **loaded** if the narrow path finds nothing
 *        and `window.pdfjsLib` is missing ( see `loadPdfjs()` ). Without them only the
 *        narrow path is tried.
 * @return {Promise<string>}
 */

export async function extractPdfXml( buf, pdfjs ) {

	/**
	 * ⚠️ **FIRST THE PATH WITHOUT pdf.js, AND ALWAYS.** `extractPdfXmlPlain()` walks the
	 * data streams of the PDF and takes the one that begins like an invoice XML - measured on all 50
	 * official ZUGFeRD samples ( 50 of 50, `test/pdfxml-corpus.mjs` ). It needs nothing but
	 * the browser.
	 *
	 * Requiring `window.pdfjsLib` here would make `<einvoice-viewer>` practically unusable for an
	 * embedding in another CMS: whoever wanted to read PDF invoices would have to put 448 KB of pdf.js
	 * plus a 1.2-MB worker next to it - for a job that 9 KB of our own code does.
	 *
	 * ⚠️ AND pdf.js STILL STAYS, AS THE FALLBACK. It really knows the attachment structure of a PDF
	 * instead of guessing it; where the narrow path finds nothing, the thorough one may still
	 * try. The order costs nothing: if the narrow path finds something, the check of the root
	 * element makes sure it is the right thing.
	 */
	try {

		const schmal = await extractPdfXmlPlain( buf );

		if ( schmal ) return schmal;

	} catch ( e ) {

		// An error here is no reason to give up - there is another path below.

	}

	if ( typeof window === 'undefined' )
		throw new Error( 'The PDF does not contain a readable e-invoice (ZUGFeRD / Factur-X).' );

	/**
	 * ⚠️ **LOAD ONLY HERE, NOT WITH THE FIRST PDF.** The narrow path read all 50 official
	 * ZUGFeRD samples; whoever arrives here has a PDF it could not read. Fetching 448 KB plus
	 * a 1.2-MB worker for this case is right - fetching them for **every** PDF
	 * would not be.
	 *
	 * ⚠️ **pdf.js 6.x EXISTS ONLY AS AN ES MODULE**, without a UMD build and thus without its own
	 * `window.pdfjsLib`. `import()` delivers the namespace; it is put there by this code,
	 * so that the rest of this function stays unchanged. And `workerSrc` must be set **before** the first
	 * `getDocument()` - pdf.js cannot guess the address of its worker from a bare
	 * import specifier.
	 */
	await loadPdfjs( pdfjs );

	if ( ! window.pdfjsLib )
		throw new Error( 'The PDF does not contain a readable e-invoice (ZUGFeRD / Factur-X).' );

	const doc = await window.pdfjsLib.getDocument( { data: buf } ).promise;
	const attachments = await doc.getAttachments();

	if ( ! attachments )
		throw new Error( 'The PDF does not contain a readable e-invoice (ZUGFeRD / Factur-X).' );

	// ⚠️ pdf.js 6.1.200 (mozilla/pdf.js#21418) changed getAttachments()'s shape TWICE over - measured
	// on 18.09.2026 against a real ZUGFeRD PDF (every upload failed with "not a readable
	// e-invoice" even though the attachment was right there):
	//   1. The container went from a plain object ( entries as OWN properties ) to a `Map`.
	//      `Object.keys()` on a Map returns `[]` ( a Map's entries are not its own enumerable
	//      properties ), so a loop over the keys silently never runs.
	//   2. The per-attachment VALUE lost its `content` field entirely - it now carries only
	//      `{rawFilename, filename, description}`, no bytes. The bytes must be fetched separately via
	//      `doc.getAttachmentContent( name )` ( confirmed against the real pdf.js 6.3.289 source,
	//      `PDFDocumentProxy.getAttachmentContent()` ).
	// `names`/`getContentOf()` below handle BOTH the OLD shape ( content inline, plain object ) and
	// the NEW one ( Map + separate async call ), so this keeps working whichever pdf.js version ends
	// up loaded from the CDN.
	const names = typeof attachments.keys === 'function' ? [ ...attachments.keys() ] : Object.keys( attachments );

	async function getContentOf( name ) {
		const entry = typeof attachments.get === 'function' ? attachments.get( name ) : attachments[ name ];
		if ( entry && entry.content )
			return entry.content;
		return await doc.getAttachmentContent( name );
	}

	const decoder = new TextDecoder( 'utf-8' );

	for ( const name of names ) {

		const bytes = await getContentOf( name );
		const head = decoder.decode( bytes.slice( 0, 64 ) ).trimStart();

		if ( head.startsWith( '<?xml' ) || head.startsWith( '<rsm:' ) || head.startsWith( '<Invoice' ) || head.startsWith( '<CreditNote' ) )
			return decoder.decode( bytes );

	}

	throw new Error( 'The PDF does not contain a readable e-invoice (ZUGFeRD / Factur-X).' );

}


/**
 * Client-side port of `einvoice-viewer-for-peppol-en16931`'s own
 * `SPEV_Plugin::detect_technical_context()`/`guess_standard()`/`find_bt24()`/`raw_customization_id()`
 * (PHP, no longer used by the shortcode's own render path, but still the reference this
 * was ported from line-for-line) - best-effort BT/BG scope + rule-set-name guess for the Technical
 * Details extension's "Field Browser"/"Rule Browser" popup buttons, so they can open pre-filtered
 * instead of dumping the visitor into an unfiltered list of a couple thousand rows.
 *
 * ⚠️ SAME "BEST-EFFORT" CAVEAT AS THE PHP ORIGINAL - only recognises the profile families confirmed
 * against a real corpus sample (EN16931 core, XRechnung, PEPPOL BIS Billing 3.0, Factur-X core/EXTENDED,
 * CIUS-PT); everything else deliberately returns '' rather than guessing. Extend the two lookup tables
 * only against a confirmed real sample, matching the PHP original's own STATUS.md note.
 * ⚠️ EXTENDED-CTC-FR is the one family added from the publisher's own
 * examples rather than from the local corpus, which holds none; see guessStandard().
 *
 * The scope stays `EXTENDED` for EXTENDED-CTC-FR on purpose: the profile uses the fields of Factur-X
 * EXTENDED plus five French ones, and the field browser's own `EXTENDED-CTC-FR` option would hide the
 * Factur-X EXTENDED fields the invoice actually carries.
 *
 * @param {string} rawXml
 * @param {'cii'|'ubl'} syntax
 * @param {object} model
 * @return {{syntax: string, scope: string, standard: string}}
 */

export function detectTechnicalContext( rawXml, syntax, model ) {

	let profile = findBt24( model && model.sections );

	// ⚠️ SAME MEASURED UBL QUIRK AS THE PHP ORIGINAL - a UBL upload's rendered BT-24 loses the
	// "xrechnung_3.0"-style profile suffix (horstoeko's UBL→CII bridge normalises it away before
	// ModelBuilder ever saw it, per the PHP original's own comment); reading the raw
	// <cbc:CustomizationID> straight out of the uploaded text sidesteps that loss. No-op for CII.
	if ( syntax === 'ubl' ) {

		const raw = rawCustomizationId( rawXml );

		if ( raw !== '' ) profile = raw;

	}

	const scope = ( profile === '' ) ? '' : ( profile.toLowerCase().indexOf( 'extended' ) !== -1 ? 'EXTENDED' : 'EN16931' );

	return { syntax, scope, standard: guessStandard( profile, syntax ), edition: guessEdition( profile ) };

}


/**
 * The edition of the format, when BT-24 names one - today only XRechnung does ( `…kosit:xrechnung_3.0` → `3.0` ).
 *
 * ⚠️ NEVER THE `1p0` OF FACTUR-X. `urn:factur-x.eu:1p0:extended` carries the version of the Factur-X namespace,
 * not the ZUGFeRD or Factur-X edition 2.x; the documents simply do not name their edition in BT-24. The Peppol
 * `billing:3.0` is left out too, because "3" already stands in the name of the format.
 *
 * ⚠️ A SEPARATE FIELD, NOT A LONGER `standard`. Add-ons compare `standard` with fixed names; "XRechnung-CII 3.0"
 * would match none of them.
 *
 * @param {string} profile BT-24
 * @return {string} e.g. "3.0", or ''
 */

function guessEdition( profile ) {

	const m = /xrechnung_(\d+(?:\.\d+)*)/i.exec( String( profile || '' ) );

	return m ? m[ 1 ] : '';

}


/**
 * @param {string} rawXml
 * @return {string}
 */

function rawCustomizationId( rawXml ) {

	const m = /<(?:[a-zA-Z0-9]+:)?CustomizationID\b[^>]*>([^<]+)</.exec( String( rawXml ) );

	return m ? m[ 1 ].trim() : '';

}


/**
 * @param {object} nodes
 * @return {string}
 */

function findBt24( nodes ) {

	if ( ! nodes || typeof nodes !== 'object' ) return '';

	if ( nodes.bt === 'BT-24' && nodes.value !== undefined ) return String( nodes.value );

	for ( const key of Object.keys( nodes ) ) {

		const child = nodes[ key ];

		if ( ! child || typeof child !== 'object' ) continue;

		if ( Array.isArray( child ) ) {

			for ( const entry of child ) {

				const found = findBt24( entry );

				if ( found !== '' ) return found;

			}

		} else {

			const found = findBt24( child );

			if ( found !== '' ) return found;

		}

	}

	return '';

}


/**
 * The rule-set name ( as the rule catalog spells it ) that fits a specification identifier ( BT-24 ).
 *
 * ⚠️ EXTENDED-CTC-FR COMES BEFORE EVERY OTHER TEST BELOW EXCEPT XRECHNUNG, IN BOTH SYNTAXES. The
 * publisher's examples ( FNFE-MPE, github.com/fnfempe/France_RFE ) carry
 * `urn:cen.eu:en16931:2017#conformant#urn.cpro.gouv.fr:1p0:extended-ctc-fr` in UBL and CII alike. That
 * value contains `cen.eu:en16931:2017`, so with a later test a UBL invoice of this profile would fall
 * through to `EN16931-UBL` and a CII one to `EN16931-CII`; the older CII marker `factur-x.eu:1p0:extended-ctc`
 * contains `factur-x.eu:1p0:extended` and would come out as `FACTUR-X-EXTENDED`. Both markers are the ones
 * `resolve.mjs` uses for the profile `fr-ctc`, so the viewer's format line, the rule browser filter and
 * the resolver name the same profile. ⚠️ `urn.cpro` with a DOT after `urn` is the published form;
 * "correcting" it to a colon would make the test miss every real invoice. The names
 * `EXTENDED-CTC-FR-CII` and `EXTENDED-CTC-FR-UBL` exist in the rule catalog of the "Extended Profiles"
 * add-on; without the add-on the rule browser ignores an unknown name and opens unfiltered.
 *
 * @param {string} profile
 * @param {string|null} syntax
 * @return {string}
 */

function guessStandard( profile, syntax ) {

	if ( profile === '' || ! syntax ) return '';

	const p = profile.toLowerCase();
	const suffix = ( syntax === 'ubl' ) ? '-UBL' : '-CII';

	if ( p.indexOf( 'xrechnung' ) !== -1 ) return 'XRechnung' + suffix;

	if ( p.indexOf( 'cpro.gouv.fr:1p0:extended-ctc-fr' ) !== -1 ) return 'EXTENDED-CTC-FR' + suffix;
	if ( p.indexOf( 'factur-x.eu:1p0:extended-ctc' ) !== -1 ) return 'EXTENDED-CTC-FR' + suffix;

	if ( p.indexOf( 'factur-x.eu:1p0:extended' ) !== -1 ) return 'FACTUR-X-EXTENDED';
	if ( p.indexOf( 'factur-x.eu' ) !== -1 ) return 'FACTUR-X-EN16931';

	if ( p.indexOf( 'peppol.eu' ) !== -1 && p.indexOf( 'billing:3' ) !== -1 ) return 'PEPPOL-BIS-Billing3-UBL';

	if ( p.indexOf( 'cius-pt' ) !== -1 ) return 'CIUS-PT-UBL';

	if ( p.indexOf( 'cen.eu:en16931:2017' ) !== -1 ) return 'EN16931' + suffix;

	return '';

}
