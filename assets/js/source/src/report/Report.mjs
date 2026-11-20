// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The parsed validation report - the single shape every reader produces.
 *
 * ⚠️ LINE-FOR-LINE PORT of libs/einvoice-report/src/Report.php (PHP). Keep the two in sync by hand;
 * there is no code generator for this. Logic, comments and even
 * the field shapes are deliberately identical to the PHP source so a diff between the two stays
 * meaningful. See that file's own doc block for the "why" behind each shape - not repeated here.
 *
 * @since 1.4.0
 */

export class Report {

	/** The order of the row levels: the stronger statement wins on a row - twin of `Report::STATUS_RANK`. */
	static STATUS_RANK = { '': 0, info: 1, warning: 2, error: 3 };

	/**
	 * The level of a finding as the status of a row - twin of `Report::row_status()`.
	 *
	 * @param {string} severity `error`, `warning`, `information`
	 * @return {'error'|'warning'|'info'}
	 */
	static rowStatus( severity ) {
		const s = String( severity || '' );
		return s === 'error' ? 'error' : ( s === 'warning' ? 'warning' : 'info' );
	}

	constructor() {

		/** @type {Object<string,string>} */
		this.meta = {};

		/** @type {Array<{step:string, errors:number, warnings:number, infos:number}>} */
		this.steps = [];

		/** @type {Array<{position:string, rule:string, severity:string, message:string, path:string, rows:number[]}>} */
		this.findings = [];

		/** @type {Array<{pos:string, level:number, name:string, value:string, attr:string, path:string, status:string}>} */
		this.document = [];

		/** @type {string[]} */
		this.notices = [];

	}


	/**
	 * How many findings of a severity there are.
	 *
	 * @param {string} severity 'error', 'warning' or 'information'
	 * @return {number}
	 */

	count( severity ) {
		let n = 0;
		for ( const f of this.findings )
			if ( ( f.severity || '' ) === severity )
				n++;
		return n;
	}


	/**
	 * Whether the report itself says the document was rejected.
	 *
	 * ⚠️ ASK THE REPORT, DO NOT COUNT - see Report.php's own comment. Deriving "rejected" from
	 * "errors > 0" would usually agree, but a report can carry errors from a step that does not block.
	 *
	 * @return {boolean}
	 */

	rejected() {
		const stated = String( this.meta.compliant || '' );
		if ( stated === 'no' ) return true;
		if ( stated === 'yes' ) return false;
		return this.count( 'error' ) > 0;
	}


	/**
	 * Reconstruct an XPath for every document row - see ReaderXml/ReaderHtml for how a row's `level`
	 * and `name` are populated; this only ever reads those two fields plus writes `path`.
	 *
	 * @return {void}
	 */

	buildPaths() {

		const stack = new Map();   // level => the INDEXED segment, e.g. "IncludedNote[3]"
		const seen  = new Map();   // full indexed parent path + "/" + name => how often seen

		for ( const row of this.document ) {

			const level = Number( row.level );

			/*
			 * ⚠️ THE LOCAL NAME, NOT `row.name` AS IT STANDS. The readers of KoSIT reports write
			 * names without prefixes, so for them nothing changes. A document whose rows carry
			 * `ram:ID` would otherwise get `ram:ID[1]` here, and `resolveField()` compares with a
			 * needle that `normalisePath()` has stripped to `ID[1]` - no row would ever match.
			 */
			const name  = localPart( row.name );

			for ( const l of [ ...stack.keys() ] )
				if ( l >= level )
					stack.delete( l );

			let parent = '';
			for ( let l = 1; l < level; l++ )
				if ( stack.has( l ) )
					parent += '/' + stack.get( l );

			const key = parent + '/' + name;
			seen.set( key, ( seen.get( key ) || 0 ) + 1 );

			const segment = name + '[' + seen.get( key ) + ']';

			row.path = parent + '/' + segment;
			stack.set( level, segment );

		}

	}


