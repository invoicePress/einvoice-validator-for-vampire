// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Field hints computed from the invoice alone - mandatory fields group by group, the VAT category logic
 * and the date order. These hints are shown in the viewer like the totals hint
 * ( `TotalsCheck.mjs` ), and need no data package. The IBAN check digit belongs to the add-on Identifier
 * Check, and a code outside its reference list is marked by Technical Details, not here: the viewer checks
 * the logic of the invoice, the add-ons the identifiers and the codes.
 *
 * Line-for-line twin of libs/einvoice-model/php/InvoiceHints.php. Both sides run the same rules over
 * the same input in the same order: the finished model ( for the mandatory fields ) and a small set of
 * raw values ( the "facts", for everything else ). `test/compare-model-full.mjs` compares the hint texts
 * of both sides per business term, and `test/compare-render.mjs` the markup.
 *
 * ⚠️ **A HINT IS NOT A VALIDATION, AND THE PAGE SAYS SO.** The set is small on purpose: only rules that
 * follow from the document itself, without a code list, a national rule or a data package. The model gets
 * `hintNotice` as soon as one field carries a hint ( or a totals mismatch ), and the template shows it
 * above the invoice: the validators check all rules. A missing hint therefore says nothing about
 * validity.
 *
 * ⚠️ **THE MANDATORY FIELDS ARE READ FROM THE MODEL, NOT FROM THE XML, AND THAT IS WHAT MAKES THE PARITY
 * HOLD.** The model is the one structure both builders already produce identically ( 4 673 fields in
 * three languages, measured before this file existed ). A rule that looks at "is the row of BT-27 empty"
 * gives the same answer on both sides by construction, while a second XML reader in PHP would be
 * a second place to get a path wrong. The price is that a field the model never shows cannot be checked;
 * the excluded fields and the reason for each stand in `EXCLUDED_MANDATORY` below.
 *
 * ⚠️ **THE NODE CATALOGUES ARE NOT THE SOURCE OF THE MANDATORY TABLE, AND THEY CANNOT BE.** Measured on
 * 08.10.2026 against the semantic cardinalities in `libs/einvoice-reference-data/dist/fields/en16931.json`:
 * 53 business terms disagree. Every node of `ubl-nodes.json` says `0..1` or `0..n`, also for BT-1, BT-2,
 * BT-27 and BG-25, while BT-17 and BT-18 say `1..1` there. `cii-nodes.json` hangs BT-5, BG-22 and BG-23
 * below BG-19 ( direct debit ), so "required only if the parent group is present" read from that file
 * would never ask for the totals of an invoice without a direct debit. And `en16931.json` itself gives
 * BT-46-1 and BT-60-1 the cardinality `1..1` of the CII attribute, where EN 16931 says `0..1`. The table
 * below is therefore written out once, by hand, with the business rule of the CEN schematron for every
 * field, and `test/invoice-hints.test.mjs` holds it against `en16931.json`: every field in the table must
 * be `1..` there, and every `1..` field there must be in the table or in `EXCLUDED_MANDATORY`.
 *
 * @since the release after 0.19.1
 */

import { V, all, VIn } from './Accessor.mjs';
import { taxCategoryName } from './Codelists.mjs';
import { t, tf } from './Labels.mjs';


// ---- the mandatory table -----------------------------------------------------------------------------

/**
 * Fields the invoice must always carry, with the business rule that demands each of them.
 *
 * ⚠️ BG-2, BG-4, BG-5, BG-7, BG-8 and BG-22 are mandatory groups ( `1..1` ), so their mandatory fields
 * are mandatory for the document. That is why BT-40 and BT-55 stand here and not under a group.
 */
export const DOCUMENT_MANDATORY = Object.freeze( [
	[ 'BT-1', 'BR-02' ], [ 'BT-2', 'BR-03' ], [ 'BT-3', 'BR-04' ], [ 'BT-5', 'BR-05' ], [ 'BT-24', 'BR-01' ],
	[ 'BT-27', 'BR-06' ], [ 'BT-40', 'BR-09' ], [ 'BT-44', 'BR-07' ], [ 'BT-55', 'BR-11' ],
	[ 'BT-106', 'BR-12' ], [ 'BT-109', 'BR-13' ], [ 'BT-112', 'BR-14' ], [ 'BT-115', 'BR-15' ],
] );

/**
 * Optional document-level groups and what they demand once they are present.
 *
 * ⚠️ **"PRESENT" MEANS: AT LEAST ONE MEMBER ROW OF THE MODEL CARRIES A VALUE.** The schematron asks
 * whether the XML element exists; an element that exists but is empty therefore gets a finding from the
 * validator and no hint here. That direction is the safe one - a hint must never fire on an invoice the
 * validator accepts.
 *
 * ⚠️ **THE MEMBERS ARE THE BUSINESS TERMS OF THE GROUP, NOT THE ROWS OF THE CARD.** The delivery card
 * shows BT-72 ( delivery date ) under the subhead of BG-15, but BT-72 belongs to BG-13. Counting it as a
 * member of BG-15 would ask for a delivery country ( BT-80 ) on every invoice that names a delivery date.
 *
 * `when` instead of `group` means: the field itself is the condition ( an identifier and its scheme ).
 */
