// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The translation layer of the invoice model - the counterpart of `Context::t()` on the PHP side.
 *
 * ⚠️ **WHY IT HAS TO EXIST AT ALL.** The invoice view is rendered completely in the browser. Without a
 * translation layer in this package a German page would show English field labels, while the
 * translations sit complete in the viewer's `.po` and nobody reads them.
 *
 * ⚠️ **THE MODULE STATE IS SAFE HERE, AND ONLY HERE.** `setLanguage()` sets a variable that
 * `t()` reads afterwards - taken on its own, exactly the pattern that gives two concurrent renders
 * each other's language. It holds because `ModelAssembler.build()` is **completely
 * synchronous**: no `async`, no `await`, no `Promise` in the whole file ( measured
 * 05.10.2026 ). JavaScript is single-threaded, so one `build()` runs from the first to the last
 * line without a second one getting in between - the same reasoning with which PHP keeps the state on the
 * `Context` instance per request.
 *
 * ⚠️ Whoever ever makes `build()` `async` must rebuild this file first - then the reasoning
 * no longer holds, and the language must be passed through the call chain.
 *
 * @since 0.9.11
 */

/**
 * ⚠️ **THERE ARE NO BAKED-IN TABLES.** The language tables are loaded as files
 * `i18n/<lang>.json.js` next to the bundle. Baking them into each bundle cost 17.2 and 17.8 KB per
 * bundle, in five bundles, each the same.
 *
 * ⚠️ `en` HAS NO TABLE, AND THAT IS NOT AN OVERSIGHT. The source texts in the code **are** English;
 * a table that maps every text onto itself would be a second place where English could
 * differ.
 *
 * ⚠️ **WHAT THIS MEANS FOR A CONSUMER THAT LOADS NOTHING.** It gets English, and throughout -
 * not a half-translated page. Every consumer loads the tables: the four plugins that ship a bundle all
 * carry a folder `i18n/` with three files, and the three apps embed the tables in the single file or
 * link them in the folder package. Whoever includes a bundle elsewhere must take the files along.
 *
 * ⚠️ **MEASURED BEFORE THE BAKED-IN TABLES WERE REMOVED, NOT ASSUMED** ( 06.10.2026 ). The loaded table of the
 * viewer listed 315 keys against 290 baked-in ones in German and 283 in French, with **zero**
 * differing texts. The only key that only the old table knew was the same sentence of the settings
 * page with the shortcode written out instead of the placeholder `%s` - a text this bundle never reads.
 */

/** The language the next `t()` answers in. */
let current = 'en';

/** The table for it - resolved once, not looked up again on every `t()`. */
let table = null;


/**
 * Sets the language for the following translations.
 *
 * An unknown code falls back to English, exactly as `SPEV_Plugin::tooltip_lang()` does on the
 * PHP side - never to a guessed neighbouring language.
 *
 * @param {string} lang Two-letter code, e.g. 'de'. 'de_DE' is understood as well.
 * @return {string} The language actually set.
 */

/**
 * The loaded table of a language, if there is one.
 *
 * ⚠️ **A FILE BESIDE THE BUNDLE IS THE ONLY SOURCE.** A file `i18n/<lang>.json.js` next to the bundle
 * registers itself in `globalThis.einvoiceI18n`. A fourth language is thus a file that is added, not a
 * change to the code. If the file is missing, `t()` answers with the English source text.
 *
 * @param {string} lang
 * @return {object|null}
 */

function nachgeladen( lang ) {

	const alle = globalThis.einvoiceI18n;

	if ( ! alle || typeof alle !== 'object' ) return null;

	const tabelle = alle[ lang ];

	return ( tabelle && typeof tabelle === 'object' ) ? tabelle : null;

}


/**
 * The languages that are available at all.
 *
 * ⚠️ **THE LIST COMES FROM THE LOADED FILE.** `i18n/index.json.js` names the languages, so the list
 * stands in **one** place instead of a fixed `['de','en','fr']` in many places of the source
 * ( 25 such places were counted on 06.10.2026 ). English is always included, because the source texts
 * in the code are English.
 *
 * @return {string[]}
 */

function verfuegbar() {

	const alle = globalThis.einvoiceI18n;

	if ( alle && Array.isArray( alle.available ) && alle.available.length )
		return alle.available;

	/*
	 * ⚠️ **WITHOUT A LOADED LIST THERE IS ONLY ENGLISH, AND THAT IS THE TRUTH, NOT POVERTY.**
	 * A list that names languages for which no texts are available would give a language switcher
	 * buttons that do nothing.
	 */
	return [ 'en' ];

}


export function setLanguage( lang ) {

	// 'de_DE' and 'de-DE' are valid input - the caller should not have to take a locale apart.
	const short = String( lang ?? '' ).slice( 0, 2 ).toLowerCase();

	current = verfuegbar().indexOf( short ) >= 0 ? short : 'en';

	// ⚠️ English has no table and therefore gets `null` - `t()` then returns the source text.
	table = current === 'en' ? null : ( nachgeladen( current ) ?? null );

	return current;

}


