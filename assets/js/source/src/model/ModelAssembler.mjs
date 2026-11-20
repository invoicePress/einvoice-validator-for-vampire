// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Assembles the neutral invoice model (hero/sections/cards/rows, matching
 * libs/einvoice-model/schema/einvoice-model.schema.json) from a TreeReader.read() output tree - the
 * JS side of the model layer, sitting on top of TreeReader.
 *
 * Line-for-line port of einvoice-viewer-for-peppol-en16931/includes/core/ModelBuilder.php's own
 * build() - hero, invoice_data_card, party_card (seller+buyer), references_card, delivery_card,
 * payee_card, tax_representative_card, payment_means/payment_terms -> payment_data_card/transfer_card/
 * debit_card/card_info_card, note_cards, position_group (+line_allowance_charge_table), allowance_charge_table,
 * tax_table, totals_block, attachment_cards.
 *
 * Works UNCHANGED against either a CII tree or a UBL tree, because both catalogs use the SAME BT/BG
 * ids (see tools/build-ubl-nodes.mjs) - this file never branches on syntax.
 *
 * @since 3.2.0
 */

import { V, all, VIn, allIn, firstValue, recordReads, lastRead, refIn } from './Accessor.mjs';
import * as CL from './Codelists.mjs';
// ⚠️ `isZero()` is not needed here: the totals rows are all listed, including those with the
// value zero. `Format.mjs` keeps the function, because the PHP side has its counterpart.
import { formatDate, formatNum, formatAmount, formatUnitPrice, setFormatLanguage } from './Format.mjs';
import { setLanguage, t, tf } from './Labels.mjs';
import { TotalsCheck } from './TotalsCheck.mjs';
import { applyHints, factsFromTree } from './InvoiceHints.mjs';


// ---- row/card builders (ModelBuilder.php's f()/sub()/card()) ------------------------------------

/**
 * ⚠️ **TRANSLATION HAPPENS HERE, AND ONLY HERE.** Each of the roughly 140 labels of this file goes through
 * `f()`, `sub()` or `card()` - so these three places are enough, instead of wrapping every call.
 * The alternative ( `f( t( 'Account holder' ), … )` at every call ) would be the same effect with
 * 140 chances to forget a place, and nobody would see it: a forgotten wrapper delivers
 * an English text, not an error.
 *
 * ⚠️ The source text stays the literal in the code. `t()` looks it up and returns it
 * unchanged when there is no translation - which is why the English case is free and
 * needs no table.
 */

function f( label, value, bt, href = '' ) {
	return attach( { type: 'field', label: t( label ), value: String( value ?? '' ), bt: bt || '', href }, bt );
}


// ---- the edit mode ( docs/editor-entwurf.md ) -----------------------------------------------------------

/**
 * Set by `build( …, { edit: true } )` for the time of that build, else null.
 *
 * ⚠️ **WITHOUT THE SWITCH THE MODEL IS UNCHANGED, BYTE FOR BYTE.** `test/compare-model.mjs` holds it against the PHP
 * twin `ModelBuilder.php`. The references exist only with the switch, and they are not enumerable: `JSON.stringify()`
 * of an edit model gives the same text as without it, and no tree is copied into a serialised model.
 *
 * @type {{tree: object, reader: object, scope: {node: object|null, id: string, parent?: object|null}|null}|null}
 */
let editing = null;

/**
 * Gives a row or a table cell its reference: where in the tree its business term stands, or would stand.
 *
 * ⚠️ `lastRead()` UNLESS A SCOPE IS SET. A field row is built right after its value was read, so the last read is its
 * place. Where the rows come from occurrences read earlier ( payment means, the tables ), `inScope()` names the
 * occurrence instead.
 */
function attach( row, bt ) {
	if ( ! editing || typeof bt !== 'string' || ! /^BT-/.test( bt ) ) return row;
	const ref = editing.scope
		? refIn( editing.tree, editing.scope.node, editing.reader, editing.scope.id, bt, editing.scope.parent || null )
		: lastRead( bt );
	if ( ! ref ) return row;
	// ⚠️ The cells of one empty row share this slot, so that they fill one new occurrence ( TreeEdit.mjs ).
	if ( editing.scope ) ref.slot = editing.scope;
	Object.defineProperty( row, 'ref', { value: ref, enumerable: false, configurable: true } );
	return row;
}

/** Runs `fn` with the rows it builds tied to one occurrence of a group; outside the edit mode only runs it. */
function inScope( node, id, fn, parent = null ) {
	if ( ! editing ) return fn();
	const before = editing.scope;
	editing.scope = { node: node || null, id, parent };
	try {
		return fn();
	} finally {
		editing.scope = before;
	}
}

/** The cells of one table row, tied to their occurrence. */
function attachCells( cells, node, id, parent = null ) {
	if ( ! editing ) return cells;
	return inScope( node, id, () => cells.map( ( c ) => attach( c, c.bt ) ), parent );
}

function sub( label, bt = '' ) {
	return { type: 'subhead', label: t( label ), bt };
}

function card( title, rows, bt = '', span = 1 ) {
	return { kind: 'card', title: t( title ), bt: bt || '', span, rows };
}

/**
 * A table column - the header text of a column in `allowanceChargeTable()` / `taxTable()`.
 *
 * ⚠️ **THESE TWO ADDITIONAL FACTORIES EXIST BECAUSE THE TABLES AND THE TOTALS BUILD THEIR ROWS AS
 * OBJECT LITERALS**, not through `f()`/`card()` like all cards. Without them the labels of the tax
 * breakdown, the allowances and charges and **all totals** stay English - and that is the part of a
 * document a reader looks at first. Measured on 05.10.2026, not assumed: a comparison run over the same
 * document in `en` and `de` showed 21 unchanged rows while the other 305 of 326 labels were German.
 *
 * @param {string} label
 * @param {boolean} [num] Right-aligned, because it is a number column.
 * @return {{label: string, num?: boolean}}
 */

function th( label, num = false ) {
	return num ? { label: t( label ), num: true } : { label: t( label ) };
}

/**
 * A totals row.
 *
 * @param {string} label
 * @param {string} value Already formatted.
 * @param {string} bt
 * @param {object} [extra] `variant` for the highlighted rows ( gross, amount due ).
 * @return {object}
 */

function tot( label, value, bt, extra = {} ) {
	return attach( { label: t( label ), value, bt, ...extra }, bt );
}

/**
 * Marks a row or table cell whose stated amount differs from what its parts add up to - twin of
 * `ModelBuilder::flag()`.
 *
 * The XML value stays where it is - `value` / `v` are not touched. The row only gains a `mismatch`
 * entry with both amounts formatted like every other amount and a ready, translated hint.
 *
 * ⚠️ **`mismatch` IS THE LAST KEY, AND IT IS ONLY THERE WHEN SOMETHING DIFFERS** - the same on the PHP
 * side. The parity dump compares the hint texts per business term.
 *
 * @param {object} row
 * @param {{stated: number, calculated: number}|null} finding From `TotalsCheck`.
 * @param {string} currency
 * @return {object} The same row object.
 */

function flag( row, finding, currency ) {

	if ( ! finding ) return row;

	const stated = formatAmount( finding.stated, currency );
	const calculated = formatAmount( finding.calculated, currency );

	row.mismatch = { stated, calculated, hint: tf( 'Stated %1$s, calculated %2$s.', stated, calculated ) };

	return row;

}


// ---- date helper: value + @format sibling, default to format 102 when no format node is catalogued ---

function dateField( tree, reader, id ) {

	const raw = V( tree, reader, id );
	const format = V( tree, reader, id + '-0' );

	return formatDate( raw, format ?? '102' );

}


// ---- hero -----------------------------------------------------------------------------------------

function buildHero( tree, reader ) {

	const typeCode = V( tree, reader, 'BT-3' );
	let heroType = CL.docTypeName( typeCode );

	if ( heroType === '' )
		heroType = String( typeCode ?? '' ).trim();

	const currency = V( tree, reader, 'BT-5' ) ?? '';
	const due = V( tree, reader, 'BT-115' );
	const grand = V( tree, reader, 'BT-112' );

	return {
		type: heroType,
		no: V( tree, reader, 'BT-1' ) ?? '',
		date: dateField( tree, reader, 'BT-2' ),
		currency,
		amount: formatAmount( due !== null ? due : grand, currency ),
		bt: { type: 'BT-3', no: 'BT-1', date: 'BT-2', currency: 'BT-5', amount: 'BT-115' },
	};

}


// ---- invoice data card ------------------------------------------------------------------------------