export const DOCUMENT_GROUPS = Object.freeze( [
	{ group: 'BG-3', members: [ 'BT-25', 'BT-26' ], required: [ [ 'BT-25', 'BR-55' ] ] },
	{ group: 'BG-10', members: [ 'BT-59', 'BT-60', 'BT-60-0', 'BT-60-1', 'BT-61' ], required: [ [ 'BT-59', 'BR-17' ] ] },
	{
		group: 'BG-11',
		members: [ 'BT-62', 'BT-63', 'BT-64', 'BT-65', 'BT-164', 'BT-66', 'BT-67', 'BT-68', 'BT-69' ],
		required: [ [ 'BT-62', 'BR-18' ], [ 'BT-63', 'BR-56' ], [ 'BT-69', 'BR-20' ] ],
	},
	{ group: 'BG-15', members: [ 'BT-75', 'BT-76', 'BT-165', 'BT-77', 'BT-78', 'BT-79', 'BT-80' ], required: [ [ 'BT-80', 'BR-57' ] ] },
	// ⚠️ EN 16931 has no business rule for BT-87; the cardinality 1..1 inside BG-18 is the source.
	{ group: 'BG-18', members: [ 'BT-87', 'BT-88' ], required: [ [ 'BT-87', '1..1' ] ] },
	{ when: 'BT-34', required: [ [ 'BT-34-1', 'BR-62' ] ] },
	{ when: 'BT-49', required: [ [ 'BT-49-1', 'BR-63' ] ] },
] );

/** What each invoice line ( BG-25 ) demands; BG-29, BG-30 and BG-31 are `1..1` inside the line. */
export const LINE_MANDATORY = Object.freeze( [
	[ 'BT-126', 'BR-21' ], [ 'BT-129', 'BR-22' ], [ 'BT-130', 'BR-23' ], [ 'BT-131', 'BR-24' ],
	[ 'BT-146', 'BR-26' ], [ 'BT-151', 'BR-CO-04' ], [ 'BT-153', 'BR-25' ],
] );

/** Identifiers inside a line whose scheme becomes mandatory once the identifier is there. */
export const LINE_WHEN = Object.freeze( [
	[ 'BT-157', 'BT-157-1', 'BR-64' ],
	[ 'BT-158', 'BT-158-1', 'BR-65' ],
] );

/**
 * The repeated groups that the model shows as table rows or cards of their own: mandatory fields, and
 * the "reason or reason code" pairs ( `anyOf`, shown at the first of the two ).
 */
export const ROW_GROUPS = Object.freeze( {
	'BG-20': { required: [ [ 'BT-92', 'BR-31' ], [ 'BT-95', 'BR-32' ] ], anyOf: [ [ 'BT-97', 'BT-98', 'BR-33' ] ] },
	'BG-21': { required: [ [ 'BT-99', 'BR-36' ], [ 'BT-102', 'BR-37' ] ], anyOf: [ [ 'BT-104', 'BT-105', 'BR-38' ] ] },
	'BG-23': { required: [ [ 'BT-116', 'BR-45' ], [ 'BT-117', 'BR-46' ], [ 'BT-118', 'BR-47' ] ], anyOf: [] },
	'BG-27': { required: [ [ 'BT-136', 'BR-41' ] ], anyOf: [ [ 'BT-139', 'BT-140', 'BR-42' ] ] },
	'BG-28': { required: [ [ 'BT-141', 'BR-43' ] ], anyOf: [ [ 'BT-144', 'BT-145', 'BR-44' ] ] },
	'BG-32': { required: [ [ 'BT-160', 'BR-54' ], [ 'BT-161', 'BR-54' ] ], anyOf: [] },
	// ⚠️ EN 16931 has no business rule for BT-22; the cardinality 1..1 inside BG-1 is the source.
	'BG-1': { required: [ [ 'BT-22', '1..1' ] ], anyOf: [] },
	'BG-24': { required: [ [ 'BT-122', 'BR-52' ] ], anyOf: [] },
} );

/**
 * Fields that `en16931.json` lists as `1..` and that are deliberately NOT in the tables above, with the
 * reason. `test/invoice-hints.test.mjs` fails when a `1..` field is neither here nor in a table.
 */
export const EXCLUDED_MANDATORY = Object.freeze( {
	'BT-46-1': 'en16931.json says 1..1, EN 16931 says 0..1; the 1..1 is the CII schema cardinality of @schemeID',
	'BT-60-1': 'en16931.json says 1..1, EN 16931 says 0..1; the 1..1 is the CII schema cardinality of @schemeID',
	'BT-84': 'checked from the facts: BR-50 and BR-61 only demand it for payment means code 30 or 58',
	'BT-81': 'checked from the facts: the model shows the code of the first payment means only ( BR-49 )',
	'BT-125-1': 'the model never shows it; both builders leave the row empty ( see attachmentCards() )',
	'BT-125-2': 'the model never shows it; both builders leave the row empty ( see attachmentCards() )',
} );


