// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Writes src/model/en16931-structure.json: for every business term of EN 16931, per version of the
 * standard, whether it exists, which group it belongs to and its cardinality. Next to it,
 * src/model/en16931-versions.json with the versions and their BT-24 prefixes.
 *
 *   node tools/build-en16931-structure.mjs           checks and prints, writes nothing
 *   node tools/build-en16931-structure.mjs --write   writes the file
 *
 * WHY A FILE OF ITS OWN AND NOT TWO MORE COLUMNS IN THE NODE CATALOGUES. `cii-nodes.json` and
 * `ubl-nodes.json` describe the syntax: one business term has several nodes there ( an anchor `-00`,
 * a scheme `-1`, two trees in UBL for Invoice and CreditNote ), and their `card` column is not the
 * semantic cardinality ( every UBL node says `0..1` or `0..n`, see InvoiceHints.mjs ). Presence, place
 * in the tree and cardinality are properties of the business term in the semantic model, so they are
 * kept once per term here. The syntax catalogues only say, per node, in which versions that node
 * exists ( the optional key `norm`, see TreeReader.mjs ).
 *
 * WHERE THE VALUES COME FROM.
 *
 * - The cardinality is the column `en16931SemanticCardinality` of
 *   `libs/einvoice-reference-data/dist/fields/en16931.json`. Measured on 09.10.2026: for the 196 terms
 *   it is identical with `_einvoice-shared-data/factur-x_en16931_with_rules.json`.
 * - The parent group is written out below in PARENT_2017, in the order of EN 16931-1:2017 table 2. No
 *   file in this project carries it. ⚠️ It is checked, not trusted: the tool stops unless ( a ) the
 *   table holds exactly the 164 BT and 32 BG ids of the reference data, and ( b ) every term's CII path
 *   in the reference data lies below its parent's CII path, except the five places listed in
 *   CII_BINDING_EXCEPTIONS where the CII binding itself puts a term elsewhere. Both held on 09.10.2026.
 *
 * ⚠️ HOW TO ADD EN 16931-1:2026. Add a version entry to VERSIONS with its
 * specification identifier prefix, add a PARENT_2026 table and a cardinality source, and let this tool
 * write a "2026" entry next to every "2017" entry. A term that does not exist in 2026 simply gets no
 * "2026" entry. Examples from the XRechnung 4.0 preview: BT-20 moves into the new group BG-33, BG-26
 * into the new group BG-37, BT-10 and BT-46 become repeatable. No code in src/ changes for that; syntax
 * nodes that exist only in one version get `"norm": [ "2026" ]` in the catalogue.
 */

import { readFileSync, writeFileSync } from 'node:fs';

const here = new URL( '.', import.meta.url );
const OUT = new URL( '../src/model/en16931-structure.json', here );
const OUT_VERSIONS = new URL( '../src/model/en16931-versions.json', here );
const REFERENCE = new URL( '../../einvoice-reference-data/dist/fields/en16931.json', here );

/**
 * The versions of the standard. `specification` holds the prefixes of BT-24 that select the version;
 * the entry with `default: true` applies to every document whose BT-24 matches none ( Factur-X
 * MINIMUM, ZATCA, OIOUBL ... ).
 */
const VERSIONS = {
	'2017': { standard: 'EN 16931-1:2017', specification: [ 'urn:cen.eu:en16931:2017' ], default: true },
};

