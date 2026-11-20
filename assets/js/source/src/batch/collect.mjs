// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * What the user hands to the batch check, turned into a list of invoices: chosen files, a dropped folder, a ZIP or an
 * `.eml` with attachments.
 *
 *   const entries = entriesFromDrop( event.dataTransfer )      ⚠️ synchronously, inside the drop handler
 *   const files   = await filesFromEntries( entries )          → [ { file, path } ]
 *   const items   = await prepareItems( files, { limit } )     → [ { name, path, bytes, xml, pdf, kind, reason } ]
 *
 * ⚠️ **NOTHING HERE SENDS ANYTHING.** The module reads files in the browser and unpacks them there; what happens with an
 * item afterwards is the business of the checker the page hands to the queue.
 *
 * @since 26.10.2026
 */

import { unpack } from '../inbound/container.mjs';
import { extractPdfXmlPlain, looksLikeInvoiceXml } from '../inbound/pdfxml.mjs';

/** How many files one batch takes at most; more are listed as not checked. */
export const MAX_ITEMS = 500;

/**
 * Files a folder of an operating system carries without being part of it. They are left out silently, because a row
 * "Thumbs.db: no invoice" in an overview of a month folder is noise nobody asked for.
 */
const SYSTEM_FILE = /(^|\/)(\.DS_Store|Thumbs\.db|desktop\.ini|\._[^/]*)$|(^|\/)__MACOSX\//i;

/**
 * The entries of a drop, taken while the event is still running.
 *
 * ⚠️ **SYNCHRONOUSLY, BEFORE THE FIRST `await`.** `DataTransfer.items` is emptied by the browser as soon as the drop handler
 * returns, and an `await` returns from it. Whoever awaits first and asks for the entries afterwards gets an empty list, and
 * the drop of a folder seems to do nothing.
 *
 * @param {DataTransfer} dt
 * @return {Array<FileSystemEntry|File>} entries where the browser offers them, plain files otherwise
 */
export function entriesFromDrop( dt ) {

	const out = [];

	if ( ! dt ) return out;

	const items = dt.items ? Array.from( dt.items ) : [];

	if ( items.length && typeof items[ 0 ].webkitGetAsEntry === 'function' ) {

		for ( const it of items ) {
			if ( it.kind !== 'file' ) continue;
			const entry = it.webkitGetAsEntry();
			if ( entry ) out.push( entry );
			else { const f = it.getAsFile(); if ( f ) out.push( f ); }
		}

		return out;

	}

	return dt.files ? Array.from( dt.files ) : out;

}

/**
 * Reads all entries of a folder.
 *
 * ⚠️ **`readEntries()` MUST BE CALLED UNTIL IT RETURNS AN EMPTY LIST.** Chrome hands out at most 100 entries per call; a
 * single call reads the first hundred invoices of a month folder and drops the rest without a word.
 *
 * @param {FileSystemDirectoryEntry} dir
 * @return {Promise<FileSystemEntry[]>}
 */
function readAll( dir ) {

	const reader = dir.createReader();
	const out = [];

	return new Promise( ( resolve ) => {

		const next = () => reader.readEntries( ( batch ) => {
			if ( ! batch.length ) { resolve( out ); return; }
			out.push( ...batch );
			next();
		}, () => resolve( out ) );

		next();

	} );

}

/**
 * @param {FileSystemFileEntry} entry
 * @return {Promise<File|null>}
 */
function fileOf( entry ) {

	return new Promise( ( resolve ) => entry.file( resolve, () => resolve( null ) ) );

}

/**
 * Turns dropped entries ( folders included, any depth ) and plain files into a flat list.
 *
 * @param {Array<FileSystemEntry|File>} entries
 * @return {Promise<Array<{file: File, path: string}>>}
 */
