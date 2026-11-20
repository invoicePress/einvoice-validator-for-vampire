// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Recomputes the totals of a RECEIVED invoice from its own parts and reports where the stated value
 * differs - the arithmetic hint in the document view.
 *
 * Line-for-line twin of libs/einvoice-model/php/TotalsCheck.php. Both builders feed the same raw
 * values in the same order, and `test/compare-model-full.mjs` holds the two resulting models against
 * each other, so a difference in either file shows up as a parity failure.
 *
 * ⚠️ **THIS IS NOT A SECOND TotalsCalculator.** libs/einvoice-model's TotalsCalculator BUILDS a
 * consistent invoice from unit prices: every total there is derived from the one below it, so it
 * cannot say WHICH stated value is wrong - fed a broken invoice it simply produces a correct one. A
 * check needs the opposite: each stated value is compared with what its OWN direct parts add up to,
 * and those parts are again the STATED values one level down. A wrong BT-106 then marks BT-106 alone
 * and not also BT-109, BT-112 and BT-115 behind it. What is reused is the rounding: `money()` below
 * is a verbatim copy of TotalsCalculator.mjs's, see there.
 *
 * ⚠️ **THE TOLERANCES ARE THE STANDARD'S, NOT OURS** - a false alarm on an invoice a validator
 * accepts is worse than no hint at all ( measured on the corpus, see STATUS.md, 08.10.2026 ):
 *
 *   BT-106 … BT-115, BT-116   exact to the cent   BR-CO-10 … BR-CO-16, BR-S-08 / BR-AE-08 / …
 *   BT-117                    ± 1 currency unit    BR-CO-17 ( `abs(BT-117) - 1 <= … <= abs(BT-117) + 1` )
 *   BT-131                    ± 0.02               EN 16931 has no rule; PEPPOL-EN16931-R120 allows 0.02
 *
 * @since 0.16.0
 */

/** Exact to the cent - a half cent separates "equal after rounding" from "one cent out". */
export const TOLERANCE_EXACT = 0;

/** BR-CO-17 - the CEN schematron accepts BT-117 within one currency unit of BT-116 × rate. */
export const TOLERANCE_TAX = 1;

/** PEPPOL-EN16931-R120 - the only published rule on BT-131, with its own slack of 0.02. */
export const TOLERANCE_LINE = 0.02;

/**
 * Rounds to two decimals, half away from zero.
 *
 * ⚠️ **A VERBATIM COPY OF `money()` IN libs/einvoice-model/js/TotalsCalculator.mjs, AND IT HAS TO STAY
 * ONE.** That file explains why the rounding digit is read from a string and never from a float
 * multiplication ( 11.42 × 400.25 is exactly 4570.855 in decimal but 4570.85499… as a double ). It is
 * copied and not imported because `@einvoice/core` ships `src/` as an npm package and must not reach
 * into a sibling library's folder. `test/totals-check.test.mjs` compares both functions on the same
 * values, so a change to one of them fails there.
 *
 * ⚠️ `+ 0` at the end turns a negative zero into zero. `formatAmount( -0 )` prints "0.00", but a
 * check that compares `Object.is()` or a JSON dump would see two different values.
 *
 * @param {number} value
 * @return {number}
 */
export function money( value ) {

	const n = Number( value );

	if ( ! Number.isFinite( n ) ) return 0;

	const sign = n < 0 ? -1 : 1;
	const fixed = Math.abs( n ).toFixed( 10 );
	const dot = fixed.indexOf( '.' );
	const intPart = fixed.slice( 0, dot );
	const frac = fixed.slice( dot + 1 );
	const kept = frac.slice( 0, 2 ).padEnd( 2, '0' );
	const roundDigit = frac.charCodeAt( 2 ) - 48;

	let cents = BigInt( intPart ) * 100n + BigInt( kept );

	if ( roundDigit >= 5 )
		cents += 1n;

	return sign * ( Number( cents ) / 100 ) + 0;

}