/** EN 16931-1:2017, table 2: the members of each group, '' being the invoice itself. */
const PARENT_2017 = {
	'': [ 'BT-1', 'BT-2', 'BT-3', 'BT-5', 'BT-6', 'BT-7', 'BT-8', 'BT-9', 'BT-10', 'BT-11', 'BT-12', 'BT-13', 'BT-14', 'BT-15', 'BT-16',
		'BT-17', 'BT-18', 'BT-19', 'BT-20', 'BG-1', 'BG-2', 'BG-3', 'BG-4', 'BG-7', 'BG-10', 'BG-11', 'BG-13', 'BG-16', 'BG-20', 'BG-21',
		'BG-22', 'BG-23', 'BG-24', 'BG-25' ],
	'BG-1': [ 'BT-21', 'BT-22' ],
	'BG-2': [ 'BT-23', 'BT-24' ],
	'BG-3': [ 'BT-25', 'BT-26' ],
	'BG-4': [ 'BT-27', 'BT-28', 'BT-29', 'BT-30', 'BT-31', 'BT-32', 'BT-33', 'BT-34', 'BG-5', 'BG-6' ],
	'BG-5': [ 'BT-35', 'BT-36', 'BT-162', 'BT-37', 'BT-38', 'BT-39', 'BT-40' ],
	'BG-6': [ 'BT-41', 'BT-42', 'BT-43' ],
	'BG-7': [ 'BT-44', 'BT-45', 'BT-46', 'BT-47', 'BT-48', 'BT-49', 'BG-8', 'BG-9' ],
	'BG-8': [ 'BT-50', 'BT-51', 'BT-163', 'BT-52', 'BT-53', 'BT-54', 'BT-55' ],
	'BG-9': [ 'BT-56', 'BT-57', 'BT-58' ],
	'BG-10': [ 'BT-59', 'BT-60', 'BT-61' ],
	'BG-11': [ 'BT-62', 'BT-63', 'BG-12' ],
	'BG-12': [ 'BT-64', 'BT-65', 'BT-164', 'BT-66', 'BT-67', 'BT-68', 'BT-69' ],
	'BG-13': [ 'BT-70', 'BT-71', 'BT-72', 'BG-14', 'BG-15' ],
	'BG-14': [ 'BT-73', 'BT-74' ],
	'BG-15': [ 'BT-75', 'BT-76', 'BT-165', 'BT-77', 'BT-78', 'BT-79', 'BT-80' ],
	'BG-16': [ 'BT-81', 'BT-82', 'BT-83', 'BG-17', 'BG-18', 'BG-19' ],
	'BG-17': [ 'BT-84', 'BT-85', 'BT-86' ],
	'BG-18': [ 'BT-87', 'BT-88' ],
	'BG-19': [ 'BT-89', 'BT-90', 'BT-91' ],
	'BG-20': [ 'BT-92', 'BT-93', 'BT-94', 'BT-95', 'BT-96', 'BT-97', 'BT-98' ],
	'BG-21': [ 'BT-99', 'BT-100', 'BT-101', 'BT-102', 'BT-103', 'BT-104', 'BT-105' ],
	'BG-22': [ 'BT-106', 'BT-107', 'BT-108', 'BT-109', 'BT-110', 'BT-111', 'BT-112', 'BT-113', 'BT-114', 'BT-115' ],
	'BG-23': [ 'BT-116', 'BT-117', 'BT-118', 'BT-119', 'BT-120', 'BT-121' ],
	'BG-24': [ 'BT-122', 'BT-123', 'BT-124', 'BT-125' ],
	'BG-25': [ 'BT-126', 'BT-127', 'BT-128', 'BT-129', 'BT-130', 'BT-131', 'BT-132', 'BT-133', 'BG-26', 'BG-27', 'BG-28', 'BG-29', 'BG-30', 'BG-31' ],
	'BG-26': [ 'BT-134', 'BT-135' ],
	'BG-27': [ 'BT-136', 'BT-137', 'BT-138', 'BT-139', 'BT-140' ],
	'BG-28': [ 'BT-141', 'BT-142', 'BT-143', 'BT-144', 'BT-145' ],
	'BG-29': [ 'BT-146', 'BT-147', 'BT-148', 'BT-149', 'BT-150' ],
	'BG-30': [ 'BT-151', 'BT-152' ],
	'BG-31': [ 'BT-153', 'BT-154', 'BT-155', 'BT-156', 'BT-157', 'BT-158', 'BT-159', 'BG-32' ],
	'BG-32': [ 'BT-160', 'BT-161' ],
};

/**
 * ⚠️ The five places where the CII binding puts a term outside its semantic parent's element. They are
 * properties of the binding, not mistakes of PARENT_2017, and the catalogue follows the binding:
 * BG-13's delivery date and BG-14's invoicing period live in the delivery and settlement blocks, not
 * under the ship-to party; BT-83 and BG-19 sit on the settlement block next to the payment means;
 * BT-86 ( BIC ) sits on the payment means, not on the account ( BG-17 ).
 */
const CII_BINDING_EXCEPTIONS = new Set( [ 'BT-72', 'BG-14', 'BT-83', 'BG-19', 'BT-86' ] );

const reference = new Map( JSON.parse( readFileSync( REFERENCE, 'utf8' ) )
	.filter( ( x ) => /^B[TG]-\d+$/.test( x.id ) )
	.map( ( x ) => [ x.id, x ] ) );

const parentOf = new Map();
const order = [];

