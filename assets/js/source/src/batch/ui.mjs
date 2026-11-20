// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The batch check as a block of a page: drop many invoices, a folder or a ZIP, watch them being checked, then filter, sort
 * and export the overview, and open the single report of any row.
 *
 *   const batch = mountBatch( element, {
 *       t,                     // translates an English text ( the key ) into the language of the page
 *       lang: () => 'de',      // the language of the page, for numbers and dates
 *       check: async ( item ) => ( { report, serviceVerdict, keep } ),
 *       open: ( row, item ) => …,   // shows the single report of a row
 *       concurrency: 2,
 *       tool: 'eInvoice Validator for KoSIT',
 *       Parser: window.DOMParser,
 *   } );
 *   batch.render();            // after a language switch
 *
 * ⚠️ **THE PAGE DECIDES WHERE AN INVOICE GOES, NOT THIS MODULE.** `check` is the only way out: the apps for KoSIT and
 * Mustang pass the validation service of `config.js`, the app for VAMPIRE passes VAMPIRE JS in the browser, the WordPress
 * plugin passes its own AJAX check. Nothing in this file calls `fetch()`.
 *
 * ⚠️ **NO NEW REPORT VIEW.** A click on a row hands the row to `open`, and the page draws the report the way it draws a
 * single check. The overview and the single view therefore never disagree about a finding.
 *
 * ⚠️ **THE BLOCK USES THE CLASSES OF THE DESIGN KIT** ( `.drop`, `.btn`, `.chip`, `.badge`, `.tbl`, `.note` ) and adds only
 * what the kit has no class for: the progress bar, the sortable column heads and the clickable rows. Those few rules come
 * along as a `<style>` in the block itself, so that they also reach a shadow root, as in the WordPress plugin.
 *
 * @since 26.10.2026
 */

import { entriesFromDrop, filesFromEntries, prepareItems, MAX_ITEMS } from './collect.mjs';
import { invoiceHeader } from './header.mjs';
import { runQueue } from './queue.mjs';
import { rowFrom, counts, batchCsv, batchReportHtml, verdictLabel, noteLabel, fill, money, signedAmount, UNCHECKED } from './summary.mjs';

/** The few rules the kit has no class for. Kit tokens only, so that every kit colours them. */
export const BATCH_CSS = `
.bx { display: flex; flex-direction: column; gap: 14px; }
.bx-pick { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; margin-top: -6px; }
.bx-run { display: flex; flex-direction: column; gap: 6px; }
.bx-bar { height: 8px; border-radius: 999px; background: var( --surface-2, #eef1f4 ); overflow: hidden; border: 1px solid var( --border, #d9dee5 ); }
.bx-bar > span { display: block; height: 100%; width: 0; background: var( --accent, #2a5bd7 ); transition: width .2s; }
.bx-run-line { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; justify-content: space-between; font-size: var( --fs-sm, 13px ); }
.bx-filter { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.bx-filter .inp { max-width: 260px; }
.bx-actions { display: flex; flex-direction: row-reverse; flex-wrap: wrap-reverse; gap: 8px; }
.bx-reset { display: flex; justify-content: flex-end; }
.bx-table-wrap { overflow-x: auto; }
.bx-table th button { all: unset; cursor: pointer; }
.bx-table th button:focus-visible { outline: 2px solid var( --accent, #2a5bd7 ); outline-offset: 2px; }
.bx-table th[aria-sort="ascending"] button::after { content: " \\2191"; }
.bx-table th[aria-sort="descending"] button::after { content: " \\2193"; }
.bx-table tbody tr.bx-open { cursor: pointer; }
.bx-table tbody tr.bx-open:focus-visible { outline: 2px solid var( --accent, #2a5bd7 ); outline-offset: -2px; }
.bx-table .bx-sub { display: block; font-size: var( --fs-xs, 11px ); color: var( --muted, #5b6573 ); word-break: break-all; }
.bx-table .bx-rules { font-family: var( --mono, monospace ); font-size: var( --fs-xs, 12px ); }
.bx-table td.bx-name { max-width: 260px; word-break: break-word; }
.bx-empty { padding: 14px; color: var( --muted, #5b6573 ); font-size: var( --fs-sm, 13px ); }
`;

