// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Hand-picked codelist lookups - line-for-line port of ModelBuilder.php's doc_type_name() /
 * payment_means_name() / unit_name() / tax_category_name() / country_name() / party_id_scheme_name()
 * / ea_scheme_name() / note_subject_name() / reference_qualifier_name() - see those PHP methods' own
 * doc blocks for why only a hand-picked subset of each official codelist is here, not the full
 * generated reference-data layer.
 *
 * ⚠️ **THE TABLES ARE ENGLISH, BUT EVERY NAME FUNCTION RETURNS ITS RESULT THROUGH `t()`.** The invoice view
 * is rendered completely in the browser, so this package is the only source of the texts shown. Without
 * `t()` a German page would show "Credit transfer" instead of "Überweisung". The tables stay English
 * because they are the source texts. See `Labels.mjs`.
 *
 * ⚠️ **THE TRANSLATION HAPPENS IN THE NAME FUNCTIONS, NOT IN `coded()`.** That function only joins code
 * and designation ( "380 - Handelsrechnung" ); it is formatting, not a text source. Translating there
 * would let `docTypeLabel()` run the translation twice, because `docTypeName()` needs it anyway -
 * harmless, but not explainable.
 *
 * @since 3.2.0
 */

import { t } from './Labels.mjs';


/**
 * Combines a resolved designation with its raw code: "381 - Credit note". Returns the bare code
 * when the designation is unknown - never a guessed wording.
 *
 * @param {string} name Already translated by the caller - see this file's own header.
 * @param {string} code
 * @return {string}
 */

export function coded( name, code ) {

	code = String( code ?? '' ).trim();

	if ( code === '' ) return name;

	return name !== '' ? code + ' - ' + name : code;

}

const DOC_TYPE = {
	'326': 'Partial invoice',
	'380': 'Commercial invoice',
	'381': 'Credit note',
	'384': 'Corrected invoice',
	'389': 'Self-billed invoice',
	'751': 'Invoice information for accounting purposes',
	'875': 'Partial construction invoice',
	'876': 'Partial final construction invoice',
	'877': 'Final construction invoice',
};

export function docTypeName( code ) {
	const hit = DOC_TYPE[ String( code ?? '' ).trim() ];

	// ⚠️ An unknown code returns '', not t( '' ) - otherwise the empty string would stand as a key in the
	// table, and a hit on it would be a translation for "nothing".
	return hit === undefined ? '' : t( hit );
}

export function docTypeLabel( code ) {
	return coded( docTypeName( code ), code );
}

/**
 * The type of a supporting document ( BT-122-0, UNTDID 1001 ), for the cards of BG-24.
 *
 * ⚠️ ONLY 916. The EN 16931 binding writes `916` for a supporting document; `50` and `130` never reach a card
 * ( `NOT_A_SUPPORTING_DOCUMENT` in `ModelAssembler.mjs` ). Any other code is shown bare. The wording is the one of
 * `libs/einvoice-reference-data/dist/codelists/UNTDID_1001/UNTDID_1001_4.EN.json`: "Related document".
 *
 * @param {string} code
 * @return {string}
 */
export function supportingDocTypeLabel( code ) {
	const c = String( code ?? '' ).trim();

	return coded( c === '916' ? t( 'Related document' ) : '', c );
}

const PAYMENT_MEANS = {
	'1': 'Instrument not defined',
	'10': 'In cash',
	'20': 'Cheque',
	'30': 'Credit transfer',
	'42': 'Payment to bank account',
	'48': 'Bank card',
	'49': 'Direct debit',
	'57': 'Standing agreement',
	'58': 'SEPA credit transfer',
	'59': 'SEPA direct debit',
	'68': 'Online payment service',
	'97': 'Clearing between partners',
	'ZZZ': 'Mutually defined',
};

export function paymentMeansName( code ) {
	const hit = PAYMENT_MEANS[ String( code ?? '' ).trim() ];

	return coded( hit === undefined ? '' : t( hit ), code );
}

const UNIT_SYMBOLS = {
	MLT: 'ml', MTR: 'm', CMT: 'cm', KMT: 'km',
	MTK: 'm²', MTQ: 'm³', KGM: 'kg', GRM: 'g',
	TNE: 't', KWH: 'kWh', P1: '%', MIN: 'min',
};

const UNIT_WORDS = {
	C62: 'piece', H87: 'piece', XPP: 'piece', PCE: 'piece',
	EA: 'each', SET: 'set', LTR: 'litre', HUR: 'hour',
	DAY: 'day', WEE: 'week', MON: 'month', ANN: 'year',
};

export function unitName( code ) {

	code = String( code ?? '' ).trim().toUpperCase();

	if ( code === '' ) return '';

	/**
	 * ⚠️ A UNIT SYMBOL IS NOT TRANSLATED. "kg", "m²", "kWh" are SI symbols and the same in every
	 * language; sending them through `t()` would not be wrong, but it would invite twelve keys into
	 * the tables that have no business there.
	 */
	if ( UNIT_SYMBOLS[ code ] !== undefined )
		return coded( UNIT_SYMBOLS[ code ], code );

	// Die ausgeschriebenen Einheiten ( „piece", „hour", „month" ) dagegen schon.
	const word = UNIT_WORDS[ code ];

	return coded( word === undefined ? '' : t( word ), code );

}