	/**
	 * Attach findings to document rows and mark those rows.
	 *
	 * @return {void}
	 */

	linkFindings() {

		if ( ! this.document.length )
			return;

		if ( this.document[ 0 ].path === undefined || this.document[ 0 ].path === '' )
			this.buildPaths();

		this.findings.forEach( ( finding, i ) => {

			const path = String( finding.path || '' );
			if ( path === '' )
				return;

			const rows = [];
			this.document.forEach( ( row, j ) => {
				if ( Report.pathMatches( path, String( row.path ) ) )
					rows.push( j );
			} );

			this.findings[ i ].rows = rows;

			// ⚠️ Three levels: error, warning, info. An information marked its row as a warning until 19.10.2026 ( Ralf ).
			const mark = Report.rowStatus( finding.severity );

			for ( const j of rows )
				if ( Report.STATUS_RANK[ mark ] > ( Report.STATUS_RANK[ this.document[ j ].status || '' ] || 0 ) )
					this.document[ j ].status = mark;

		} );

	}


	/**
	 * Find the document row a BT/BG field's OWN XPath (from profile data, not from a finding)
	 * addresses. Silent on ambiguity - more than one candidate and no way to prefer one means no
	 * value is shown at all, rather than a wrong one dressed up as a match.
	 *
	 * @param {string} xpath CII or UBL, matching nearIndex's syntax
	 * @param {number|null} nearIndex a document row index whose subtree is searched first, or null
	 * @return {{row: number|null, ambiguous: boolean}}
	 */

	resolveField( xpath, nearIndex = null ) {

		const rows = this.fieldRows( xpath, nearIndex );

		if ( rows.length === 1 )
			return { row: rows[ 0 ], ambiguous: false };

		return { row: null, ambiguous: rows.length > 1 };

	}


	/**
	 * Every document row a BT/BG field's own XPath addresses - twin of `Report::field_rows()`.
	 *
	 * ⚠️ **A STEP WITHOUT AN INDEX MEANS "ANY", NOT "THE FIRST".** The field XPaths of the profile data
	 * name no index ( `…/IncludedSupplyChainTradeLineItem/…/LineTotalAmount` for BT-131 ). Until
	 * 23.10.2026 they went through `normalisePath()`, which adds `[1]` to every step, and so a line-level
	 * field always meant the FIRST invoice line. Measured on an SAP invoice with three lines: under
	 * BR-CO-10, "Σ Invoice line net amount (BT-131)" showed 4,436.61, the net amount of line 1, while the
	 * three lines add up to 5,174.61; and only the first line was marked in the document tree. Only an
	 * index the XPath states itself is compared now.
	 *
	 * ⚠️ **FROM THE ANCHOR UPWARDS, THE FIRST ELEMENT THAT HOLDS A MATCH WINS.** A finding often anchors on
	 * a leaf: BR-S-08 on `ApplicableTradeTax[2]/CategoryCode`, whose rate ( BT-119 ) and basis ( BT-116 )
	 * are siblings, not children. Searching only below the anchor found nothing there, and the old `[1]`
	 * then showed the rate of the FIRST VAT breakdown under a finding about the second ( measured on the
	 * official test report zugferd-e05-totals-errors ). Going up one level at a time finds the values of
	 * the breakdown the finding is about, and a line-level field under a header finding climbs to the root
	 * and comes back with every line; the caller decides what more than one means.
	 *
	 * @param {string} xpath CII or UBL, matching nearIndex's syntax
	 * @param {number|null} nearIndex a document row index whose subtree is searched first, or null
	 * @return {number[]}
	 */

	fieldRows( xpath, nearIndex = null ) {

		if ( ! this.document.length )
			return [];

		/*
		 * ⚠️ AN EMPTY PATH COUNTS AS MISSING. A check for `undefined` alone takes `path: ''` for
		 * "already built", and no field ever matches. Same check in `Report::field_rows()`.
		 */
		if ( this.document[ 0 ].path === undefined || this.document[ 0 ].path === '' )
			this.buildPaths();

		const trimmed = String( xpath || '' ).replace( /\s+\([^)]*\)\s*$/, '' ).trim();
		const steps   = Report.fieldPattern( trimmed );