function invoiceDataCard( tree, reader ) {

	const currency = V( tree, reader, 'BT-5' ) ?? '';
	const taxCurrency = V( tree, reader, 'BT-6' ) ?? '';

	const rows = [
		f( 'Invoice number', V( tree, reader, 'BT-1' ), 'BT-1' ),
		f( 'Invoice type', CL.docTypeLabel( V( tree, reader, 'BT-3' ) ), 'BT-3' ),
		f( 'Invoice date', dateField( tree, reader, 'BT-2' ), 'BT-2' ),
		sub( 'Billing period', 'BG-14' ),
		f( 'Start date', dateField( tree, reader, 'BT-73' ), 'BT-73' ),
		f( 'Currency', currency, 'BT-5' ),
		f( 'End date', dateField( tree, reader, 'BT-74' ), 'BT-74' ),
		f( 'VAT accounting currency', ( taxCurrency && taxCurrency.toUpperCase() !== currency.toUpperCase() ) ? taxCurrency : '', 'BT-6' ),
		f( 'Buyer reference', V( tree, reader, 'BT-10' ), 'BT-10' ),
		f( 'Project reference', V( tree, reader, 'BT-11' ), 'BT-11' ),
	];

	/*
	 * ⚠️ THE PROJECT NAME ( BT-11-0, `ram:SpecifiedProcuringProject/ram:Name` ) HAS A ROW OF ITS OWN. Until 27.10.2026 it stood
	 * behind the project number in the row BT-11, without a badge of its own. Ralf, 27.10.2026, after the check against the
	 * sheet "Factur-X CII D22B EN16931" of Factur-X 1.09.2: every field with a semantic data type there belongs in the view
	 * ( B212 in `BEFUNDE.md` ). UBL has no project name ( `cac:ProjectReference` knows only `cbc:ID` ), and `ubl-nodes.json`
	 * no `BT-11-0`; there the row is left out, as the department in `partyCard()`. Twin: the invoice data card in
	 * `ModelBuilder.php`.
	 */
	// ⚠️ `col: 2`: under the project number in the right column, not in the left one under "Buyer reference" ( Ralf,
	// 27.10.2026 ). The grid fills row by row; see .spev-col2 in spev-viewer.css.
	// ⚠️ Set on the row, not spread into a copy: the edit references of `attach()` are not enumerable, and `{ ...row }`
	// would drop them, so the editor could no longer change the project name.
	if ( reader.knows( 'BT-11-0' ) ) {
		const name = f( 'Project name', V( tree, reader, 'BT-11-0' ), 'BT-11-0' );
		name.col = 2;
		rows.push( name );
	}

	return card( 'Invoice data', rows, '', 2 );

}


// ---- party card (seller / buyer) ---------------------------------------------------------------------

const PARTY_BT = {
	seller: { name: 'BT-27', trade: 'BT-28', l1: 'BT-35', l2: 'BT-36', l3: 'BT-162', zip: 'BT-38', city: 'BT-37', sub: 'BT-39', country: 'BT-40', id: 'BT-29', scheme: 'BT-29-1', legal: 'BT-30', legalScheme: 'BT-30-1', vat: 'BT-31', tax: 'BT-32', desc: 'BT-33', contact: 'BT-41', dept: 'BT-41-0', phone: 'BT-42', mail: 'BT-43', ea: 'BT-34', eaScheme: 'BT-34-1' },
	buyer: { name: 'BT-44', trade: 'BT-45', l1: 'BT-50', l2: 'BT-51', l3: 'BT-163', zip: 'BT-53', city: 'BT-52', sub: 'BT-54', country: 'BT-55', id: 'BT-46', scheme: 'BT-46-1', legal: 'BT-47', legalScheme: 'BT-47-1', vat: 'BT-48', contact: 'BT-56', dept: 'BT-56-0', phone: 'BT-57', mail: 'BT-58', ea: 'BT-49', eaScheme: 'BT-49-1' },
};

function partyCard( tree, reader, who ) {

	const isSeller = who === 'seller';
	const bt = PARTY_BT[ who ];
	const groupId = isSeller ? 'BG-4' : 'BG-7';

	// ⚠️ BT-29/BT-46 ( "Identifier" ) vs BT-29-0/BT-46-0 ( "Global identifier" ): TWO INDEPENDENT XML
	// elements - `ram:ID` and `ram:GlobalID` can BOTH be present on the same party at once, and often
	// are in real invoices (a local ERP-assigned number alongside a GLN). One merged row that prefers
	// GlobalID's value whenever one exists silently DROPS the plain ID value
	// and mislabels the GlobalID's value with the plain BT-29/BT-46 tag instead of BT-29-0/BT-46-0.
	// Measured with E03_01_AbweichenderZahlungsempf.xml's SellerTradeParty (
	// `<ram:ID>549910</ram:ID><ram:GlobalID schemeID="0088">4000001123452</ram:GlobalID>` both
	// present): a merged row renders "Identifier BT-29 4000001123452" and never shows 549910
	// anywhere. Each gets its own row, exactly like every other independent field in this card.
	// ⚠️ UBL HAS `bt.id + '-0'` TOO. The CII binding writes an identifier with a
	// scheme into `ram:GlobalID`, and `ubl-nodes.json` reads a `cbc:ID` with `@schemeID` as BT-29-0 /
	// BT-46-0 and one without as BT-29 / BT-46 ( item 5 in `tools/add-ubl-nodes.php` ). Without that, UBL
	// has no "Global identifier" row and shows the GLN as "Identifier", while the same invoice in CII and on
	// the PHP side shows it as "Global identifier".
	// ⚠️ firstValue(), not V(): in UBL every identifier has a `cac:PartyIdentification` of its own, and the
	// plain one may stand in the second - see firstValue() in Accessor.mjs.
	const id = firstValue( tree, reader, bt.id ) ?? '';
	const globalId = firstValue( tree, reader, bt.id + '-0' ) ?? '';
	const schema = firstValue( tree, reader, bt.scheme ) ?? '';

	const rows = [
		f( isSeller ? 'Company name' : 'Name', V( tree, reader, bt.name ), bt.name ),
		f( 'Trading name', V( tree, reader, bt.trade ), bt.trade ),
		sub( 'Postal address', isSeller ? 'BG-5' : 'BG-8' ),
		f( 'Address line 1', V( tree, reader, bt.l1 ), bt.l1 ),
		f( 'Address line 2', V( tree, reader, bt.l2 ), bt.l2 ),
		f( 'Address line 3', V( tree, reader, bt.l3 ), bt.l3 ),
		f( 'Postal code', V( tree, reader, bt.zip ), bt.zip ),
		f( 'City', V( tree, reader, bt.city ), bt.city ),
		f( 'Country subdivision', V( tree, reader, bt.sub ), bt.sub ),
		f( 'Country', CL.countryName( V( tree, reader, bt.country ) ), bt.country ),
		f( 'Identifier', id, bt.id ),
	];

	// ⚠️ Catalogue instead of value - the same correction and the same reason as in `deliveryCard()`.
	if ( reader.knows( bt.id + '-0' ) )
		rows.push( f( 'Global identifier', globalId, bt.id + '-0' ) );

	rows.push(
		f( 'Scheme of the identifier', CL.partyIdSchemeName( schema ), bt.scheme ),
		f( 'Registration number', V( tree, reader, bt.legal ), bt.legal ),
		f( 'Scheme of the registration number', CL.partyIdSchemeName( V( tree, reader, bt.legalScheme ) ), bt.legalScheme ),
		f( 'VAT ID', V( tree, reader, bt.vat ), bt.vat ),
	);

	if ( isSeller ) {
		rows.push( f( 'Tax number', V( tree, reader, bt.tax ), bt.tax ) );
		rows.push( f( 'Further legal information', V( tree, reader, bt.desc ), bt.desc ) );
	}

	rows.push( sub( 'Contact details', isSeller ? 'BG-6' : 'BG-9' ) );
	rows.push( f( 'Contact', V( tree, reader, bt.contact ), bt.contact ) );
	/*
	 * ⚠️ THE DEPARTMENT IS BT-41 ( SELLER ) AND BT-56 ( BUYER ), NOT AN EXTENDED-ONLY FIELD. The CEN binding of EN 16931
	 * to CII carries the "contact point" in `ram:PersonName` OR in `ram:DepartmentName`; the CEN schematron counts both
	 * as the same business term ( CII-SR-465 / CII-SR-466: "Only one BT-41 element is allowed" ). The catalogues name
	 * the second carrier `BT-41-0` / `BT-56-0`, because a node id must be unique, and the badge shows that id as well
	 * ( Ralf, 27.10.2026; the reason stands at `bt()` in QubaTemplate.mjs ). Until 2.39.1 this row was hard-wired empty and without a code, so a
	 * department in an XRechnung or ZUGFeRD invoice was never shown and the editor could not change it.
	 *
	 * ⚠️ THE ROW KEEPS THE CATALOGUE ID `BT-41-0`, NOT `BT-41`. Two rows with the same code in one card would merge in
	 * every consumer that keys rows by their code ( the parity dump, the inbound check, the layouts ), and `attach()`
	 * would give the department the reference of the contact name.
	 *
	 * ⚠️ UBL HAS NO DEPARTMENT ( `cac:Contact` knows only `cbc:Name` ), and `ubl-nodes.json` no `BT-41-0`. There the row
	 * stays as it was: empty, without a code and without an input in the editor.
	 */
	const dept = reader.knows( bt.dept ) ? bt.dept : '';
	rows.push( f( 'Department', dept ? V( tree, reader, dept ) : '', dept ) );
	rows.push( f( 'Phone', V( tree, reader, bt.phone ), bt.phone ) );
	rows.push( f( 'E-mail', V( tree, reader, bt.mail ), bt.mail ) );
	rows.push( f( 'Electronic address', V( tree, reader, bt.ea ), bt.ea ) );
	rows.push( f( 'Scheme of the electronic address', CL.eaSchemeName( V( tree, reader, bt.eaScheme ) ), bt.eaScheme ) );

	return card( isSeller ? 'Seller information' : 'Buyer information', rows, groupId );

}


// ---- payment cards ------------------------------------------------------------------------------------