export async function filesFromEntries( entries ) {

	const out = [];

	const walk = async ( e, prefix ) => {

		if ( out.length > MAX_ITEMS * 2 ) return;

		// A plain File ( from an input or an older browser ): its relative path, when the folder input set one.
		if ( typeof File !== 'undefined' && e instanceof File ) {
			out.push( { file: e, path: e.webkitRelativePath || e.name } );
			return;
		}

		if ( e.isFile ) {
			const f = await fileOf( e );
			if ( f ) out.push( { file: f, path: ( prefix ? prefix + '/' : '' ) + e.name } );
			return;
		}

		if ( e.isDirectory ) {
			const kids = await readAll( e );
			// ⚠️ Sorted by name, so that a month folder appears in the order the file manager shows it.
			kids.sort( ( a, b ) => String( a.name ).localeCompare( String( b.name ) ) );
			for ( const k of kids ) await walk( k, ( prefix ? prefix + '/' : '' ) + e.name );
		}

	};

	for ( const e of entries || [] ) await walk( e, '' );

	return out.filter( ( f ) => ! SYSTEM_FILE.test( f.path ) );

}

/**
 * Whether bytes start with "%PDF".
 *
 * @param {Uint8Array} b
 * @return {boolean}
 */
export function isPdf( b ) {

	return b.length > 4 && b[ 0 ] === 0x25 && b[ 1 ] === 0x50 && b[ 2 ] === 0x44 && b[ 3 ] === 0x46;

}

/**
 * Unpacks containers, reads the XML of every PDF and sorts out what is no invoice.
 *
 * Each item carries:
 * - `name` and `path` ( a file in a ZIP: `archive.zip/inner/file.xml` ),
 * - `bytes`, the file as it came ( a PDF is sent as a PDF, so that Mustang checks its PDF part as well ),
 * - `xml`, the invoice XML ( from the PDF, or the file itself ), with a byte order mark where the file has one,
 * - `kind`: `'xml'`, `'pdf'` or `'none'`, and for `'none'` a `reason`: `'no-xml-in-pdf'`, `'not-an-invoice'`, `'too-many'`.
 *
 * ⚠️ **AN ITEM WITHOUT AN INVOICE STAYS IN THE LIST.** A month folder with a scanned PDF among the e-invoices must show that
 * PDF as "no e-invoice"; leaving it out would let the user believe every file of the folder was checked.
 *
 * @param {Array<{file: (File|Blob), path: string}>} files
 * @param {{limit?: number}} [opts]
 * @return {Promise<object[]>}
 */
export async function prepareItems( files, opts = {} ) {

	const limit = opts.limit || MAX_ITEMS;
	const out = [];

	for ( const { file, path } of files || [] ) {

		const raw = new Uint8Array( await file.arrayBuffer() );
		const name = String( file.name || path || '' );

		// ⚠️ unpack() returns the file itself when it is no container, so one loop serves both.
		const inner = await unpack( { name, data: raw } );

		for ( const part of inner ) {

			const inPath = part.from ? ( path || name ) + '/' + part.name : ( path || name );

			if ( SYSTEM_FILE.test( inPath ) ) continue;

			const base = { name: String( part.name || name ).split( '/' ).pop(), path: inPath };

			if ( out.length >= limit ) { out.push( { ...base, bytes: null, xml: '', pdf: false, kind: 'none', reason: 'too-many' } ); continue; }

			out.push( await classify( base, part.data ) );

		}

	}

	return out;

}

/**
 * @param {{name: string, path: string}} base
 * @param {Uint8Array} bytes
 * @return {Promise<object>}
 */
export async function classify( base, bytes ) {

	if ( isPdf( bytes ) ) {

		const xml = await extractPdfXmlPlain( bytes );

		return xml === ''
			? { ...base, bytes, xml: '', pdf: true, kind: 'none', reason: 'no-xml-in-pdf' }
			: { ...base, bytes, xml, pdf: true, kind: 'pdf', reason: '' };

	}

	// ⚠️ `ignoreBOM: true` keeps a byte order mark, so that VAMPIRE JS sees the same bytes as the file ( its checksum ).
	const xml = new TextDecoder( 'utf-8', { ignoreBOM: true } ).decode( bytes );

	return looksLikeInvoiceXml( xml )
		? { ...base, bytes, xml, pdf: false, kind: 'xml', reason: '' }
		: { ...base, bytes: null, xml: '', pdf: false, kind: 'none', reason: 'not-an-invoice' };

}