// ---- the texts ---------------------------------------------------------------------------------------

/*
 * ⚠️ **EVERY TEXT IS A LITERAL `t()` OR `tf()` CALL, AND IT HAS TO STAY ONE.** `_claude-tools/js-texts-to-php.php`
 * finds the texts of this bundle by exactly that pattern and writes them into the viewer's anchor file,
 * from where they reach the `.pot`. A text built from pieces or passed through a variable would be
 * shown in English on every page, without an error. The PHP twin lists the same English sentences in
 * `Context::translate()`.
 */

function missing( rule ) {
	return tf( 'Missing, although EN 16931 requires this field here (%s).', rule );
}

function missingReason( rule ) {
	return tf( 'Missing: EN 16931 requires a reason or a reason code here (%s).', rule );
}

/**
 * The sentence for one VAT category rule.
 *
 * @param {string} kind
 * @param {string} category The display name of the category, e.g. "AE - VAT Reverse Charge".
 * @param {string} rule
 * @return {string}
 */
function vatText( kind, category, rule ) {

	switch ( kind ) {
		case 'seller': return tf( 'Missing: with VAT category %1$s, EN 16931 requires the seller VAT identifier, the seller tax registration identifier or the tax representative VAT identifier (%2$s).', category, rule );
		case 'sellerStrict': return tf( 'Missing: with VAT category %1$s, EN 16931 requires the seller VAT identifier or the tax representative VAT identifier (%2$s).', category, rule );
		case 'buyer': return tf( 'Missing: with VAT category %1$s, EN 16931 requires the buyer VAT identifier or the buyer legal registration identifier (%2$s).', category, rule );
		case 'buyerStrict': return tf( 'Missing: with VAT category %1$s, EN 16931 requires the buyer VAT identifier (%2$s).', category, rule );
		case 'ratePositive': return tf( 'With VAT category %1$s the VAT rate must be greater than zero (%2$s).', category, rule );
		case 'rateZero': return tf( 'With VAT category %1$s the VAT rate must be 0 (%2$s).', category, rule );
		case 'taxZero': return tf( 'With VAT category %1$s the tax amount must be 0 (%2$s).', category, rule );
		case 'reasonRequired': return tf( 'With VAT category %1$s, EN 16931 requires an exemption reason or an exemption reason code (%2$s).', category, rule );
		case 'reasonForbidden': return tf( 'With VAT category %1$s there must be no exemption reason and no exemption reason code (%2$s).', category, rule );
	}

	return '';

}


// ---- the VAT categories ------------------------------------------------------------------------------

/**
 * What EN 16931 demands per VAT category, with the rule prefix of the CEN schematron.
 *
 * ⚠️ **ONLY THE SIX CATEGORIES WHOSE RULES FOLLOW FROM THE DOCUMENT ALONE.** "O" ( not subject to VAT )
 * forbids VAT identifiers, "L" and "M" ( Canary Islands, Ceuta and Melilla ) have their own rate rules;
 * those would need more judgement than a hint should make. The check stays small and exact.
 *
 * ⚠️ **K AND G ACCEPT FEWER SELLER IDENTIFIERS THAN S, Z, E AND AE.** BR-IC-02 and BR-G-02 name only the
 * seller VAT identifier ( BT-31 ) and the tax representative's ( BT-63 ); BR-S-02 and its siblings also
 * accept the seller tax registration identifier ( BT-32, schemeID "FC" ). Measured in the schematron
 * tests of `rule-catalog/en16931-cii/`, not assumed from the descriptions.
 *
 * `rate`: 'positive' ( BR-S-05 … 07 ) or 'zero' ( BR-x-05 … 07 ). `taxZero`: BR-x-09. `reason`:
 * 'required' ( BR-x-10 ) or 'forbidden' ( BR-S-10, BR-Z-10 ). `buyer`: '' or which rule for BT-48.
 */
const VAT_CATEGORIES = Object.freeze( {
	S: { prefix: 'BR-S', seller: 'seller', buyer: '', rate: 'positive', taxZero: false, reason: 'forbidden' },
	Z: { prefix: 'BR-Z', seller: 'seller', buyer: '', rate: 'zero', taxZero: true, reason: 'forbidden' },
	E: { prefix: 'BR-E', seller: 'seller', buyer: '', rate: 'zero', taxZero: true, reason: 'required' },
	AE: { prefix: 'BR-AE', seller: 'seller', buyer: 'buyer', rate: 'zero', taxZero: true, reason: 'required' },
	K: { prefix: 'BR-IC', seller: 'sellerStrict', buyer: 'buyerStrict', rate: 'zero', taxZero: true, reason: 'required' },
	G: { prefix: 'BR-G', seller: 'sellerStrict', buyer: '', rate: 'zero', taxZero: true, reason: 'required' },
} );