/**
 * A raw value as a number, or null when the document does not carry it.
 *
 * ⚠️ **`''` IS "ABSENT", NOT ZERO.** `Number( '' )` is 0, and a missing BT-113 would then look like a
 * stated "0.00" - harmless for BT-113, but a missing BT-131 compared against a calculated 100.00
 * would mark a line as wrong that only lacks a value.
 *
 * @param {*} value
 * @return {number|null}
 */
function num( value ) {

	if ( value === null || value === undefined ) return null;

	const s = String( value ).trim();

	if ( s === '' ) return null;

	const n = Number( s );

	return Number.isFinite( n ) ? n : null;

}

/**
 * The group key of a VAT category and rate.
 *
 * ⚠️ **THE RATE IS COMPARED AS A NUMBER, NOT AS TEXT.** A line says `19`, the breakdown `19.00`; as
 * strings they would form two groups and every breakdown line would be "wrong".
 *
 * @param {*} category
 * @param {*} rate
 * @return {string}
 */
function groupKey( category, rate ) {

	const r = num( rate );

	return String( category ?? '' ).trim().toUpperCase() + '|' + String( r === null ? 0 : r );

}

/**
 * The comparison itself.
 *
 * ⚠️ **THE STATED VALUE IS ROUNDED BEFORE THE COMPARISON.** BR-DEC allows two decimals; a document
 * that writes `100.000` must not be "wrong by 0.000". Rounding both sides to the cent and then
 * comparing with a tolerance plus a millionth absorbs the binary noise of the subtraction itself
 * ( 0.1 + 0.2 - 0.3 is 5.5e-17, not 0 ).
 *
 * @param {number} stated
 * @param {number} calculated
 * @param {number} tolerance
 * @return {{stated: number, calculated: number}|null}
 */
function compare( stated, calculated, tolerance ) {

	const s = money( stated );
	const c = money( calculated );

	if ( Math.abs( s - c ) <= tolerance + 0.000001 ) return null;

	return { stated: s, calculated: c };

}

/**
 * Collects the parts of one invoice while the model is built and checks each total once its parts
 * are known.
 *
 * ⚠️ **THE ORDER OF THE CALLS IS THE ORDER OF THE MODEL, AND IT IS NOT FREE.** Lines first, then the
 * document allowances and charges, then the VAT breakdown, then the totals - exactly the order in
 * which `ModelAssembler.build()` and `ModelBuilder::build()` produce their sections. Each check only
 * looks at parts that are already recorded; calling `totals()` before `taxes()` would compare BT-110
 * with an empty sum.
 */
export class TotalsCheck {

	/**
	 * ⚠️ **AN EXTENSION SWITCHES OFF THE SUMS OVER PARTS: BT-106, BT-107, BT-108 AND BT-116.** A
	 * specification identifier ( BT-24 ) with `#conformant#` declares an EXTENSION of EN 16931, and an
	 * extension may add parts that EN 16931 does not know: sub-lines whose amounts are already inside
	 * their group line ( ZUGFeRD EXTENDED `SubInvoiceLine`, the XRechnung extension ), or logistics
	 * charges that count towards BT-108 without being a BG-21. Summing only the EN 16931 parts then
	 * reports a correct invoice as wrong. Measured on 08.10.2026: all 25 EXTENDED documents of the
	 * corpus carry `#conformant#`, and without this switch 7 of them were flagged at BT-106, BT-108 or
	 * BT-116 although the official Factur-X samples are valid. The header identities ( BT-109, BT-110,
	 * BT-112, BT-115 ), BT-117 and BT-131 stay checked - an extension does not change them.
	 *
	 * @param {{line?: number, tax?: number, extension?: boolean}} [options] `line` and `tax` only for
	 *        measurements and tests - the viewer never passes them, and the defaults are the
	 *        standard's ( see the file header ). `extension` comes from BT-24.
	 */
	constructor( options = {} ) {

		this.toleranceLine = options.line ?? TOLERANCE_LINE;
		this.toleranceTax = options.tax ?? TOLERANCE_TAX;
		this.extension = options.extension === true;

		this.lineCount = 0;     // every BG-25, with or without BT-131
		this.netMissing = false; // at least one line without BT-131 - BT-106 cannot be summed
		this.lineNets = [];      // [ { net, key } ]
		this.allowances = [];    // [ { amount, key } ]  BG-20
		this.charges = [];       // [ { amount, key } ]  BG-21
		this.taxAmounts = [];    // BT-117 as stated
		this.incomplete = false; // a part without amount or category - group sums are not reliable

	}

