// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Unpacks what the user drags in: ZIP and `.eml`.
 *
 * `detect.mjs` recognises an archive and says "not unpacked". This file unpacks it. It is needed three
 * times: in the inbound check lite, in the web app and in the plugin - which is why it lives in the core
 * and not in one of the three interfaces.
 *
 * ⚠️ NO DEPENDENCY, NOT EVEN FOR ZIP. Decompression is done by `DecompressionStream`, a
 * platform function ( browsers since 2023, Node since 18 ). A ZIP package would weigh more than the
 * 200 KB the whole bundle may have - and a self-written inflate would be the wrong ambition.
 *
 * ⚠️ THIS FILE IS ASYNCHRONOUS, THE REST OF THE CHECK CORE IS NOT. `DecompressionStream` exists only as a
 * stream; whoever wanted to make a synchronous function of it would have to block, and a browser cannot
 * do that. Unpacking therefore belongs BEFORE the check run, not inside it - `review()`
 * stays synchronous and thus testable in Node and in the add-in alike.
 */

/** How deeply nested containers are unpacked. */
export const MAX_DEPTH = 3;

/** How many entries of a container are read at most. */
export const MAX_ENTRIES = 200;

/**
 * ⚠️ An upper limit for the unpacked size, and it is no decoration. A "zip bomb" is a few
 * kilobytes in size and unpacks to gigabytes; without a limit the user's browser freezes,
 * and the user takes it for an error of the program. 64 MB is enough for any invoice with attachments
 * by orders of magnitude.
 */
export const MAX_TOTAL_BYTES = 64 * 1024 * 1024;


/**
 * Unpacks an entry until only plain files are left.
 *
 * @param {{ name?: string, data: Uint8Array|ArrayBuffer }} file
 * @param {object} [options]	{ depth, budget }
 * @return {Promise<Array<{ name: string, data: Uint8Array, from: string }>>}
 */
export async function unpack( file, options ) {

	const state = options && options.state ? options.state : { bytes: 0 };
	const depth = ( options && options.depth ) || 0;

	const name = ( file && file.name ) || '';
	const data = toBytes( file && file.data );

	if ( data.length === 0 ) return [];

	const kind = containerKind( data, name );

	if ( kind === '' || depth >= MAX_DEPTH )
		return [ { name, data, from: ( options && options.from ) || '' } ];

	const inner = kind === 'zip' ? await unzip( data, state ) : unpackEml( data, state );

	const out = [];

	for ( const entry of inner ) {

		const nested = await unpack( entry, { depth: depth + 1, state, from: name } );

		for ( const n of nested ) out.push( n );

	}

	/**
	 * ⚠️ AN EMPTY CONTAINER BECOMES THE CONTAINER ITSELF, NOT NOTHING. A ZIP without readable
	 * entries ( encrypted, broken, unknown method ) must not disappear without a trace -
	 * otherwise the interface reports "no file recognised" and the user looks for the error on their side.
	 */
	return out.length > 0 ? out : [ { name, data, from: ( options && options.from ) || '' } ];

}

/**
 * What kind of container is this - or none?
 *
 * @param {Uint8Array} bytes
 * @param {string} name
 * @return {'zip'|'eml'|''}
 */
export function containerKind( bytes, name ) {

	if ( bytes.length >= 2 && bytes[ 0 ] === 0x50 && bytes[ 1 ] === 0x4b ) return 'zip';

	/**
	 * ⚠️ `.eml` HAS NO SIGNATURE IN THE FIRST BYTES. A message begins with some header
	 * line, and which one is up to the sending program. It is therefore recognised by
	 * its form: a sequence of `Name: value` lines, among which one of the three mandatory lines of a
	 * message occurs. The extension alone is not enough - Outlook attaches files without an extension
	 * ( see `detect.mjs` ).
	 */
	const head = latin1( bytes.subarray( 0, 2048 ) );

	if ( /^(from|to|subject|date|received|message-id|mime-version|content-type)\s*:/im.test( head )
		&& /^mime-version\s*:|^content-type\s*:/im.test( head ) )
		return 'eml';

	if ( /\.eml$/i.test( name ) && /:/.test( head ) ) return 'eml';

	return '';

}