/** The order of the verdicts when the column is sorted: the bad ones first in descending order. */
const RANK = { accepted: 1, warnings: 2, rejected: 3, error: 4, noinvoice: 5, skipped: 6 };

const BADGE = { accepted: 'ok', warnings: 'warn', rejected: 'err', error: 'err', noinvoice: 'mute', skipped: 'mute' };

/**
 * @param {HTMLElement} root
 * @param {object} opts
 * @return {{ render: Function, reset: Function, add: Function, rows: Function, state: Function }}
 */
export function mountBatch( root, opts ) {

	const t = opts.t || ( ( s ) => s );
	const lang = typeof opts.lang === 'function' ? opts.lang : () => String( opts.lang || 'en' );
	const Parser = opts.Parser || ( typeof DOMParser !== 'undefined' ? DOMParser : null );
	const doc = root.ownerDocument || document;

	/** The items of the current batch, in the order they came. */
	let items = [];

	/** Filter and sort of the table. */
	let filter = 'all';
	let query = '';
	let sortKey = '';
	let sortDir = 1;

	/** The running batch: its AbortController, or null. */
	let running = null;

	/** The last finished run, for the summary line: { ms, checked }. */
	let last = null;

	let waitText = '';
	let renderTimer = 0;
	let selectedId = -1;
	let nextId = 1;

	const el = ( tag, attrs = {}, ...kids ) => {
		const n = doc.createElement( tag );
		for ( const [ k, v ] of Object.entries( attrs ) ) {
			if ( v === null || v === undefined || v === false ) continue;
			if ( k === 'class' ) n.className = v;
			else if ( k === 'text' ) n.textContent = v;
			else n.setAttribute( k, v === true ? '' : String( v ) );
		}
		for ( const k of kids ) if ( k !== null && k !== undefined ) n.append( k );
		return n;
	};

	/* ------------------------------------------------------------------ the frame */

	root.textContent = '';
	root.classList.add( 'bx' );

	const style = el( 'style' );
	style.textContent = BATCH_CSS;

	const files = el( 'input', { type: 'file', multiple: true, hidden: true, accept: '.xml,.pdf,.zip,.eml,application/xml,text/xml,application/pdf,application/zip' } );
	const folder = el( 'input', { type: 'file', multiple: true, hidden: true, webkitdirectory: true } );

	const dropTitle = el( 'div', { class: 'drop-title' } );
	const dropSub = el( 'div', { class: 'drop-sub' } );
	const drop = el( 'div', { class: 'drop bx-drop', tabindex: '0', role: 'button' }, dropTitle, dropSub, files, folder );

	const folderBtn = el( 'button', { type: 'button', class: 'btn' } );
	const pick = el( 'div', { class: 'bx-pick' }, folderBtn );

	const bar = el( 'span' );
	const runText = el( 'span', { role: 'status', 'aria-live': 'polite' } );
	const cancelBtn = el( 'button', { type: 'button', class: 'btn' } );
	const run = el( 'div', { class: 'bx-run', hidden: true }, el( 'div', { class: 'bx-bar' }, bar ), el( 'div', { class: 'bx-run-line' }, runText, cancelBtn ) );

	const message = el( 'div', { class: 'note', hidden: true } );

	const chips = el( 'div', { class: 'bx-filter', role: 'group' } );
	const search = el( 'input', { type: 'search', class: 'inp' } );

	const csvBtn = el( 'button', { type: 'button', class: 'btn' } );
	const htmlBtn = el( 'button', { type: 'button', class: 'btn' } );
	const resetBtn = el( 'button', { type: 'button', class: 'btn' } );

	const thead = el( 'thead' );
	const tbody = el( 'tbody' );
	const table = el( 'table', { class: 'tbl bx-table' }, thead, tbody );

	const overview = el( 'div', { class: 'bx-overview', hidden: true },
		el( 'div', { class: 'bx-filter' }, chips, search ),
		el( 'div', { class: 'bx-actions' }, csvBtn, htmlBtn ),
		el( 'div', { class: 'bx-reset' }, resetBtn ),
		el( 'div', { class: 'panel bx-table-wrap' }, table ),
	);

	root.append( style, drop, pick, run, message, overview );

	/* ------------------------------------------------------------------ texts */

	const say = ( text, type ) => {
		message.textContent = text || '';
		message.className = 'note ' + ( { error: 'err', warn: 'warn', ok: 'ok' }[ type ] || 'info' );
		message.hidden = ! text;
	};

	const COLUMNS = [
		[ 'name', 'File' ],
		[ 'number', 'Invoice number' ],
		[ 'seller', 'Seller' ],
		[ 'issueDate', 'Issue date' ],
		[ 'total', 'Amount', 'num' ],
		[ 'verdict', 'Verdict' ],
		[ 'errors', 'Errors', 'num' ],
		[ 'rules', 'Most frequent rules' ],
	];

	const texts = () => {

		dropTitle.textContent = t( 'Drag invoices, a folder or a ZIP here, or click to choose files' );
		dropSub.textContent = t( 'XML, ZUGFeRD / Factur-X PDF, ZIP or e-mail ( .eml ), as many as you like' );
		drop.setAttribute( 'aria-label', t( 'Choose invoices for the batch check' ) );
		folderBtn.textContent = t( 'Choose a folder' );
		cancelBtn.textContent = t( 'Cancel' );
		search.setAttribute( 'placeholder', t( 'Search file, number, seller or rule' ) );
		search.setAttribute( 'aria-label', t( 'Search file, number, seller or rule' ) );
		chips.setAttribute( 'aria-label', t( 'Show' ) );
		csvBtn.textContent = t( 'Download overview ( CSV )' );
		htmlBtn.textContent = t( 'Summary report ( HTML )' );
		resetBtn.textContent = t( 'Reset' );

		thead.textContent = '';
		const tr = el( 'tr' );
		for ( const [ key, label, cls ] of COLUMNS ) {
			const th = el( 'th', { class: cls || null, scope: 'col', 'aria-sort': sortKey === key ? ( sortDir > 0 ? 'ascending' : 'descending' ) : null } );
			const b = el( 'button', { type: 'button', 'data-sort': key, text: t( label ) } );
			th.append( b );
			tr.append( th );
		}
		thead.append( tr );

	};

	/* ------------------------------------------------------------------ rows */

	const rowOf = ( it ) => {

		if ( ! it.row || it.rowState !== it.state ) {
			const res = it.result || null;
			it.row = rowFrom( it, it.header, res ? res.report : null, {
				serviceVerdict: res ? res.serviceVerdict : '',
				message: it.error ? String( it.error.message || it.error ) : '',
			} );
			it.row.id = it.id;
			it.rowState = it.state;
		}

		return it.row;

	};

	const visible = () => {

		const q = query.trim().toLowerCase();

		let rows = items.map( rowOf ).filter( ( r ) => {
			if ( filter === 'unchecked' ? ! UNCHECKED.includes( r.verdict ) : filter !== 'all' && r.verdict !== filter ) return false;
			if ( q === '' ) return true;
			const hay = [ r.name, r.path, r.number, r.seller, ...r.rules.map( ( x ) => x.rule ) ].join( ' ' ).toLowerCase();
			return hay.includes( q );
		} );

		if ( sortKey ) {
			const val = ( r ) => {
				if ( sortKey === 'verdict' ) return RANK[ r.verdict ] || 9;
				if ( sortKey === 'total' ) return r.total === null ? -Infinity : signedAmount( { type: r.type }, r.total );
				if ( sortKey === 'errors' ) return UNCHECKED.includes( r.verdict ) ? -1 : r.errors;
				if ( sortKey === 'rules' ) return r.rules.length ? r.rules[ 0 ].rule : '';
				return String( r[ sortKey ] || '' ).toLowerCase();
			};
			rows = rows.slice().sort( ( a, b ) => {
				const x = val( a ), y = val( b );
				return ( x < y ? -1 : x > y ? 1 : 0 ) * sortDir || a.id - b.id;
			} );
		}

		return rows;

	};

	const drawChips = () => {

		const c = counts( items.map( rowOf ) );
		chips.textContent = '';

		for ( const [ key, label ] of [ [ 'all', 'All' ], [ 'accepted', 'Accepted' ], [ 'warnings', 'With warnings' ], [ 'rejected', 'Rejected' ], [ 'unchecked', 'Not checked' ] ] ) {
			const b = el( 'button', { type: 'button', class: 'chip' + ( filter === key ? ' on' : '' ), 'data-filter': key, 'aria-pressed': filter === key ? 'true' : 'false' },
				t( label ) + ' ', el( 'b', { text: String( c[ key ] ) } ) );
			chips.append( b );
		}

	};

	const drawTable = () => {

		tbody.textContent = '';

		const rows = visible();

		if ( ! rows.length ) {
			tbody.append( el( 'tr', {}, el( 'td', { colspan: String( COLUMNS.length ), class: 'bx-empty', text: t( 'No file matches this filter.' ) } ) ) );
			return;
		}

		const L = lang();

		for ( const r of rows ) {

			const it = items.find( ( x ) => x.id === r.id );
			const opens = !! ( it && it.state === 'done' && it.result );
			const verdict = it && it.state === 'running' ? t( 'Checking …' ) : it && it.state === 'waiting' ? t( 'Waiting' ) : verdictLabel( r, t );
			const badge = it && ( it.state === 'running' || it.state === 'waiting' ) ? 'info' : BADGE[ r.verdict ];
			const note = it && it.state !== 'running' && it.state !== 'waiting' ? noteLabel( r, t ) : '';

			const tr = el( 'tr', {
				class: [ opens ? 'bx-open' : '', r.id === selectedId ? 'selected' : '', 'edge-' + ( { ok: 'ok', warn: 'warn', err: 'err' }[ badge ] || 'info' ) ].filter( Boolean ).join( ' ' ),
				'data-id': String( r.id ),
				tabindex: opens ? '0' : null,
				title: opens ? t( 'Show the report of this invoice' ) : null,
			},
			el( 'td', { class: 'bx-name' }, r.name, r.path && r.path !== r.name ? el( 'span', { class: 'bx-sub', text: r.path } ) : null ),
			el( 'td', { text: r.number } ),
			el( 'td', { text: r.seller } ),
			el( 'td', { class: 'nowrap', text: r.issueDate } ),
			el( 'td', { class: 'num nowrap', text: r.total === null ? '' : money( signedAmount( { type: r.type }, r.total ), L ) + ( r.currency ? ' ' + r.currency : '' ) } ),
			el( 'td', {}, el( 'span', { class: 'badge ' + badge, text: verdict } ) ),
			el( 'td', { class: 'num', text: UNCHECKED.includes( r.verdict ) ? '' : String( r.errors ) } ),
			el( 'td', {},
				el( 'span', { class: 'bx-rules', text: r.rules.map( ( x ) => x.n > 1 ? x.rule + ' (' + x.n + ')' : x.rule ).join( ', ' ) } ),
				note ? el( 'span', { class: 'bx-sub', text: note } ) : null ),
			);

			tbody.append( tr );

		}

	};

	const drawRun = () => {

		const total = items.filter( ( it ) => it.kind !== 'none' ).length;
		const done = items.filter( ( it ) => it.kind !== 'none' && ( it.state === 'done' || it.state === 'error' || it.state === 'skipped' ) ).length;

		bar.style.width = total ? Math.round( done / total * 100 ) + '%' : '0';
		cancelBtn.hidden = ! running;

		if ( running ) {
			runText.textContent = fill( t( '%1$d of %2$d invoices checked' ), done, total ) + ( waitText ? ' · ' + waitText : '' );
		} else if ( last ) {
			const s = ( last.ms / 1000 ).toLocaleString( lang(), { maximumFractionDigits: 1 } );
			const avg = last.checked ? ( last.ms / 1000 / last.checked ).toLocaleString( lang(), { maximumFractionDigits: 2 } ) : '0';
			runText.textContent = fill( t( '%1$d invoices checked in %2$s seconds, %3$s seconds per invoice on average.' ), last.checked, s, avg );
		}

		run.hidden = ! items.length;

	};

	const render = () => {
		renderTimer = 0;
		texts();
		drawRun();
		drawChips();
		drawTable();
		overview.hidden = ! items.length;
	};

	// ⚠️ At most five drawings a second while the batch runs; a drawing per finished invoice slows a batch of 500 down.
	const soon = () => { if ( ! renderTimer ) renderTimer = setTimeout( render, 200 ); };

	/* ------------------------------------------------------------------ the run */

	const reset = () => {
		if ( running ) running.abort();
		items = [];
		last = null;
		selectedId = -1;
		say( '', '' );
		render();
		if ( typeof opts.onReset === 'function' ) opts.onReset();
	};

	/**
	 * Takes files ( or dropped entries ), prepares them and checks them.
	 *
	 * @param {Array<File|FileSystemEntry>} entries
	 * @return {Promise<void>}
	 */
	const add = async ( entries ) => {

		if ( running ) return;

		running = new AbortController();
		waitText = '';
		// ⚠️ The summary of the previous batch goes at once: while the files are read, it would look like the result of this one.
		last = null;
		items = [];
		render();
		say( t( 'Reading the files …' ), 'info' );

		try {

			const list = await filesFromEntries( entries );
			const prepared = await prepareItems( list, { limit: MAX_ITEMS } );

			// ⚠️ A new batch replaces the old one. Two batches in one table would mix two folders in one export.
			items = prepared.map( ( p ) => ( {
				...p,
				id: nextId++,
				state: p.kind === 'none' ? 'skipped' : 'waiting',
				header: p.kind === 'none' || ! Parser ? null : invoiceHeader( p.xml, Parser ),
			} ) );

			selectedId = -1;
			last = null;

			if ( ! items.length ) { say( t( 'No file was found.' ), 'warn' ); running = null; render(); return; }

			say( '', '' );
			render();

			const { stoppedBy, ms } = await runQueue( items, ( it ) => opts.check( it, { signal: running.signal } ), {
				concurrency: opts.concurrency || 1,
				signal: running.signal,
				onUpdate: ( it ) => {
					// The bytes are not needed after the check; the XML stays for the single report.
					if ( it.state === 'done' || it.state === 'error' ) it.bytes = null;
					if ( it.state === 'done' ) waitText = '';
					soon();
				},
				onWait: ( { seconds } ) => {
					waitText = fill( t( 'The validation service asks for a pause; the batch goes on in %d seconds.' ), seconds );
					soon();
				},
			} );

			last = { ms, checked: items.filter( ( it ) => it.state === 'done' ).length };

			if ( stoppedBy ) say( fill( t( 'The batch was stopped: %s' ), String( stoppedBy.message || stoppedBy ) ), 'error' );
			else if ( running.signal.aborted ) say( t( 'The batch was cancelled. The invoices checked so far stay in the overview.' ), 'warn' );

		} catch ( err ) {

			say( String( ( err && err.message ) || err ), 'error' );

		} finally {

			running = null;
			waitText = '';
			clearTimeout( renderTimer );
			render();
			root.dataset.batchMs = last ? String( last.ms ) : '';
			root.dataset.batchChecked = last ? String( last.checked ) : '';
			if ( typeof opts.onDone === 'function' ) opts.onDone( items.map( rowOf ) );

		}

	};

	/* ------------------------------------------------------------------ events */

	const download = ( text, type, name ) => {
		const url = URL.createObjectURL( new Blob( [ text ], { type } ) );
		const a = el( 'a', { href: url, download: name } );
		doc.body.append( a );
		a.click();
		a.remove();
		setTimeout( () => URL.revokeObjectURL( url ), 1000 );
	};

	const stamp = () => new Date().toISOString().slice( 0, 10 );

	drop.addEventListener( 'click', ( e ) => { if ( e.target === drop || drop.contains( e.target ) ) files.click(); } );
	drop.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); files.click(); } } );

	// ⚠️ Without preventDefault() on dragover there is no drop; the browser opens the file as a page instead.
	[ 'dragenter', 'dragover' ].forEach( ( n ) => drop.addEventListener( n, ( e ) => { e.preventDefault(); drop.classList.add( 'drag' ); } ) );
	[ 'dragleave', 'drop' ].forEach( ( n ) => drop.addEventListener( n, () => drop.classList.remove( 'drag' ) ) );

	drop.addEventListener( 'drop', ( e ) => {
		e.preventDefault();
		e.stopPropagation();
		const entries = entriesFromDrop( e.dataTransfer );
		if ( entries.length ) add( entries );
	} );

	files.addEventListener( 'click', ( e ) => e.stopPropagation() );
	folder.addEventListener( 'click', ( e ) => e.stopPropagation() );
	files.addEventListener( 'change', () => { const l = Array.from( files.files ); files.value = ''; if ( l.length ) add( l ); } );
	folder.addEventListener( 'change', () => { const l = Array.from( folder.files ); folder.value = ''; if ( l.length ) add( l ); } );
	folderBtn.addEventListener( 'click', () => folder.click() );

	cancelBtn.addEventListener( 'click', () => { if ( running ) running.abort(); } );

	chips.addEventListener( 'click', ( e ) => {
		const b = e.target.closest( '[data-filter]' );
		if ( ! b ) return;
		filter = b.getAttribute( 'data-filter' );
		drawChips();
		drawTable();
	} );

	search.addEventListener( 'input', () => { query = search.value; drawTable(); } );

	thead.addEventListener( 'click', ( e ) => {
		const b = e.target.closest( '[data-sort]' );
		if ( ! b ) return;
		const key = b.getAttribute( 'data-sort' );
		sortDir = sortKey === key ? -sortDir : 1;
		sortKey = key;
		texts();
		drawTable();
	} );

	const openRow = ( tr ) => {
		const id = Number( tr.getAttribute( 'data-id' ) );
		const it = items.find( ( x ) => x.id === id );
		if ( ! it || it.state !== 'done' || ! it.result ) return;
		selectedId = id;
		drawTable();
		if ( typeof opts.open === 'function' ) opts.open( rowOf( it ), it );
	};

	tbody.addEventListener( 'click', ( e ) => { const tr = e.target.closest( 'tr.bx-open' ); if ( tr ) openRow( tr ); } );
	tbody.addEventListener( 'keydown', ( e ) => {
		if ( e.key !== 'Enter' && e.key !== ' ' ) return;
		const tr = e.target.closest( 'tr.bx-open' );
		if ( tr ) { e.preventDefault(); openRow( tr ); }
	} );

	csvBtn.addEventListener( 'click', () => download( batchCsv( items.map( rowOf ), t ), 'text/csv;charset=utf-8', 'batch-check-' + stamp() + '.csv' ) );

	htmlBtn.addEventListener( 'click', () => download( batchReportHtml( items.map( rowOf ), {
		t, lang: lang(), title: t( 'Batch check: overview' ), tool: opts.tool || '', totalMs: last ? last.ms : 0,
	} ), 'text/html;charset=utf-8', 'batch-check-' + stamp() + '.html' ) );

	resetBtn.addEventListener( 'click', reset );

	render();

	return {
		render,
		reset,
		add,
		rows: () => items.map( rowOf ),
		state: () => ( { running: !! running, items: items.length, last } ),
	};

}