	/**
	 * One invoice line ( BG-25 ). Returns the BT-131 finding.
	 *
	 * ⚠️ **BT-149 MISSING OR ZERO MEANS 1.** EN 16931 makes the price base quantity optional with a
	 * default of one, and the PHP reader zero-defaults it when absent. Dividing by that zero would
	 * produce Infinity and a red mark on every line without a base quantity.
	 *
	 * @param {object} line { net, quantity, price, baseQuantity, allowances: [], charges: [], category, rate }
	 * @return {{stated: number, calculated: number}|null}
	 */
	line( line ) {

		const net = num( line.net );
		const category = String( line.category ?? '' ).trim();

		this.lineCount++;

		if ( net === null ) this.netMissing = true;
		if ( net === null || category === '' ) this.incomplete = true;

		if ( net !== null ) this.lineNets.push( { net, key: groupKey( category, line.rate ) } );

		const quantity = num( line.quantity );
		const price = num( line.price );

		if ( net === null || quantity === null || price === null ) return null;

		const base = num( line.baseQuantity );
		const divisor = base === null || base === 0 ? 1 : base;

		let adjust = 0;
		for ( const c of line.charges || [] ) adjust += num( c ) ?? 0;
		for ( const a of line.allowances || [] ) adjust -= num( a ) ?? 0;

		// ⚠️ `quantity * price / divisor`, left to right, and the same order in PHP - a different
		// order can land on a different double and, on a half cent, on a different cent.
		return compare( net, quantity * price / divisor + adjust, this.toleranceLine );

	}

	/**
	 * One document-level allowance ( BG-20 ) or charge ( BG-21 ).
	 *
	 * @param {boolean} isCharge
	 * @param {*} amount   BT-92 / BT-99
	 * @param {*} category BT-95 / BT-102
	 * @param {*} rate     BT-96 / BT-103
	 * @return {void}
	 */
	allowanceCharge( isCharge, amount, category, rate ) {

		const a = num( amount );

		if ( a === null || String( category ?? '' ).trim() === '' ) this.incomplete = true;

		( isCharge ? this.charges : this.allowances ).push( { amount: a ?? 0, key: groupKey( category, rate ) } );

	}

	/**
	 * The VAT breakdown ( BG-23 ). Returns one `{ basis, tax }` pair of findings per entry.
	 *
	 * ⚠️ **TWO ENTRIES WITH THE SAME CATEGORY AND RATE GET NO BT-116 CHECK.** Both would be compared
	 * with the same line sum, and at least one of them is then "wrong" although the document may only
	 * split one category by exemption reason. Which of the two carries which lines cannot be told
	 * from the parts.
	 *
	 * @param {Array<object>} entries [ { category, rate, basis, tax } ]
	 * @return {Array<{basis: object|null, tax: object|null}>}
	 */
	taxes( entries ) {

		const keyCount = {};
		for ( const e of entries ) {
			const k = groupKey( e.category, e.rate );
			keyCount[ k ] = ( keyCount[ k ] || 0 ) + 1;
		}

		return entries.map( ( e ) => {

			const key = groupKey( e.category, e.rate );
			const basis = num( e.basis );
			const tax = num( e.tax );
			const rate = num( e.rate );

			if ( tax !== null ) this.taxAmounts.push( tax );

			let basisFinding = null;

			if ( basis !== null && ! this.extension && ! this.incomplete && keyCount[ key ] === 1 ) {

				let lines = 0, charges = 0, allowances = 0;

				for ( const l of this.lineNets ) if ( l.key === key ) lines += l.net;
				for ( const c of this.charges ) if ( c.key === key ) charges += c.amount;
				for ( const a of this.allowances ) if ( a.key === key ) allowances += a.amount;

				// ⚠️ Each sum rounded on its own, as BR-S-08 does: round( Σ lines ) + round( Σ charges ) - round( Σ allowances ).
				basisFinding = compare( basis, money( lines ) + money( charges ) - money( allowances ), TOLERANCE_EXACT );

			}

			let taxFinding = null;

			if ( basis !== null && tax !== null && rate !== null )
				taxFinding = compare( tax, basis * rate / 100, this.toleranceTax );

			return { basis: basisFinding, tax: taxFinding };

		} );

	}

