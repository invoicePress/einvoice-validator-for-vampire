// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Gets the embedded XML out of a ZUGFeRD/Factur-X PDF, **without pdf.js**.
 *
 * ⚠️ WHY NOT SIMPLY pdf.js? Because the inbound check lite is meant to be a single HTML file that
 * runs from `file://` and stays below 1 MB. pdf.js weighs 460 KB plus a 1.2 MB worker - that limit
 * cannot be kept with it. `parse.mjs`'s `extractPdfXml()` stays the path in the viewer, where pdf.js is
 * loaded anyway; here stands the narrow path.
 *
 * ⚠️ AND IT IS NOT THE WORSE ONE, ONLY THE NARROWER ONE. Only what an invoice needs is read: the
 * content of embedded files. Pages, fonts, images, text - none of that is touched. The visual still
 * needs pdf.js.
 *
 * Method: walk every data stream of the PDF, unpack those with `FlateDecode`, and
 * take the one that begins like an invoice XML. Sounds crude, but is exactly right - see below.
 */

/** How many data streams are looked at at most. */
export const MAX_STREAMS = 400;

/** How large an unpacked stream may be at most before it is skipped. */
export const MAX_STREAM_BYTES = 16 * 1024 * 1024;

/**
 * Gets the invoice XML out of a PDF.
 *
 * @param {Uint8Array|ArrayBuffer} data
 * @return {Promise<string>}	The XML, or '' when there is none in it.
 */
export async function extractPdfXmlPlain( data ) {

	const bytes = data instanceof Uint8Array ? data : new Uint8Array( data || 0 );

	if ( bytes.length === 0 ) return '';

	for ( const stream of streams( bytes ) ) {

		const text = await decodeStream( bytes, stream );

		if ( text === '' ) continue;

		if ( looksLikeInvoiceXml( text ) ) return text;

	}

	return '';

}

/**
 * Recognises an invoice XML by its root element.
 *
 * ⚠️ BY THE ROOT ELEMENT, NOT BY THE FILE NAME. The name in the PDF is, depending on the producer,
 * `factur-x.xml`, `zugferd-invoice.xml`, `ZUGFeRD-invoice.xml`, `xrechnung.xml` or
 * `order-x.xml` - the list grows with every vendor, and whoever maintains it loses against the
 * next one. The root element, by contrast, is standardised.
 *
 * ⚠️ AND THAT IS WHY THE CRUDE METHOD IS THE RIGHT ONE HERE. A PDF carries dozens of data streams;
 * exactly one of them begins with `<?xml` and carries an invoice root element. Resolving that cleanly through
 * the PDF object structure ( `/Names` → `/EmbeddedFiles` → `/Filespec` → `/EF` → `/F` )
 * requires a complete PDF reader with cross-reference tables, object streams and incremental
 * updates - three hundred lines for a result that a comparison of the root element
 * delivers just as reliably.
 *
 * @param {string} text
 * @return {boolean}
 */
export function looksLikeInvoiceXml( text ) {

	const root = rootElementName( text );

	return /^(CrossIndustryInvoice|Invoice|CreditNote|SCRDMCCBDACIOMessageStructure)$/.test( root );

}

/**
 * The local name of the root element - that is, the first real element, not the first angle bracket.
 *
 * ⚠️ A WINDOW OVER THE FIRST FEW KILOBYTES IS NOT ENOUGH. A search in the first 4096 characters for
 * the root element finds none in any ZUGFeRD sample ( measured: 0 of 50 ), although the XML is unpacked
 * correctly. The official samples carry a **licence notice several kilobytes long as an XML comment**
 * before the root, in German and in English. The measurement then reports "nothing found", and the
 * error looks like a broken decompressor.
 *
 * Therefore what may stand before the root is skipped - prolog, comments, processing
 * instructions, DOCTYPE - instead of hoping for a length.
 *
 * @param {string} text
 * @return {string}	The local name, or ''.
 */
export function rootElementName( text ) {

	let s = String( text || '' ).replace( /^﻿/, '' );

	let at = 0;

	// ⚠️ An upper limit against a document that consists only of comments.
	for ( let guard = 0; guard < 1000; guard++ ) {

		while ( at < s.length && /\s/.test( s.charAt( at ) ) ) at++;

		if ( s.charAt( at ) !== '<' ) return '';

		const next = s.charAt( at + 1 );

		if ( next === '?' ) { const end = s.indexOf( '?>', at ); if ( end < 0 ) return ''; at = end + 2; continue; }

		if ( next === '!' ) {

			if ( s.startsWith( '<!--', at ) ) { const end = s.indexOf( '-->', at ); if ( end < 0 ) return ''; at = end + 3; continue; }

			// DOCTYPE, CDATA und Verwandtes: bis zur schliessenden Klammer.
			const end = s.indexOf( '>', at );

			if ( end < 0 ) return '';

			at = end + 1;

			continue;

		}

		const m = /^<([A-Za-z_][\w.\-]*)(?::([A-Za-z_][\w.\-]*))?[\s/>]/.exec( s.slice( at, at + 200 ) );

		return m ? ( m[ 2 ] || m[ 1 ] ) : '';

	}

	return '';

}