/**
 * Reads a ZIP archive.
 *
 * ⚠️ READ FROM THE CENTRAL DIRECTORY AT THE END, NOT FROM THE FRONT. The local headers at the
 * start of the file may carry size and checksum as zero and write the real values into a
 * "data descriptor" BEHIND the data ( every program that creates a ZIP as a stream does that -
 * Outlook too ). Whoever reads from the front then does not know where an entry ends, and
 * loses everything from the first such entry on. The central directory always carries the values.
 *
 * @param {Uint8Array} bytes
 * @param {object} [state]
 * @return {Promise<Array<{ name: string, data: Uint8Array }>>}
 */
export async function unzip( bytes, state ) {

	const budget = state || { bytes: 0 };

	const eocd = findEocd( bytes );

	if ( eocd < 0 ) return [];

	const view = new DataView( bytes.buffer, bytes.byteOffset, bytes.byteLength );

	const count = view.getUint16( eocd + 10, true );
	let offset  = view.getUint32( eocd + 16, true );

	const out = [];

	for ( let i = 0; i < count && i < MAX_ENTRIES; i++ ) {

		if ( offset + 46 > bytes.length ) break;
		if ( view.getUint32( offset, true ) !== 0x02014b50 ) break;   // "PK\x01\x02"

		const method     = view.getUint16( offset + 10, true );
		const compressed = view.getUint32( offset + 20, true );
		const nameLen    = view.getUint16( offset + 28, true );
		const extraLen   = view.getUint16( offset + 30, true );
		const commentLen = view.getUint16( offset + 32, true );
		const localAt    = view.getUint32( offset + 42, true );

		const name = utf8( bytes.subarray( offset + 46, offset + 46 + nameLen ) );

		offset += 46 + nameLen + extraLen + commentLen;

		// Directory entries carry no data.
		if ( name.endsWith( '/' ) ) continue;

		const data = await readLocalEntry( bytes, view, localAt, method, compressed, budget );

		if ( data ) out.push( { name, data } );

	}

	return out;

}

/**
 * @param {Uint8Array} bytes
 * @param {DataView} view
 * @param {number} at
 * @param {number} method
 * @param {number} compressed
 * @param {object} budget
 * @return {Promise<Uint8Array|null>}
 */
async function readLocalEntry( bytes, view, at, method, compressed, budget ) {

	if ( at + 30 > bytes.length ) return null;
	if ( view.getUint32( at, true ) !== 0x04034b50 ) return null;   // "PK\x03\x04"

	const nameLen  = view.getUint16( at + 26, true );
	const extraLen = view.getUint16( at + 28, true );

	const start = at + 30 + nameLen + extraLen;
	const raw   = bytes.subarray( start, start + compressed );

	if ( method === 0 ) return charge( raw, budget );

	// ⚠️ Only "deflate" ( 8 ). Everything else - bzip2, LZMA, encrypted - is skipped instead of
	// half read; a half-read entry would look like a broken invoice.
	if ( method !== 8 ) return null;

	try {

		/**
		 * ⚠️ `deflate-raw`, NOT `deflate`. A ZIP stores the raw deflate stream without the
		 * zlib header ( two bytes ) and without the Adler checksum. `new DecompressionStream( 'deflate' )`
		 * expects exactly that header and throws "incorrect header check" - the error looks like
		 * a broken archive and is none.
		 */
		const stream = new Blob( [ raw ] ).stream().pipeThrough( new DecompressionStream( 'deflate-raw' ) );

		const buf = await new Response( stream ).arrayBuffer();

		return charge( new Uint8Array( buf ), budget );

	} catch ( err ) {

		return null;

	}

}