/** The order in which the categories are looked at - fixed, so both sides attach hints in one order. */
const CATEGORY_ORDER = [ 'S', 'Z', 'E', 'AE', 'K', 'G' ];


// ---- small helpers -----------------------------------------------------------------------------------

function s( value ) {
	return String( value ?? '' ).trim();
}

/**
 * A number, or null for "not there".
 *
 * ⚠️ `''` IS "ABSENT", NOT ZERO - the same rule as `num()` in TotalsCheck.mjs. A missing rate compared
 * with "must be 0" would otherwise pass.
 *
 * @param {*} value
 * @return {number|null}
 */
function num( value ) {
	const v = s( value );
	if ( v === '' ) return null;
	const n = Number( v );
	return Number.isFinite( n ) ? n : null;
}

/**
 * A date as `YYYYMMDD`, or '' when it is missing or in a format that cannot be compared.
 *
 * ⚠️ **ONLY FORMAT 102 AND THE ISO DATE OF UBL.** CII may also say 610 ( a month ) or 616 ( a week ).
 * Comparing "202603" with "20260315" as text would put a month before every day in it; such a date is
 * left out instead.
 *
 * @param {*} raw
 * @param {*} format The CII `@format`, null in UBL.
 * @return {string}
 */
export function hintDate( raw, format ) {

	const r = s( raw );
	const f = s( format );

	if ( r === '' || ( f !== '' && f !== '102' ) ) return '';

	if ( /^\d{8}$/.test( r ) ) return r;

	const m = r.match( /^(\d{4})-(\d{2})-(\d{2})$/ );

	return m ? m[ 1 ] + m[ 2 ] + m[ 3 ] : '';

}

function addHint( target, rule, text ) {

	if ( ! target || text === '' ) return;

	( target.hints ??= [] ).push( { rule, text } );

}


// ---- the facts ---------------------------------------------------------------------------------------

/**
 * The raw values the VAT, date and payment hints need, read from the catalogue tree.
 *
 * Twin of `InvoiceHints::facts_from_cii()` on the PHP side, which reads the same values with XPath from
 * the CII XML. The shape is the same on both sides; `test/invoice-hints.test.mjs` shows it.
 *
 * ⚠️ **A FIELD THE CATALOGUE DOES NOT KNOW IS READ AS ''.** `ubl-nodes.json` has no node for BT-63;
 * `V()` then returns null, and the tax representative counts as absent. For the seller
 * rules that can only add a hint, never remove one - and only on a UBL invoice with a tax representative
 * and without a seller VAT identifier, which the corpus does not contain.
 *
 * @param {object} tree
 * @param {object} reader
 * @return {object}
 */
export function factsFromTree( tree, reader ) {

	const v = ( id ) => s( V( tree, reader, id ) );
	const vin = ( scope, group, id ) => s( VIn( scope, reader, group, id ) );

	return {
		issueDate: hintDate( V( tree, reader, 'BT-2' ), V( tree, reader, 'BT-2-0' ) ),
		dueDate: hintDate( V( tree, reader, 'BT-9' ), V( tree, reader, 'BT-9-0' ) ),
		periodStart: hintDate( V( tree, reader, 'BT-73' ), V( tree, reader, 'BT-73-0' ) ),
		periodEnd: hintDate( V( tree, reader, 'BT-74' ), V( tree, reader, 'BT-74-0' ) ),
		sellerVat: v( 'BT-31' ),
		sellerTax: v( 'BT-32' ),
		taxRepVat: v( 'BT-63' ),
		buyerVat: v( 'BT-48' ),
		buyerLegal: v( 'BT-47' ),
		lines: all( tree, reader, 'BG-25' ).map( ( l ) => ( {
			start: hintDate( VIn( l, reader, 'BG-25', 'BT-134' ), VIn( l, reader, 'BG-25', 'BT-134-0' ) ),
			end: hintDate( VIn( l, reader, 'BG-25', 'BT-135' ), VIn( l, reader, 'BG-25', 'BT-135-0' ) ),
			category: vin( l, 'BG-25', 'BT-151' ).toUpperCase(),
			rate: vin( l, 'BG-25', 'BT-152' ),
		} ) ),
		allowances: all( tree, reader, 'BG-20' ).map( ( e ) => ( {
			category: vin( e, 'BG-20', 'BT-95' ).toUpperCase(),
			rate: vin( e, 'BG-20', 'BT-96' ),
		} ) ),
		charges: all( tree, reader, 'BG-21' ).map( ( e ) => ( {
			category: vin( e, 'BG-21', 'BT-102' ).toUpperCase(),
			rate: vin( e, 'BG-21', 'BT-103' ),
		} ) ),
		breakdown: all( tree, reader, 'BG-23' ).map( ( e ) => ( {
			category: vin( e, 'BG-23', 'BT-118' ).toUpperCase(),
			rate: vin( e, 'BG-23', 'BT-119' ),
			tax: vin( e, 'BG-23', 'BT-117' ),
			reason: vin( e, 'BG-23', 'BT-120' ),
			reasonCode: vin( e, 'BG-23', 'BT-121' ),
		} ) ),
		means: all( tree, reader, 'BG-16' ).map( ( m ) => ( {
			type: vin( m, 'BG-16', 'BT-81' ),
			iban: vin( m, 'BG-16', 'BT-84' ),
			proprietary: vin( m, 'BG-16', 'BT-84-0' ),
			name: vin( m, 'BG-16', 'BT-85' ),
			bic: vin( m, 'BG-16', 'BT-86' ),
		} ) ),
	};

}


