/* SP eInvoice KoSIT Validator - upload, run, show the report, offer it as a download.
 *
 * Shadow DOM host, drag & drop uploader: the same construction as
 * einvoice-viewer-for-peppol-en16931's assets/js/spev-uploader.js - boot(), nonce-then-upload, drag/busy
 * states. What differs is what happens with the result - the server picks one of three views
 * ( `data.view`, see class-sppv-plugin.php's report_view() ) and this file renders each differently:
 *
 *   'lite'      "Report Lite" - a <einvoice-report> FRAGMENT, injected via innerHTML so the custom
 *               element can upgrade itself. The Validator's own default.
 *   'explainer' the eInvoice Validation Explainer's plain-language rendering, if that plugin is
 *               active and could make sense of the report - nested Shadow DOM, see renderExplained().
 *   ( anything else, 'raw' ) the validator's OWN full HTML document ( complete page, inline <style> ) -
 *               the last-resort fallback, into a sandboxed <iframe>, never innerHTML.
 *
 * ⚠️ sandbox HAS NO "allow-scripts". The raw-fallback report is produced by a trusted local tool,
 * but the values inside it came from whatever the visitor uploaded - if the KoSIT report XSLT ever
 * left a field unescaped, a plain, blocked-by-default iframe is what stops that from doing anything.
 * That report needs no script to render; it is tables and CSS.
 */

