// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Value formatting - line-for-line port of Context::date()/number()/amount()/unit_price() as used by
 * ModelBuilder.php, adapted to a plain default (no WP locale, no gettext) since this package carries
 * no i18n layer of its own (same approach as every other libs/einvoice-core-js port).
 *
 * ⚠️ Dates: udt:DateTimeString `@format="102"` (CCYYMMDD, UN/CEFACT's near-universal CII date
 * format) - confirmed the ONLY format value occurring anywhere in the checked corpus
 * (`grep format="\d+"` across all 23 EN16931 CII documents: 102, every time) - and the ISO 8601 date
 * of UBL ( see formatDate() ). An unrecognised format returns the raw string rather
 * than a silently wrong date.
 *
 * @since 3.2.0
 */

/**
 * Separators and date pattern per language.
 *
 * ⚠️ **THE GERMAN AND ENGLISH VALUES ARE MEASURED, NOT CHOSEN.** They are what WordPress itself
 * produces, read out of the shipped translation catalogues on 06.10.2026: `de_DE.mo` translates
 * `number_format_decimal_point` to U+002C and `number_format_thousands_sep` to U+002E, and the
 * English catalogue leaves both strings untranslated, which makes `WP_Locale` fall back to `.` and
 * `,`. A German or English site therefore sees exactly the same numbers as WordPress itself would
 * format.
 *
 * ⚠️ **The French thousands separator is a NO-BREAK SPACE ( U+00A0 ), not U+202F and not a plain
 * space.** French typography wants a narrow no-break space, and CLDR says U+202F - but WordPress's
 * own French catalogue supplies a no-break space, and these values exist to agree with WordPress and
 * with the PHP side, not with CLDR. A plain space would be wrong for a different reason: it lets a
 * line break fall between the digit groups.
 *
 * ⚠️ **`Context.php` carries the same table and must keep carrying the same values.** There is no
 * generator for these six values; the display-parity run over the corpus is what catches a drift
 * between the two copies.
 */

const FORMATS = {
	de: { thousands: '.',      decimal: ',', date: 'DD.MM.YYYY' },
	en: { thousands: ',',      decimal: '.', date: 'YYYY-MM-DD' },
	fr: { thousands: ' ', decimal: ',', date: 'DD/MM/YYYY' },
};

/**
 * The language the next format call works in.
 *
 * ⚠️ **THE MODULE STATE IS SAFE HERE FOR THE SAME REASON IT IS SAFE IN `Labels.mjs`, AND ONLY FOR
 * THAT REASON:** `ModelAssembler.build()` is fully synchronous, so one build runs from its first to
 * its last line without a second one getting in between. Whoever makes `build()` async has to rework
 * this file first and thread the language through the call chain.
 */

let current = 'en';


/**
 * Sets the language for the following format calls.
 *
 * An unknown code falls back to English, never to a guessed neighbouring language - same rule as
 * `Labels.setLanguage()`.
 *
 * @param {string} lang Two-letter code, e.g. 'de'. A full locale such as 'de_DE' is understood too.
 * @return {string} The language actually set.
 */

export function setFormatLanguage( lang ) {

	const short = String( lang ?? '' ).slice( 0, 2 ).toLowerCase();

	current = Object.prototype.hasOwnProperty.call( FORMATS, short ) ? short : 'en';

	return current;

}


/**
 * The separators and the date pattern of a language, for a caller that formats outside `build()`.
 *
 * ⚠️ **AN ARGUMENT, NOT THE MODULE STATE.** The group view ( `src/groupview/` ) is drawn with its own
 * language and must not depend on which language the last `build()` of the EN 16931 path left behind. It
 * reads the same table, so a German page shows "1.666,66" in both views; a copy of the table there would
 * be a third place that `Context.php`'s note above would have to name.
 *
 * @param {string} lang
 * @return {{thousands: string, decimal: string, date: string}}
 */

export function formatsFor( lang ) {

	const short = String( lang ?? '' ).slice( 0, 2 ).toLowerCase();

	return { ...( FORMATS[ short ] || FORMATS.en ) };

}


/**
 * @param {string|null} raw		The udt:DateTimeString text content.
 * @param {string|null} format	Its @format attribute.
 * @return {string} The date in the active language's pattern, or '' when absent/unparseable.
 */

export function formatDate( raw, format ) {

	if ( raw === null || raw === '' ) return '';

	/*
	 * ⚠️ A UBL DATE IS ISO 8601 ( `xs:date`, "2018-04-13" ) AND HAS NO FORMAT ATTRIBUTE AT ALL.
	 * The callers pass format '102' as the default for a missing format node, and the pattern below wants
	 * eight digits - so without this branch every UBL date falls through to "return raw" and stands in the
	 * German view as "2018-04-13" next to "13.04.2018" for the same invoice in CII ( measured 09.10.2026 ).
	 * The PHP side does not have the gap: it reads UBL through the conversion to CII, which writes format 102.
	 *
	 * ⚠️ An ISO date is recognised BEFORE the format check, because it is unambiguous whatever format is
	 * passed: format 102 never contains a hyphen. `xs:date` may carry a time zone ( "2018-04-13Z",
	 * "2018-04-13+01:00" ); it is dropped, as the conversion on the PHP side drops it.
	 */
	const iso = String( raw ).match( /^(\d{4})-(\d{2})-(\d{2})(?:Z|[+-]\d{2}:\d{2})?$/ );

	if ( iso )
		return pattern( iso[ 1 ], iso[ 2 ], iso[ 3 ] );

	if ( format !== '102' ) return raw; // unrecognised format - surfaced raw, not guessed

	const m = String( raw ).match( /^(\d{4})(\d{2})(\d{2})$/ );

	if ( ! m ) return raw;

	return pattern( m[ 1 ], m[ 2 ], m[ 3 ] );

}