// ---- the model, cut into the scopes the rules look at ---------------------------------------------------

/**
 * One group occurrence: the rows that belong to it, found by their business term.
 *
 * ⚠️ `get()` RETURNS THE FIRST ROW WITH THAT TERM. A card shows each field of its group once; where the
 * model repeats a term in one scope ( BT-84 in the credit transfer card, once per account ), the caller
 * uses `allOf()`.
 */
class Scope {

	constructor( rows ) {
		this.rows = rows;
	}

	get( bt ) {
		return this.rows.find( ( r ) => r && r.bt === bt ) || null;
	}

	allOf( bt ) {
		return this.rows.filter( ( r ) => r && r.bt === bt );
	}

	val( bt ) {
		const r = this.get( bt );
		return r ? s( r.value !== undefined ? r.value : r.v ) : '';
	}

	/** Is at least one of these terms filled? */
	any( list ) {
		return list.some( ( bt ) => this.val( bt ) !== '' );
	}

}

/**
 * Cuts the model into the scopes the rules look at.
 *
 * ⚠️ **THE TABLE ROWS ARE TOLD APART BY THE BUSINESS TERM OF THEIR FIRST CELL, NOT BY POSITION.** The
 * allowances and charges table lists BG-20 rows and then BG-21 rows, and an empty placeholder row stands
 * for a kind that has no occurrence. A placeholder row is no occurrence and gets no mandatory hint:
 * `rowIsBlank()` tells it apart exactly as the template does.
 *
 * @param {object} model
 * @return {object}
 */
function scopesOf( model ) {

	const out = { doc: [], notes: [], attachments: [], lines: [], allowanceRows: [], chargeRows: [], taxRows: [] };

	for ( const section of model.sections || [] ) {

		if ( section.layout === 'group' && section.bt === 'BG-25' ) {

			const line = { rows: [], acRows: [] };

			for ( const block of section.items || [] ) {
				if ( block.kind === 'card' ) line.rows.push( ...( block.rows || [] ).filter( ( r ) => r.type === 'field' ) );
				else if ( block.kind === 'table' ) line.acRows.push( ...( block.rows || [] ) );
			}

			out.lines.push( line );

			continue;

		}

		for ( const block of section.items || [] ) {

			if ( block.kind === 'card' ) {

				const fields = ( block.rows || [] ).filter( ( r ) => r.type === 'field' );

				if ( block.bt === 'BG-1' ) out.notes.push( new Scope( fields ) );
				else if ( block.bt === 'BG-24' ) out.attachments.push( new Scope( fields ) );
				else out.doc.push( ...fields );

			} else if ( block.kind === 'table' ) {

				if ( Array.isArray( block.bt ) && block.bt.indexOf( 'BG-20' ) !== -1 ) {

					for ( const row of block.rows || [] ) {
						if ( rowIsBlank( row ) ) continue;
						if ( row[ 0 ] && row[ 0 ].bt === 'BG-20' ) out.allowanceRows.push( row );
						if ( row[ 0 ] && row[ 0 ].bt === 'BG-21' ) out.chargeRows.push( row );
					}

				} else if ( block.bt === 'BG-23' ) {

					out.taxRows.push( ...( block.rows || [] ) );

				}

			} else if ( block.kind === 'totals' ) {

				out.doc.push( ...( block.rows || [] ) );

			}

		}

	}

	out.doc = new Scope( out.doc );

	return out;

}

/**
 * Does a table row carry no value? The first cell does not count: it holds the kind ( "Allowance",
 * "Charge" ), which the builder writes into the placeholder row too. Same rule as `rowIsEmpty()` in
 * QubaTemplate.mjs.
 *
 * @param {Array} row
 * @return {boolean}
 */
function rowIsBlank( row ) {

	for ( let i = 1; i < row.length; i++ )
		if ( s( row[ i ].v ) !== '' ) return false;

	return true;

}


// ---- the rules -----------------------------------------------------------------------------------------