function readPaymentMeans( tree, reader ) {

	/*
	 * ⚠️ EVERY PAYMENT MEANS: the view shows the complete content of the XML, also every bank account.
	 * Earlier only the first was read, so that UBL, CII and the PHP side
	 * looked the same. Now all four read every one: BG-16 repeats in both catalogues, `ModelBuilder.php` reads
	 * every `SpecifiedTradeSettlementPaymentMeans`, and `Reader::repairFurtherPaymentMeans()` gives the PHP
	 * side every `cac:PaymentMeans` of a UBL invoice. ⚠️ Removing the old `.slice( 0, 1 )` on one side only
	 * makes the parity run in `_claude-tools/parity-corpus/` differ on exactly the documents with more than one.
	 */
	return all( tree, reader, 'BG-16' ).map( ( m ) => ( {
		scope: m,
		type: VIn( m, reader, 'BG-16', 'BT-81' ) ?? '',
		info: VIn( m, reader, 'BG-16', 'BT-82' ) ?? '',
		payeeIban: VIn( m, reader, 'BG-16', 'BT-84' ) ?? '',
		// ⚠️ The second carrier of BT-84 ( `ram:ProprietaryID`, a national account number ); only CII has it, see transferCard().
		payeeProp: reader.knows( 'BT-84-0' ) ? ( VIn( m, reader, 'BG-16', 'BT-84-0' ) ?? '' ) : '',
		payeeAcct: VIn( m, reader, 'BG-16', 'BT-85' ) ?? '',
		payeeBic: VIn( m, reader, 'BG-16', 'BT-86' ) ?? '',
		cardId: VIn( m, reader, 'BG-16', 'BT-87' ) ?? '',
		cardHolder: VIn( m, reader, 'BG-16', 'BT-88' ) ?? '',
		buyerIban: VIn( m, reader, 'BG-16', 'BT-91' ) ?? '',
	} ) );

}

// Port of ModelBuilder.php's payee_card() (BG-10) - needed so the Inbound Check extension's check (c)
// "payee = seller" can find BT-59 in a client-built model, the same way it does server-side.

function payeeCard( tree, reader ) {

	// ⚠️ Payee HAS a GlobalID (BT-60-0) row. `InvoiceSuiteAbstractDocumentBaseReader` exposes
	// `getDocumentPayeeGlobalId()`, and `ModelBuilder.php::payee_card()` (PHP) calls it (see that file's own
	// comment). This JS reader is catalog-driven and reads BT-60-0/BT-60-1 exactly like every other party's
	// GlobalID/scheme pair.
	// ⚠️ Also in UBL: `cbc:ID` with `@schemeID` is BT-60-0, without it BT-60 ( see
	// partyCard() ). V() is enough here, because `ubl-nodes.json` reads one `cac:PartyIdentification` only.
	const id = V( tree, reader, 'BT-60' ) ?? '';
	const globalId = V( tree, reader, 'BT-60-0' ) ?? '';
	const schema = V( tree, reader, 'BT-60-1' ) ?? '';

	const rows = [
		f( 'Payee name', V( tree, reader, 'BT-59' ), 'BT-59' ),
		f( 'Payee identifier', id, 'BT-60' ),
	];

	// ⚠️ Catalogue instead of value - the same correction and the same reason as in `deliveryCard()`:
	// a card in which an empty field disappears while the neighbouring fields stand there empty
	// would be the same error.
	if ( reader.knows( 'BT-60-0' ) )
		rows.push( f( 'Global identifier', globalId, 'BT-60-0' ) );

	if ( reader.knows( 'BT-60-1' ) )
		rows.push( f( 'Scheme of the identifier', CL.partyIdSchemeName( schema ), 'BT-60-1' ) );

	rows.push( f( 'Payee legal registration identifier', V( tree, reader, 'BT-61' ), 'BT-61' ) );
	// ⚠️ Its scheme ( BT-61-1 ) since 27.10.2026, with the label of BT-30-1 / BT-47-1 in `partyCard()` ( B212 in `BEFUNDE.md` ).
	if ( reader.knows( 'BT-61-1' ) )
		rows.push( f( 'Scheme of the registration number', CL.partyIdSchemeName( V( tree, reader, 'BT-61-1' ) ), 'BT-61-1' ) );

	return card( 'Payee', rows, 'BG-10' );

}


// references_card/delivery_card/tax_representative_card/note_cards/attachment_cards: the Exporter's
// template needs every BT/BG the template lists, not just what the Viewer's own UI happens to show, so
// these cards are ported although the Viewer alone would not need all of them.

/**
 * The document type codes that make a document reference something other than a supporting document.
 *
 * ⚠️ **ONE ELEMENT, THREE BUSINESS TERMS.** In CII `ram:AdditionalReferencedDocument` carries the tender
 * or lot reference ( BT-17, type code `50` ), the invoiced object identifier ( BT-18, type code `130` )
 * and the supporting documents ( BG-24, every other code, in practice `916` ). In UBL only BT-18 shares
 * `cac:AdditionalDocumentReference` with BG-24; BT-17 is `cac:OriginatorDocumentReference` there.
 *
 * ⚠️ **THE SAME LIST AS `NOT_AN_ATTACHMENT` IN `Attachments.mjs`, AND IT MUST STAY THE SAME.** The
 * viewer's section "Attachments" reads the XML, this file reads the catalogue tree; both have to agree
 * on what counts as an attachment, or a reference shows up in one place and not in the other. It is
 * not imported from there because the bundles that carry this file without the viewer would then also
 * carry the attachment preview.
 */

const NOT_A_SUPPORTING_DOCUMENT = [ '50', '130' ];

/**
 * The order and contract references, including BT-17 and BT-18.
 *
 * The tender or lot reference ( BT-17 ) and the invoiced object identifier ( BT-18 ) appear under their own
 * names here, where EN 16931 puts them among the document-level references BT-12 to BT-19, and not as
 * cards "Supporting documents" ( BG-24 ).
 *
 * ⚠️ **THE TYPE CODE IS CHECKED BY THE CATALOGUE, NOT HERE.** The anchors `BT-17-00`
 * and `BT-18-00` in `cii-nodes.json` carry the filters `ram:TypeCode='50'` and `ram:TypeCode='130'`,
 * and `BT-18-00` in `ubl-nodes.json` carries `cbc:DocumentTypeCode='130'`. Without the filters
 * `TreeReader` would hand the anchors out by their order in the catalogue: `BT-17-00`
 * would take the first `ram:AdditionalReferencedDocument` and `BT-18-00` the second, whatever their type
 * code.
 *
 * ⚠️ **A NEW BUNDLE NEEDS THE NEW CATALOGUES.** With an old `cii-nodes.json` beside it, `V( 'BT-17' )`
 * would return the identifier of the first document reference, which is often a real attachment.
 * Bundle and catalogues leave `dist/` together and are copied together by `core-js-sync.php`.
 *
 * ⚠️ **ONLY THE FIRST OCCURRENCE.** EN 16931 allows each of the two once. The EXTENDED example
 * `X14_01_Rechnungskorrektur` carries two references with type code 130; the second one is shown
 * nowhere. `ModelBuilder.php` behaves the same.
 */

function referencesCard( tree, reader ) {

	const rows = [
		f( 'Purchase order reference', V( tree, reader, 'BT-13' ), 'BT-13' ),
		f( 'Sales order reference', V( tree, reader, 'BT-14' ), 'BT-14' ),
		f( 'Contract reference', V( tree, reader, 'BT-12' ), 'BT-12' ),
		f( 'Tender or lot reference', V( tree, reader, 'BT-17' ), 'BT-17' ),
		f( 'Despatch advice reference', V( tree, reader, 'BT-16' ), 'BT-16' ),
		f( 'Receiving advice reference', V( tree, reader, 'BT-15' ), 'BT-15' ),
		sub( 'Preceding invoice reference', 'BG-3' ),
		f( 'Preceding invoice number', V( tree, reader, 'BT-25' ), 'BT-25' ),
		f( 'Preceding invoice date', dateField( tree, reader, 'BT-26' ), 'BT-26' ),
		f( 'Object identifier', V( tree, reader, 'BT-18' ), 'BT-18' ),
		// ⚠️ The scheme gets a row of its own, the same way the line-level object identifier shows
		// BT-128-0 beside BT-128. Appended in brackets to the identifier, it would hide which document
		// reference the scheme had come from.
		f( 'Scheme of the object identifier', CL.referenceQualifierName( V( tree, reader, 'BT-18-1' ) ), 'BT-18-1' ),
		f( 'Buyer accounting reference', V( tree, reader, 'BT-19' ), 'BT-19' ),
		sub( 'Process control', 'BG-2' ),
		// ⚠️ BT-23 is read like BT-24 below. Both catalogues know it ( CII
		// `ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID`, UBL `cbc:ProfileID` ).
		// ModelBuilder.php reads it from the CII XML; change one side and the parity run splits.
		f( 'Business process', V( tree, reader, 'BT-23' ), 'BT-23' ),
		f( 'Specification identifier', V( tree, reader, 'BT-24' ), 'BT-24' ),
	];

	return card( 'Order & contract data', rows );

}