/**
 * Year, month and day in the active language's pattern.
 *
 * @param {string} year
 * @param {string} month
 * @param {string} day
 * @return {string}
 */

function pattern( year, month, day ) {

	switch ( FORMATS[ current ].date ) {

		case 'DD.MM.YYYY': return `${day}.${month}.${year}`;
		case 'DD/MM/YYYY': return `${day}/${month}/${year}`;
		default:           return `${year}-${month}-${day}`;

	}

}


/**
 * Applies the active language's separators to a plain `toFixed()` result.
 *
 * ⚠️ **THE ORDER IS NOT A MATTER OF TASTE: GROUPING COMES LAST.** The callers trim trailing zeros
 * with a string operation, and the trimming has to happen while the number still looks like
 * "5900.0000". Group a German number first and the trailing-zero trim would start eating into
 * "5.900,00" from the right and the thousands dot would end up in the same character class as the
 * decimal comma.
 *
 * @param {string} fixed A `toFixed()` result, i.e. digits with an optional "." and nothing else.
 * @return {string}
 */

function applySeparators( fixed ) {

	const sep = FORMATS[ current ];

	const negative = fixed.startsWith( '-' );
	const body     = negative ? fixed.slice( 1 ) : fixed;

	const dot      = body.indexOf( '.' );
	const whole    = dot === -1 ? body : body.slice( 0, dot );
	const fraction = dot === -1 ? ''   : body.slice( dot + 1 );

	const grouped = whole.replace( /\B(?=(\d{3})+(?!\d))/g, sep.thousands );

	return ( negative ? '-' : '' ) + grouped + ( fraction === '' ? '' : sep.decimal + fraction );

}


/**
 * @param {string|number|null} value
 * @return {string} up to 4 decimals, trailing zeros/point trimmed - matches Context::number().
 */

export function formatNum( value ) {

	// ⚠️ null stays '' HERE, deliberately NOT zero-defaulted for every caller. horstoeko/invoicesuite
	// zero-defaults a numeric getter out-parameter ONLY when that specific getter runs
	// UNCONDITIONALLY and the source sub-element happens to be missing (e.g. BT-149's
	// getDocumentPositionNetPrice() always fires) - a getter call GUARDED behind its own
	// first…()-style existence check (e.g. BT-147's gross-price-discount branch) leaves PHP's
	// variable at its untouched null, rendering '' correctly ( measured against ModelBuilder.php's own
	// fixtures with compare-model-full.mjs, 13.09.2026 ).
	// ⚠️ NO CALL SITE PASSES `?? 0`. Every such zero would be invented, and the PHP side asks its XML
	// whether the value is stated ( ModelBuilder::stated() ).
	if ( value === null || value === '' ) return '';

	const n = Number( value );

	if ( Number.isNaN( n ) ) return '';

	let out = n.toFixed( 4 );

	// ⚠️ Trim first, group second - see applySeparators()'s own note on why the order is fixed.
	if ( out.indexOf( '.' ) !== -1 )
		out = out.replace( /0+$/, '' ).replace( /\.$/, '' );

	return applySeparators( out );

}


/**
 * @param {string|number|null} value
 * @param {string} currency
 * @return {string} always 2 decimals, grouped in the active language: "5,900.00 EUR" in English,
 *                  "5.900,00 EUR" in German, "5 900,00 EUR" in French - matches Context::amount().
 */

export function formatAmount( value, currency ) {

	// see formatNum()'s own comment - null stays '' by default here too, per-field exceptions applied
	// at the ModelAssembler.mjs call site.
	if ( value === null || value === '' ) return '';

	const n = Number( value );

	if ( Number.isNaN( n ) ) return '';

	const out = applySeparators( n.toFixed( 2 ) );

	return currency ? `${out} ${currency}` : out;

}


/**
 * BT-146/147/148 only - up to 4 decimals like formatNum(), with a currency suffix like
 * formatAmount() - matches Context::unit_price()'s own doc comment for why these three do not go
 * through formatAmount() despite also carrying a currency.
 *
 * @param {string|number|null} value
 * @param {string} currency
 * @return {string}
 */

export function formatUnitPrice( value, currency ) {

	if ( value === null || value === '' ) return '';

	const out = formatNum( value );

	return currency ? `${out} ${currency}` : out;

}


/**
 * @param {string|number|null} value
 * @return {boolean}
 */

export function isZero( value ) {

	return value === null || value === '' || Number( value ) === 0;

}