/**
 * Applies the mandatory rules of one group occurrence.
 *
 * @param {Scope} scope
 * @param {Array} required [ [ bt, rule ], … ]
 * @param {Array} anyOf    [ [ bt, bt, rule ], … ]
 * @param {function(string): boolean} knows
 * @return {void}
 */
function requireIn( scope, required, anyOf, knows ) {

	for ( const [ bt, rule ] of required ) {

		const row = scope.get( bt );

		if ( row && knows( bt ) && scope.val( bt ) === '' ) addHint( row, rule, missing( rule ) );

	}

	for ( const [ a, b, rule ] of anyOf ) {

		const row = scope.get( a );

		if ( row && knows( a ) && knows( b ) && scope.val( a ) === '' && scope.val( b ) === '' ) addHint( row, rule, missingReason( rule ) );

	}

}

/** A table row as a scope: its cells, found by their business term. */
function rowScope( row ) {
	return new Scope( row );
}

/**
 * The rate rule of one line, allowance or charge ( BR-x-05, -06, -07 ).
 *
 * ⚠️ **A MISSING RATE BREAKS THE RULE.** The schematron tests `RateApplicablePercent > 0` and
 * `RateApplicablePercent = 0`; on an absent element both are false. So "no rate" with category Z is as
 * wrong as "19" with category Z.
 *
 * @param {object|null} target The row or cell that shows the rate.
 * @param {string} category
 * @param {string} rate
 * @param {string} suffix '05' ( line ), '06' ( allowance ), '07' ( charge )
 * @return {void}
 */
function rateRule( target, category, rate, suffix ) {

	const c = VAT_CATEGORIES[ category ];

	if ( ! c || ! target ) return;

	const r = num( rate );
	const ok = c.rate === 'positive' ? ( r !== null && r > 0 ) : ( r !== null && r === 0 );

	if ( ! ok ) addHint( target, c.prefix + '-' + suffix, vatText( c.rate === 'positive' ? 'ratePositive' : 'rateZero', taxCategoryName( category ), c.prefix + '-' + suffix ) );

}

/**
 * Adds the hints to the model and returns it.
 *
 * ⚠️ **THE ORDER OF THE STEPS IS THE ORDER OF THE PHP TWIN, AND IT IS NOT FREE.** Two hints on the same
 * field appear in the order they were added; the parity dump compares the lists as they are.
 *
 * @param {object} model The model from `build()`, before any add-on extended it.
 * @param {object} facts From `factsFromTree()`.
 * @param {{knows?: function(string): boolean, extension?: boolean}} [options] `knows( bt )`: does the
 *        reader know the field at all? A field the catalogue does not know shows an empty row on every
 *        invoice and must not be reported as missing. `extension`: BT-24
 *        carries `#conformant#`, see step 3.
 * @return {object} The same model.
 */
