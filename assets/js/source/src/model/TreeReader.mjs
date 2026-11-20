// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Catalog-driven CII field extractor - the JavaScript model builder, CII slice.
 *
 * NOT a line-for-line port of ModelBuilder.php's ~80 named horstoeko getter calls (see
 * einvoice-viewer-for-peppol-en16931/docs/horstoeko-inventar.md). Extraction is driven from the GENERATED
 * node catalog (cii-nodes.json, exported from einvoice-viewer-for-peppol-en16931's own
 * includes/core/Metadata/cii-nodes.php via tools/export-cii-nodes.php) instead: one generic
 * tree-walking engine, not ~80 hand-written getters ( see einvoice-viewer-extended-profiles/STATUS.md ).
 * horstoeko itself becomes unnecessary, not merely replaced 1:1.
 *
 * This same engine also drives the hand-authored UBL node tree (tools/build-ubl-nodes.mjs ->
 * src/model/ubl-nodes.json) unchanged - both catalogs share the exact
 * same {id, parent, rel, rep, filter, attr, value} shape, so no UBL-specific reader code exists.
 *
 * LITERAL PREFIX MATCHING, NOT NAMESPACE-AWARE - same measured, deliberate simplification as
 * ReaderXml.mjs (see that file's own doc block): getElementsByTagName-equivalent matching on the
 * literal prefixed string, not namespace-URI resolution. Every node.rel in the catalog already
 * carries the exact prefix the syntax uses (ram:/rsm:/udt:/qdt: for CII, cac:/cbc: for UBL), so this
 * is a direct match, not a heuristic.
 *
 * VERSIONS OF THE STANDARD. read() first takes the document's specification
 * identifier ( BT-24 ) and asks NormVersion.mjs which version of EN 16931 it follows; today that is
 * always EN 16931-1:2017. A catalogue node may carry `"norm": [ "2017" ]` to say it exists only in the
 * listed versions; read() then skips it, and its whole subtree, for a document of any other version. A
 * node without `norm` exists in every version, which is every node today. When EN 16931-1:2026 comes,
 * new nodes get `"norm": [ "2026" ]` and nothing in this file changes.
 *
 * @since 3.1.0
 */

import { normVersionOf, normVersions } from './NormVersion.mjs';

export class TreeReader {

	/**
	 * @param {Array<object>} nodes cii-nodes.json (or ubl-nodes.json) - id, parent, rel, card, type, rep, attr, value, filter,
	 *        and optionally norm ( the versions of EN 16931 in which the node exists ).
	 *
	 * ⚠️ `rep` IS THE SYNTAX, `card` IS NOT USED. `rep` says whether the XML element repeats in its parent and
	 * decides whether the tree holds an array. `card` is descriptive only and is not the semantic cardinality
	 * either ( every UBL node says 0..1 or 0..n ); that one stands in en16931-structure.json. Example: CII
	 * BG-16 has `card` 0..1 like EN 16931, but `rep` true, because CII repeats the payment means element to
	 * carry several accounts.
	 */

	constructor( nodes ) {

		this.nodes = nodes;

		this.childrenOf = new Map();
		this.roots = [];

		nodes.forEach( ( node, index ) => {

			if ( node.parent === null ) {
				this.roots.push( index );
				return;
			}

			if ( ! this.childrenOf.has( node.parent ) )
				this.childrenOf.set( node.parent, [] );

			this.childrenOf.get( node.parent ).push( index );

		} );

		// SIBLING-RANK FALLBACK for nodes the catalog cannot otherwise disambiguate: two (or more)
		// non-repeating nodes sharing the exact same (parent, rel, filter='') collide on the same set
		// of candidate elements, and DECLARATION ORDER in the catalog array then decides which node
		// claims which occurrence. Root-level siblings (parent === null) are grouped as well.
		//
		// ⚠️ NO CATALOG NODE USES THIS ( measured 08.10.2026, both catalogs ). BT-110 and BT-111 ( VAT
		// total in the invoice currency BT-5 and in the accounting currency BT-6, both
		// .../ram:TaxTotalAmount in CII and cac:TaxTotal/cbc:TaxAmount in UBL ) must not be told apart by
		// order: EN 16931 tells them apart by `currencyID`, the schema does not fix their order, and a
		// document may carry only the accounting-currency one. Measured on
		// `_mutated-demo/zugferd-e05-totals-errors.xml` ( BT-5 `EURO`, the only element `currencyID="EUR"` ):
		// by order this reader took it as BT-110 and computed BT-112 as 529.87, while the PHP side found no
		// BT-110 and computed 473.00. Both nodes therefore carry a field-reference filter on BT-5 ( see
		// _evalFilter() ). The fallback stays for a future pair that really has nothing else to tell it
		// apart - but such a pair should first be checked for an attribute or a sibling element that the
		// norm uses to distinguish them.
		this.siblingRank = new Map();

		const groups = new Map();

		nodes.forEach( ( node, index ) => {

			if ( node.rep || node.filter )
				return;

			const key = String( node.parent ) + ' ' + node.rel;

			if ( ! groups.has( key ) )
				groups.set( key, [] );

			groups.get( key ).push( index );

		} );

		for ( const indices of groups.values() )
			if ( indices.length > 1 )
				indices.forEach( ( nodeIndex, rank ) => this.siblingRank.set( nodeIndex, rank ) );

		/*
		 * ⚠️ A `norm` KEY IS CHECKED HERE, ONCE, AND A MISTAKE STOPS THE READER. A misspelt version ( "2016",
		 * or a plain string instead of a list ) would otherwise make the node silently absent from every
		 * document - an invisible loss of a value.
		 *
		 * ⚠️ BT-24 MUST NOT CARRY `norm`. read() resolves BT-24 to find the version; a BT-24 node that
		 * depended on the version would make that question circular.
		 */
		const known = new Set( normVersions() );

		nodes.forEach( ( node ) => {

			if ( node.norm === undefined )
				return;

			if ( ! Array.isArray( node.norm ) || node.norm.length === 0 || node.norm.some( ( v ) => ! known.has( v ) ) )
				throw new Error( 'TreeReader: node ' + node.id + ' has an invalid norm ' + JSON.stringify( node.norm ) + '; known versions: ' + [ ...known ].join( ', ' ) );

			if ( node.id === 'BT-24' )
				throw new Error( 'TreeReader: BT-24 decides the version and must not carry norm' );

		} );

		/** The version the last read() used, e.g. '2017'; null before the first read(). */
		this.lastVersion = null;
		this._version = null;

	}



	/**
	 * The version of EN 16931 a document follows, from its BT-24 ( see NormStructure.normVersionOf() ).
	 *
	 * @param {Document} doc
	 * @return {string} e.g. '2017'
	 */

	normVersion( doc ) {

		const before = this._version;

		this._version = null;

		try {

			return normVersionOf( this._fieldValue( doc, 'BT-24' ) );

		} finally {

			this._version = before;

		}

	}



	/**
	 * Does this catalogue node exist in the version of the document being read?
	 *
	 * @param {object} node
	 * @return {boolean}
	 */

	_inVersion( node ) {

		return node.norm === undefined || this._version === null || node.norm.includes( this._version );

	}



	/**
	 * Reads every catalog node against a parsed document.
	 *
	 * @param {Document} doc A DOMParser-parsed document (CII: rsm:CrossIndustryInvoice root; UBL: Invoice root).
	 * @return {object} Nested value tree, keyed by BT/BG code - see flatten() for a diff-friendly view.
	 */

	read( doc ) {

		const result = {};

		this._version = this.normVersion( doc );
		this.lastVersion = this._version;

		try {

			for ( const rootIndex of this.roots )
				this._collect( rootIndex, doc.documentElement, result );

		} finally {

			this._version = null;

		}

		return result;

	}



	/**
	 * Direct (not deep) element children matching a literal, possibly prefixed, tag name.
	 *
	 * Deliberately element.children + tagName ===, not getElementsByTagName() - the latter returns
	 * ALL descendants, which would wrongly cross into a nested repeating group's own children (e.g. a
	 * line item's own tax category must not be found while resolving the document-level one of a
	 * DIFFERENT ancestor).
	 *
	 * @param {Element} el
	 * @param {string} tagName
	 * @return {Element[]}
	 */

	_directChildren( el, tagName ) {

		const out = [];

		for ( const child of el.children )
			if ( child.tagName === tagName )
				out.push( child );

		return out;

	}



	/**
	 * Evaluates a catalog `filter` predicate against a candidate element.
	 *
	 * TWO shapes occur in the catalogs: SEGMENT[/SEGMENT...]='literal' and the same with '!=' - e.g.
	 * CII's BG-20/BG-21 ram:ChargeIndicator/udt:Indicator='false'/'true', UBL's
	 * cbc:ChargeIndicator='false'/'true', and UBL's BT-32-00
	 * cac:TaxScheme/cbc:ID!='VAT'. Anything else throws, rather than silently misinterpreting a
	 * predicate this engine was never built to parse in general. The final segment may be an
	 * attribute (@name).
	 *
	 * ⚠️ **WHY `!=` EXISTS.** BT-32 is the seller's tax number - in UBL the same element as the VAT
	 * identifier BT-31 ( `cac:PartyTaxScheme/cbc:CompanyID` ), told apart only by the scheme next to it.
	 * BT-31 is the one with `VAT`; **BT-32 is every other one.** A filter `cac:TaxScheme/cbc:ID='TAX'`
	 * hits nothing: measured on 06.10.2026 over all sample documents, `TAX` occurs **zero times**, while
	 * 18 documents carry another scheme - `FC`, `ID`, `GST`, `63`. A filter on an equality that does
	 * not exist is dead code, and it does not stand out: the field simply stays empty.
	 *
	 * ⚠️ **A LIST OF THE OTHER SCHEMES WOULD BE THE WRONG WAY.** `FC`, `ID`, `GST` are what occurs in
	 * this corpus, not what is allowed - the next jurisdiction brings a fourth. The standard says
	 * "not VAT", so that is what stands here.
	 *
	 * A THIRD shape compares against ANOTHER FIELD of the same document instead of a literal:
	 * SEGMENT[/SEGMENT...]={BT-x} and the same with '!='. It exists for BT-110 / BT-111 only
	 * ( `@currencyID={BT-5}` and `@currencyID!={BT-5}` ), see _evalReference() for its rules.
	 *
	 * @param {Element} el
	 * @param {string} filter
	 * @return {boolean}
	 */

	_evalFilter( el, filter ) {

		const reference = filter.match( /^(.+?)(!?)=\{([A-Za-z0-9-]+)\}$/ );

		if ( reference )
			return this._evalReference( el, reference[ 1 ], reference[ 2 ] === '!', reference[ 3 ] );

		const match = filter.match( /^(.+?)(!?)='([^']*)'$/ );

		if ( ! match )
			throw new Error( 'TreeReader: unsupported filter shape "' + filter + '"' );

		const pathStr = match[ 1 ];
		const negiert = match[ 2 ] === '!';
		const expected = match[ 3 ];
		const segs = pathStr.split( '/' );
		const lastSeg = segs[ segs.length - 1 ];

		let current = el;

		for ( let i = 0; i < segs.length - 1; i++ ) {

			const kids = this._directChildren( current, segs[ i ] );

			/**
			 * ⚠️ **A MISSING INTERMEDIATE NODE IS NOT "SATISFIED" FOR `!=`.** Without the element there
			 * is no value that could be unequal - the condition is then undecided, and undecided means
			 * `false` here. Returning `true` would make the filter hit every document that lacks the
			 * element, and BT-32 would suddenly stand everywhere.
			 */
			if ( kids.length === 0 )
				return false;

			current = kids[ 0 ];

		}

		if ( lastSeg.charAt( 0 ) === '@' ) {

			const wert = current.getAttribute( lastSeg.slice( 1 ) ) ?? '';

			return negiert ? wert !== expected : wert === expected;

		}

		const kids = this._directChildren( current, lastSeg );

		if ( kids.length === 0 )
			return false;

		const wert = ( kids[ 0 ].textContent || '' ).trim();

		return negiert ? wert !== expected : wert === expected;

	}



	/**
	 * Evaluates a field-reference filter ( `PATH={BT-x}` / `PATH!={BT-x}` ) against a candidate element.
	 *
	 * Written for one pair: BT-110 is the `ram:TaxTotalAmount` ( UBL: `cac:TaxTotal/cbc:TaxAmount` )
	 * in the invoice currency BT-5, BT-111 any one in another currency. The rules are the ones of
	 * `ModelBuilder::summation_from_xml()` and `foreign_tax_total()` on the PHP side, so both readers
	 * pick the same element:
	 *
	 * - ⚠️ **AN EMPTY OR MISSING VALUE COUNTS AS EQUAL TO THE REFERENCED FIELD.** An amount without
	 *   `currencyID` is in the invoice currency, so it is BT-110 and never BT-111. That is the reason
	 *   for this rule, and it is why the shape is not a general "equals another field" operator: a
	 *   future use with a different meaning of "empty" needs a shape of its own, not this one.
	 * - ⚠️ **THE COMPARISON IGNORES CASE**, like PHP's `strcasecmp()`. Without that, `eur` against
	 *   `EUR` would make the same element BT-111 here and BT-110 there.
	 * - ⚠️ **BT-111 IS "NOT BT-5", NOT "EQUAL TO BT-6".** PHP compares only against BT-5, and so does
	 *   this filter. Whether the row shows the amount is determined later, in `totalsBlock()`, by BT-6
	 *   being set and different from BT-5 - again the same rule on both sides.
	 * - When BT-5 itself is missing, the referenced value is '' : an element without `currencyID` is
	 *   BT-110, one with any `currencyID` is BT-111. PHP passes '' for a missing BT-5 and behaves the same.
	 *
	 * @param {Element} el
	 * @param {string} pathStr SEGMENT[/SEGMENT...], the last one may be an attribute (@name).
	 * @param {boolean} negiert true for '!='.
	 * @param {string} fieldId The referenced business term, e.g. 'BT-5'.
	 * @return {boolean}
	 */

	_evalReference( el, pathStr, negiert, fieldId ) {

		const segs = pathStr.split( '/' );
		const lastSeg = segs[ segs.length - 1 ];

		let current = el;
		let wert = '';

		for ( let i = 0; i < segs.length - 1 && current; i++ )
			current = this._directChildren( current, segs[ i ] )[ 0 ] ?? null;

		if ( current ) {

			if ( lastSeg.charAt( 0 ) === '@' )
				wert = current.getAttribute( lastSeg.slice( 1 ) ) ?? '';
			else
				wert = ( this._directChildren( current, lastSeg )[ 0 ]?.textContent ?? '' );

		}

		wert = wert.trim();

		const gleich = wert === '' || wert.toUpperCase() === this._fieldValue( el.ownerDocument, fieldId ).toUpperCase();

		return negiert ? ! gleich : gleich;

	}



	/**
	 * The value of one business term in a document, read through this same catalog.
	 *
	 * Walks every catalog chain that ends in `id` from the document element down, taking at each step
	 * the occurrence _collect() would take ( the first one, or the sibling rank ), and returns the
	 * first value found. '' when the document does not carry the field.
	 *
	 * ⚠️ **EVERY CHAIN, NOT THE FIRST ONE.** The UBL catalog holds two trees, one for `Invoice` and one
	 * for `CreditNote`, and BT-5 exists in both. idChainTo() returns only the first ( the `Invoice`
	 * one ), and a credit note would then always get '' - every credit note in a foreign currency
	 * would lose its BT-110 without any visible error.
	 *
	 * ⚠️ **THE RESULT IS CACHED PER DOCUMENT**, because the filter runs once per candidate element.
	 * The cache is a WeakMap keyed by the document, so it needs no reset between two read() calls and
	 * holds nothing once the document is gone.
	 *
	 * @param {Document} doc
	 * @param {string} id
	 * @return {string}
	 */

	_fieldValue( doc, id ) {

		if ( ! doc )
			return '';

		this._fieldCache ??= new WeakMap();

		if ( ! this._fieldCache.has( doc ) )
			this._fieldCache.set( doc, new Map() );

		const cache = this._fieldCache.get( doc );

		if ( cache.has( id ) )
			return cache.get( id );

		/*
		 * ⚠️ A REFERENCE THAT LEADS BACK TO ITSELF WOULD RECURSE WITHOUT END. No catalog does that today
		 * ( BT-5's chain carries no filter ); the set of ids being resolved turns a future mistake into a clear error
		 * instead of a stack overflow in the browser.
		 */
		this._resolving ??= new Set();

		if ( this._resolving.has( id ) )
			throw new Error( 'TreeReader: field reference to ' + id + ' refers back to itself' );

		this._resolving.add( id );

		let value = '';

		try {

			for ( let target = 0; target < this.nodes.length && value === ''; target++ ) {

				if ( this.nodes[ target ].id !== id || ! this.nodes[ target ].value )
					continue;

				const chain = [];

				for ( let i = target; i !== null; i = this.nodes[ i ].parent )
					chain.unshift( i );

				let el = doc.documentElement;

				for ( const index of chain ) {

					const node = this.nodes[ index ];

					// ⚠️ The same version rule as _collect(), so a referenced field is never taken from a
					// node the tree itself leaves out.
					if ( ! this._inVersion( node ) ) {
						el = null;
						break;
					}

					const matches = this._matchesFor( node, el );
					const pick = node.rep ? 0 : ( this.siblingRank.get( index ) ?? 0 );

					el = matches[ pick ] ?? null;

					if ( ! el )
						break;

				}

				if ( el )
					value = this._valueOf( this.nodes[ target ], el );

			}

		} finally {

			this._resolving.delete( id );

		}

		cache.set( id, value );

		return value;

	}



	/**
	 * The elements matching one catalog node's `rel`, relative to its parent's context element.
	 *
	 * Handles both shapes found in the catalogs: a ROOT node's `rel` is an ABSOLUTE, possibly
	 * multi-segment path starting with the document element's own tag name (e.g. CII's BG-25:
	 * "/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem";
	 * UBL's ROOT: "/Invoice"); every other node's `rel` is exactly ONE segment relative to its
	 * immediate parent (a handful of UBL leaf nodes use two, e.g. "cac:TaxTotal/cbc:TaxAmount" - the
	 * loop below handles any segment count uniformly either way). Both are walked by the same segment
	 * loop - a root path's leading segment is verified against the context element (which for root
	 * nodes IS the document element) and stripped before the loop.
	 *
	 * @param {object} node
	 * @param {Element} contextEl
	 * @return {Element[]}
	 */

	_matchesFor( node, contextEl ) {

		if ( node.attr )
			return [ contextEl ]; // the attribute lives ON the context element itself, no descent

		let segs = node.rel.split( '/' ).filter( ( s ) => s.length > 0 );

		if ( node.parent === null ) {

			/**
			 * ⚠️ THE ROOT ELEMENT MAY CARRY A PREFIX. `ubl-nodes.json` names the root
			 * simply `Invoice`, but real XRechnung files write `<ubl:Invoice …>` - and
			 * `tagName` returns the qualified name including the prefix. Without this the comparison
			 * fails, and **silently**: no error, only an empty model. The viewer then shows nothing for
			 * such documents without saying why.
			 *
			 * Measured on 23.09.2026 over the corpus: without this rule **26 of 58 UBL documents** were
			 * read empty, 22 of them because of this prefix alone.
			 *
			 * ⚠️ IGNORE PREFIXES ONLY AT THE ROOT, NEVER DEEPER. In UBL `cbc:`
			 * and `cac:` distinguish real namespaces - `cbc:ID` and `cac:ID` are different things. Whoever
			 * cuts off prefixes further down confuses them. This is only about the one place
			 * where the document type is checked.
			 */
			if ( localNameOf( segs[ 0 ] ) !== localNameOf( contextEl.tagName ) )
				return []; // measured precondition failure - wrong document element, not an error

			segs = segs.slice( 1 );

		}

		let current = [ contextEl ];

		for ( const seg of segs ) {

			const next = [];

			for ( const el of current )
				next.push( ...this._directChildren( el, seg ) );

			current = next;

			if ( current.length === 0 )
				break;

		}

		if ( node.filter )
			current = current.filter( ( el ) => this._evalFilter( el, node.filter ) );

		return current;

	}



	/**
	 * The scalar value a `value:true` node carries on one matched element.
	 *
	 * ⚠️ **THIS VALUE IS TRIMMED, AND IT STAYS TRIMMED.** Every reader of the tree
	 * relies on it: codes are compared with `===`, dates go through an anchored pattern, amounts through
	 * `Number()`, and the display shows `f()`'s value as it is. Real documents are indented, so the text of
	 * a multi-line element begins with a line break and the indentation of the next line - layout of the
	 * file, not content of the invoice. The untrimmed text is kept next to it as `raw`, see `_rawOf()`.
	 *
	 * @param {object} node
	 * @param {Element} el
	 * @return {string}
	 */

	_valueOf( node, el ) {

		if ( node.attr )
			return el.getAttribute( node.rel.slice( 1 ) ) ?? '';

		return ( el.textContent ?? '' ).trim();

	}



	/**
	 * The text of one matched element exactly as the document writes it, or null when it equals the
	 * trimmed `value` ( which is the case for almost every element ).
	 *
	 * ⚠️ **A WRITER NEEDS THIS, THE DISPLAY DOES NOT.** XRechnung writes its cash
	 * discount terms into BT-20 as lines of the form `#SKONTO#TAGE=14#PROZENT=2.00#`, and rule BR-DE-18
	 * demands that EVERY line ends with a line break - the last one included. `trim()` removes that last
	 * line break, and an invoice written back from the trimmed value is rejected by KoSIT and VAMPIRE
	 * ( measured 09.10.2026 on KoSIT 01.10a, UBL and CII, in the e-invoice-eu round trip E3 ). Inner line
	 * breaks survive `trim()`; only the ends are lost, and only the ends are what this method adds back.
	 *
	 * ⚠️ **THE RAW TEXT CONTAINS THE INDENTATION OF THE FILE AS WELL.** Whether a leading line break plus
	 * ten spaces is content or layout cannot be determined here; XML says every character of an `xs:string`
	 * is content, and the norm gives no rule. So the tree keeps both and the consumer chooses: the display
	 * takes `value`, a writer that must reproduce the document takes `raw ?? value`.
	 *
	 * ⚠️ **ONLY WHEN IT DIFFERS, SO THE TREE STAYS UNCHANGED FOR EVERY OTHER ELEMENT.** Tests and tools
	 * that compare trees or walk `value` see no change for a document without such texts. Attributes are
	 * never trimmed in the first place and never get `raw`.
	 *
	 * ⚠️ **AN XML PARSER TURNS EVERY CR LF INTO LF BEFORE THIS CODE SEES IT** ( XML 1.0, section 2.11 ),
	 * and the browser's `DOMParser` does. `linkedom`, which the Node tests use, does not: a file checked
	 * out with Windows line endings then yields `\r\n` here. That is a property of the test parser, not
	 * of the document, and it is deliberately not "repaired" in this place.
	 *
	 * @param {object} node
	 * @param {Element} el
	 * @param {string} value What `_valueOf()` returned for the same element.
	 * @return {string|null}
	 */

	_rawOf( node, el, value ) {

		if ( node.attr )
			return null;

		const raw = el.textContent ?? '';

		return raw === value ? null : raw;

	}



	/**
	 * Recursively resolves one node (and its subtree) against a context element, writing into
	 * out[node.id] - an array for a repeating node (one entry per occurrence), a single object
	 * otherwise, or nothing at all when the node is genuinely absent from this document (mirrors
	 * ModelBuilder.php's own "swallow the exception, leave the field unset" behaviour, see
	 * ModelBuilder::call()).
	 *
	 * @param {number} nodeIndex
	 * @param {Element} contextEl
	 * @param {object} out
	 * @return {void}
	 */

	_collect( nodeIndex, contextEl, out ) {

		const node = this.nodes[ nodeIndex ];

		// A node of another version of the standard: absent, with its whole subtree ( see read() ).
		if ( ! this._inVersion( node ) )
			return;

		const matches = this._matchesFor( node, contextEl );
		const childIndices = this.childrenOf.get( nodeIndex ) || [];

		const build = ( el ) => {

			const entry = {};

			if ( node.value ) {

				entry.value = this._valueOf( node, el );

				// ⚠️ The untrimmed text, only where it differs - see _rawOf().
				const raw = this._rawOf( node, el, entry.value );

				if ( raw !== null )
					entry.raw = raw;

			}

			for ( const childIndex of childIndices )
				this._collect( childIndex, el, entry );

			return entry;

		};

		if ( node.rep ) {

			if ( matches.length === 0 )
				return;

			out[ node.id ] = matches.map( build );

		} else {

			const rank = this.siblingRank.get( nodeIndex ) ?? 0;

			if ( matches.length <= rank )
				return;

			out[ node.id ] = build( matches[ rank ] );

		}

	}



	/**
	 * The chain of node ids from the nearest root anchor down to one target id, e.g.
	 * idChainTo('BT-31') -> ['BT-10-00', 'BG-4', 'BT-31-00', 'BT-31']. Node ids are unique across the
	 * whole catalog (two nodes sharing a `path`/`rel` - see this class's own doc block - always have
	 * DIFFERENT ids, e.g. BG-20 vs BG-21), so this is a safe single-source-of-truth alternative to
	 * hand-writing the chain at every call site in ModelAssembler.mjs.
	 *
	 * @param {string} id
	 * @return {string[]|null} null when the id is not in the catalog.
	 */

	/**
	 * Does this catalogue know the business term at all?
	 *
	 * ⚠️ **THE QUESTION IS "DOES THE FIELD EXIST IN THIS SYNTAX?", NOT "IS THERE A VALUE IN IT?".**
	 * Confusing the two makes a card append its row only when the value is not empty
	 * ( `if ( globalId !== '' )` ), reasoned with the field not existing in UBL. In CII the field
	 * then disappears for a document that simply did not fill it - while every other empty field of
	 * the same card stands there as "—" ( BT-71-0/BT-71-1 under BG-13 were affected ).
	 *
	 * With this answer the **syntax** decides about the row and the **value** about its
	 * content - as for all other fields, and as the switch "only filled fields" requires.
	 *
	 * @param {string} id
	 * @return {boolean}
	 */

	knows( id ) {

		return this.nodes.some( ( n ) => n.id === id );

	}



	idChainTo( id ) {

		const index = this.nodes.findIndex( ( n ) => n.id === id );

		if ( index === -1 )
			return null;

		const chain = [];
		let current = index;

		while ( current !== null ) {
			chain.unshift( this.nodes[ current ].id );
			current = this.nodes[ current ].parent;
		}

		return chain;

	}



	/**
	 * Flattens a read() tree into a diff-friendly list of leaf occurrences.
	 *
	 * @param {object} tree
	 * @param {string} [pathPrefix]
	 * @return {Array<{id: string, path: string, value: string}>}
	 */

	static flatten( tree, pathPrefix = '' ) {

		const out = [];

		for ( const [ id, node ] of Object.entries( tree ) ) {

			const occurrences = Array.isArray( node ) ? node : [ node ];

			occurrences.forEach( ( occurrence, i ) => {

				const path = Array.isArray( node ) ? pathPrefix + id + '[' + i + ']' : pathPrefix + id;

				// ⚠️ `raw` rides along only where the tree has it, so a flattened tree without such
				// texts keeps exactly its usual shape.
				if ( occurrence.value !== undefined )
					out.push( occurrence.raw !== undefined ? { id, path, value: occurrence.value, raw: occurrence.raw } : { id, path, value: occurrence.value } );

				// ⚠️ `raw` is a property of the occurrence, not a child node - walking into it as if it were
				// one would treat a string as a subtree.
				const rest = {};
				for ( const key of Object.keys( occurrence ) )
					if ( key !== 'value' && key !== 'raw' )
						rest[ key ] = occurrence[ key ];

				out.push( ...TreeReader.flatten( rest, path + '/' ) );

			} );

		}

		return out;

	}

}


/**
 * The name without a prefix.
 *
 * ⚠️ Meant only for comparing the ROOT ELEMENT - see _matchesFor(). Deeper in the tree
 * prefixes carry meaning in UBL ( cbc: versus cac: ) and must not be dropped.
 *
 * @param {string} name
 * @return {string}
 */
function localNameOf( name ) {

	const s = String( name || '' );
	const cut = s.lastIndexOf( ':' );

	return cut === -1 ? s : s.slice( cut + 1 );

}