	/**
	 * The document totals ( BG-22 ). Returns a finding or null per business term.
	 *
	 * ⚠️ **A MISSING OPTIONAL TOTAL COUNTS AS ZERO, A MISSING RESULT MEANS "NO CHECK".** BT-107, BT-108,
	 * BT-113 and BT-114 may be left out, and the schematron then treats them as zero ( the "or not(…)"
	 * branches of BR-CO-13 and BR-CO-16 ). The total being checked must be there, though - an absent
	 * BT-115 is a different finding, and the validator reports it.
	 *
	 * @param {object} t { lineTotal, allowanceTotal, chargeTotal, taxBasis, taxTotal, grand, prepaid, rounding, due }
	 * @return {object} { 'BT-106': …, 'BT-107': …, 'BT-108': …, 'BT-109': …, 'BT-110': …, 'BT-112': …, 'BT-115': … }
	 */
	totals( t ) {

		const v = {
			lineTotal: num( t.lineTotal ), allowanceTotal: num( t.allowanceTotal ), chargeTotal: num( t.chargeTotal ),
			taxBasis: num( t.taxBasis ), taxTotal: num( t.taxTotal ), grand: num( t.grand ),
			prepaid: num( t.prepaid ), rounding: num( t.rounding ), due: num( t.due ),
		};

		const out = { 'BT-106': null, 'BT-107': null, 'BT-108': null, 'BT-109': null, 'BT-110': null, 'BT-112': null, 'BT-115': null };

		const sum = ( list, field ) => {
			let s = 0;
			for ( const e of list ) s += e[ field ];
			return money( s );
		};

		// BR-CO-10 - only when every line carries its net amount, otherwise the sum is short.
		if ( v.lineTotal !== null && ! this.extension && this.lineCount > 0 && ! this.netMissing )
			out[ 'BT-106' ] = compare( v.lineTotal, sum( this.lineNets, 'net' ), TOLERANCE_EXACT );

		// BR-CO-11 / BR-CO-12
		if ( v.allowanceTotal !== null && ! this.extension ) out[ 'BT-107' ] = compare( v.allowanceTotal, sum( this.allowances, 'amount' ), TOLERANCE_EXACT );
		if ( v.chargeTotal !== null && ! this.extension ) out[ 'BT-108' ] = compare( v.chargeTotal, sum( this.charges, 'amount' ), TOLERANCE_EXACT );

		// BR-CO-13
		if ( v.taxBasis !== null && v.lineTotal !== null )
			out[ 'BT-109' ] = compare( v.taxBasis, v.lineTotal - ( v.allowanceTotal ?? 0 ) + ( v.chargeTotal ?? 0 ), TOLERANCE_EXACT );

		// BR-CO-14
		if ( v.taxTotal !== null && this.taxAmounts.length ) {
			let s = 0;
			for ( const x of this.taxAmounts ) s += x;
			out[ 'BT-110' ] = compare( v.taxTotal, s, TOLERANCE_EXACT );
		}

		// BR-CO-15
		if ( v.grand !== null && v.taxBasis !== null )
			out[ 'BT-112' ] = compare( v.grand, v.taxBasis + ( v.taxTotal ?? 0 ), TOLERANCE_EXACT );

		// BR-CO-16
		if ( v.due !== null && v.grand !== null )
			out[ 'BT-115' ] = compare( v.due, v.grand - ( v.prepaid ?? 0 ) + ( v.rounding ?? 0 ), TOLERANCE_EXACT );

		return out;

	}

}