		if ( ! steps.length )
			return [];

		let matches = [];
		this.document.forEach( ( row, j ) => {
			if ( Report.patternMatches( String( row.path || '' ), steps, false ) )
				matches.push( j );
		} );

		if ( ! matches.length )
			this.document.forEach( ( row, j ) => {
				if ( Report.patternMatches( String( row.path || '' ), steps, true ) )
					matches.push( j );
			} );

		for ( let a = nearIndex; matches.length && a !== null && this.document[ a ] !== undefined; a = parentRow( this.document, a ) ) {

			const scoped = matches.filter( j => j === a || rowInSubtree( this.document, a, j ) );

			if ( scoped.length )
				return scoped;

		}

		return matches;

	}


	/**
	 * The steps of a field XPath as { name, index }, `index` null where the XPath states none - twin of
	 * `Report::field_pattern()`. Prefixes and predicates go the same way as in `normalisePath()`.
	 *
	 * @param {string} xpath
	 * @return {Array<{name: string, index: string|null}>}
	 */

	static fieldPattern( xpath ) {

		const p = String( xpath || '' ).trim().replace( /\\/g, '/' ).replace( /\/+/g, '/' ).replace( /\/+$/, '' );
		const out = [];

		for ( let step of p.replace( /^\//, '' ).split( '/' ) ) {

			if ( step === '' ) continue;

			const m     = [ ...step.matchAll( /\[(\d+)\]/g ) ];
			const index = m.length ? m[ m.length - 1 ][ 1 ] : null;

			step = step.replace( /\[[^\]]*\]/g, '' ).replace( /^Q\{[^}]*\}/, '' ).replace( /^(?:\*|[A-Za-z][A-Za-z0-9_.-]*):/, '' ).trim();

			if ( step !== '' )
				out.push( { name: step, index } );

		}

		return out;

	}


	/**
	 * Whether a row path like `/A[1]/B[2]` fits a field pattern - twin of `Report::pattern_matches()`.
	 *
	 * @param {string} path the indexed path `buildPaths()` gave the row
	 * @param {Array<{name: string, index: string|null}>} steps
	 * @param {boolean} suffix true: the pattern only has to match the end of a longer path
	 * @return {boolean}
	 */

	static patternMatches( path, steps, suffix ) {

		const segs = path.split( '/' ).filter( s => s !== '' );

		if ( suffix ? segs.length <= steps.length : segs.length !== steps.length )
			return false;

		const offset = segs.length - steps.length;

		for ( let i = 0; i < steps.length; i++ ) {

			const m = /^(.*)\[(\d+)\]$/.exec( segs[ offset + i ] );

			if ( ! m || m[ 1 ] !== steps[ i ].name || ( steps[ i ].index !== null && m[ 2 ] !== steps[ i ].index ) )
				return false;

		}

		return true;

	}


	/**
	 * The supporting documents ( BG-24 ) of the document, read from its flat rows - twin of
	 * `Report::attachments()` in PHP.
	 *
	 * ⚠️ **ONLY THE HEADER LEVEL.** In CII the element is BG-24 only when its parent is
	 * `ApplicableHeaderTradeAgreement`; under an invoice line the same element is the line object
	 * identifier ( BT-128 ). In UBL it sits directly under the root, which is level 2.
	 *
	 * ⚠️ **AND NOT THE TYPE CODES 130 AND 50.** CII and UBL use the same element for the invoiced object
	 * identifier ( BT-18 ) and the tender reference ( BT-17 ) - see `NOT_AN_ATTACHMENT` in
	 * `src/model/Attachments.mjs`.
	 *
	 * ⚠️ **THE FILE NAME ONLY WHEN THE ROW NAMES IT.** A document view built from the invoice itself
	 * ( `DocumentView` ) writes attributes as `filename="Aufmass.pdf"`. The document table of a KoSIT
	 * HTML report keeps the attribute values but drops their names ( "application/pdf · Aufmass.pdf" );
	 * which of the two values is the file name is not stated there, and guessing it from the shape of
	 * the text is exactly the kind of guess this file does not make. The label then falls back to the
	 * description ( BT-123 ) and the reference ( BT-122 ).
	 *
	 * @return {Array<{row: number, id: string, description: string, fileName: string, label: string}>}
	 */

	attachments() {

		const out = [];
		const doc = this.document;

		for ( let j = 0; j < doc.length; j++ ) {

			const name = localPart( doc[ j ].name );

			if ( name !== 'AdditionalReferencedDocument' && name !== 'AdditionalDocumentReference' ) continue;

			const level = Number( doc[ j ].level );

			if ( name === 'AdditionalReferencedDocument' ) {

				const parent = parentRow( doc, j );

				if ( parent === null || localPart( doc[ parent ].name ) !== 'ApplicableHeaderTradeAgreement' ) continue;

			} else if ( level !== 2 ) {

				continue;

			}

			let id = '', description = '', fileName = '', typeCode = '';

			for ( let k = j + 1; k < doc.length && Number( doc[ k ].level ) > level; k++ ) {

				const child = localPart( doc[ k ].name );
				const direct = Number( doc[ k ].level ) === level + 1;
				const value = String( doc[ k ].value || '' ).trim();

				if ( direct && ( child === 'IssuerAssignedID' || child === 'ID' ) && id === '' ) id = value;
				else if ( direct && ( child === 'Name' || child === 'DocumentDescription' ) && description === '' ) description = value;
				else if ( direct && ( child === 'TypeCode' || child === 'DocumentTypeCode' ) ) typeCode = value;
				else if ( ( child === 'AttachmentBinaryObject' || child === 'EmbeddedDocumentBinaryObject' ) && fileName === '' ) {

					const m = /(?:^|\s)filename="([^"]*)"/.exec( String( doc[ k ].attr || '' ) );

					if ( m ) fileName = m[ 1 ].trim();

				}

			}

			if ( typeCode === '130' || typeCode === '50' ) continue;

			out.push( { row: j, id, description, fileName, label: fileName || description || id } );

		}

		return out;

	}


	/**
	 * The attachments a finding is about - twin of `Report::attachments_for()` in PHP.
	 *
	 * 1. Does the finding point into an attachment ( its first row lies in a BG-24 subtree, or is the
	 *    BG-24 row itself )? Then that one attachment.
	 * 2. Otherwise, is the rule about attachments ( `Report.touchesAttachments( fields )` )? Then all of
	 *    them - BR-DE-22, for example, demands unique file names across all attachments and points at
	 *    the invoice as a whole.
	 * 3. Otherwise none.
	 *
	 * @param {object} finding
	 * @param {string[]} fields The rule's business terms.
	 * @return {Array<object>} Entries of `attachments()`.
	 */

	attachmentsFor( finding, fields ) {

		const all = this.attachments();

		if ( ! all.length ) return [];

		const anchor = ( finding && finding.rows && finding.rows.length ) ? finding.rows[ 0 ] : null;

		if ( anchor !== null && anchor !== undefined )
			for ( const a of all )
				if ( a.row === anchor || rowInSubtree( this.document, a.row, anchor ) )
					return [ a ];

		return Report.touchesAttachments( fields ) ? all : [];

	}


	/**
	 * Is a rule about attachments - does it name BG-24 or one of BT-122 to BT-125?
	 *
	 * ⚠️ **SUFFIXES COUNT.** The rule data names `BT-125-2` ( the file name, BR-RO-L211 ) and `BT-122-0`
	 * ( the type code, FX-SCH-A-000559 ); both are about an attachment as much as `BT-125` is.
	 *
	 * @param {string[]} fields
	 * @return {boolean}
	 */

	static touchesAttachments( fields ) {

		return ( fields || [] ).some( ( f ) => /^(BG-24|BT-12[2-5])(-|$)/.test( String( f ) ) );

	}


	/**
	 * Does a finding's XPath address this document row?
	 *
	 * @param {string} findingPath from the report, with namespace prefixes
	 * @param {string} rowPath reconstructed, without prefixes
	 * @return {boolean}
	 */

	static pathMatches( findingPath, rowPath ) {
		const a = Report.normalisePath( findingPath );
		const b = Report.normalisePath( rowPath );
		if ( a === '' || b === '' ) return false;
		if ( a === b ) return true;

		// ⚠️ A bare `*` step ( VAMPIRE's UBL root `/*` ) matches any name with the same index - see
		// `Report::path_matches()` in PHP for the measurement ( 0 of 28 UBL findings linked before, 28 of 28 after ).
		if ( a.indexOf( '*[' ) === -1 ) return false;

		const sa = a.split( '/' );
		const sb = b.split( '/' );

		if ( sa.length !== sb.length ) return false;

		return sa.every( ( seg, k ) => seg === sb[ k ] || ( seg.indexOf( '*[' ) === 0 && seg.slice( 1 ) === sb[ k ].replace( /^[^[]*/, '' ) ) );
	}


	/**
	 * Bring a path into the one form both sides can be compared in - three writing conventions seen
	 * in the wild ( plain prefixed, wildcard-with-namespace-predicate, Clark notation `Q{uri}Name` ),
	 * reduced to the same shape. See the PHP Report.php's own doc block - unchanged here, only ported.
	 *
	 * @param {string} path
	 * @return {string}
	 */

	static normalisePath( path ) {

		let p = String( path || '' ).trim();
		if ( p === '' ) return '';

		p = p.replace( /\\/g, '/' ).replace( /\/+/g, '/' ).replace( /\/+$/, '' );

		const out = [];

		for ( let step of p.replace( /^\//, '' ).split( '/' ) ) {

			if ( step === '' ) continue;

			let index = '1';
			const m = [ ...step.matchAll( /\[(\d+)\]/g ) ];
			if ( m.length ) index = m[ m.length - 1 ][ 1 ];

			step = step.replace( /\[[^\]]*\]/g, '' );
			step = step.replace( /^Q\{[^}]*\}/, '' );
			step = step.replace( /^(?:\*|[A-Za-z][A-Za-z0-9_.-]*):/, '' );
			step = step.trim();

			if ( step === '' ) continue;

			out.push( step + '[' + index + ']' );

		}

		return out.length ? '/' + out.join( '/' ) : '';

	}

}


/**
 * The name without its namespace prefix - `ram:Name` and `Name` both give `Name`.
 *
 * @param {string} name
 * @return {string}
 */

function localPart( name ) {

	return String( name ?? '' ).replace( /^.*:/, '' );

}


/**
 * The row of the parent element in the flat, level-tagged document, or null for the root.
 *
 * @param {Array} document
 * @param {number} row
 * @return {number|null}
 */

function parentRow( document, row ) {

	const level = Number( document[ row ]?.level ?? 0 );

	for ( let k = row - 1; k >= 0; k-- )
		if ( Number( document[ k ].level ) < level ) return k;

	return null;

}


/**
 * Is `candidate` a descendant of `anchor` in the flat, level-tagged document?
 *
 * @param {Array} document
 * @param {number} anchor
 * @param {number} candidate
 * @return {boolean}
 */

function rowInSubtree( document, anchor, candidate ) {

	if ( candidate <= anchor || document[ anchor ] === undefined )
		return false;

	const level = Number( document[ anchor ].level );

	for ( let k = anchor + 1; k < candidate; k++ )
		if ( Number( document[ k ]?.level ?? 0 ) <= level )
			return false;

	return Number( document[ candidate ]?.level ?? 0 ) > level;

}
