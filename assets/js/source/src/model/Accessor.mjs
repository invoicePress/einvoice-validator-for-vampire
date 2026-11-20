// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Generic navigation over a TreeReader.read() output tree, driven by the catalog's own parent
 * chain (TreeReader.idChainTo()) instead of hand-written chains at every call site - eliminates the
 * transcription-error risk of manually re-deriving ~80 nested paths by hand.
 *
 * @since 3.2.0
 */

/**
 * The reads of the edit mode: for every catalog id the place it was last read from.
 *
 * ⚠️ NULL OUTSIDE THE EDIT MODE, AND THEN NOTHING IS RECORDED. The viewer builds its model with these functions on every
 * draw; a map filled on every read would cost time for a mode that is off.
 *
 * ⚠️ "LAST READ" IS THE RIGHT ANSWER ONLY WHERE THE MODEL READS A VALUE AND BUILDS ITS ROW RIGHT AFTER. That holds for
 * the field rows and the totals ( `f( label, VIn( … ), bt )` ). Where the model reads every occurrence of a group first
 * and builds the rows later ( the three tables, the payment means ), the last read is the last occurrence for every row;
 * those places hand their reference over themselves ( `ModelAssembler.mjs` ). See `docs/editor-entwurf.md`.
 *
 * @type {Map<string, {node: object|null, scope: object, scopeId: string|null, id: string}>|null}
 */
let reads = null;

/**
 * Switches the recording of the edit mode on ( a fresh map ) or off.
 *
 * @param {boolean} on
 */
export function recordReads( on ) {
	reads = on ? new Map() : null;
}

/**
 * Where an id was last read, or null: the node holding the value when there was one, else the scope the read started
 * from, so that `TreeEdit.mjs` can create the missing nodes below it.
 *
 * @param {string} id
 * @return {{node: object|null, scope: object, scopeId: string|null, id: string}|null}
 */
export function lastRead( id ) {
	return reads ? reads.get( id ) || null : null;
}

function note( id, node, scope, scopeId ) {
	if ( reads ) reads.set( id, { node: node && node.value !== undefined ? node : null, scope, scopeId, id } );
}


/**
 * Walks a chain of node ids from a starting object, taking the FIRST occurrence at every repeating
 * step along the way (only the walk's own final step is exposed separately, by V()/getAll() below,
 * so a caller can choose to enumerate all occurrences there instead).
 *
 * @param {object} start
 * @param {string[]} chain
 * @return {object|null} the node object ( `{value?, ...children}` ), or null when absent.
 */

function walk( start, chain ) {

	let current = start;

	for ( const step of chain ) {

		if ( current == null ) return null;

		let node = current[ step ];

		if ( node === undefined ) return null;

		current = Array.isArray( node ) ? node[ 0 ] : node;

	}

	return current;

}


/**
 * The scalar value of a catalog id, read from the tree root.
 *
 * @param {object} tree
 * @param {import('./TreeReader.mjs').TreeReader} reader
 * @param {string} id
 * @return {string|null}
 */

export function V( tree, reader, id ) {

	const chain = reader.idChainTo( id );

	if ( ! chain ) return null;

	const node = walk( tree, chain );

	note( id, node, tree, null );

	return node && node.value !== undefined ? node.value : null;

}


/**
 * The first value of a catalog id across ALL occurrences of every repeating step on its chain, read from
 * the tree root.
 *
 * ⚠️ WHY V() IS NOT ENOUGH HERE. V() takes the first occurrence at every step. In UBL
 * each seller identifier sits in a `cac:PartyIdentification` of its own ( BT-29-00, repeating ), and
 * the one without a scheme is BT-29 and the one with a scheme BT-29-0. A seller whose first
 * `cac:PartyIdentification` carries a GLN with scheme and whose second one a plain number has BT-29 only in
 * the SECOND occurrence; V() would look into the first, find nothing and show an empty row. In CII both
 * `ram:ID` and `ram:GlobalID` repeat directly under BG-4, so there the first occurrence is the same as with
 * V() and nothing changes.
 *
 * @param {object} tree
 * @param {import('./TreeReader.mjs').TreeReader} reader
 * @param {string} id
 * @return {string|null}
 */

export function firstValue( tree, reader, id ) {

	const chain = reader.idChainTo( id );

	if ( ! chain ) return null;

	const search = ( current, depth ) => {

		if ( current == null ) return null;

		if ( depth === chain.length ) {
			if ( current.value === undefined ) return null;
			// ⚠️ The node itself, so that the edit mode changes the occurrence that was shown, not the first one.
			note( id, current, tree, null );
			return current.value;
		}

		const node = current[ chain[ depth ] ];

		if ( node === undefined ) return null;

		for ( const occurrence of Array.isArray( node ) ? node : [ node ] ) {

			const found = search( occurrence, depth + 1 );

			if ( found !== null ) return found;

		}

		return null;

	};

	const found = search( tree, 0 );

	// A miss is noted as well, or the edit mode would find an older read of the same id from another place.
	if ( found === null ) note( id, null, tree, null );

	return found;

}