function deliveryCard( tree, reader ) {

	// ⚠️ BT-71-0 ( "Global identifier" ) / BT-71-1 ( "Scheme of the identifier" ) - the same two rows
	// as partyCard()'s BT-29-0/BT-46-0 and payeeCard()'s BT-60-0/BT-60-1 above
	// (see partyCard()'s own comment). `ram:ID` and `ram:GlobalID` are independent
	// elements on ShipToTradeParty too, both readable via this catalog.
	// ⚠️ UBL tells the two apart as well: `cbc:ID` with `@schemeID` is BT-71-0, without
	// it BT-71, as in CII ( see partyCard() ). V() is enough here, because `cac:DeliveryLocation` has one ID.
	const id = V( tree, reader, 'BT-71' ) ?? '';
	const globalId = V( tree, reader, 'BT-71-0' ) ?? '';
	const schema = V( tree, reader, 'BT-71-1' ) ?? '';

	const rows = [
		f( 'Delivery location identifier', id, 'BT-71' ),
	];

	/**
	 * ⚠️ **THE CATALOGUE DECIDES ABOUT THE ROW, NOT THE VALUE.** A condition like `if ( globalId !== '' )`
	 * or `if ( schema !== '' )`, reasoned with BT-71-0 not existing in UBL, also hits every CII document
	 * that simply did not fill the field: the row disappears, while BT-165, BT-79 and BT-72 of the same
	 * card stand there empty as "—". BT-71-0 and BT-71-1 then go missing under BG-13.
	 *
	 * ⚠️ And the switch "only filled fields" depends on exactly that: an empty field must **stand
	 * there** so that it can be hidden on request. A field that hides itself takes the job away from
	 * the switch and the information "this field exists, it is empty" away from the reader.
	 */
	if ( reader.knows( 'BT-71-0' ) )
		rows.push( f( 'Global identifier', globalId, 'BT-71-0' ) );

	if ( reader.knows( 'BT-71-1' ) )
		rows.push( f( 'Scheme of the identifier', CL.partyIdSchemeName( schema ), 'BT-71-1' ) );

	rows.push(
		f( 'Deliver to', V( tree, reader, 'BT-70' ), 'BT-70' ),
		sub( 'Deliver to postal address', 'BG-15' ),
		f( 'Address line 1', V( tree, reader, 'BT-75' ), 'BT-75' ),
		f( 'Address line 2', V( tree, reader, 'BT-76' ), 'BT-76' ),
		f( 'Address line 3', V( tree, reader, 'BT-165' ), 'BT-165' ),
		f( 'Postal code', V( tree, reader, 'BT-78' ), 'BT-78' ),
		f( 'City', V( tree, reader, 'BT-77' ), 'BT-77' ),
		f( 'Country subdivision', V( tree, reader, 'BT-79' ), 'BT-79' ),
		f( 'Country', CL.countryName( V( tree, reader, 'BT-80' ) ), 'BT-80' ),
		f( 'Delivery date', dateField( tree, reader, 'BT-72' ), 'BT-72' ),
	);

	return card( 'Delivery information', rows, 'BG-13' );

}

function taxRepresentativeCard( tree, reader ) {

	const rows = [
		f( 'Tax representative name', V( tree, reader, 'BT-62' ), 'BT-62' ),
		// BT-63: same VA-scheme sibling filter the catalog already applies for BT-63 elsewhere - already
		// verified against the full corpus via identifiers.mjs's own taxRepresentative() (23/23 files).
		f( 'Tax representative VAT identifier', V( tree, reader, 'BT-63' ), 'BT-63' ),
		sub( 'Tax representative postal address', 'BG-12' ),
		f( 'Address line 1', V( tree, reader, 'BT-64' ), 'BT-64' ),
		f( 'Address line 2', V( tree, reader, 'BT-65' ), 'BT-65' ),
		f( 'Address line 3', V( tree, reader, 'BT-164' ), 'BT-164' ),
		f( 'Postal code', V( tree, reader, 'BT-67' ), 'BT-67' ),
		f( 'City', V( tree, reader, 'BT-66' ), 'BT-66' ),
		f( 'Country subdivision', V( tree, reader, 'BT-68' ), 'BT-68' ),
		f( 'Country', CL.countryName( V( tree, reader, 'BT-69' ) ), 'BT-69' ),
	];

	return card( 'Seller tax representative', rows, 'BG-11' );

}

function noteCards( tree, reader ) {

	const cards = [];

	/*
	 * ⚠️ IN UBL THE SUBJECT CODE IS PART OF THE NOTE TEXT. EN 16931-3-2 writes BT-21 as a
	 * prefix `#CODE#` in front of the text of `cbc:Note`, e.g. `#ADU#Es gelten unsere …`; there is no element
	 * for it, so `ubl-nodes.json` has no node BT-21 and BT-22 carries the whole text. The prefix is split off
	 * here, and only when the catalogue does not know BT-21 - in CII `ram:SubjectCode` is an element of its
	 * own, and a CII note that happens to begin with `#…#` is text.
	 *
	 * ⚠️ ONLY THE FIRST `#…#` IS THE CODE, AND THE REST STAYS AS IT IS, a trailing `#` included. That is how
	 * the PHP side shows it after its UBL-to-CII conversion: `#SOURCECURRENCYCODE#EUR#` of the CIUS-PT
	 * example becomes subject `SOURCECURRENCYCODE` and note `EUR#` ( measured 09.10.2026 ).
	 */
	const subjectInText = ! reader.knows( 'BT-21' );

	for ( const scope of all( tree, reader, 'BG-1' ) ) {

		let content = ( VIn( scope, reader, 'BG-1', 'BT-22' ) ?? '' ).trim();
		let subject = ( VIn( scope, reader, 'BG-1', 'BT-21' ) ?? '' ).trim();

		if ( subjectInText ) {

			const prefix = /^#([^#]*)#/.exec( content );

			if ( prefix ) {
				subject = prefix[ 1 ].trim();
				content = content.slice( prefix[ 0 ].length ).trim();
			}

		}

		if ( content === '' && subject === '' )
			continue;

		const rows = [
			f( 'Subject', CL.noteSubjectName( subject ), 'BT-21' ),
			f( 'Note', content, 'BT-22' ),
		];

		cards.push( card( 'Invoice note', rows, 'BG-1' ) );

	}

	return cards;

}

function isUrl( value ) {
	const v = String( value ?? '' ).trim();
	return v !== '' && /^https?:\/\//i.test( v );
}

function attachmentCards( tree, reader ) {

	const cards = [];

	// ⚠️ FILTERED BY TYPE CODE. Without the filter every document reference gets a card here,
	// including the object identifier ( 130 ) and the tender reference ( 50 ), which would then appear
	// twice or only as a card. They are rows of `referencesCard()`; `ModelBuilder.php` filters the
	// same two codes in its own `attachment_cards()`.
	//
	// ⚠️ UBL BG-24 NEEDS THE NODES THAT `tools/add-ubl-nodes.php` APPENDS for
	// `cac:AdditionalDocumentReference`; without them this loop finds nothing in a UBL invoice. In UBL the
	// tender reference ( BT-17 ) is a separate element, `cac:OriginatorDocumentReference`, and is never a
	// card here. The PHP side turns it into a reference with type code 50 on its way through the UBL-to-CII
	// conversion, and the filter drops that card on the PHP side as well.
	for ( const scope of all( tree, reader, 'BG-24' ) ) {

		const typeCode = String( VIn( scope, reader, 'BG-24', 'BT-122-0' ) ?? '' ).trim();

		if ( NOT_A_SUPPORTING_DOCUMENT.indexOf( typeCode ) >= 0 )
			continue;

		const issuerId = VIn( scope, reader, 'BG-24', 'BT-122' ) ?? '';
		const name = VIn( scope, reader, 'BG-24', 'BT-123' ) ?? '';
		const uri = VIn( scope, reader, 'BG-24', 'BT-124' ) ?? '';
		// BT-125 itself is the embedded binary's own base64 content - only its PRESENCE is shown, never
		// the content, same as ModelBuilder.php's own ( $binDataName !== '' || $binFilename !== '' ) check.
		const binData = VIn( scope, reader, 'BG-24', 'BT-125' ) ?? '';

		// ⚠️ MEASURED, NOT GUESSED: BT-125-1/BT-125-2 (the AttachmentBinaryObject's @mimeCode/@filename)
		// are DELIBERATELY LEFT EMPTY, never read from the tree, even though the catalog can resolve
		// them correctly from the raw XML. ModelBuilder.php's own $binMime/$binFilename come out empty
		// for this exact element pair on the one corpus document that actually carries both attributes
		// (E08_01_Elektron_Anlage_XML.xml has mimeCode="application/pdf" filename="Aufmass.pdf" in the
		// raw XML, but compare-model-full.mjs measured php=[""] for both BT-125-1 and BT-125-2 - a real
		// horstoeko getter gap, not a catalog bug here) - the model matches PHP's actual output, because the
		// parity run holds both sides byte-equal. BT-125 (the binary's own base64 presence)
		// is NOT affected - no mismatch measured there, so it IS read normally.
		const rows = [
			f( 'Identifier', issuerId, 'BT-122' ),
			// ⚠️ The type code is shown, not only used to filter codes 50 and 130.
			f( 'Type of referenced document', CL.supportingDocTypeLabel( typeCode ), 'BT-122-0' ),
			f( 'Attachment description', name, 'BT-123' ),
			f( 'Reference (URL)', uri, 'BT-124', isUrl( uri ) ? uri : '' ),
			// ⚠️ `t( 'yes' )`, not `'yes'`. This word stands in the place of a data value and is
			// still interface: the attachment itself is a file, and the view only shows that it
			// exists. The language tables carry "ja" and "oui"; the parity run in German catches a bare text.
			f( 'Attached document', ( binData !== '' ) ? t( 'yes' ) : '', 'BT-125' ),
			f( 'Attachment format', '', 'BT-125-1' ),
			f( 'Attachment file name', '', 'BT-125-2' ),
		];

		cards.push( card( 'Additional supporting documents', rows, 'BG-24', 2 ) );

	}

	return cards;

}

function paymentDataCard( tree, reader, means ) {

	/*
	 * ⚠️ ONE PAIR OF ROWS PER DIFFERENT PAYMENT MEANS. Three accounts for credit transfer
	 * are three payment means with the same code; they show as one method here and as three accounts in the
	 * card "Credit transfer". A second, different method ( a card next to a transfer ) gets its own pair, so
	 * nothing in the file is left out. Twin: `ModelBuilder::payment_data_card()`.
	 */
	const methods = [];

	for ( const m of ( means.length ? means : [ {} ] ) ) {
		const type = m.type ?? '';
		const info = m.info ?? '';
		if ( methods.length && methods.some( ( x ) => x.type === type && x.info === info ) )
			continue;
		methods.push( { type, info, scope: m.scope || null } );
	}

	const rows = [];

	for ( const m of methods ) {
		inScope( m.scope, 'BG-16', () => {
			rows.push( f( 'Payment method', CL.paymentMeansName( m.type ), 'BT-81' ) );
			rows.push( f( 'Payment method text', m.info, 'BT-82' ) );
		} );
	}

	rows.push(
		f( 'Payment due date', dateField( tree, reader, 'BT-9' ), 'BT-9' ),
		f( 'Remittance information', V( tree, reader, 'BT-83' ), 'BT-83' ),
		f( 'Payment terms', V( tree, reader, 'BT-20' ), 'BT-20' ),
	);

	return card( 'Payment data', rows, 'BG-16' );

}

