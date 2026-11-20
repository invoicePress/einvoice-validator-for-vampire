// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The button "Validate with …" of a validator plugin in a host of interface 8, in WordPress: the plugin checks on its own
 * website, with the Java validator or the Validator Bridge entered on its settings page, and draws the report with its
 * own renderer into a tab of the host.
 *
 *   ( window.einvoiceSiteValidators ||= [] ).push( { engine, prefix, render, ajaxUrl, texts, xmlOnly } )   by the plugin
 *   registerSiteValidators()                                                                             by this bundle
 *
 * ⚠️ **UP TO THE VIEWER 2.x THE VIEWER DREW THESE BUTTONS** and posted to `spko_validate`, `spmv_validate` and
 * `sppv_validate` itself ( `spev-uploader.js` ). Now each validator plugin brings its button, and the viewer knows none of
 * them by name. The website stays the source: the plugin checks with the engine its settings page names, and never
 * with another one ( Ralf, 28.10.2026: no source means no check ).
 *
 * ⚠️ **ONE BUNDLE, SEVERAL COPIES, EACH ENTRY ONCE.** Each validator plugin ships this bundle and pushes its own entry into
 * the list before it. Every copy that runs registers the entries nobody has claimed yet and marks them, so two copies on one
 * page never give a validator two buttons.
 *
 * ⚠️ **THE RENDERER IS THE PLUGIN'S** ( `window.SPKO_renderReport`, `SPMV_renderReport`, `SPPV_renderReport` ), the same
 * function the plugin's own page uses. The Invoice Monitor listens at these functions and keeps the report with the
 * invoice ( `wp-ledger.mjs` ), so drawing through them is what files the report.
 *
 * ⚠️ **THE XML OF A ZUGFeRD PDF, NOT THE PDF.** KoSIT accepts only XML; Mustang and VAMPIRE get the XML the viewer read out
 * of the PDF as well, as the viewer did since 2.38.1.
 */

/** The names of the tabs are product names and stay the same in every language, with the order of the viewer 2.x. */
const TABS = {
	kosit: { title: 'KoSIT Validator', order: 110, name: 'KoSIT Validator' },
	mustang: { title: 'Mustang Validator', order: 120, name: 'Mustang Validator' },
	vampire: { title: 'VAMPIRE Validator', order: 130, name: 'VAMPIRE Validator' },
};

/**
 * The manifest of one validator's button.
 *
 * @param {string} engine
 * @return {object}
 */
export function manifestFor( engine ) {
	return {
		id: 'validator-' + engine,
		name: TABS[ engine ].name,
		version: '2.0.0',
		api: 8,
		hosts: [ 'viewer', 'inbound', 'outbound' ],
		requires: [ 'document', 'toolbar' ],
		optional: [ 'tabs', 'panels' ],
		switches: [ 'button_spev_' + engine, 'tab_' + engine ],
	};
}

/**
 * The file to check: the original for an XML upload, the read-out XML for a PDF.
 *
 * @param {object} doc `ctx.document`
 * @return {File|null}
 */
export function checkFile( doc ) {
	const file = doc && doc.file;
	if ( ! doc || ! doc.rawXml ) return file || null;
	if ( file && /\.xml$/i.test( file.name || '' ) ) return file;
	const stem = String( ( file && file.name ) || 'invoice' ).replace( /\.[^.]+$/, '' );
	return new File( [ doc.rawXml ], stem + '.xml', { type: 'application/xml' } );
}

const browserLoads = {};

/** The scripts of VAMPIRE JS for the check in the browser, once per page. */
function loadBrowserCheck( scripts ) {
	if ( typeof globalThis.SPPV_checkInBrowser === 'function' ) return Promise.resolve();
	const key = scripts.engine + '|' + scripts.draw;
	if ( browserLoads[ key ] ) return browserLoads[ key ];
	const add = ( src, module ) => new Promise( ( resolve, reject ) => {
		const s = document.createElement( 'script' );
		if ( module ) s.type = 'module';
		s.src = src;
		s.onload = () => resolve();
		s.onerror = () => reject( new Error( '' ) );
		document.head.appendChild( s );
	} );
	browserLoads[ key ] = add( scripts.engine, false )
		.then( () => add( scripts.draw, true ) )
		.then( () => { if ( typeof globalThis.SPPV_checkInBrowser !== 'function' ) throw new Error( '' ); } )
		.catch( ( e ) => { delete browserLoads[ key ]; throw e; } );
	return browserLoads[ key ];
}

/**
 * @param {object} ctx
 * @param {object} cfg one entry of `einvoiceSiteValidators`
 */