/**
 * All occurrences of a ( possibly repeating ) catalog id, read from the tree root - each entry is
 * the occurrence's own subtree object, usable as a scope for VIn()/allIn() below.
 *
 * @param {object} tree
 * @param {import('./TreeReader.mjs').TreeReader} reader
 * @param {string} id
 * @return {object[]}
 */

export function all( tree, reader, id ) {

	const chain = reader.idChainTo( id );

	if ( ! chain ) return [];

	const parent = chain.length > 1 ? walk( tree, chain.slice( 0, -1 ) ) : tree;

	if ( ! parent ) return [];

	const node = parent[ chain[ chain.length - 1 ] ];

	if ( node === undefined ) return [];

	return Array.isArray( node ) ? node : [ node ];

}


/**
 * One occurrence ( the first ) of a catalog id, read from the tree root - the subtree object itself,
 * for use as a scope with VIn()/allIn().
 *
 * @param {object} tree
 * @param {import('./TreeReader.mjs').TreeReader} reader
 * @param {string} id
 * @return {object|null}
 */

export function scope( tree, reader, id ) {

	const found = all( tree, reader, id );

	return found.length ? found[ 0 ] : null;

}


/**
 * The scalar value of a catalog id, read RELATIVE to an already-resolved scope ( e.g. one specific
 * BG-25 line-item occurrence ) instead of the tree root.
 *
 * @param {object} scopeNode
 * @param {import('./TreeReader.mjs').TreeReader} reader
 * @param {string} scopeId	The catalog id the scope itself corresponds to (e.g. 'BG-25').
 * @param {string} id		The target id, a descendant of scopeId.
 * @return {string|null}
 */

export function VIn( scopeNode, reader, scopeId, id ) {

	const remainder = chainFrom( reader, scopeId, id );

	if ( ! remainder ) return null;

	const node = walk( scopeNode, remainder );

	note( id, node, scopeNode, scopeId );

	return node && node.value !== undefined ? node.value : null;

}


/**
 * All occurrences of a catalog id, read relative to an already-resolved scope.
 *
 * @param {object} scopeNode
 * @param {import('./TreeReader.mjs').TreeReader} reader
 * @param {string} scopeId
 * @param {string} id
 * @return {object[]}
 */

export function allIn( scopeNode, reader, scopeId, id ) {

	const remainder = chainFrom( reader, scopeId, id );

	if ( ! remainder || ! remainder.length ) return [];

	const parent = remainder.length > 1 ? walk( scopeNode, remainder.slice( 0, -1 ) ) : scopeNode;

	if ( ! parent ) return [];

	const node = parent[ remainder[ remainder.length - 1 ] ];

	if ( node === undefined ) return [];

	return Array.isArray( node ) ? node : [ node ];

}


/**
 * One occurrence, relative to an already-resolved scope.
 *
 * @param {object} scopeNode
 * @param {import('./TreeReader.mjs').TreeReader} reader
 * @param {string} scopeId
 * @param {string} id
 * @return {object|null}
 */

export function scopeIn( scopeNode, reader, scopeId, id ) {

	const found = allIn( scopeNode, reader, scopeId, id );

	return found.length ? found[ 0 ] : null;

}


function chainFrom( reader, ancestorId, targetId ) {

	const full = reader.idChainTo( targetId );

	if ( ! full ) return null;

	const index = full.indexOf( ancestorId );

	return index === -1 ? full : full.slice( index + 1 );

}


/**
 * The reference of the edit mode for an id inside a known occurrence of a group, without reading a value: for the places
 * that read every occurrence first and build their rows later ( see `reads` above ).
 *
 * ⚠️ A NULL SCOPE IS AN OCCURRENCE THAT DOES NOT EXIST YET, such as the empty row a table shows for a group the invoice
 * lacks. `parent` then names where `TreeEdit.mjs` creates it: the occurrence of the enclosing group, or the root.
 *
 * ⚠️ AN ID WHOSE CHAIN DOES NOT PASS THE GROUP BELONGS TO THE DOCUMENT. In UBL BT-7 and BT-8 stand once for the whole
 * invoice although the view shows them in the first row of the VAT breakdown; their reference starts at the root.
 *
 * @param {object} tree
 * @param {object|null} scopeNode
 * @param {import('./TreeReader.mjs').TreeReader} reader
 * @param {string} scopeId
 * @param {string} id
 * @param {{node: object, id: string}|null} [parent]
 * @return {{node: object|null, scope: object|null, scopeId: string|null, id: string, parent?: object|null}|null}
 */
export function refIn( tree, scopeNode, reader, scopeId, id, parent = null ) {

	const full = reader.idChainTo( id );

	if ( ! full ) return null;

	const at = scopeId ? full.indexOf( scopeId ) : -1;

	const withValue = ( n ) => ( n && n.value !== undefined ? n : null );

	if ( at === -1 ) return { node: withValue( walk( tree, full ) ), scope: tree, scopeId: null, id };

	if ( ! scopeNode ) return { node: null, scope: null, scopeId, id, parent };

	return { node: withValue( walk( scopeNode, full.slice( at + 1 ) ) ), scope: scopeNode, scopeId, id };

}