function transferCard( means, reader ) {

	/*
	 * ⚠️ BT-84 HAS TWO CARRIERS IN CII, AND THE SECOND ONE IS NO IBAN. The CEN binding of EN 16931 to CII carries the
	 * "payment account identifier" in `ram:IBANID` OR in `ram:ProprietaryID`, a national account number of a country
	 * without IBAN. The catalogue names the second carrier `BT-84-0`, and the badge shows that id ( Ralf,
	 * 27.10.2026; the reason stands at `bt()` in QubaTemplate.mjs ). Until viewer 2.39.1 only `ram:IBANID` was read, so an invoice that names its account only
	 * as a proprietary id showed no account at all.
	 *
	 * ⚠️ THE ROW KEEPS THE CATALOGUE ID `BT-84-0`, NOT `BT-84`. Everything that pays from this card looks for `BT-84`
	 * exactly: the EPC QR code ( `epcAccounts()` takes only BT-84/85/86 ), the SEPA file of the invoice monitor and the
	 * bank check of the inbound check. A national account number must never reach a SEPA transfer, and with its own
	 * code it cannot. Two rows with one code would also merge in the parity dump and give the account number the
	 * edit reference of the IBAN.
	 *
	 * ⚠️ UBL HAS ONE CARRIER ONLY ( `cac:PayeeFinancialAccount/cbc:ID` is BT-84 whatever it holds ), and
	 * `ubl-nodes.json` no `BT-84-0`. There the row is left out, as the department in `partyCard()`. A national
	 * number in UBL stands in the IBAN row; the IBAN hint ( iban.mjs ) only checks a value that looks like an IBAN.
	 */
	const prop = reader.knows( 'BT-84-0' );

	const rows = [];
	let any = false;

	for ( const m of means ) {

		if ( m.payeeIban === '' && m.payeeProp === '' && m.payeeAcct === '' && m.payeeBic === '' )
			continue;

		any = true;
		inScope( m.scope, 'BG-16', () => {
			rows.push( f( 'Account holder', m.payeeAcct, 'BT-85' ) );
			rows.push( f( 'IBAN', m.payeeIban, 'BT-84' ) );
			if ( prop ) rows.push( f( 'Account number', m.payeeProp, 'BT-84-0' ) );
			rows.push( f( 'BIC', m.payeeBic, 'BT-86' ) );
		} );

	}

	if ( ! any ) {
		// ⚠️ In the edit mode an account here is a payment means that does not exist yet: the first one, if there is
		// one without an account, else a new one.
		inScope( means.length ? means[ 0 ].scope : null, 'BG-16', () => {
			rows.push( f( 'Account holder', '', 'BT-85' ) );
			rows.push( f( 'IBAN', '', 'BT-84' ) );
			if ( prop ) rows.push( f( 'Account number', '', 'BT-84-0' ) );
			rows.push( f( 'BIC', '', 'BT-86' ) );
		} );
	}

	return card( 'Credit transfer', rows, 'BG-17' );

}

function debitCard( tree, reader, means ) {

	// ⚠️ BT-89/BT-90 are NOT under BG-16 (payment means) on the CII side, unlike BT-84..BT-88/BT-91 -
	// measured against cii-nodes.php: BT-89 (mandate reference) lives under the PAYMENT TERMS
	// wrapper (SpecifiedTradePaymentTerms/DirectDebitMandateID), BT-90 (creditor reference) is a plain
	// document-level field (ApplicableHeaderTradeSettlement/CreditorReferenceID) - exactly matching
	// ModelBuilder.php's own reads ($terms['mandate'] from getDocumentPaymentTerm(), $creditorRef from
	// getDocumentGeneralPaymentInformation(), neither from payment_means()). Read directly from the
	// tree root instead of scoped to a payment-means occurrence - resolves correctly on the UBL side
	// too (there BT-89 genuinely does live under PaymentMeans/PaymentMandate), since idChainTo() finds
	// each catalog's real position automatically.
	const withBuyerIban = means.find( ( m ) => m.buyerIban !== '' ) || {};

	const rows = [
		// ⚠️ `firstValue()`, not `V()`. In UBL the mandate sits in a payment means, and `V()` looks only
		// at the first one; `PINT_AUNZ_invoice.xml` has five, with the mandate in a later one, and `V()` shows
		// none. In CII the mandate sits in the payment terms, of which there may also be several.
		f( 'Mandate reference', firstValue( tree, reader, 'BT-89' ), 'BT-89' ),
		inScope( withBuyerIban.scope || ( means[ 0 ] && means[ 0 ].scope ) || null, 'BG-16', () => f( 'Debited account (IBAN)', withBuyerIban.buyerIban || '', 'BT-91' ) ),
		f( 'Creditor reference (SEPA)', V( tree, reader, 'BT-90' ), 'BT-90' ),
	];

	return card( 'Direct debit', rows, 'BG-19' );

}

function cardInfoCard( means ) {

	const withCard = means.find( ( m ) => m.cardId !== '' || m.cardHolder !== '' ) || {};

	const rows = inScope( withCard.scope || ( means[ 0 ] && means[ 0 ].scope ) || null, 'BG-16', () => [
		f( 'Card number (last digits)', withCard.cardId || '', 'BT-87' ),
		f( 'Card holder', withCard.cardHolder || '', 'BT-88' ),
	] );

	return card( 'Card information', rows, 'BG-18' );

}


// ---- line items (position_group) ---------------------------------------------------------------------

/**
 * The allowances and charges of **one line** ( BG-27 / BG-28 ) as **one** table across the full
 * width - the same shape in which `allowanceChargeTable()` shows the document level: one table across
 * both columns, not two narrow cards.
 *
 * ⚠️ **THE TABLE ALWAYS APPEARS, EVEN WHEN THE LINE CARRIES NO ALLOWANCE OR CHARGE.**
 * Basic requirement of this view: **every BG and every BT is shown, filled or not**, and only the
 * reader may hide something, through the switch "only filled fields". If a kind carries no
 * entry, **one empty row** is produced for it - with its group code and all five
 * field codes, so that they stay visible.
 *
 * ⚠️ **AN EMPTY LIST IS NO REASON TO SUPPRESS A FIELD** - it is the reason to show it
 * empty. If this function returns `null`, a document without line allowances and charges lacks
 * the whole area, and ten field codes can no longer be found.
 *
 * ⚠️ **THE COLUMNS ARE THOSE OF THE DOCUMENT LEVEL WITHOUT THE TWO TAX COLUMNS.** BG-27 and BG-28 carry
 * no tax category and no tax rate - those are BT-95/BT-96 and BT-102/BT-103
 * and thus fields of the document level alone. Two empty columns in every row would claim the information
 * is missing, although the standard does not know it there.
 *
 * @param {object} lineScope
 * @param {object} reader
 * @param {string} currency
 * @return {object} The table block - always, never `null`.
 */

function lineAllowanceChargeTable( lineScope, reader, currency ) {

	const rows = [];

	for ( const [ groupId, isCharge ] of [ [ 'BG-27', false ], [ 'BG-28', true ] ] ) {

		const bt = isCharge
			? { amount: 'BT-141', basis: 'BT-142', pct: 'BT-143', reason: 'BT-144', code: 'BT-145' }
			: { amount: 'BT-136', basis: 'BT-137', pct: 'BT-138', reason: 'BT-139', code: 'BT-140' };

		const gelesen = readLineAllowanceCharge( lineScope, reader, groupId );

		// No entries of this kind: an empty set, so that the codes stay visible.
		const liste = gelesen.length ? gelesen : [ { amount: null, basis: null, pct: null, reason: '', code: '', scope: null } ];

		for ( const e of liste ) {

			rows.push( attachCells( [
				{ v: t( isCharge ? 'Charge' : 'Allowance' ), bt: groupId },
				{ v: e.reason, bt: bt.reason },
				{ v: e.code, bt: bt.code },
				{ v: formatAmount( e.basis, currency ), num: true, bt: bt.basis },
				{ v: ( e.pct !== null && e.pct !== '' ) ? formatNum( e.pct ) + ' %' : '', num: true, bt: bt.pct },
				{ v: formatAmount( e.amount, currency ), num: true, bt: bt.amount },
			], e.scope, groupId, { node: lineScope, id: 'BG-25' } ) );

		}

	}

	return {
		kind: 'table',
		title: t( 'Allowances & charges' ),
		bt: [ 'BG-27', 'BG-28' ],
		span: 2,
		columns: [
			th( 'Type' ), th( 'Reason' ), th( 'Reason code' ),
			th( 'Base amount', true ), th( 'Percentage', true ), th( 'Amount', true ),
		],
		rows,
	};

}

function readLineAllowanceCharge( lineScope, reader, groupId ) {

	return allIn( lineScope, reader, 'BG-25', groupId ).map( ( e ) => ( {
		scope: e,
		amount: VIn( e, reader, groupId, groupId === 'BG-27' ? 'BT-136' : 'BT-141' ),
		basis: VIn( e, reader, groupId, groupId === 'BG-27' ? 'BT-137' : 'BT-142' ),
		pct: VIn( e, reader, groupId, groupId === 'BG-27' ? 'BT-138' : 'BT-143' ),
		reason: VIn( e, reader, groupId, groupId === 'BG-27' ? 'BT-139' : 'BT-144' ) ?? '',
		code: VIn( e, reader, groupId, groupId === 'BG-27' ? 'BT-140' : 'BT-145' ) ?? '',
	} ) );

}