/**
 * The languages this bundle can offer.
 *
 * Meant for a language switcher that should not have to know which languages exist - it asks.
 *
 * @return {string[]}
 */

export function languages() {

	return verfuegbar();

}


/**
 * Die eingestellte Sprache.
 *
 * @return {string}
 */

export function language() {

	return current;

}


/**
 * Translates a source text.
 *
 * ⚠️ **A MISSING ENTRY RETURNS THE SOURCE TEXT, NOT AN EMPTY STRING.** That is the behaviour of
 * `__()` itself and the reason why the generated tables do not carry proper names
 * ( "IBAN", "BIC", "Codice Fiscale" ) at all: for them the source text is the right answer in
 * every language.
 *
 * @param {string} text The English source text.
 * @return {string}
 */

export function t( text ) {

	if ( table === null ) return text;

	const hit = table[ text ];

	return ( typeof hit === 'string' && hit !== '' ) ? hit : text;

}


/**
 * The separator between context and text in a table key, the same byte gettext uses ( EOT, `\x04` ).
 *
 * ⚠️ **NOT A CHOICE OF TASTE.** `_claude-tools/po2mo.php` writes a context entry into the `.mo` as
 * `msgctxt` + `\x04` + `msgid`, and `_claude-tools/i18n-build.php` builds the browser tables with the
 * same key. Any other separator would make the browser look for a key that no table holds, and `tx()`
 * would fall back to English without a message.
 */
export const CONTEXT_SEPARATOR = '\u0004';


/**
 * Translates a source text in a context - the counterpart of `_x()` in PHP.
 *
 * ⚠️ **WHY THIS EXISTS.** "Note" is two different words in German. The
 * invoice view labels the invoice note BT-22 "Bemerkung"; the inbound check marks the lowest severity of a
 * finding "Hinweis". Both packages translate the same English key, and the inbound plugin and apps merge
 * the viewer's table under their own, so without a context the later table wins and the invoice note is
 * headed "Hinweis" ( measured 08.10.2026 ). With a context the two meanings have two keys, and the order
 * of the tables no longer matters.
 *
 * ⚠️ **ONLY THE SIDE THAT NEEDS IT CARRIES A CONTEXT.** The viewer's "Note" is the label of the PHP model
 * as well ( `Context.php`, `ModelBuilder.php` ) and stays a plain key; the inbound side asks with a
 * context. Giving both sides a context would change the PHP model and its parity tests for nothing.
 *
 * ⚠️ **A MISSING CONTEXT ENTRY GIVES THE ENGLISH TEXT, NOT `t( text )`.** That is what gettext does, and
 * it is the point: falling back to the plain key would hand the finding the invoice view's "Bemerkung"
 * again, the exact mix-up this function removes. `check-languages.php` reports a missing context entry
 * like any other missing text.
 *
 * ⚠️ **THE CALL MUST BE WRITTEN WITH TWO LITERALS IN SINGLE QUOTES**, text first, context second.
 * `_claude-tools/js-texts-to-php.php` reads exactly this form and writes an `_x()` into the anchor file,
 * so the `.pot` gets the entry with its `msgctxt`. ⚠️ The scanner reads comments too; an example call
 * with single quotes in a comment is picked up as a text of the viewer ( measured 08.10.2026 ), which is
 * why none stands here.
 *
 * @param {string} text    The English source text.
 * @param {string} context A short description of the meaning, in English, as `_x()` takes it.
 * @return {string}
 */

export function tx( text, context ) {

	if ( table === null ) return text;

	const hit = table[ String( context ) + CONTEXT_SEPARATOR + text ];

	return ( typeof hit === 'string' && hit !== '' ) ? hit : text;

}


/**
 * Translates a source text with placeholders and inserts the values.
 *
 * ⚠️ **THE PLACEHOLDERS ARE THOSE OF `sprintf()` IN PHP, AND THAT IS THE WHOLE REASON FOR THIS
 * FUNCTION.** The source text stands as `__()` in a PHP file of the plugin and goes through the
 * `.pot` to a translator. The translator knows `%s` and `%1$s` and may reorder with the numbers;
 * a German sentence often puts the verb at the end. An own notation like `{name}`
 * would be understood by nobody there, and translate.wordpress.org would not check it.
 *
 * ⚠️ **TRANSLATE FIRST, THEN INSERT.** The other way round the invoice amount would already stand in
 * the key, and no table would have an entry for exactly that amount.
 *
 * Supported are `%s`, `%d` and the numbered forms `%1$s`, `%2$d`; `%%` gives a
 * percent sign. None of the sentences needs more.
 *
 * @param {string} text  The English source text with placeholders.
 * @param {...*}   werte The values in the order of the source text.
 * @return {string}
 */

export function tf( text, ...werte ) {

	let naechster = 0;

	return t( text ).replace( /%(?:(\d+)\$)?([sd%])/g, ( ganz, nummer, art ) => {

		if ( art === '%' ) return '%';

		const i = nummer ? Number( nummer ) - 1 : naechster++;

		const wert = werte[ i ];

		return wert === undefined || wert === null ? '' : String( wert );

	} );

}