const TAX_CATEGORY = {
	S: 'Standard rate',
	Z: 'Zero rated goods',
	E: 'Exempt from tax',
	AE: 'VAT Reverse Charge',
	K: 'VAT exempt for EEA intra-community supply of goods and services',
	G: 'Free export item, tax not charged',
	O: 'Services outside scope of tax',
	L: 'Canary Islands general indirect tax',
	M: 'Tax for production, services and importation in Ceuta and Melilla',
};

export function taxCategoryName( code ) {

	code = String( code ?? '' ).trim().toUpperCase();

	if ( code === '' ) return '';

	const hit = TAX_CATEGORY[ code ];

	return coded( hit === undefined ? '' : t( hit ), code );

}

const COUNTRY = {
	DE: 'Germany', AT: 'Austria', CH: 'Switzerland', FR: 'France',
	IT: 'Italy', NL: 'Netherlands', BE: 'Belgium', LU: 'Luxembourg',
	PL: 'Poland', CZ: 'Czechia', DK: 'Denmark', ES: 'Spain',
	GB: 'United Kingdom', US: 'United States',
};

export function countryName( code ) {

	code = String( code ?? '' ).trim().toUpperCase();

	if ( code === '' ) return '';

	const hit = COUNTRY[ code ];

	return coded( hit === undefined ? '' : t( hit ), code );

}

const PARTY_ID_SCHEME = {
	'0060': 'Data Universal Numbering System (D-U-N-S Number)',
	'0088': 'EAN Location Code',
	'0160': 'GTIN - Global Trade Item Number',
	'0096': 'Danish Chamber of Commerce Scheme (EDIRA compliant)',
	'0183': 'Swiss Unique Business Identification Number (UIDB)',
	'0184': 'DIGSTORG',
	'0188': 'Corporate Number of The Social Security and Tax Number System',
	'0190': 'Organisatie Identificatie Nummer (OIN)',
	'0191': 'Company Code (Estonia)',
	'0192': 'Organisasjonsnummer',
	'0193': 'UBL.BE Party Identifier',
	'0196': 'Icelandic identifier - Íslensk kennitala',
	'0198': 'ERSTORG',
	'0199': 'Legal Entity Identifier (LEI)',
	'0204': 'Leitweg-ID',
	'0208': "Numéro d'entreprise / ondernemingsnummer / Unternehmensnummer",
	'0210': 'Codice Fiscale',
	'0211': 'Partita IVA',
	'0212': 'Finnish Organization Identifier',
	'0213': 'Finnish Organization Value Add Tax Identifier',
};

export function partyIdSchemeName( code ) {

	code = String( code ?? '' ).trim();

	if ( code === '' ) return '';

	/**
	 * ⚠️ THE `t()` CALL STAYS, ALTHOUGH ALMOST NO SCHEME NAME IS TRANSLATED. "Codice Fiscale",
	 * "Leitweg-ID", "Organisasjonsnummer" are called that in every language and are missing from the generated
	 * tables on purpose; `t()` therefore returns them unchanged. Deciding here which name is
	 * translatable would mean maintaining a second list - the table already says it.
	 */
	const hit = PARTY_ID_SCHEME[ code ];

	return coded( hit === undefined ? '' : t( hit ), code );

}

export function eaSchemeName( code ) {

	code = String( code ?? '' ).trim();

	if ( code.toUpperCase() === 'EM' )
		return coded( t( 'Electronic mail' ), code );

	return partyIdSchemeName( code );

}

const NOTE_SUBJECT = {
	AAB: 'Payment term', AAI: 'General information',
	AAJ: 'Additional conditions of sale/purchase', AAK: 'Price conditions',
	AAR: 'Terms of delivery', ABL: 'Government information',
	ACB: 'Additional information', ACY: 'Introduction',
	ADU: 'Note', PMT: 'Payment information',
	REG: 'Regulatory information', SUR: 'Supplier remarks',
	TXD: 'Tax declaration',
};

export function noteSubjectName( code ) {

	code = String( code ?? '' ).trim();

	if ( code === '' ) return '';

	const hit = NOTE_SUBJECT[ code ];

	return coded( hit === undefined ? '' : t( hit ), code );

}

const REFERENCE_QUALIFIER = {
	ACE: 'Related document number',
	AED: 'Commodity number',
	ON: 'Order document identifier, buyer assigned',
	VN: 'Order number (vendor)',
};

export function referenceQualifierName( code ) {

	code = String( code ?? '' ).trim();

	if ( code === '' ) return '';

	const hit = REFERENCE_QUALIFIER[ code ];

	return coded( hit === undefined ? '' : t( hit ), code );

}
