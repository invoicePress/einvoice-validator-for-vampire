// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Client-side port of `einvoice-viewer-identifier-check`'s own `SPIC_Extractor::extract_from_reader()`
 * (PHP). Extracts the identifier fields the identifier check covers (BT-29/
 * 30/31/32/34/46/47/48/49/63/71/84/86/90/91/157) DIRECTLY from the catalog tree, not from the rendered
 * display model - the same reason the PHP original reads the raw `$reader` instead of the finished
 * model: `ModelAssembler.mjs`'s own party_id_scheme_name()-equivalent lookups (Codelists.mjs) translate
 * a raw ISO 6523 ICD code like "0088" into a human label ("EAN Location Code") before it reaches the
 * display model - there is no reliable way back to "0088" from a translated label, and certainly not
 * from a German or French one.
 *
 * ⚠️ NOT A SECOND PARSE. This reads the SAME `tree`/`reader` `<einvoice-viewer>`'s own build() already
 * produced for the SAME upload - a narrow set of extra catalog lookups (scoped to identifier-bearing
 * fields only), not a parallel TreeReader run.
 *
 * @since 3.4.0
 */

import { V, all, allIn, VIn, firstValue } from './Accessor.mjs';


/**
 * @param {object} tree
 * @param {import('./TreeReader.mjs').TreeReader} reader
 * @return {Array<{bt:string, value:string, schemeType:string, schemeCode:string, line:number|null}>}
 */

export function extractIdentifiers( tree, reader ) {

	const out = [];

	sellerAndBuyer( tree, reader, out );
	taxRepresentative( tree, reader, out );
	delivery( tree, reader, out );
	payment( tree, reader, out );
	positions( tree, reader, out );

	return out.filter( ( x ) => x !== null );

}


/**
 * BT-29..34 (seller, BG-4) and BT-46..49 (buyer, BG-7) - the exact same catalog ids
 * `ModelAssembler.mjs`'s own partyCard() reads, just kept unlabelled/untranslated here.
 */

function sellerAndBuyer( tree, reader, out ) {

	for ( const isSeller of [ true, false ] ) {

		const idBt        = isSeller ? 'BT-29' : 'BT-46';
		const globalIdBt   = isSeller ? 'BT-29-0' : 'BT-46-0';
		const globalIdBt1  = isSeller ? 'BT-29-1' : 'BT-46-1';
		const legBt        = isSeller ? 'BT-30' : 'BT-47';
		const legBt1       = isSeller ? 'BT-30-1' : 'BT-47-1';
		const vatBt        = isSeller ? 'BT-31' : 'BT-48';
		const eaBt         = isSeller ? 'BT-34' : 'BT-49';
		const eaBt1        = isSeller ? 'BT-34-1' : 'BT-49-1';

		// ⚠️ SAME PREFERENCE ORDER AS THE PHP ORIGINAL: GlobalID (+ its @schemeID) wins over the bare
		// ID, which is reported with an EMPTY scheme rather than guessed - identical "don't fabricate"
		// rule as everywhere else in this project.
		// ⚠️ firstValue() and not V(): in UBL each identifier has a
		// `cac:PartyIdentification` of its own, and the one with or without a scheme may stand in the second.
		// For CII both calls return the same, see firstValue() in Accessor.mjs.
		const globalId = firstValue( tree, reader, globalIdBt );

		if ( globalId !== null && globalId !== '' )
			out.push( identifier( idBt, globalId, 'icd', firstValue( tree, reader, globalIdBt1 ) ?? '' ) );
		else {
			const bareId = firstValue( tree, reader, idBt );
			if ( bareId !== null && bareId !== '' )
				out.push( identifier( idBt, bareId, 'icd', '' ) );
		}

		// BT-30 / BT-47 - legal registration identifier
		const legId = V( tree, reader, legBt );
		if ( legId !== null && legId !== '' )
			out.push( identifier( legBt, legId, 'icd', V( tree, reader, legBt1 ) ?? '' ) );

		// BT-31 / BT-48 (VAT) - the catalog already disambiguates the VA-scheme tax registration by
		// sibling rank (see cii-nodes.json's own BT-31 vs BT-32 parent ids), so no manual scheme
		// check is needed here, unlike the PHP original's own loop over a raw scheme=>value map.
		const vat = V( tree, reader, vatBt );
		if ( vat !== null && vat !== '' )
			out.push( identifier( vatBt, vat, 'vat', '' ) );

		// BT-32 - seller-only tax number (FC scheme), same "already disambiguated by the catalog" note.
		if ( isSeller ) {
			const taxNo = V( tree, reader, 'BT-32' );
			if ( taxNo !== null && taxNo !== '' )
				out.push( identifier( 'BT-32', taxNo, 'freetext', '' ) );
		}

		// BT-34 / BT-49 (electronic address, EAS-scheme)
		const ea = V( tree, reader, eaBt );
		if ( ea !== null && ea !== '' )
			out.push( identifier( eaBt, ea, 'eas', V( tree, reader, eaBt1 ) ?? '' ) );

	}

}