export function applyHints( model, facts, options = {} ) {

	const knows = typeof options.knows === 'function' ? options.knows : () => true;
	const extension = options.extension === true;
	const sc = scopesOf( model );
	const doc = sc.doc;

	// 1. Mandatory fields of the document and of the optional document-level groups.
	requireIn( doc, DOCUMENT_MANDATORY, [], knows );

	for ( const g of DOCUMENT_GROUPS ) {
		const present = g.when ? doc.val( g.when ) !== '' : doc.any( g.members );
		if ( present ) requireIn( doc, g.required, [], knows );
	}

	// 2. Notes.
	for ( const note of sc.notes ) requireIn( note, ROW_GROUPS[ 'BG-1' ].required, [], knows );

	// 3. Lines: mandatory fields, identifier schemes, item attributes, line allowances and charges, the
	//    rate rule and the line period.
	sc.lines.forEach( ( line, i ) => {

		const scope = new Scope( line.rows );

		/*
		 * ⚠️ **AN EXTENSION SKIPS THE MANDATORY FIELDS OF THE LINE.** ZUGFeRD / Factur-X EXTENDED and the
		 * XRechnung extension ( `#conformant#` in BT-24 ) add sub-lines that are not EN 16931 lines and
		 * carry no VAT category of their own. Measured on 08.10.2026: without this switch the official
		 * sample `X18_01_SubInvoiceLines_Kaffee_Bundle_Set_Bsp4___.xml` got "BT-151 missing" twice. It is
		 * the same switch, for the same reason, as the one in TotalsCheck.mjs.
		 */
		if ( ! extension ) requireIn( scope, LINE_MANDATORY, [], knows );

		for ( const [ when, bt, rule ] of LINE_WHEN )
			if ( scope.val( when ) !== '' ) requireIn( scope, [ [ bt, rule ] ], [], knows );

		// ⚠️ The attributes come in pairs, BT-160 then BT-161, one pair per BG-32.
		for ( let k = 0; k < line.rows.length; k++ ) {
			if ( line.rows[ k ].bt !== 'BT-160' ) continue;
			const pair = new Scope( [ line.rows[ k ], line.rows[ k + 1 ] && line.rows[ k + 1 ].bt === 'BT-161' ? line.rows[ k + 1 ] : null ].filter( Boolean ) );
			if ( pair.any( [ 'BT-160', 'BT-161' ] ) ) requireIn( pair, ROW_GROUPS[ 'BG-32' ].required, [], knows );
		}

		for ( const row of line.acRows ) {
			if ( rowIsBlank( row ) || ! row[ 0 ] ) continue;
			const g = ROW_GROUPS[ row[ 0 ].bt ];
			if ( g ) requireIn( rowScope( row ), g.required, g.anyOf, knows );
		}

		const f = ( facts.lines || [] )[ i ];

		if ( f ) {
			rateRule( scope.get( 'BT-152' ), f.category, f.rate, '05' );
			if ( f.start !== '' && f.end !== '' && f.end < f.start ) addHint( scope.get( 'BT-135' ), 'BR-30', tf( 'The end date is before the start date (%s).', 'BR-30' ) );
		}

	} );

	// 4. Document allowances and charges: mandatory fields and the rate rule.
	sc.allowanceRows.forEach( ( row, k ) => {
		const scope = rowScope( row );
		requireIn( scope, ROW_GROUPS[ 'BG-20' ].required, ROW_GROUPS[ 'BG-20' ].anyOf, knows );
		const f = ( facts.allowances || [] )[ k ];
		if ( f ) rateRule( scope.get( 'BT-96' ), f.category, f.rate, '06' );
	} );

	sc.chargeRows.forEach( ( row, k ) => {
		const scope = rowScope( row );
		requireIn( scope, ROW_GROUPS[ 'BG-21' ].required, ROW_GROUPS[ 'BG-21' ].anyOf, knows );
		const f = ( facts.charges || [] )[ k ];
		if ( f ) rateRule( scope.get( 'BT-103' ), f.category, f.rate, '07' );
	} );

	// 5. VAT breakdown: mandatory fields, BR-48, the tax amount and the exemption reason per category.
	sc.taxRows.forEach( ( row, k ) => {

		const scope = rowScope( row );

		requireIn( scope, ROW_GROUPS[ 'BG-23' ].required, [], knows );

		const f = ( facts.breakdown || [] )[ k ];

		if ( ! f ) return;

		// ⚠️ BR-48: the rate may only be missing when the category is "O" ( not subject to VAT ).
		if ( f.rate === '' && f.category !== 'O' && f.category !== '' ) addHint( scope.get( 'BT-119' ), 'BR-48', missing( 'BR-48' ) );

		const c = VAT_CATEGORIES[ f.category ];

		if ( ! c ) return;

		const name = taxCategoryName( f.category );
		const tax = num( f.tax );

		if ( c.taxZero && tax !== null && tax !== 0 ) addHint( scope.get( 'BT-117' ), c.prefix + '-09', vatText( 'taxZero', name, c.prefix + '-09' ) );

		const hasReason = f.reason !== '' || f.reasonCode !== '';

		if ( c.reason === 'required' && ! hasReason ) addHint( scope.get( 'BT-120' ), c.prefix + '-10', vatText( 'reasonRequired', name, c.prefix + '-10' ) );

		if ( c.reason === 'forbidden' && hasReason ) addHint( scope.get( f.reason !== '' ? 'BT-120' : 'BT-121' ), c.prefix + '-10', vatText( 'reasonForbidden', name, c.prefix + '-10' ) );

	} );

	// 6. Supporting documents.
	for ( const att of sc.attachments ) requireIn( att, ROW_GROUPS[ 'BG-24' ].required, [], knows );

	// 7. The VAT identifiers each category in use demands ( BR-x-02 … 04 ).
	vatParties( doc, facts );

	// 8. Dates.
	if ( facts.issueDate && facts.dueDate && facts.dueDate < facts.issueDate )
		addHint( doc.get( 'BT-9' ), '', t( 'The payment due date is before the invoice date.' ) );

	if ( facts.periodStart && facts.periodEnd && facts.periodEnd < facts.periodStart )
		addHint( doc.get( 'BT-74' ), 'BR-29', tf( 'The end date is before the start date (%s).', 'BR-29' ) );

	// 9. Payment means: BR-49 and BR-61. ⚠️ The IBAN check digit is not checked here: it is an identifier,
	// and identifiers are checked by the add-on Identifier Check.
	payments( doc, facts.means || [] );

	// ⚠️ The sentence names all four checks that can trigger it - the three groups above and the totals
	// mismatch from TotalsCheck, which `hasAnyHint()` counts as well. A new check belongs in the sentence,
	// in the PHP twin and in every .po, or the notice claims less than the page marks.
	if ( hasAnyHint( model ) ) model.hintNotice = t( 'Note: this is not a full validation. The viewer only checks mandatory fields, the VAT category rules, the order of the dates and whether the totals add up. A validator checks all rules.' );

	return model;

}