function positionGroup( tree, reader, lineScope, currency, index, check ) {

	const V_ = ( id ) => VIn( lineScope, reader, 'BG-25', id );

	// ⚠️ Stated values only, in the order of the PHP twin: charges added, allowances subtracted.
	const lineFinding = check.line( {
		net: V_( 'BT-131' ),
		quantity: V_( 'BT-129' ),
		price: V_( 'BT-146' ),
		baseQuantity: V_( 'BT-149' ),
		allowances: readLineAllowanceCharge( lineScope, reader, 'BG-27' ).map( ( e ) => e.amount ),
		charges: readLineAllowanceCharge( lineScope, reader, 'BG-28' ).map( ( e ) => e.amount ),
		category: V_( 'BT-151' ) ?? '',
		rate: V_( 'BT-152' ),
	} );

	const lineId = V_( 'BT-126' ) ?? '';

	const c1 = [
		f( 'Line identifier', lineId, 'BT-126' ),
		f( 'Free text', V_( 'BT-127' ), 'BT-127' ),
		f( 'Object identifier', V_( 'BT-128' ), 'BT-128' ),
		/*
		 * ⚠️ THE SCHEME IS BT-128-1, NOT BT-128-0. In `cii-nodes.json` BT-128-0 is `ram:TypeCode`, always 130, and
		 * BT-128-1 is `ram:ReferenceTypeCode`, the scheme ( e.g. "VN - Order number (vendor)" ). From 09.10.2026 the row
		 * read BT-128-1 but kept the code BT-128-0, so the badge named the type code beside the scheme. Since 27.10.2026 the
		 * row carries BT-128-1 on both sides ( Ralf, check against the Factur-X sheet, B212 in `BEFUNDE.md` ); no layout
		 * and no add-on looks for the row by its code ( searched 27.10.2026 ). In UBL BT-128-1 is the `@schemeID` of
		 * `cbc:ID` ( `tools/add-ubl-nodes.php` ).
		 */
		f( 'Scheme of the object identifier', CL.referenceQualifierName( V_( 'BT-128-1' ) ), 'BT-128-1' ),
		f( 'Order line reference', V_( 'BT-132' ), 'BT-132' ),
		f( 'Accounting reference', V_( 'BT-133' ), 'BT-133' ),
		sub( 'Billing period', 'BG-26' ),
		f( 'Start date', formatDate( V_( 'BT-134' ), V_( 'BT-134-0' ) ?? '102' ), 'BT-134' ),
		f( 'End date', formatDate( V_( 'BT-135' ), V_( 'BT-135-0' ) ?? '102' ), 'BT-135' ),
	];

	const pTaxRate = V_( 'BT-152' );
	const rate = ( pTaxRate !== null && pTaxRate !== '' ) ? formatNum( pTaxRate ) + ' %' : '';

	const c2 = [
		f( 'Quantity', formatNum( V_( 'BT-129' ) ), 'BT-129' ),
		f( 'Unit', CL.unitName( V_( 'BT-130' ) ), 'BT-130' ),
		f( 'Net unit price', formatUnitPrice( V_( 'BT-146' ), currency ), 'BT-146' ),
		flag( f( 'Net line amount', formatAmount( V_( 'BT-131' ), currency ), 'BT-131' ), lineFinding, currency ),
		// gross price discount - BT-147's own chain already walks through the price group's nested
		// AllowanceCharge wrapper automatically (VIn()/idChainTo() resolve the FULL chain, wrapper
		// nodes included - no manual intermediate-scope resolution needed, same as every other field
		// here).
		f( 'Item price discount', formatUnitPrice( V_( 'BT-147' ), currency ), 'BT-147' ),
		/*
		 * ⚠️ BT-148 AND BT-149 DO NOT SET `?? 0`. The PHP library returns 0 for a missing gross price and a
		 * missing base quantity ( getDocumentPositionGrossPrice() and getDocumentPositionNetPrice() run for
		 * every line ). Imitating that makes "0" appear at nearly every line on both sides - 407 gross-price
		 * cells and 449 base-quantity cells over the 135 corpus documents ( measured 09.10.2026 ) - and the
		 * parity count never sees it, because both sides show the same wrong value. `ModelBuilder::stated()`
		 * asks the XML, and both sides show an empty cell. A stated 0 stays 0 here, because
		 * `formatUnitPrice()` and `formatNum()` only blank null and ''.
		 */
		f( 'Gross unit price', formatUnitPrice( V_( 'BT-148' ), currency ), 'BT-148' ),
		// ⚠️ The gross price may carry its own base quantity ( CII only, `ram:BasisQuantity` of
		// `ram:GrossPriceProductTradePrice` ); 10 of 13 corpus invoices with a gross price carry it ( measured
		// 14.10.2026 ). The same row stands in `ModelBuilder.php`, directly after the gross price.
		f( 'Gross price base quantity', formatNum( V_( 'BT-149-1' ) ), 'BT-149-1' ),
		// ⚠️ The unit of that base quantity ( BT-150-1, `@unitCode` of the gross price's `ram:BasisQuantity` ) since
		// 27.10.2026; until then only the unit of the net price ( BT-150 ) was shown ( B212 in `BEFUNDE.md` ).
		f( 'Gross price base unit', CL.unitName( V_( 'BT-150-1' ) ), 'BT-150-1' ),
		f( 'Price base quantity', formatNum( V_( 'BT-149' ) ), 'BT-149' ),
		f( 'Price base unit', CL.unitName( V_( 'BT-150' ) ), 'BT-150' ),
		sub( 'Line VAT information', 'BG-30' ),
		f( 'VAT category', CL.taxCategoryName( V_( 'BT-151' ) ), 'BT-151' ),
		f( 'VAT rate', rate, 'BT-152' ),
	];

	const acTable = lineAllowanceChargeTable( lineScope, reader, currency );

	const c5 = [
		f( 'Item name', V_( 'BT-153' ), 'BT-153' ),
		f( 'Item description', V_( 'BT-154' ), 'BT-154' ),
		f( "Seller's item number", V_( 'BT-155' ), 'BT-155' ),
		f( "Buyer's item number", V_( 'BT-156' ), 'BT-156' ),
		f( 'Item EAN (GTIN)', V_( 'BT-157' ), 'BT-157' ),
		f( 'Scheme of the item identifier', CL.partyIdSchemeName( V_( 'BT-157-1' ) ), 'BT-157-1' ),
		f( 'Item classification', V_( 'BT-158' ), 'BT-158' ),
		f( 'Classification scheme', V_( 'BT-158-1' ), 'BT-158-1' ),
		f( 'Classification scheme version', V_( 'BT-158-2' ), 'BT-158-2' ),
		f( 'Item country of origin', CL.countryName( V_( 'BT-159' ) ), 'BT-159' ),
	];

	const chars = allIn( lineScope, reader, 'BG-25', 'BG-32' );

	if ( chars.length ) {
		c5.push( sub( 'Item attributes', 'BG-32' ) );
		for ( const ch of chars ) {
			c5.push( f( 'Attribute name', VIn( ch, reader, 'BG-32', 'BT-160' ), 'BT-160' ) );
			c5.push( f( 'Attribute value', VIn( ch, reader, 'BG-32', 'BT-161' ), 'BT-161' ) );
		}
	}

	/*
	 * ⚠️ **THE WORD GOES THROUGH `t()`, THE NUMBER DOES NOT.** The PHP side translates the same key
	 * ( `ModelBuilder::line()`, `$this->t( 'Item' )` ); as a fixed English word here, every line group
	 * would be called "Item 2" in the German and the French view, while all headings inside it were
	 * translated. The language tables carry the key: "Position" and "Ligne".
	 */
	const heading = ( t( 'Item' ) + ' ' + ( lineId !== '' ? lineId : String( index ) ) ).trim();

	/*
	 * ⚠️ **THE TABLE STANDS AFTER THE ITEM DETAILS, NOT BETWEEN THE CARDS.** It runs across
	 * both columns; standing in the middle, it would cut the two-column grid in two and the card
	 * "Item details" would slide below it into a row of its own.
	 */
	const items = [
		card( 'Position', c1 ),
		card( 'Price details', c2, 'BG-29' ),
		card( 'Item information', c5, 'BG-31', 2 ),
		acTable,
	];

	return { layout: 'group', title: heading, bt: 'BG-25', items };

}


// ---- document-level allowances/charges table -----------------------------------------------------------

/**
 * The allowances and charges of the document ( BG-20 / BG-21 ).
 *
 * ⚠️ **THE TABLE ALWAYS APPEARS, EVEN WHEN THE DOCUMENT CARRIES NO ALLOWANCE OR CHARGE.** A document
 * can state `AllowanceTotalAmount` and `ChargeTotalAmount` as `0.00` and carry not a single
 * group. If this function returned `null` then, nothing would stand where fourteen
 * field codes belong.
 *
 * ⚠️ **THE BASIC REQUIREMENT IS: EVERY BG AND EVERY BT IS SHOWN, FILLED OR NOT.** What
 * is hidden is chosen by the reader through the switch "only filled fields", not by the builder
 * of the model.
 *
 * ⚠️ **A PLACEHOLDER ROW STAYS EMPTY THROUGHOUT.** A placeholder row stands for a group that **does not
 * exist at all**; writing "0,00 EUR" and "0 %" there would be an invented statement.
 *
 * ⚠️ A REAL ROW DOES NOT SET `?? 0` EITHER. A real allowance that states no
 * base amount or no percentage shows an empty cell; the PHP side asks its XML for the same answer.
 *
 * @param {object} tree
 * @param {object} reader
 * @param {string} currency
 * @return {object} The table block - always, never `null`.
 */

