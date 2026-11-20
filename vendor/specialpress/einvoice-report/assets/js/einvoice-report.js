// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * <einvoice-report> - "Report Lite": findings list + severity filter, no BT mapping.
 *
 * ⚠️ NESTED SHADOW ROOT, NOT THE HOST PAGE'S OWN - same reasoning as
 * einvoice-validator-for-peppol-en16931's spkv-uploader.js renderExplained(): this component's
 * stylesheet declares its own tokens, and loading it into whatever shadow root already wraps this
 * element ( the Validator's `.spkv-host`, or any future host ) would let its `:host` rule leak into
 * that root instead of staying scoped to itself.
 *
 * Progressive enhancement: the PHP renderer ( Lite\Renderer::render() ) already emits a complete,
 * readable list. This only adds the interactive severity filter - with JavaScript disabled, or before
 * this script has loaded, every finding is simply shown, unfiltered.
 */
( function()
{
	'use strict';

	if ( typeof customElements === 'undefined' || customElements.get( 'einvoice-report' ) )
		return;

	class EInvoiceReport extends HTMLElement
	{

		connectedCallback()
		{
			if ( this.dataset.ereportReady === '1' )
				return;

			this.dataset.ereportReady = '1';

			var light = document.createElement( 'div' );

			while ( this.firstChild )
				light.appendChild( this.firstChild );

			var shade = this.attachShadow( { mode: 'open' } );

			/**
			 * ⚠️ SEVERAL ADDRESSES, SEPARATED BY COMMA OR WHITESPACE. The report view needs
			 * two stylesheets: its own rules and the design kit its look comes from.
			 * A stylesheet of the document does not reach this shadow root.
			 *
			 * ⚠️ THE ORDER IS THE ORDER GIVEN. The own file belongs in front, the kit
			 * behind it; the own rules win by specificity, not by order
			 * ( see einvoice-report.css ).
			 *
			 * A single address works as well.
			 */
			var cssUrls = String( this.dataset.css || '' ).split( /[\s,]+/ );

			for ( var u = 0; u < cssUrls.length; u++ )
			{
				if ( ! cssUrls[ u ] )
					continue;

				var link = document.createElement( 'link' );
				link.rel  = 'stylesheet';
				link.href = cssUrls[ u ];
				shade.appendChild( link );
			}

			shade.appendChild( light );

			var buttons = shade.querySelectorAll( '.ereport-filter-btn' );
			var items   = shade.querySelectorAll( '.ereport-finding' );

			for ( var i = 0; i < buttons.length; i++ )
			{
				buttons[ i ].addEventListener( 'click', function( e )
				{
					var btn      = e.currentTarget;
					var severity = btn.dataset.severity;
					var pressed  = btn.getAttribute( 'aria-pressed' ) === 'true';

					btn.setAttribute( 'aria-pressed', pressed ? 'false' : 'true' );
					// The kit shows the chosen state of a .chip with .on; aria-pressed stays the source of the state.
					btn.classList.toggle( 'on', ! pressed );

					for ( var j = 0; j < items.length; j++ )
					{
						if ( items[ j ].dataset.severity !== severity )
							continue;

						items[ j ].hidden = pressed;
					}
				} );
			}
		}

	}

	customElements.define( 'einvoice-report', EInvoiceReport );

} )();