/**
 * The seller and buyer identifiers the VAT categories in use demand.
 *
 * ⚠️ **ONE HINT PER FIELD, NOT ONE PER CATEGORY.** An invoice with S and Z lines and no seller VAT
 * identifier breaks BR-S-02 and BR-Z-02 at once; two hints at BT-31 saying the same would only be
 * noise. The first category in `CATEGORY_ORDER` names the rule. The stricter set of K and G is looked at
 * separately, because BT-32 does not satisfy it.
 *
 * ⚠️ **THE RULE NUMBER FOLLOWS THE PART THAT CARRIES THE CATEGORY:** -02 for a line, -03 for a document
 * allowance, -04 for a document charge, looked at in that order.
 *
 * @param {Scope} doc
 * @param {object} facts
 * @return {void}
 */
function vatParties( doc, facts ) {

	const where = {};

	for ( const [ list, suffix ] of [ [ facts.lines || [], '02' ], [ facts.allowances || [], '03' ], [ facts.charges || [], '04' ] ] )
		for ( const e of list )
			if ( VAT_CATEGORIES[ e.category ] && where[ e.category ] === undefined ) where[ e.category ] = suffix;

	const used = CATEGORY_ORDER.filter( ( c ) => where[ c ] !== undefined );

	const sellerAny = facts.sellerVat !== '' || facts.sellerTax !== '' || facts.taxRepVat !== '';
	const sellerStrict = facts.sellerVat !== '' || facts.taxRepVat !== '';

	const loose = used.find( ( c ) => VAT_CATEGORIES[ c ].seller === 'seller' );
	const strict = used.find( ( c ) => VAT_CATEGORIES[ c ].seller === 'sellerStrict' );

	if ( loose && ! sellerAny ) {
		const rule = VAT_CATEGORIES[ loose ].prefix + '-' + where[ loose ];
		addHint( doc.get( 'BT-31' ), rule, vatText( 'seller', taxCategoryName( loose ), rule ) );
	} else if ( strict && ! sellerStrict ) {
		const rule = VAT_CATEGORIES[ strict ].prefix + '-' + where[ strict ];
		addHint( doc.get( 'BT-31' ), rule, vatText( 'sellerStrict', taxCategoryName( strict ), rule ) );
	}

	if ( where.AE !== undefined && facts.buyerVat === '' && facts.buyerLegal === '' ) {
		const rule = 'BR-AE-' + where.AE;
		addHint( doc.get( 'BT-48' ), rule, vatText( 'buyer', taxCategoryName( 'AE' ), rule ) );
	} else if ( where.K !== undefined && facts.buyerVat === '' ) {
		const rule = 'BR-IC-' + where.K;
		addHint( doc.get( 'BT-48' ), rule, vatText( 'buyerStrict', taxCategoryName( 'K' ), rule ) );
	}

}

/**
 * The payment means hints.
 *
 * ⚠️ **THE N-TH ACCOUNT ROW OF THE MODEL IS THE N-TH PAYMENT MEANS WITH AN IBAN, AN ACCOUNT NAME OR A
 * BIC** - the filter `transferCard()` and `transfer_card()` apply. Changing that filter in a builder
 * without changing it here would put the hint at another account.
 *
 * ⚠️ **BR-61 ONLY WHERE THE ACCOUNT ELEMENT EXISTS.** The schematron's context is
 * `PayeePartyCreditorFinancialAccount` of a means with code 30 or 58. A means 58 with no account at all
 * passes EN 16931 ( XRechnung adds its own rule for that ). An account element is taken as present when
 * it carries an identifier or an account name; the BIC ( BT-86 ) sits in a different element.
 *
 * @param {Scope} doc
 * @param {Array<object>} means
 * @return {void}
 */
function payments( doc, means ) {

	if ( means.some( ( m ) => m.type === '' ) ) addHint( doc.get( 'BT-81' ), 'BR-49', missing( 'BR-49' ) );

	const accounts = doc.allOf( 'BT-84' );
	const shown = means.filter( ( m ) => m.iban !== '' || m.name !== '' || m.bic !== '' );

	shown.forEach( ( m, k ) => {

		const row = accounts[ k ];

		if ( ! row ) return;

		const hasId = m.iban !== '' || m.proprietary !== '';
		const hasAccount = hasId || m.name !== '';

		if ( ( m.type === '30' || m.type === '58' ) && hasAccount && ! hasId ) addHint( row, 'BR-61', missing( 'BR-61' ) );

	} );

}

/**
 * Does any row, cell or totals line carry a hint or a totals mismatch?
 *
 * @param {object} model
 * @return {boolean}
 */
function hasAnyHint( model ) {

	const marked = ( r ) => r && ( ( r.hints && r.hints.length ) || ( r.mismatch && r.mismatch.hint ) );

	for ( const section of model.sections || [] )
		for ( const block of section.items || [] )
			for ( const row of block.rows || [] ) {
				if ( Array.isArray( row ) ) { if ( row.some( marked ) ) return true; }
				else if ( marked( row ) ) return true;
			}

	return false;

}