function allowanceChargeTable( tree, reader, currency, check ) {

	const rows = [];

	for ( const [ groupId, isCharge ] of [ [ 'BG-20', false ], [ 'BG-21', true ] ] ) {

		const bt = isCharge
			? { amount: 'BT-99', basis: 'BT-100', pct: 'BT-101', cat: 'BT-102', rate: 'BT-103', reason: 'BT-104', code: 'BT-105' }
			: { amount: 'BT-92', basis: 'BT-93', pct: 'BT-94', cat: 'BT-95', rate: 'BT-96', reason: 'BT-97', code: 'BT-98' };

		const gruppen = all( tree, reader, groupId );

		if ( ! gruppen.length ) {

			rows.push( attachCells( [
				{ v: t( isCharge ? 'Charge' : 'Allowance' ), bt: groupId },
				{ v: '', bt: bt.reason },
				{ v: '', bt: bt.code },
				{ v: '', num: true, bt: bt.cat },
				{ v: '', num: true, bt: bt.rate },
				{ v: '', num: true, bt: bt.basis },
				{ v: '', num: true, bt: bt.pct },
				{ v: '', num: true, bt: bt.amount },
			], null, groupId ) );

			continue;

		}

		for ( const e of gruppen ) {

			const V_ = ( id ) => VIn( e, reader, groupId, id );

			check.allowanceCharge( isCharge, V_( bt.amount ), V_( bt.cat ) ?? '', V_( bt.rate ) );

			rows.push( attachCells( [
				{ v: t( isCharge ? 'Charge' : 'Allowance' ), bt: groupId },
				{ v: V_( bt.reason ) ?? '', bt: bt.reason },
				{ v: V_( bt.code ) ?? '', bt: bt.code },
				{ v: CL.taxCategoryName( V_( bt.cat ) ), num: true, bt: bt.cat },
				{ v: ( V_( bt.rate ) !== null && V_( bt.rate ) !== '' ) ? formatNum( V_( bt.rate ) ) + ' %' : '', num: true, bt: bt.rate },
				/*
				 * ⚠️ BT-93/BT-100 ( BasisAmount ) AND BT-94/BT-101 ( CalculationPercent ) STAY EMPTY WHEN THE
				 * INVOICE DOES NOT STATE THEM. ModelBuilder.php's library presets exactly these two
				 * out-parameters with 0, which would be an invented value; the PHP side asks the XML
				 * ( `stated()` in ModelBuilder.php ), and both show an empty cell.
				 */
				{ v: formatAmount( V_( bt.basis ), currency ), num: true, bt: bt.basis },
				{ v: ( V_( bt.pct ) !== null && V_( bt.pct ) !== '' ) ? formatNum( V_( bt.pct ) ) + ' %' : '', num: true, bt: bt.pct },
				{ v: formatAmount( V_( bt.amount ), currency ), num: true, bt: bt.amount },
			], e, groupId ) );

		}

	}

	return {
		kind: 'table',
		title: t( 'Allowances & charges' ),
		bt: [ 'BG-20', 'BG-21' ],
		span: 2,
		columns: [
			th( 'Type' ), th( 'Reason' ), th( 'Reason code' ),
			th( 'VAT category', true ), th( 'VAT %', true ),
			th( 'Base amount', true ), th( 'Percentage', true ), th( 'Amount', true ),
		],
		rows,
	};

}


// ---- VAT breakdown table -------------------------------------------------------------------------------

function taxTable( tree, reader, currency, check ) {

	const read = all( tree, reader, 'BG-23' );

	// ⚠️ In the edit mode an invoice without a VAT breakdown gets one empty row, so that the breakdown can be entered.
	// Without the switch the table is left out, as before ( the PHP twin does the same ).
	if ( ! read.length && ! editing ) return null;

	const entries = read.length ? read : [ null ];

	// ⚠️ Read once for the check, in document order - the same order as the rows below.
	const found = check.taxes( read.map( ( e ) => ( {
		category: VIn( e, reader, 'BG-23', 'BT-118' ) ?? '',
		rate: VIn( e, reader, 'BG-23', 'BT-119' ),
		basis: VIn( e, reader, 'BG-23', 'BT-116' ),
		tax: VIn( e, reader, 'BG-23', 'BT-117' ),
	} ) ) );

	// BT-7's format sibling (BT-7-0) does not exist in the catalog - same "no format node" case as
	// BT-134/BT-135, default to format 102 (see Format.mjs's own doc block for why that default is
	// safe: it is the only format value measured anywhere in the checked corpus).
	/*
	 * ⚠️ IN UBL BT-7 AND BT-8 BELONG TO THE DOCUMENT, NOT TO A VAT BREAKDOWN. CII writes
	 * both into every `ram:ApplicableTradeTax`; UBL has one `cbc:TaxPointDate` ( BT-7 ) and one
	 * `cac:InvoicePeriod/cbc:DescriptionCode` ( BT-8 ) for the whole invoice. `VIn()` inside a BG-23 scope
	 * cannot reach them, because their chain does not pass BG-23. They are shown in the FIRST row, which is
	 * where the PHP side puts BT-7 after its UBL-to-CII conversion ( measured on the five XRechnung UBL
	 * documents 02.01a to 03.07a and on UBL/allowance-example.xml, 09.10.2026 ).
	 *
	 * ⚠️ THE CATALOGUE DECIDES, NOT THE SYNTAX: the fallback applies when the chain of the id does not pass
	 * BG-23. For CII it never applies; a CII row without BT-7 stays empty instead of borrowing another row's.
	 */
	const docLevel = ( id ) => {
		const chain = reader.idChainTo( id );
		return chain && chain.indexOf( 'BG-23' ) === -1 ? V( tree, reader, id ) : null;
	};
	const docBt7 = docLevel( 'BT-7' );

	/*
	 * ⚠️ BT-8 COMES FROM TWO CODE LISTS, AND THE VIEW SHOWS THE ONE OF CII. UBL writes the
	 * code of UNTDID 2005 into `cac:InvoicePeriod/cbc:DescriptionCode` ( 3, 35, 432 ), CII the code of
	 * UNTDID 2475 into `ram:DueDateTypeCode` ( 5, 29, 72 ); EN 16931-3-2 and -3-3 map them one to one. The
	 * CII view and the PHP side, which reads UBL through the conversion to CII, show 72 for "paid to date";
	 * without the mapping the UBL view would show 432 for the same invoice. The tree keeps 432, because a
	 * writer of UBL needs the code as the document carries it. An unknown code is shown as it stands.
	 */
	const BT8_2005_TO_2475 = { 3: '5', 35: '29', 432: '72' };
	const docBt8Raw = docLevel( 'BT-8' );
	const docBt8 = docBt8Raw === null ? null : ( BT8_2005_TO_2475[ String( docBt8Raw ).trim() ] ?? docBt8Raw );

	const rows = entries.map( ( e, i ) => {

		const V_ = ( id ) => ( e ? VIn( e, reader, 'BG-23', id ) : null ) ?? ( i === 0 ? ( id === 'BT-7' ? docBt7 : ( id === 'BT-8' ? docBt8 : null ) ) : null );

		return attachCells( [
			{ v: CL.taxCategoryName( V_( 'BT-118' ) ), bt: 'BT-118' },
			// ⚠️ BT-119 is optional for category O; without the guard the cell reads " %" ( UBL/vat-category-O.xml ).
			{ v: ( V_( 'BT-119' ) !== null && V_( 'BT-119' ) !== '' ) ? formatNum( V_( 'BT-119' ) ) + ' %' : '', num: true, bt: 'BT-119' },
			flag( { v: formatAmount( V_( 'BT-116' ), currency ), num: true, bt: 'BT-116' }, found[ i ] ? found[ i ].basis : null, currency ),
			flag( { v: formatAmount( V_( 'BT-117' ), currency ), num: true, bt: 'BT-117' }, found[ i ] ? found[ i ].tax : null, currency ),
			{ v: V_( 'BT-120' ) ?? '', bt: 'BT-120' },
			{ v: V_( 'BT-121' ) ?? '', bt: 'BT-121' },
			{ v: formatDate( V_( 'BT-7' ), '102' ), bt: 'BT-7' },
			{ v: V_( 'BT-8' ) ?? '', bt: 'BT-8' },
		], e, 'BG-23' );

	} );

	return {
		kind: 'table',
		title: t( 'VAT breakdown' ),
		bt: 'BG-23',
		span: 2,
		columns: [
			th( 'Category' ), th( 'Rate', true ),
			th( 'Taxable amount', true ), th( 'Tax amount', true ),
			th( 'Exemption reason' ), th( 'Exemption reason code' ),
			th( 'Tax point date' ), th( 'Tax due date code' ),
		],
		rows,
	};

}


// ---- totals ---------------------------------------------------------------------------------------------

