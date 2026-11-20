// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Bundle entry point for a PLAIN (non-Custom-Element) consumer that wants to parse and render an
 * invoice itself. `<einvoice-viewer>` (einvoice-viewer.mjs) is the right choice for a
 * new integration; this export exists for `einvoice-viewer-for-peppol-en16931`'s own
 * `assets/js/spev-uploader.js`, whose Shadow DOM already has a fixed toolbar (KoSIT/Export/Visualizer/
 * Identifier Check/Inbound Check buttons) that is NOT part of the Web Component and cannot simply be
 * dropped inside it without restructuring that markup - see parse.mjs's own header for the full
 * reasoning.
 *
 * Usage (dynamic import from a classic, non-module script):
 *
 *   const M = await import( '/wp-content/plugins/einvoice-viewer-for-peppol-en16931/assets/js/einvoice-model.bundle.js' );
 *   const reader = await M.loadReader( ciiNodesUrl );
 *   const tree   = reader.read( doc );
 *   const model  = M.build( tree, reader );
 *   const html   = M.render( model, tooltipFor );
 */

export { TreeReader } from './TreeReader.mjs';
export { build } from './ModelAssembler.mjs';
export { render } from './QubaTemplate.mjs';
export { loadReader, loadTooltips, sniff, detectSyntax, extractPdfXml, detectTechnicalContext } from './parse.mjs';
export { extractIdentifiers } from './identifiers.mjs';