( function () {
	'use strict';

	if ( typeof window.SPPV === 'undefined' )
		return;

	var CFG = window.SPPV;


	/**
	 * Render one validation result ( report + XML download ) into a container element.
	 *
	 * ⚠️ EXPOSED AS window.SPPV_renderReport FOR REUSE. einvoice-viewer-for-peppol-en16931's
	 * "validate with the KoSIT validator" button calls this directly ( see spev-uploader.js ) rather
	 * than reimplementing the iframe-vs-explainer branch a second time - a second, drifting copy of
	 * this logic is exactly the kind of duplicate mechanism this project's own history warns against
	 * ( see einvoice-validation-explainer-for-peppol-en16931/STATUS.md §8f, "sonst zwei Mechanismen
	 * im Code, von denen einer tot ist" ).
	 *
	 * @param {HTMLElement} container   emptied and filled by this call
	 * @param {object}      data        the sppv_validate AJAX response's `data`
	 */

	function renderResult ( container, data )
	{

		container.innerHTML = '';

		var head = document.createElement( 'div' );
		head.className = 'sppv-result-head';

		// ⚠️ TEXTS FROM CFG.i18n, WITH THE ENGLISH ONES ONLY AS A FALLBACK. Both stood here as fixed
		// English strings, and a German page then carried one English heading and one English button.
		var i18n = CFG.i18n || {};

		var label = document.createElement( 'span' );
		label.textContent = i18n.reportTitle || i18n.report || 'Validation report'; // ⚠️ The bridge on the viewer page passes the title as `report`, the own page as `reportTitle`.

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'btn sppv-download';
		button.textContent = i18n.download || 'Download report.xml';

		button.addEventListener( 'click', function () {

			var blob = new Blob( [ data.xml ], { type: 'application/xml' } );
			var url  = URL.createObjectURL( blob );
			var a    = document.createElement( 'a' );

			a.href     = url;
			a.download = data.filename || 'report.xml';

			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );

			// the URL only needs to live long enough for the click to be handled
			window.setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );

		} );

		head.appendChild( label );

		// ⚠️ NO report.xml WITHOUT A REPORT. A check that stopped at a rule it could not evaluate has no report
		// ( SPPV_Validator::aborted() ); a button offering an empty file would look like a broken download.
		if ( typeof data.xml === 'string' && data.xml !== '' )
			head.appendChild( button );

		// The engine's own HTML report, as it came - see do_validate() in class-sppv-plugin.php. It stands next to report.xml.
		if ( typeof data.htmlReport === 'string' && data.htmlReport !== '' )
			head.appendChild( downloadButton( i18n.downloadHtml || 'Download report.html', data.htmlReport, 'text/html', data.htmlReportFilename || 'report.html' ) );

		// The positive report ( every rule with its result ), present only when the operator switched it on;
		// see do_validate() in class-sppv-plugin.php. ⚠️ TWO FILES, NOT ONE MORE COPY OF THE SAME: the JSON is the
		// engine's complete report with the rule lines, the HTML is the print version to file next to the
		// invoice. The section on screen is part of the view itself, not of these buttons.
		if ( typeof data.ruleReport === 'string' && data.ruleReport !== '' )
			head.appendChild( downloadButton( i18n.downloadRuleReport || 'Report with every rule ( JSON )', data.ruleReport, 'application/json', data.ruleReportFilename || 'rule-report.json' ) );

		if ( typeof data.rulePrint === 'string' && data.rulePrint !== '' )
			head.appendChild( downloadButton( i18n.downloadRulePrint || 'Print version of the checked rules ( HTML )', data.rulePrint, 'text/html', data.rulePrintFilename || 'checked-rules.html' ) );

		/*
		 * ⚠️ THE BUTTON AREA ABOVE THE TITLE BAR HAS A FIXED ORDER ( Ralf, 18.10.2026, the same in every tool ):
		 *
		 *   - The lowest row holds "Reset" and nothing else, right-aligned.
		 *   - Above it stands the row of the other buttons. The first one stands on the right, each further one to the
		 *     left of the one before, and a full row continues in a new row ABOVE it.
		 *
		 * `.sppv-tools-actions` does that with `flex-direction: row-reverse` ( the first button on the right ) and
		 * `flex-wrap: wrap-reverse` ( new rows above ). The buttons are collected into `head` further up so that the
		 * condition of each one stays in one place, and moved here in one go; a button added later, such as "Show
		 * invoice" of the KoSIT validator, is appended to `.sppv-tools-actions` and so lands left of the others.
		 */
		var tools = document.createElement( 'div' );
		tools.className = 'sppv-result-tools';

		var actions = document.createElement( 'div' );
		actions.className = 'sppv-tools-actions';

		Array.prototype.slice.call( head.querySelectorAll( 'button' ) ).forEach( function ( b ) { actions.appendChild( b ); } );

		var resetRow = document.createElement( 'div' );
		resetRow.className = 'sppv-tools-reset';

		var reset = document.createElement( 'button' );
		reset.type = 'button';
		reset.className = 'btn sppv-download sppv-reset';
		reset.textContent = ( CFG.i18n && CFG.i18n.reset ) || 'Reset';

		// Empties the result: report, invoice view and buttons. `.sppv-result:empty` then hides the frame as well.
		reset.addEventListener( 'click', function () {
			container.innerHTML = '';
		} );

		resetRow.appendChild( reset );

		if ( actions.children.length )
			tools.appendChild( actions );

		tools.appendChild( resetRow );
		container.appendChild( tools );

		container.appendChild( head );

		if ( data.view === 'explainer' && data.html )
		{
			renderExplained( container, data );
			return;
		}

		if ( data.view === 'lite' && data.html )
		{
			renderLite( container, data );
			return;
		}

		var frame = document.createElement( 'iframe' );
		frame.className = 'sppv-report-frame';
		frame.setAttribute( 'sandbox', 'allow-same-origin' );
		frame.setAttribute( 'title', 'KoSIT validation report' );
		frame.srcdoc = data.html;

		container.appendChild( frame );

	}


	/**
	 * A button that offers a text as a file download.
	 *
	 * @param {string} text      the button label
	 * @param {string} content   the file content
	 * @param {string} type      the MIME type
	 * @param {string} filename  the suggested file name
	 * @return {HTMLButtonElement}
	 */

	function downloadButton ( text, content, type, filename )
	{

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'btn sppv-download';
		button.textContent = text;

		button.addEventListener( 'click', function () {

			var url = URL.createObjectURL( new Blob( [ content ], { type: type } ) );
			var a   = document.createElement( 'a' );

			a.href     = url;
			a.download = filename;

			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );

			// the URL only needs to live long enough for the click to be handled
			window.setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );

		} );

		return button;

	}


	/**
	 * "Report Lite" - unlike the explainer's rendering, this is a plain `<einvoice-report>` FRAGMENT, not a
	 * complete HTML document, so it goes straight into the DOM ( innerHTML ) rather than a sandboxed iframe -
	 * the custom element upgrades itself and manages its OWN nested shadow root ( see
	 * libs/einvoice-report/assets/js/einvoice-report.js ), the same isolation principle renderExplained()
	 * above already applies for the explainer's markup.
	 *
	 * @param {HTMLElement} container
	 * @param {object}      data
	 */

	function renderLite ( container, data )
	{

		var wrap = document.createElement( 'div' );
		wrap.className = 'sppv-lite-wrap';
		wrap.innerHTML = data.html;

		container.appendChild( wrap );

	}


	/**
	 * ⚠️ NESTED SHADOW DOM, NOT THE CALLER'S OWN ROOT. spx-explainer.css declares its design tokens
	 * on `:root, :host` ( see einvoice-validation-explainer-for-peppol-en16931/STATUS.md §8b - it has
	 * to reach both a plain document AND a shadow root ). Loading it straight into the CALLER's own
	 * shadow root ( this plugin's `.sppv-host`, or the viewer's `.spev-host` ) would make that `:host`
	 * rule apply to THAT root too, silently overwriting its own typography/colour tokens with the
	 * explainer's - the exact class of cross-stylesheet leak this family has already hit and fixed
	 * once ( same STATUS.md section ), just one nesting level up. A second, inner attachShadow() gives
	 * spx-explainer.css a `:host` of its own that nothing else shares.
	 *
	 * @param {HTMLElement} container
	 * @param {object}      data
	 */

	function renderExplained ( container, data )
	{

		var wrap = document.createElement( 'div' );
		wrap.className = 'sppv-explainer-wrap';

		var innerShade = wrap.attachShadow( { mode: 'open' } );

		// ⚠️ data.cssUrl, NOT data.explainerCss - the per-view explainer/explainerCss bool+URL fields do not
		// exist; the answer has the unified view/cssUrl shape ( see STATUS.md ). Reading the old name gives
		// link.href the literal string "undefined", the stylesheet 404s, and the explainer's markup renders
		// completely unstyled - it looks as if the Explainer "wasn't working" although the HTML is correct.
		// ⚠️ data.cssUrl CARRIES SEVERAL ADDRESSES, separated by spaces - the report view first, the design kit
		// after it. See the same mechanism in boot() further down.
		String( data.cssUrl || '' ).split( /[\s,]+/ ).forEach( function ( href ) {

			if ( href === '' )
				return;

			var link = document.createElement( 'link' );

			link.rel  = 'stylesheet';
			link.href = href;

			innerShade.appendChild( link );

		} );

		var root = document.createElement( 'div' );
		root.className = 'spx-root';
		root.innerHTML = data.html;

		innerShade.appendChild( root );

		container.appendChild( wrap );

		// The interactive bits ( BT toggle, "errors only", search, jump links ) are wired by the
		// explainer's OWN wireReport(), exposed as window.SPX_wireReport for exactly this kind of
		// reuse - see spx-uploader.js. Without it ( explainer present but its script failed to load,
		// or a future version stops exposing it ) the report still displays, just without the
		// interactive extras - a static report is still a usable one.
		( typeof window.SPX_wireReport === 'function' ? window.SPX_wireReport : wireReportOwn )( innerShade, root, wrap );

	}


	/**
	 * Wire the view options of the explorer view: "BT numbers", "Findings only", the search box and the jump links.
	 *
	 * ⚠️ A COPY OF wireReport() FROM THE KoSIT VALIDATION EXPLORER ( spx-uploader.js ), USED WHEN THAT PLUGIN IS NOT
	 * ACTIVE. The validator renders the explorer view itself since the view became part of the validator, but the
	 * wiring stayed in the explorer: on a site without the explorer plugin the search box and both switches did
	 * nothing ( Ralf, 18.10.2026 ). When the explorer is active, its own function is used, so a newer explorer still
	 * wins; this copy only fills the gap.
	 */
	function wireReportOwn ( shade, result, host )
	{

		var body   = result.querySelector( '.doc-body' );
		var bt     = result.querySelector( '.spx-toggle-bt' );
		var err    = result.querySelector( '.spx-toggle-err' );
		var search = result.querySelector( '.spx-doc-search' );

		if ( body && bt )
		{
			bt.checked = ( host.dataset.bt !== '0' );

			body.classList.toggle( 'hide-bt', ! bt.checked );

			bt.addEventListener( 'change', function () {
				body.classList.toggle( 'hide-bt', ! this.checked );
			} );
		}

		/**
		 * "Findings only" and the search box both decide, per row, whether it stays visible - and they have to
		 * agree in the SAME pass. Two independent mechanisms ( a body-level CSS class for one, per-row classes
		 * for the other ) cannot express "an error row that also matches the search text": a plain CSS :not()
		 * selector has no way to test a substring match, so the search has to run in JS anyway, and once it
		 * does, folding the "findings only" condition into the same pass is what lets the two combine
		 * correctly instead of only ever applying whichever was set last.
		 *
		 * Mirrors the prototype's applyDocFilters() one for one - see validierungsbericht-erklaerer.html.
		 */

		function applyDocFilters ()
		{

			if ( ! body )
				return;

			var query   = search ? search.value.trim().toLowerCase() : '';
			var errOnly = !! ( err && err.checked );
			var rows    = body.querySelectorAll( '.doc-row' );

			for ( var i = 0; i < rows.length; i++ )
			{

				var r = rows[ i ];

				r.classList.remove( 'highlight-search' );

				var show = true;

				if ( errOnly && ! r.classList.contains( 'error-row' ) && ! r.classList.contains( 'warn-row' ) && ! r.classList.contains( 'info-row' ) )
					show = false;

				if ( query !== '' )
				{
					var nameEl = r.querySelector( '.doc-elem-name' );
					var valEl  = r.querySelector( '.doc-elem-val' );
					var btEl   = r.querySelector( '.doc-elem-bt' );

					var name = nameEl ? nameEl.textContent.toLowerCase() : '';
					var val  = valEl  ? valEl.textContent.toLowerCase()  : '';
					var badge = btEl  ? btEl.textContent.toLowerCase()   : '';

					if ( name.indexOf( query ) !== -1 || val.indexOf( query ) !== -1 || badge.indexOf( query ) !== -1 )
					{
						if ( show )
							r.classList.add( 'highlight-search' );
					}
					else
					{
						show = false;
					}
				}

				r.classList.toggle( 'hidden-empty', ! show );

			}

		}

		if ( err )
			err.addEventListener( 'change', applyDocFilters );

		if ( search )
		{
			// a short debounce, same as the prototype's - noticeable typing lag over ~500 rows
			// otherwise, without making the box feel unresponsive
			var searchTimer = null;

			search.addEventListener( 'input', function () {
				window.clearTimeout( searchTimer );
				searchTimer = window.setTimeout( applyDocFilters, 120 );
			} );
		}

		result.addEventListener( 'click', function ( e ) {

			var link = e.target.closest ? e.target.closest( 'a[href^="#"]' ) : null;

			if ( ! link )
				return;

			e.preventDefault();

			var target = shade.getElementById( link.getAttribute( 'href' ).slice( 1 ) );

			if ( ! target )
				return;

			target.scrollIntoView( { behavior: 'smooth', block: 'center' } );

			// a short highlight, so the eye finds the row it was sent to
			target.classList.add( 'selected' );

			window.setTimeout( function () { target.classList.remove( 'selected' ); }, 1600 );

		} );

	}


	function boot ( host )
	{

		if ( host.dataset.sppvReady === '1' )
			return;

		host.dataset.sppvReady = '1';

		var template = host.querySelector( 'template' );

		if ( ! template )
			return;

		var shade = host.attachShadow( { mode: 'open' } );

		// ⚠️ CFG.cssUrl CARRIES SEVERAL ADDRESSES, separated by spaces: this shell first, the design kit after
		// it. A stylesheet of the document does not reach this shadow root, and the kit sets the tokens the
		// shell takes its colours from. The order is part of the agreement - see the header of
		// sppv-validator.css.
		String( CFG.cssUrl || '' ).split( /[\s,]+/ ).forEach( function ( href ) {

			if ( href === '' )
				return;

			var link = document.createElement( 'link' );

			link.rel  = 'stylesheet';
			link.href = href;

			shade.appendChild( link );

		} );
		shade.appendChild( template.content.cloneNode( true ) );

		var drop    = shade.querySelector( '.drop' );
		var input   = shade.querySelector( '.sppv-file' );
		var message = shade.querySelector( '.sppv-message' );
		var result  = shade.querySelector( '.sppv-result' );

		if ( ! drop || ! input )
			return;

		var busy = false;


		function say ( text, kind )
		{
			message.textContent = text || '';
			// An error is the kit's .note.err, any other line muted text. An empty line is hidden, because an empty
			// .note still draws its frame.
			message.className   = 'sppv-message' + ( kind === 'error' ? ' note err' : ' muted' );
			message.hidden      = ! text;
		}


		function remaining ()
		{
			// ⚠️ The browser check does not count against the daily limit ( see `wp-vampire-browser.mjs` ); the line would
			// count down for checks that never reach the server.
			if ( CFG.browser || typeof CFG.remaining !== 'number' || CFG.remaining < 0 )
				return;

			say( CFG.i18n.remaining.replace( '%d', CFG.remaining ) );
		}


		/**
		 * ⚠️ THE NONCE IS FETCHED, NOT PRINTED INTO THE PAGE - a cached page would otherwise carry a
		 * nonce that is stale by the time a visitor actually uploads something. Same reasoning, same
		 * construction as the sister plugins.
		 */

		function withNonce ( then )
		{
			var body = new FormData();

			body.append( 'action', 'sppv_nonce' );

			fetch( CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( r ) {
					if ( r && r.success && r.data && r.data.nonce )
						then( r.data.nonce );
					else
						say( CFG.i18n.failed, 'error' );
				} )
				.catch( function () { say( CFG.i18n.failed, 'error' ); } );
		}


		function validate ( file )
		{
			var name = ( file.name || '' ).toLowerCase();

			if ( ! /\.(xml|pdf)$/.test( name ) )
			{
				say( CFG.i18n.wrongType, 'error' );
				return false;
			}

			if ( CFG.maxSize && file.size > CFG.maxSize )
			{
				say( CFG.i18n.tooLarge, 'error' );
				return false;
			}

			return true;
		}


		/**
		 * pdf.js and the shared model bundle, both loaded ONLY when a PDF is actually dropped - an
		 * XML-only visitor never pays for the 1,7 MB.
		 *
		 * ⚠️ pdf.js 6.x ships only as an ES module; there is no UMD build that sets
		 * `window.pdfjsLib` by itself. The dynamic `import()` returns the namespace object, and this
		 * assigns it to `window.pdfjsLib` because that is what the shared `extractPdfXml()` checks.
		 * `GlobalWorkerOptions.workerSrc` MUST be set before the first `getDocument()`: pdf.js cannot
		 * infer its worker's URL from its own import specifier.
		 *
		 * ⚠️ The worker file is named `.js`, not pdf.js's own `.mjs` - found live on a real host: a
		 * server that serves `.mjs` as `text/plain` makes the browser refuse the worker, and the
		 * browser goes by the Content-Type header, never by the extension.
		 */

		var pdfJsPromise = null;
		var modelPromise = null;

		function loadPdfJs ()
		{
			if ( window.pdfjsLib )
				return Promise.resolve( window.pdfjsLib );

			if ( ! pdfJsPromise )
				pdfJsPromise = import( CFG.pdfJsUrl ).then( function ( ns ) {
					ns.GlobalWorkerOptions.workerSrc = CFG.pdfJsWorkerUrl;
					window.pdfjsLib = ns;
					return ns;
				} );

			return pdfJsPromise;
		}

		function loadModel ()
		{
			if ( ! modelPromise )
				modelPromise = import( CFG.modelBundleUrl );

			return modelPromise;
		}


		/**
		 * A ZUGFeRD / Factur-X PDF carries the invoice as an embedded XML attachment. It is pulled out
		 * HERE, in the browser, and only the XML is sent.
		 *
		 * ⚠️ That is a deliberate choice, not a shortcut. The server stays XML-only: no PDF parser in
		 * PHP, no 3 MB upload for a 30 KB invoice, and one less file format that untrusted bytes can
		 * exploit on the server. The price is that PDF intake needs JavaScript - which the upload
		 * form needs anyway.
		 *
		 * @param {File} file
		 * @return {Promise<File>} the file to send: the original for XML, an extracted one for PDF
		 */

		function asXmlFile ( file )
		{

			if ( ! /\.pdf$/.test( ( file.name || '' ).toLowerCase() ) )
				return Promise.resolve( file );

			return new Promise( function ( resolve, reject ) {

				var reader = new FileReader();

				reader.onerror = function () { reject( new Error( CFG.i18n.failed ) ); };

				reader.onload = function () {

					var buf = reader.result;

					Promise.all( [ loadPdfJs(), loadModel() ] )
						.then( function ( loaded ) {
							return loaded[ 1 ].extractPdfXml( buf );
						} )
						.then( function ( xmlText ) {

							if ( ! xmlText )
								throw new Error( CFG.i18n.noXmlInPdf );

							var base = ( file.name || 'invoice.pdf' ).replace( /\.pdf$/i, '' );

							resolve( new File( [ xmlText ], base + '.xml', { type: 'application/xml' } ) );

						} )
						.catch( function ( e ) {
							reject( new Error( ( e && e.message ) ? e.message : CFG.i18n.noXmlInPdf ) );
						} );

				};

				reader.readAsArrayBuffer( file );

			} );

		}


		function send ( file )
		{

			if ( busy || ! validate( file ) )
				return;

			busy = true;

			// ⚠️ OWN CLASS, NOT 'drag'. 'drag' means "a file is being dragged over the zone right now",
			// 'busy' means "the validator is running" - conflating the two would make an in-progress
			// run look identical to a drag-hover.

			drop.classList.add( 'busy' );

			result.innerHTML = '';
			say( /\.pdf$/.test( ( file.name || '' ).toLowerCase() ) ? CFG.i18n.extracting : CFG.i18n.loading );

			asXmlFile( file ).then( function ( xmlFile ) {

			/*
			 * ⚠️ THE BROWSER CHECK OF THE ADD-ON VAMPIRE JS, WHEN IT IS THERE. `CFG.browser` is set by the plugin only while
			 * the add-on is active and no setting needs the server ( SPPV_Plugin::browser_config() ); the function comes
			 * from the add-on's script. Both must be there: a page from the cache can carry the one without the other.
			 *
			 * ⚠️ NO FALLBACK TO THE SERVER WHEN THE BROWSER CHECK FAILS. The visitor was promised that the invoice stays on
			 * the computer; sending it after all because the browser check stumbled would break that promise without a
			 * word. The message says what went wrong instead.
			 */
			if ( CFG.browser && typeof window.SPPV_checkInBrowser === 'function' )
			{
				say( CFG.browser.texts.checking );

				// ⚠️ WITH ITS BYTE ORDER MARK. `file.text()` drops it, and the checksum in the positive report would then differ
				// from that of the file and from the server's ( measured 22.10.2026 on an OIOUBL invoice: 9701 bytes against 9704 ).
				xmlFile.arrayBuffer()
					.then( function ( buffer ) { return new TextDecoder( 'utf-8', { ignoreBOM: true } ).decode( buffer ); } )
					.then( function ( text ) { return window.SPPV_checkInBrowser( text, xmlFile.name, CFG.browser ); } )
					.then( function ( data ) {
						busy = false;
						drop.classList.remove( 'busy' );
						renderResult( result, data );
						say( '' );
					} )
					.catch( function ( e ) {
						busy = false;
						drop.classList.remove( 'busy' );
						say( ( e && e.message ) ? e.message : CFG.i18n.failed, 'error' );
					} );

				return;
			}

			say( CFG.i18n.loading );

			withNonce( function ( nonce ) {

				var body = new FormData();

				body.append( 'action', 'sppv_validate' );
				// The language of the page, so that the answer speaks it too ( EInvoicePress_Request_Language ).
				body.append( 'lang', CFG.lang || '' );
				body.append( 'nonce', nonce );
				body.append( 'file', xmlFile, xmlFile.name );

				fetch( CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( r ) {

						busy = false;
						drop.classList.remove( 'busy' );

						if ( ! r || ! r.success )
						{
							say( ( r && r.data && r.data.message ) ? r.data.message : CFG.i18n.failed, 'error' );
							return;
						}

						renderResult( result, r.data );
						say( '' );

						if ( typeof r.data.remaining === 'number' )
						{
							CFG.remaining = r.data.remaining;
							remaining();
						}

					} )
					.catch( function () {
						busy = false;
						drop.classList.remove( 'busy' );
						say( CFG.i18n.failed, 'error' );
					} );

			} );

			} ).catch( function ( e ) {

				// ⚠️ The PDF's own message is passed through, not replaced by the generic one. "This
				// PDF carries no e-invoice" and "the upload failed" send the visitor to two completely
				// different next steps.
				busy = false;
				drop.classList.remove( 'busy' );
				say( ( e && e.message ) ? e.message : CFG.i18n.failed, 'error' );

			} );

		}


		drop.addEventListener( 'click', function () { input.click(); } );

		drop.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' )
			{
				e.preventDefault();
				input.click();
			}
		} );

		input.addEventListener( 'change', function () {
			if ( this.files && this.files[ 0 ] )
				send( this.files[ 0 ] );
		} );

		[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
			drop.addEventListener( ev, function ( e ) { e.preventDefault(); drop.classList.add( 'drag' ); } );
		} );

		[ 'dragleave', 'drop' ].forEach( function ( ev ) {
			drop.addEventListener( ev, function ( e ) { e.preventDefault(); drop.classList.remove( 'drag' ); } );
		} );

		drop.addEventListener( 'drop', function ( e ) {
			if ( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ] )
				send( e.dataTransfer.files[ 0 ] );
		} );

		remaining();

		bootBatch( shade, withNonceOnce );


		/**
		 * The nonce for the batch check, fetched once per batch and kept; `sppv_validate` accepts it for hours.
		 *
		 * ⚠️ NOT withNonce() PER INVOICE. That would be a second request for every invoice of the batch, and a month folder
		 * of a hundred invoices would cost two hundred requests instead of a hundred and one.
		 *
		 * @param {boolean} fresh  true after a 403, to fetch a new one
		 * @return {Promise<string>}
		 */
		var batchNonce = null;

		function withNonceOnce ( fresh )
		{
			if ( fresh )
				batchNonce = null;

			if ( ! batchNonce )
				batchNonce = new Promise( function ( resolve, reject ) {
					var body = new FormData();
					body.append( 'action', 'sppv_nonce' );
					fetch( CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( r ) {
							if ( r && r.success && r.data && r.data.nonce )
								resolve( r.data.nonce );
							else
								reject( new Error( CFG.i18n.failed ) );
						} )
						.catch( function () { reject( new Error( CFG.i18n.failed ) ); } );
				} ).catch( function ( e ) { batchNonce = null; throw e; } );

			return batchNonce;
		}

	}


	/**
	 * The batch check: the tabs "Single check" and "Batch check", and the batch block of libs/einvoice-core-js.
	 *
	 * ⚠️ THE BLOCK IS LOADED ON THE FIRST CLICK ON "BATCH CHECK", as an ES module ( `CFG.batch.bundleUrl` ), the same way the
	 * model bundle is loaded only for a PDF. A visitor who checks one invoice never loads it.
	 *
	 * ⚠️ ONE INVOICE PER REQUEST, TO THE SAME `sppv_validate` AS THE SINGLE CHECK, OR TO VAMPIRE JS WHEN THE ADD-ON CHECKS
	 * IN THE BROWSER. A PDF is read in the browser first ( `extractPdfXmlPlain()` of the bundle, without pdf.js ), and only
	 * its XML is sent, as in the single check. The plugin answers with its report and its own drawing of the explanation;
	 * the overview reads the report for its row, and a click on the row shows the drawing with renderResult().
	 *
	 * ⚠️ THE DAILY LIMIT STOPS THE BATCH. A 429 of the plugin means "no more uploads today"; waiting would not help, and
	 * asking with every further invoice would only collect refusals. The queue lists the rest as not checked.
	 *
	 * @param {ShadowRoot} shade
	 * @param {function(boolean): Promise<string>} nonce
	 */

	function bootBatch ( shade, nonce )
	{

		var modes  = shade.querySelector( '.sppv-modes' );
		var single = shade.querySelector( '.sppv-single' );
		var pane   = shade.querySelector( '.sppv-batch' );

		if ( ! modes || ! single || ! pane || ! CFG.batch || ! CFG.batch.bundleUrl )
			return;

		var B       = CFG.batch;
		var bt      = function ( s ) { return ( B.texts && B.texts[ s ] ) || s; };
		var message = pane.querySelector( '.sppv-batch-message' );
		var result  = pane.querySelector( '.sppv-batch-result' );
		var root    = pane.querySelector( '.sppv-batch-root' );
		var lede    = pane.querySelector( '.sppv-batch-lede' );
		var mounted = null;

		// ⚠️ With the add-on VAMPIRE JS the invoices stay in the browser, and the sentence about this website would be wrong.
		if ( CFG.browser && typeof window.SPPV_checkInBrowser === 'function' && lede )
			lede.textContent = bt( 'Check many invoices at once: a month folder, a ZIP or a selection of files. VAMPIRE JS checks them one after the other in this browser, and the overview lists the verdict of every file. Nothing leaves the computer.' );

		function say ( text )
		{
			message.textContent = text || '';
			message.className   = 'sppv-batch-message muted';
			message.hidden      = ! text;
		}

		function remainingLine ()
		{
			if ( CFG.browser || typeof CFG.remaining !== 'number' || CFG.remaining < 0 )
				return;

			say( CFG.i18n.remaining.replace( '%d', CFG.remaining ) );
		}

		function serverCheck ( item )
		{
			return nonce( false ).then( function ( n ) {

				var body = new FormData();
				var base = String( item.name || 'invoice' ).replace( /\.(xml|pdf)$/i, '' );

				body.append( 'action', 'sppv_validate' );
				body.append( 'lang', CFG.lang || '' );
				body.append( 'nonce', n );
				// ⚠️ The XML as text: a byte order mark at its start becomes the three bytes of the file again.
				body.append( 'file', new File( [ item.xml ], base + '.xml', { type: 'application/xml' } ), base + '.xml' );

				return fetch( CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) {

					return r.json().catch( function () { return null; } ).then( function ( j ) {

						var text = ( j && j.data && j.data.message ) ? j.data.message : CFG.i18n.failed;

						if ( r.status === 429 )
						{
							var stop = new Error( text );
							stop.status = 429;
							stop.stop   = true;
							throw stop;
						}

						// A nonce that ran out while the batch ran: the next invoice asks for a new one.
						if ( r.status === 403 )
							nonce( true ).catch( function () {} );

						if ( ! j || ! j.success )
							throw new Error( text );

						return j.data;

					} );

				} );

			} );
		}

		function mount ( mod )
		{
			mounted = mod.mountBatch( root, {
				t: bt,
				lang: function () { return String( CFG.lang || document.documentElement.lang || 'en' ).slice( 0, 2 ); },
				tool: B.tool || '',
				concurrency: Number( B.concurrency ) || 1,
				Parser: window.DOMParser,
				check: function ( item ) {

					var run = ( CFG.browser && typeof window.SPPV_checkInBrowser === 'function' )
						? window.SPPV_checkInBrowser( item.xml, item.name, CFG.browser )
						: serverCheck( item );

					return run.then( function ( data ) {

						if ( typeof data.remaining === 'number' )
						{
							CFG.remaining = data.remaining;
							remainingLine();
						}

						// ⚠️ No report, no row verdict: the check ended without one ( a rule set is missing, a rule stopped it ).
						if ( typeof data.xml !== 'string' || data.xml === '' )
							throw new Error( bt( 'No result for this document. The single check names the reason.' ) );

						return { report: mod.readReport( data.xml ), keep: data };

					} );

				},
				open: function ( row, item ) {
					renderResult( result, item.result.keep );
					result.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				},
				onReset: function () {
					result.innerHTML = '';
					remainingLine();
				},
			} );
		}

		function setMode ( mode )
		{
			var batch = mode === 'batch';
			var tabs  = modes.querySelectorAll( '[data-mode]' );

			for ( var i = 0; i < tabs.length; i++ )
			{
				var on = tabs[ i ].getAttribute( 'data-mode' ) === mode;
				tabs[ i ].classList.toggle( 'on', on );
				tabs[ i ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
			}

			single.hidden = batch;
			pane.hidden   = ! batch;

			if ( ! batch || mounted )
				return;

			mounted = true;
			say( bt( 'Checking …' ) );

			import( B.bundleUrl ).then( function ( mod ) {
				say( '' );
				mount( mod );
				remainingLine();
			} ).catch( function () {
				mounted = null;
				say( CFG.i18n.failed );
			} );
		}

		modes.addEventListener( 'click', function ( e ) {
			var b = e.target.closest ? e.target.closest( '[data-mode]' ) : null;
			if ( b )
				setMode( b.getAttribute( 'data-mode' ) );
		} );

	}


	function init ()
	{
		var hosts = document.querySelectorAll( '.sppv-host' );

		for ( var i = 0; i < hosts.length; i++ )
			boot( hosts[ i ] );
	}

	if ( document.readyState === 'loading' )
		document.addEventListener( 'DOMContentLoaded', init );
	else
		init();

	// See renderResult()'s own doc comment above - exposed for the viewer's "validate with the
	// KoSIT validator" button. Only reached when window.SPPV exists ( the guard at the top of this
	// file ), i.e. only when this script was actually meant to load, whether for this plugin's own
	// widget or as the bridge-only load enqueue_bridge_script() sets up for a sibling plugin.
	//
	// ⚠️ BOTH THE OLD GLOBAL AND THE REGISTRY POINT AT THE SAME FUNCTION. window.SPPV_renderReport stays as
	// it is ( einvoice-viewer-for-peppol-en16931's own spev-uploader.js reads it directly );
	// EInvoice.register() is the preferred way for anything new. `window.EInvoice` is guaranteed to exist
	// because 'einvoice-host' is a hard script dependency of this file - see class-sppv-plugin.php.
	window.SPPV_renderReport = renderResult;

	if ( typeof window.EInvoice !== 'undefined' )
		window.EInvoice.register( 'validator', { renderReport: renderResult } );

}() );