export function setupSiteValidator( ctx, cfg ) {

	const engine = cfg.engine;
	const texts = cfg.texts || {};

	if ( ! ctx.allowed( 'button_spev_' + engine ) ) return;

	let doc = ctx.document;
	let busy = false;
	let reportFor = null;
	let tab = null;

	const bar = ctx.place( 'toolbar', { order: TABS[ engine ].order } );
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'btn spev-' + engine + '-btn';
	button.textContent = texts.button || TABS[ engine ].name;
	bar.el.appendChild( button );

	const panel = ctx.has( 'panels' ) ? ctx.place( 'panel', { order: TABS[ engine ].order } ) : null;
	const message = document.createElement( 'div' );
	message.className = 'spev-' + engine + '-message';
	message.setAttribute( 'role', 'status' );
	message.setAttribute( 'aria-live', 'polite' );
	message.hidden = true;
	// ⚠️ Without tabs ( `tab_kosit="no"` ) the report stands below the message, above the invoice, as before tabs existed.
	const fallback = document.createElement( 'div' );
	fallback.className = 'spev-' + engine + '-result';
	if ( panel ) panel.el.append( message, fallback );

	const say = ( text, type ) => {
		const kind = { error: 'err', warn: 'warn', ok: 'ok', info: 'info' }[ type ];
		message.textContent = text || '';
		message.className = 'spev-' + engine + '-message' + ( kind ? ' note ' + kind : ' muted' );
		message.hidden = ! text;
	};

	// KoSIT checks only XML; a PDF without readable XML gets no button.
	const visible = () => { button.hidden = ! ( doc && doc.model && ( ! cfg.xmlOnly || doc.rawXml ) ); };
	visible();

	const area = () => {
		tab = ctx.has( 'tabs' ) ? ctx.place( 'tab', { id: engine, title: TABS[ engine ].title, order: TABS[ engine ].order } ) : null;
		if ( tab ) { tab.el.textContent = ''; return tab.el; }
		fallback.textContent = '';
		return fallback;
	};

	const show = ( data ) => {
		const render = globalThis[ cfg.render ];
		if ( typeof render !== 'function' ) return;
		const el = area();
		render( el, data );
		// The renderer's own "Reset" belongs to the plugin's page, not to the viewer.
		for ( const r of el.querySelectorAll( '[class$="-tools-reset"]' ) ) r.remove();
		reportFor = doc ? doc.id : null;
		if ( tab ) tab.open();
	};

	const nonce = async () => {
		const body = new URLSearchParams();
		body.append( 'action', cfg.prefix + '_nonce' );
		const json = await ( await fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } ) ).json().catch( () => null );
		return json && json.success && json.data ? json.data.nonce : '';
	};

	button.addEventListener( 'click', async () => {

		// A second click on the same invoice opens its report again; the validator would give the same.
		if ( tab && reportFor !== null && doc && reportFor === doc.id && tab.el.isConnected ) { tab.open(); return; }

		const file = checkFile( doc );
		if ( busy || ! file ) return;

		busy = true;
		button.disabled = true;
		say( texts.loading || '', 'info' );
		const id = doc.id;

		try {

			let data;

			// ⚠️ VAMPIRE JS INSTEAD OF THE SERVER, WHEN THE PLUGIN NAMES IT ( add-on einvoice-validator-for-vampire-js ).
			if ( texts.browser && texts.scripts && texts.scripts.engine && texts.scripts.draw ) {
				if ( texts.browser.texts && texts.browser.texts.checking ) say( texts.browser.texts.checking, 'info' );
				await loadBrowserCheck( texts.scripts );
				const text = new TextDecoder( 'utf-8', { ignoreBOM: true } ).decode( await file.arrayBuffer() );
				data = await globalThis.SPPV_checkInBrowser( text, file.name, texts.browser );
			} else {
				const form = new FormData();
				form.append( 'action', cfg.prefix + '_validate' );
				form.append( 'spev_viewer', '1' );
				form.append( 'lang', String( ctx.lang || '' ).slice( 0, 2 ) );
				form.append( 'nonce', await nonce() );
				form.append( 'file', file, file.name );
				const json = await ( await fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } ) ).json().catch( () => null );
				if ( ! json || ! json.success || ! json.data ) throw new Error( json && json.data && json.data.message ? json.data.message : ( texts.failed || '' ) );
				data = json.data;
			}

			// ⚠️ A NEW INVOICE WHILE THE CHECK RAN: its report belongs to the old one and is dropped.
			if ( ! doc || doc.id !== id ) return;

			say( '' );
			show( data );

		} catch ( e ) {

			if ( doc && doc.id === id ) say( ( e && e.message ) || texts.failed || '', 'error' );

		} finally {

			busy = false;
			button.disabled = false;

		}

	} );

	ctx.on( 'document-opened', ( d ) => {
		doc = d; reportFor = null; tab = null; say( '' ); fallback.textContent = ''; visible();
		// The inbound module can switch on an automatic check: every opened invoice is checked as if the
		// button had been pressed. ⚠️ Deferred by one task, so the other extensions see the document first
		// and the report tab lands after the tabs they place for it.
		if ( typeof ctx.autoCheck === 'function' && ctx.autoCheck() ) setTimeout( () => { if ( doc === d ) button.click(); }, 0 );
	} );

	// ⚠️ THE DOCUMENT CAN BE OPEN BEFORE THIS EXTENSION STARTS; then no "document-opened" reaches it for the first invoice.
	if ( doc && typeof ctx.autoCheck === 'function' && ctx.autoCheck() ) { const first = doc; setTimeout( () => { if ( doc === first ) button.click(); }, 0 ); }
	ctx.on( 'document-closed', () => { doc = null; reportFor = null; tab = null; say( '' ); fallback.textContent = ''; visible(); } );

}

/**
 * Registers every entry of `einvoiceSiteValidators` that no other copy of this bundle has claimed.
 *
 * @param {object} [g] the global object, for tests
 */
export function registerSiteValidators( g = globalThis ) {
	for ( const cfg of Array.isArray( g.einvoiceSiteValidators ) ? g.einvoiceSiteValidators : [] ) {
		if ( ! cfg || cfg.claimed || ! TABS[ cfg.engine ] ) continue;
		cfg.claimed = true;
		( g.einvoiceExtensions = g.einvoiceExtensions || [] ).push( { manifest: manifestFor( cfg.engine ), setup: ( ctx ) => setupSiteValidator( ctx, cfg ) } );
	}
}