/**
 * BT-63 - the seller's tax representative VAT identifier (BG-11). Same "already disambiguated by the
 * catalog" reasoning as BT-31/BT-48 above.
 */

function taxRepresentative( tree, reader, out ) {

	const vat = V( tree, reader, 'BT-63' );

	if ( vat !== null && vat !== '' )
		out.push( identifier( 'BT-63', vat, 'vat', '' ) );

}


/**
 * BT-71 - the deliver-to location identifier (BG-13).
 *
 * ⚠️ NO SCHEME READ HERE - matches the PHP original's own documented limitation
 * (ModelBuilder::delivery_card() never calls a GlobalId-style getter for Ship To at all), not because
 * the catalog cannot provide one. Kept identical to the measured PHP baseline rather than "improved"
 * unilaterally - see this project's own STATUS.md convention on not silently diverging from a sibling's
 * documented behaviour.
 */

function delivery( tree, reader, out ) {

	const id = V( tree, reader, 'BT-71' );

	if ( id !== null && id !== '' )
		out.push( identifier( 'BT-71', id, 'icd', '' ) );

}


/**
 * BT-84 (payee IBAN), BT-86 (payee BIC), BT-90 (SEPA creditor reference), BT-91 (debited account IBAN) -
 * same BG-16 occurrence loop `readPaymentMeans()` elsewhere in this package already uses.
 */

function payment( tree, reader, out ) {

	const creditorRef = V( tree, reader, 'BT-90' );

	if ( creditorRef !== null && creditorRef !== '' )
		out.push( identifier( 'BT-90', creditorRef, 'sepa-creditor-id', '' ) );

	let buyerIbanSeen = false;

	for ( const means of all( tree, reader, 'BG-16' ) ) {

		const payeeIban = VIn( means, reader, 'BG-16', 'BT-84' );
		if ( payeeIban !== null && payeeIban !== '' )
			out.push( identifier( 'BT-84', payeeIban, 'iban', '' ) );

		const payeeBic = VIn( means, reader, 'BG-16', 'BT-86' );
		if ( payeeBic !== null && payeeBic !== '' )
			out.push( identifier( 'BT-86', payeeBic, 'bic', '' ) );

		// BT-91 is the DEBITED account for a direct debit - only the first one seen, matching the PHP
		// original's own "break on first match" behaviour (SEPA debit is 0..1 in practice; a repeat
		// occurrence past the first is not a second, DIFFERENT debited account worth reporting again).
		const buyerIban = VIn( means, reader, 'BG-16', 'BT-91' );
		if ( ! buyerIbanSeen && buyerIban !== null && buyerIban !== '' ) {
			out.push( identifier( 'BT-91', buyerIban, 'iban', '' ) );
			buyerIbanSeen = true;
		}

	}

}


/**
 * BT-157 - the item standard identifier (BG-31, one per invoice line). Scheme dispatch is ICD-shaped,
 * same as the PHP original.
 */

function positions( tree, reader, out ) {

	let index = 0;

	for ( const lineScope of all( tree, reader, 'BG-25' ) ) {

		index++;

		const gid = VIn( lineScope, reader, 'BG-25', 'BT-157' );

		if ( gid !== null && gid !== '' )
			out.push( identifier( 'BT-157', gid, 'icd', VIn( lineScope, reader, 'BG-25', 'BT-157-1' ) ?? '', index ) );

	}

}


/**
 * @param {string} bt
 * @param {string} value
 * @param {string} schemeType
 * @param {string} schemeCode
 * @param {number|null} [line]
 * @return {{bt:string, value:string, schemeType:string, schemeCode:string, line:number|null}|null}
 *			null when $value is empty after trimming - never pushed into the result by the callers
 *			above in the first place, but kept as a second guard matching the PHP original's own
 *			identifier() helper.
 */

function identifier( bt, value, schemeType, schemeCode, line = null ) {

	value = String( value ?? '' ).trim();

	if ( value === '' ) return null;

	return { bt, value, schemeType, schemeCode: String( schemeCode ?? '' ).trim(), line };

}