function totalsBlock( tree, reader, currency, check ) {

	const lineTotal = V( tree, reader, 'BT-106' );
	const allowance = V( tree, reader, 'BT-107' );
	const charge = V( tree, reader, 'BT-108' );
	const taxBasis = V( tree, reader, 'BT-109' );
	const taxTotal = V( tree, reader, 'BT-110' );
	const taxTotalTaxCurrency = V( tree, reader, 'BT-111' );
	const grand = V( tree, reader, 'BT-112' );
	const prepaid = V( tree, reader, 'BT-113' );
	const rounding = V( tree, reader, 'BT-114' );
	const due = V( tree, reader, 'BT-115' );

	/**
	 * ⚠️ **EVERY TOTALS ROW IS LISTED, EVEN AT ZERO AND EVEN WITHOUT A VALUE.** Basic requirement
	 * of this view: every BG and every BT is shown, filled or not. What is hidden is chosen by
	 * the reader through the switch "only filled fields".
	 *
	 * ⚠️ **NO TOTAL GETS `?? 0`.** `formatAmount( null )` returns `''`,
	 * `formatAmount( '0.00' )` returns `'0.00 EUR'`. A document that does not state a total shows an
	 * empty row that the switch hides; an explicit `0.00` stays. The PHP side reads all nine
	 * totals from the XML and tells both cases apart; this side reads the element directly anyway.
	 */
	const found = check.totals( {
		lineTotal, allowanceTotal: allowance, chargeTotal: charge, taxBasis, taxTotal,
		grand, prepaid, rounding, due,
	} );

	const rows = [
		flag( tot( 'Sum of line net amounts', formatAmount( lineTotal, currency ), 'BT-106' ), found[ 'BT-106' ], currency ),
		flag( tot( 'Allowances', formatAmount( allowance, currency ), 'BT-107' ), found[ 'BT-107' ], currency ),
		flag( tot( 'Charges', formatAmount( charge, currency ), 'BT-108' ), found[ 'BT-108' ], currency ),
	];

	rows.push( flag( tot( 'Total without VAT', formatAmount( taxBasis, currency ), 'BT-109' ), found[ 'BT-109' ], currency ) );
	rows.push( flag( tot( 'Total VAT', formatAmount( taxTotal, currency ), 'BT-110' ), found[ 'BT-110' ], currency ) );

	/**
	 * ⚠️ **BT-111 IS SHOWN IN THE CURRENCY ITS OWN ELEMENT NAMES, NOT IN BT-6.** That is what the PHP
	 * side does: `foreign_tax_total()` returns the amount and the element's `currencyID`, and
	 * `totals_block()` shows the amount as soon as that currency differs from BT-5. In a consistent
	 * document BT-6 and that currency are the same; in
	 * `_mutated-demo/zugferd-e05-totals-errors.xml` ( BT-5 `EURO`, no BT-6, one element with `EUR` )
	 * PHP shows "56.87 EUR", and asking BT-6 here would show nothing ( measured 08.10.2026 ). The row shows
	 * what the document says, even when BT-6 is missing or names something else.
	 *
	 * ⚠️ The catalog filter `@currencyID!={BT-5}` already keeps BT-111 out of the invoice currency.
	 * The check for a differing currency stays anyway: it repeats the PHP condition word for word, and
	 * it keeps an empty currency code out of the formatted amount.
	 */
	const taxTotalCurrency = V( tree, reader, 'BT-111-0' ) ?? '';
	const hasSecondCurrency = taxTotalCurrency !== '' && taxTotalCurrency.toUpperCase() !== currency.toUpperCase();

	rows.push( tot(
		'Total VAT in accounting currency',
		hasSecondCurrency ? formatAmount( taxTotalTaxCurrency, taxTotalCurrency ) : '',
		'BT-111'
	) );

	rows.push( flag( tot( 'Total with VAT', formatAmount( grand, currency ), 'BT-112', { variant: 'grand' } ), found[ 'BT-112' ], currency ) );

	rows.push( tot( 'Rounding amount', formatAmount( rounding, currency ), 'BT-114' ) );
	rows.push( tot( 'Prepaid amount', formatAmount( prepaid, currency ), 'BT-113' ) );

	rows.push( flag( tot( 'Amount due for payment', formatAmount( due, currency ), 'BT-115', { variant: 'due' } ), found[ 'BT-115' ], currency ) );

	return { kind: 'totals', title: t( 'Totals' ), bt: 'BG-22', rows };

}


// ---- orchestration (build()) -----------------------------------------------------------------------------

/**
 * @param {object} tree A TreeReader.read() output tree (CII or UBL - identical ids either way).
 * @param {import('./TreeReader.mjs').TreeReader} reader The SAME TreeReader instance that produced `tree`.
 * @return {object} The neutral invoice model - hero/disclaimer/sections, matching
 *                   libs/einvoice-model/schema/einvoice-model.schema.json (the ported subset only -
 *                   see this file's own header comment for what is not yet covered).
 */

/**
 * @param {object} tree
 * @param {object} reader
 * @param {{language?: string}} [options] `language`: 'de' | 'en' | 'fr' ( default 'en' ). An
 *        unknown code falls back to English, never to a guessed neighbouring language.
 * @return {object}
 */

export function build( tree, reader, options = {} ) {

	/**
	 * ⚠️ **THE LANGUAGE IS SET ON EVERY CALL, EVEN WITHOUT AN ARGUMENT.** Without the `setLanguage()` in
	 * the default case a second `build()` would keep the language of the first - the caller that
	 * passes nothing would get German, because someone earlier wanted German. The default must
	 * stay `'en'`: `test/compare-model.mjs` compares this output character by character with the
	 * PHP model, and that comparison runs in English.
	 *
	 * ⚠️ **THE LANGUAGE SETS TWO THINGS, NOT ONE.** `setLanguage()` affects the
	 * labels, `setFormatLanguage()` the separators and the date pattern. Both must be
	 * set, and both with the same code: the language switcher steers the formats as well. Whoever
	 * sets only the first gets German labels over English numbers, and in a table full of amounts
	 * that is noticed late.
	 */
	setLanguage( options.language ?? 'en' );
	setFormatLanguage( options.language ?? 'en' );

	/*
	 * ⚠️ THE EDIT MODE IS SWITCHED OFF IN `finally`, ALSO WHEN THE BUILD THROWS. Left on, the next build of the viewer
	 * would record every read and attach references to a model that is only shown.
	 */
	if ( options.edit ) {
		editing = { tree, reader, scope: null };
		recordReads( true );
		try {
			return buildModel( tree, reader, options );
		} finally {
			editing = null;
			recordReads( false );
		}
	}

	return buildModel( tree, reader, options );

}

function buildModel( tree, reader, options ) {

	const currency = V( tree, reader, 'BT-5' ) ?? '';

	/*
	 * ⚠️ **ONE CHECK PER build(), AND THE ORDER OF THE SECTIONS BELOW MUST NOT CHANGE:** lines, then the
	 * document allowances and charges, then the VAT breakdown, then the totals. Each total is checked
	 * against parts that are already recorded - see TotalsCheck.mjs. `options.totalsTolerances` exists
	 * only for the corpus measurement; the viewer never passes it.
	 *
	 * ⚠️ BT-24 decides whether the document is an EXTENSION ( `#conformant#` ) - see the `extension`
	 * option in TotalsCheck.mjs for which sums that switches off and why.
	 */
	const check = new TotalsCheck( {
		...( options.totalsTolerances ?? {} ),
		extension: String( V( tree, reader, 'BT-24' ) ?? '' ).indexOf( '#conformant#' ) !== -1,
	} );

	const model = {
		hero: buildHero( tree, reader ),
		disclaimer: t( 'No liability is assumed for the accuracy of the data.' ),
	};

	const sections = [];

	const means = readPaymentMeans( tree, reader );

	const grid = [
		invoiceDataCard( tree, reader ),
		partyCard( tree, reader, 'buyer' ),
		partyCard( tree, reader, 'seller' ),
		referencesCard( tree, reader ),
		deliveryCard( tree, reader ),
		payeeCard( tree, reader ),
		taxRepresentativeCard( tree, reader ),
		paymentDataCard( tree, reader, means ),
		transferCard( means, reader ),
		debitCard( tree, reader, means ),
		cardInfoCard( means ),
	];

	sections.push( { layout: 'grid', items: grid } );

	const noteCardList = noteCards( tree, reader );
	if ( noteCardList.length )
		sections.push( { layout: 'grid', items: noteCardList } );

	let index = 0;
	for ( const lineScope of all( tree, reader, 'BG-25' ) ) {
		index++;
		sections.push( positionGroup( tree, reader, lineScope, currency, index, check ) );
	}

	// Always - see `allowanceChargeTable()`: a document without allowances and charges shows them empty.
	sections.push( { layout: 'grid', items: [ allowanceChargeTable( tree, reader, currency, check ) ] } );

	const taxBlock = taxTable( tree, reader, currency, check );
	const totalsSection = [];
	if ( taxBlock ) totalsSection.push( taxBlock );
	totalsSection.push( totalsBlock( tree, reader, currency, check ) );
	sections.push( { layout: 'grid', items: totalsSection } );

	const attachmentCardList = attachmentCards( tree, reader );
	if ( attachmentCardList.length )
		sections.push( { layout: 'grid', items: attachmentCardList } );

	model.sections = sections;

	/*
	 * ⚠️ **THE FIELD HINTS RUN LAST, ON THE FINISHED MODEL, AND BEFORE ANY ADD-ON EXTENDS IT.** The
	 * mandatory rules read the rows of the model ( see InvoiceHints.mjs for why ), so every section must
	 * exist. An add-on's sections ( `extendModel` in the viewer element ) come later and carry no hints;
	 * they show fields EN 16931 does not know. `ModelBuilder::build()` calls its twin at the same point,
	 * before `Context::extendModel()`.
	 *
	 * ⚠️ `knows` keeps a field the catalogue cannot read out of the mandatory check. Without it, a catalogue
	 * without a node for a field would give every invoice of that syntax a "missing" hint. Only the two ZATCA
	 * samples, which carry no `cbc:CustomizationID`, get the hint "BT-24 missing".
	 */
	applyHints( model, factsFromTree( tree, reader ), { knows: ( bt ) => reader.knows( bt ), extension: check.extension } );

	return model;

}