for ( const [ group, members ] of Object.entries( PARENT_2017 ) )
	for ( const id of members ) {

		if ( parentOf.has( id ) )
			throw new Error( id + ' stands twice in PARENT_2017' );

		parentOf.set( id, group === '' ? null : group );

	}

// Semantic order: a depth-first walk from the invoice, so the file reads like table 2.
( function walk( group ) {

	for ( const id of PARENT_2017[ group ] || [] ) {

		order.push( id );
		walk( id );

	}

} )( '' );

const problems = [];

for ( const id of reference.keys() )
	if ( ! parentOf.has( id ) )
		problems.push( id + ' is in the reference data but not in PARENT_2017' );

for ( const id of parentOf.keys() )
	if ( ! reference.has( id ) )
		problems.push( id + ' is in PARENT_2017 but not in the reference data' );

const ciiPath = ( id ) => ( reference.get( id )?.xpathUnCefact22B?.xpath || '' ).replace( /\[.*?\]/g, '' );
let below = 0;

for ( const [ id, parent ] of parentOf ) {

	if ( parent === null )
		continue;

	const inside = ciiPath( id ).startsWith( ciiPath( parent ) + '/' );

	if ( inside )
		below++;

	if ( inside === CII_BINDING_EXCEPTIONS.has( id ) )
		problems.push( id + ( inside ? ' is listed as a CII binding exception but lies below ' : ' does not lie below its parent ' ) + parent + ' in CII' );

}

const bt = order.filter( ( id ) => id.startsWith( 'BT-' ) ).length;
const bg = order.filter( ( id ) => id.startsWith( 'BG-' ) ).length;

console.log( `PARENT_2017: ${ bt } BT, ${ bg } BG; ${ below } of ${ parentOf.size - PARENT_2017[ '' ].length } members lie below their parent in CII, ${ CII_BINDING_EXCEPTIONS.size } binding exceptions` );

if ( bt !== 164 || bg !== 32 )
	problems.push( `expected 164 BT and 32 BG, found ${ bt } and ${ bg }` );

if ( problems.length ) {

	console.error( problems.join( '\n' ) );
	process.exit( 1 );

}

const terms = {};

for ( const id of order ) {

	const card = reference.get( id ).en16931SemanticCardinality;

	if ( ! /^[01]\.\.[1n]$/.test( card || '' ) )
		throw new Error( id + ': unexpected cardinality ' + card );

	terms[ id ] = { '2017': { parent: parentOf.get( id ), card } };

}

/*
 * ⚠️ TWO FILES, BECAUSE THE VIEWER NEEDS ONLY THE SMALL ONE. TreeReader.mjs asks which version a document
 * follows ( NormVersion.mjs, en16931-versions.json, a few hundred bytes ). The terms ( about 10 KB ) are read
 * by NormStructure.mjs, which the tests and future consumers import. With one file, every viewer bundle
 * would carry the terms without using them: measured 09.10.2026, the viewer bundle grew from 77 287 to
 * 87 605 bytes with one file and to 78 592 bytes with two ( built in memory, not into dist/ ). esbuild cannot drop unused keys of a JSON default import, and Node allows no named import
 * from JSON, so splitting the module is the only way.
 */
const files = [
	[ OUT_VERSIONS, {
		_comment: [
			'Generated by tools/build-en16931-structure.mjs - edit the tool, not this file.',
			'The versions of EN 16931: the BT-24 prefixes that select each one; the default applies when no prefix matches.',
		],
		versions: VERSIONS,
	} ],
	[ OUT, {
		_comment: [
			'Generated by tools/build-en16931-structure.mjs - edit the tool, not this file.',
			'Per business term of EN 16931 and per version of the standard: parent group ( null = the invoice ) and semantic cardinality.',
			'A term without an entry for a version does not exist in that version.',
		],
		terms,
	} ],
];

for ( const [ url, content ] of files ) {

	const json = JSON.stringify( content, null, '\t' ) + '\n';
	const name = url.pathname.replace( /^.*\//, '' );

	if ( process.argv.includes( '--write' ) ) {

		writeFileSync( url, json );
		console.log( 'written ' + name + ' ( ' + Buffer.byteLength( json ) + ' bytes )' );

	} else {

		let current = '';

		try { current = readFileSync( url, 'utf8' ); } catch { /* not written yet */ }

		console.log( current === json ? name + ' is up to date' : name + ' differs - run with --write' );

	}

}