/**
 * All data stream sections of the PDF, as { dictAt, from, to }.
 *
 * ⚠️ THE WORD `stream` IS SEARCHED FOR, WITH BOUNDARIES. Without a word boundary the search also hits
 * `endstream`, and every such hit shifts the start by eight bytes - the unpacked stream is
 * then garbage, and silent garbage: `DecompressionStream` throws, the entry is skipped,
 * and at the end it says "no XML in the PDF".
 *
 * @param {Uint8Array} bytes
 * @return {Array<{ dict: string, from: number, to: number }>}
 */
function streams( bytes ) {

	const text = latin1( bytes );

	const out = [];

	const pattern = /stream\r\n|stream\n|stream\r/g;

	let m;

	while ( ( m = pattern.exec( text ) ) !== null && out.length < MAX_STREAMS ) {

		// ⚠️ `endstream` ends in "stream" as well - the hit must lie at the start of a word.
		const before = text.charAt( m.index - 1 );

		if ( /[A-Za-z]/.test( before ) ) continue;

		const from = m.index + m[ 0 ].length;
		const to   = text.indexOf( 'endstream', from );

		if ( to < 0 ) continue;

		/**
		 * The dictionary of the object stands BEFORE the keyword. 600 bytes back are enough for
		 * `/Filter`, `/Length` and `/Subtype`; a dictionary with more in front has nothing that is
		 * needed here.
		 */
		const dict = text.slice( Math.max( 0, m.index - 600 ), m.index );

		out.push( { dict, from, to } );

	}

	return out;

}

/**
 * Unpacks a data stream if it carries a filter we know.
 *
 * @param {Uint8Array} bytes
 * @param {{ dict: string, from: number, to: number }} stream
 * @return {Promise<string>}
 */
async function decodeStream( bytes, stream ) {

	const length = stream.to - stream.from;

	if ( length <= 0 || length > MAX_STREAM_BYTES ) return '';

	const raw = bytes.subarray( stream.from, stream.to );

	const flate = /\/Filter\s*(?:\[[^\]]*)?\/FlateDecode/.test( stream.dict );

	if ( ! flate ) {

		/**
		 * Embedded uncompressed is rare, but it occurs ( some producers store
		 * the XML raw ). A look at the first characters costs nothing.
		 */
		const head = latin1( raw.subarray( 0, 64 ) ).trimStart();

		return head.startsWith( '<' ) || head.startsWith( '﻿' ) ? utf8( trimEol( raw ) ) : '';

	}

	try {

		/**
		 * ⚠️ `deflate` HERE, NOT `deflate-raw` - UNLIKE THE ZIP. A PDF stream with
		 * `/FlateDecode` carries the zlib header; a ZIP entry does not. Swapping the two
		 * methods gives "incorrect header check" or silent nonsense, and both
		 * look like a broken file.
		 */
		const piped = new Blob( [ trimEol( raw ) ] ).stream().pipeThrough( new DecompressionStream( 'deflate' ) );

		const buf = await new Response( piped ).arrayBuffer();

		if ( buf.byteLength > MAX_STREAM_BYTES ) return '';

		return utf8( new Uint8Array( buf ) );

	} catch ( err ) {

		// A stream that cannot be unpacked is no error here: most streams of a
		// PDF are page content, fonts and images, and none of them is of interest.
		return '';

	}

}

/**
 * Cuts off the line break before `endstream`.
 *
 * ⚠️ It belongs to the syntax, not to the data. With `FlateDecode` it does not always disturb, but with
 * a raw stored XML it hangs at the end, and with some implementations of
 * `DecompressionStream` a surplus byte leads to an error instead of a result.
 *
 * @param {Uint8Array} raw
 * @return {Uint8Array}
 */
function trimEol( raw ) {

	let end = raw.length;

	if ( end > 0 && raw[ end - 1 ] === 0x0a ) end--;
	if ( end > 0 && raw[ end - 1 ] === 0x0d ) end--;

	return raw.subarray( 0, end );

}

/**
 * @param {Uint8Array} bytes
 * @return {string}
 */
function latin1( bytes ) {

	let out = '';

	for ( let i = 0; i < bytes.length; i += 8192 )
		out += String.fromCharCode.apply( null, bytes.subarray( i, i + 8192 ) );

	return out;

}

/**
 * @param {Uint8Array} bytes
 * @return {string}
 */
function utf8( bytes ) {

	return new TextDecoder( 'utf-8' ).decode( bytes );

}