/**
 * Pulls the attachments out of an `.eml` message.
 *
 * ⚠️ BY HAND, AND DELIBERATELY ONLY AS FAR AS NEEDED. A complete MIME reader is a package of its
 * own; what is needed here is exactly one thing: find the parts whose content is a file, and
 * decode them. Everything else in a message - text, HTML, nested alternatives - is
 * skipped.
 *
 * @param {Uint8Array} bytes
 * @param {object} [state]
 * @return {Array<{ name: string, data: Uint8Array }>}
 */
export function unpackEml( bytes, state ) {

	const budget = state || { bytes: 0 };

	/**
	 * ⚠️ READ AS LATIN-1, NOT UTF-8. A message is 7-bit text with encoded parts; the
	 * raw text may contain bytes that are invalid in UTF-8. `TextDecoder( 'utf-8' )` replaces
	 * them with U+FFFD - and when that happens inside a base64 block, the attachment is ruined,
	 * without an error message.
	 */
	const text = latin1( bytes );

	const boundary = ( text.match( /boundary\s*=\s*"?([^";\r\n]+)"?/i ) || [] )[ 1 ];

	if ( ! boundary ) return [];

	const parts = text.split( '--' + boundary );

	const out = [];

	for ( const part of parts ) {

		if ( out.length >= MAX_ENTRIES ) break;

		const split = part.indexOf( '\r\n\r\n' ) >= 0 ? part.indexOf( '\r\n\r\n' ) : part.indexOf( '\n\n' );

		if ( split < 0 ) continue;

		const headers = part.slice( 0, split );

		/**
		 * ⚠️ THE LAST LINE BREAK BELONGS TO THE BOUNDARY, NOT TO THE CONTENT. MIME prescribes that
		 * a CRLF precedes the boundary line - and that CRLF is not part of the file. Whoever leaves it
		 * appends two bytes to every attachment: harmless for base64 ( the characters drop out when
		 * filtering ), but for quoted-printable and for 7bit/8bit they land **in the result**, for
		 * example `<a>Müller & Sohn</a>\r\n\r\n`.
		 */
		const body = part.slice( split ).replace( /^(\r?\n){2}/, '' ).replace( /\r?\n$/, '' );

		// ⚠️ A nested multipart carries no file itself - the parts inside it arrive here again through
		// unpack(), because the body again looks like a message.
		if ( /content-type\s*:\s*multipart\//i.test( headers ) ) continue;

		const name = filenameOf( headers );
		const encoding = ( headers.match( /content-transfer-encoding\s*:\s*([^\s;]+)/i ) || [] )[ 1 ] || '';

		const disposition = ( headers.match( /content-disposition\s*:\s*([^\s;]+)/i ) || [] )[ 1 ] || '';
		const type = ( headers.match( /content-type\s*:\s*([^\s;]+)/i ) || [] )[ 1 ] || '';

		// Only what is a file: a named part or an explicit attachment.
		if ( name === '' && ! /attachment/i.test( disposition ) ) continue;

		// A plain message text is no invoice.
		if ( name === '' && /^text\/(plain|html)$/i.test( type ) ) continue;

		const data = decodePart( body, encoding, budget );

		if ( data && data.length > 0 ) out.push( { name, data } );

	}

	return out;

}

/**
 * @param {string} headers
 * @return {string}
 */
function filenameOf( headers ) {

	/**
	 * ⚠️ TWO NOTATIONS, AND THE SECOND WINS WHEN IT EXISTS. `filename="…"` stands in
	 * `Content-Disposition`, `name="…"` in `Content-Type`. Outlook writes both, Thunderbird
	 * sometimes only one. Whoever reads only `filename` loses attachments from some programs.
	 */
	const a = headers.match( /filename\s*=\s*"?([^";\r\n]+)"?/i );

	if ( a ) return a[ 1 ].trim();

	const b = headers.match( /\bname\s*=\s*"?([^";\r\n]+)"?/i );

	return b ? b[ 1 ].trim() : '';

}

/**
 * @param {string} body
 * @param {string} encoding
 * @param {object} budget
 * @return {Uint8Array|null}
 */
function decodePart( body, encoding, budget ) {

	const enc = String( encoding || '' ).toLowerCase();

	if ( enc === 'base64' ) {

		const clean = body.replace( /[^A-Za-z0-9+/=]/g, '' );

		try {

			const bin = typeof atob === 'function' ? atob( clean ) : Buffer.from( clean, 'base64' ).toString( 'latin1' );

			const out = new Uint8Array( bin.length );

			for ( let i = 0; i < bin.length; i++ ) out[ i ] = bin.charCodeAt( i ) & 0xff;

			return charge( out, budget );

		} catch ( err ) {

			return null;

		}

	}

	if ( enc === 'quoted-printable' ) {

		// ⚠️ First remove the "soft line breaks" ( a `=` at the end of a line ), THEN resolve the
		// hex sequences. The other way round a `=3D` at a line break would fall into two halves.
		const joined = body.replace( /=\r?\n/g, '' );

		const out = [];

		for ( let i = 0; i < joined.length; i++ ) {

			if ( joined.charAt( i ) === '=' && /^[0-9A-Fa-f]{2}$/.test( joined.substr( i + 1, 2 ) ) ) {

				out.push( parseInt( joined.substr( i + 1, 2 ), 16 ) );
				i += 2;

				continue;

			}

			out.push( joined.charCodeAt( i ) & 0xff );

		}

		return charge( new Uint8Array( out ), budget );

	}

	// 7bit / 8bit / binary / nichts angegeben
	const out = new Uint8Array( body.length );

	for ( let i = 0; i < body.length; i++ ) out[ i ] = body.charCodeAt( i ) & 0xff;

	return charge( out, budget );

}

/**
 * Searches the "end of central directory" signature from the back.
 *
 * ⚠️ FROM THE BACK AND ONLY IN THE LAST KILOBYTE RANGE. The signature `PK\x05\x06` can also occur in the
 * compressed data; whoever searches from the front finds a random hit with some probability
 * and reads nonsense afterwards. The trailer may carry up to 64 KB of comment, it never stands further
 * back than that.
 *
 * @param {Uint8Array} bytes
 * @return {number}
 */
function findEocd( bytes ) {

	const start = Math.max( 0, bytes.length - ( 0xffff + 22 ) );

	for ( let i = bytes.length - 22; i >= start; i-- )
		if ( bytes[ i ] === 0x50 && bytes[ i + 1 ] === 0x4b && bytes[ i + 2 ] === 0x05 && bytes[ i + 3 ] === 0x06 )
			return i;

	return -1;

}

/**
 * Books the unpacked size against the total budget and discards what goes beyond it.
 *
 * @param {Uint8Array} data
 * @param {object} budget
 * @return {Uint8Array|null}
 */
function charge( data, budget ) {

	budget.bytes = ( budget.bytes || 0 ) + data.length;

	return budget.bytes > MAX_TOTAL_BYTES ? null : data;

}

/**
 * @param {*} data
 * @return {Uint8Array}
 */
function toBytes( data ) {

	if ( data instanceof Uint8Array ) return data;
	if ( data instanceof ArrayBuffer ) return new Uint8Array( data );

	if ( typeof data === 'string' ) {

		const out = new Uint8Array( data.length );

		for ( let i = 0; i < data.length; i++ ) out[ i ] = data.charCodeAt( i ) & 0xff;

		return out;

	}

	return new Uint8Array( 0 );

}

/**
 * @param {Uint8Array} bytes
 * @return {string}
 */
function latin1( bytes ) {

	let out = '';

	// In blocks, because `String.fromCharCode( ...bytes )` blows the call stack for large fields.
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
