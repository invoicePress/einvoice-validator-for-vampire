// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The "QUBA" template (rectangular layout) - line-for-line port of
 * einvoice-viewer-for-peppol-en16931/includes/core/Template/QubaTemplate.php +
 * AbstractTemplate.php's e()/bt() helpers, adapted to plain DOM string building (no WP escaping
 * function).
 *
 * Renders the model ModelAssembler.mjs produces - the same hero/sections/cards/rows shape
 * ModelBuilder::build() itself returns (libs/einvoice-model/schema/einvoice-model.schema.json).
 *
 * ⚠️ **THREE TEXTS STAND HERE AND NOT IN THE MODEL**, and that is not an oversight but follows the
 * original: `QubaTemplate.php` writes them into its markup as well and translates them there with
 * `$this->ctx->te()`. The model carries only values in the header area, no labels.
 *
 * ⚠️ THESE THREE TEXTS MUST GO THROUGH `t()` LIKE THE MODEL'S. A search for English texts that looks for
 * strings in quotation marks does not find them, because they stand **inside markup literals**
 * ( measured 05.10.2026: such a search reported "0 hits" for this file while a German view showed
 * "Invoice date", "Currency" and "Amount due" in its header ). Whoever searches for texts this way must
 * cover `>Text<` as well.
 *
 * @since 3.2.0
 */

import { t } from './Labels.mjs';

/**
 * ⚠️ **THE APOSTROPHE IS ESCAPED AS `&#039;`, FOR THE SAKE OF EQUALITY WITH PHP.** There
 * every output runs through `htmlspecialchars( …, ENT_QUOTES )`, and that writes exactly this form.
 * In text content the escaping is superfluous and does no harm - the browser shows an apostrophe in
 * both cases. Without it, however, the two templates differ on every text with an apostrophe
 * ( "Seller's item number" ), and `compare-render.mjs` rightly reports that.
 *
 * @param {*} value
 * @return {string}
 */

function esc( value ) {

	return String( value ?? '' )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );

}

/**
 * A BT/BG code badge - the badge is always emitted; whether it is VISIBLE is CSS-controlled
 * (.spev-show-bt on the container), same as the PHP original.
 *
 * @param {string} code
 * @param {(code:string)=>string} tooltipFor
 * @return {string}
 */

function bt( code, tooltipFor ) {

	code = String( code ?? '' );

	if ( code === '' ) return '';

	/*
	 * ⚠️ THE BADGE SHOWS THE CATALOGUE ID, ALSO FOR A SECOND CARRIER OF A BUSINESS TERM ( Ralf, 27.10.2026 ). From 26.10.2026
	 * a table `BADGE_CODE` let `BT-41-0` / `BT-56-0` ( department ) and `BT-84-0` ( national account number ) show "BT-41",
	 * "BT-56" and "BT-84", because the CEN binding to CII counts them as carriers of those terms. A card then showed the same
	 * number twice, once at the contact name or the IBAN and once at the second row, and the reader could not tell the rows
	 * apart by their number. Ralf: "Die Abteilung wäre BT-41-0 bzw. BT-56-0", "auch bei der Kontonummer müsste BT-84-0
	 * korrekt genannt werden". The tooltip comes from the catalogue entry of that id ( "Seller contact Department name" ).
	 * Twin: `AbstractTemplate::bt()` in `libs/einvoice-model/php/view/Template/AbstractTemplate.php`.
	 */

	const title = tooltipFor ? tooltipFor( code ) : '';

	/**
	 * ⚠️ **TWO CLASSES, AND BOTH ARE NEEDED.** `badge code` is the kit's component and gives
	 * the look; `spev-bt` is the hook the switch for the field numbers hangs on. The kit
	 * knows no such switch, and it does not belong in there - it belongs to this view.
	 */
	return ' <span class="badge code spev-bt"'
		+ ( title !== '' ? ' data-spev-tip="' + esc( title ) + '" tabindex="0"' : '' )
		+ '>' + esc( code ) + '</span>';

}

/**
 * The head of a line group ( `.doc-group-head` ).
 *
 * ⚠️ **THE TITLE GOES INSIDE AN `<h2>`, AND THAT IS THE WHOLE POINT OF THIS FUNCTION.** The kit
 * gives `.doc-group-head` only the row and the colour; the type face, the size and the weight sit
 * on `.doc-group-head h2`. Writing the title straight into the head skips that rule.
 *
 * ⚠️ **THE CODE BADGE STAYS OUTSIDE THE `<h2>`.** The head is a flex row with a gap; the badge is a
 * sibling of the heading, not part of it.
 *
 * @param {string} cls	`doc-group-head`.
 * @param {string} title	The plain-text title.
 * @param {string} btHtml	The already-rendered code badge, or an empty string.
 * @returns {string}
 */
function head( cls, title, btHtml ) {

	return '<div class="' + cls + '"><h2>' + esc( title ) + '</h2>' + btHtml + '</div>';

}

/**
 * The head of a document block — the flag that sits on the block's top-left corner.
 *
 * ⚠️ **THE BADGE GOES INSIDE THE `<h3>` HERE, UNLIKE EVERY OTHER HEAD.** `.doc-block-head` is the
 * flag itself: an inline-flex box that is only as wide as its content, and the kit's own example
 * writes the badge inside it. Put outside, the badge would stand next to the flag on the block's
 * white surface, where its white-on-transparent treatment is unreadable — the rule that colours it
 * is `.doc-block-head .badge.code`.
 *
 * @param {string} title	The plain-text title.
 * @param {string} btHtml	The already-rendered code badge, or an empty string.
 * @returns {string}
 */
function blockHead( title, btHtml ) {

	return '<h3 class="doc-block-head">' + esc( title ) + btHtml + '</h3>';

}

function hero( model, tooltipFor ) {

	// NO BT BADGES IN THE HERO, EVER - see QubaTemplate.php's own comment: the eyebrow/number/date/
	// currency/amount line reads like a document header, not a field table, same information is
	// already labelled with its BT code in the "Invoice data" card just below.

	let html = '<header class="doc-head"><div class="doc-head-main">';
	html += '<div class="doc-head-type">' + esc( model.type ) + '</div>';
	html += '<div class="doc-head-title">' + esc( model.no ) + '</div>';
	/**
	 * ⚠️ **THE SPACE BETWEEN LABEL AND VALUE IS PART OF THE MARKUP, NOT OF THE KIT.** The kit styles
	 * `.doc-head-meta` as one running line and sets no rule on its children, so two adjacent
	 * elements render as "Invoice date11.02.2026" without it.
	 */
	html += '<div class="doc-head-meta">';
	html += '<div>' + esc( t( 'Invoice date' ) ) + ' <b>' + esc( model.date ) + '</b></div>';
	html += '<div>' + esc( t( 'Currency' ) ) + ' <b>' + esc( model.currency ) + '</b></div>';
	html += '</div></div>';

	if ( model.amount && String( model.amount ).trim() !== '' ) {
		html += '<div class="doc-head-amount"><div class="doc-head-label">' + esc( t( 'Amount due' ) )
			+ '</div><div class="doc-head-value">' + esc( model.amount ) + '</div></div>';
	}

	html += '</header>';

	return html;

}

function field( row, tooltipFor ) {

	const label = row.label ?? '';
	const value = String( row.value ?? '' ).trim();
	const href = row.href ?? '';

	/**
	 * ⚠️ **THE DASH STAYS A CHARACTER IN THE VALUE FIELD, NOT A COMPONENT OF ITS OWN.** The kit colours
	 * and italicises an empty value field through `.doc-field.blank .doc-value`; an additional
	 * `faint` on it would be a second place where the same thing is determined.
	 */
	const marked = hasHint( row );

	/*
	 * ⚠️ **AN EMPTY FIELD WITH A HINT IS NOT BLANK.** A missing mandatory field is exactly the case where
	 * the value is empty and the hint matters most. Rendered the old way it would carry the class `blank`,
	 * and the switch "only filled fields" would hide the one row the reader has to see.
	 */
	let val;
	/*
	 * ⚠️ A LINK WITH A FINDING LOSES ITS LINK IN THE VIEW "INVOICE". A link carried no hint before, so the branch for
	 * `href` came first; a finding of the report on a URI field ( BT-124 ) would vanish behind it. The marked branch
	 * now wins, which changes nothing outside that view: without findings `marked` is what it always was.
	 */
	if ( value === '' )
		val = marked ? '<div class="doc-value ' + edge( row ) + '">—' + mismatchHint( row ) + '</div>' : '<div class="doc-value">—</div>';
	else if ( href !== '' && ! ( row.findings && row.findings.length ) )
		val = '<a class="doc-value spev-link" href="' + esc( href ) + '" target="_blank" rel="noopener noreferrer">' + esc( value ) + '</a>';
	else if ( marked )
		val = '<div class="doc-value ' + edge( row ) + '">' + esc( value ) + mismatchHint( row ) + '</div>';
	else
		val = '<div class="doc-value">' + esc( value ) + '</div>';

	/**
	 * ⚠️ `blank` IS THE WHOLE BASIS OF THE SWITCH "ONLY FILLED FIELDS". The kit hides
	 * `.only-filled .doc-field.blank`, purely through CSS. Without the class the view keeps
	 * showing every empty field when the switch is on, without anything breaking.
	 *
	 * ⚠️ **NOT `:has( … )` INSTEAD.** A basic function would then depend on a selector level
	 * that older browsers do not know.
	 */
	const emptyCls = value === '' && ! marked ? ' blank' : '';

	/*
	 * ⚠️ `spev-ext` MARKS A ROW OF AN ADD-ON ( `row.ext`, set by "Extended Profiles" for every BT-X ). The viewer's
	 * switch "Only filled BT-X fields" hides `.spev-ext.blank` by CSS, independently of "Only filled fields". The PHP
	 * twin never sees such a row, so the parity with `QubaTemplate.php` is untouched.
	 */
	// `col: 2` keeps a row in the right column under its neighbour ( .spev-col2 in spev-viewer.css ).
	return '<div class="doc-field' + emptyCls + ( row.ext ? ' spev-ext' : '' ) + ( row.col === 2 ? ' spev-col2' : '' ) + '"><div class="doc-label">' + esc( label ) + bt( row.bt, tooltipFor ) + '</div>' + val + '</div>'
		+ findingCards( [ row ] );

}

/**
 * The hint lines under a value: first the totals hint, then the field hints
 * of InvoiceHints.mjs, one line each - twin of `QubaTemplate::mismatch_hint()`.
 *
 * ⚠️ **KIT CLASSES ONLY: `edge-err` ON THE VALUE, `sub` FOR THE LINE.** `edge-err` is the kit's error
 * edge and surface ( `--err` / `--err-soft` ), `sub` its small secondary line. A class of our own would
 * need a rule in every copy of the viewer CSS, and a copy without it would show the hint as plain text
 * in the middle of the amount.
 *
 * ⚠️ **THE FIELD HINTS LOOK EXACTLY LIKE THE TOTALS HINT.** What tells the reader that none of them is
 * a validation is the notice above the invoice ( `model.hintNotice` ), not a second colour.
 *
 * ⚠️ **THE XML VALUE STAYS IN FRONT, THE HINT ONLY FOLLOWS IT.** The reader must see what the invoice
 * says; what we calculated is the second statement, not a replacement.
 *
 * @param {object} row A field row, table cell or totals row.
 * @return {string} The hint markup, or '' when the row carries neither a mismatch nor a hint.
 */

function mismatchHint( row ) {

	if ( ! row ) return '';

	let html = '';

	/*
	 * ⚠️ `hintOrigin` AND `findings` EXIST ONLY IN THE VIEW "INVOICE" OF THE REPORT OUTPUT,
	 * set by `attachFindings()` in `ReportFindings.mjs` on a copy of the model. Without them this function writes
	 * the plain hint markup, and that is what `compare-render.mjs` holds against `QubaTemplate.php`.
	 *
	 * ⚠️ THE PHP TWIN HAS NO COUNTERPART, ON PURPOSE. The view "Invoice" is drawn only by `<einvoice-viewer>` in the
	 * browser; a server-side drawing of the same view would be a second one that drifts ( see `ReportViews.php` ).
	 */
	const origin = row.hintOrigin ? '<b class="spev-origin">' + esc( row.hintOrigin ) + '</b> ' : '';

	if ( row.mismatch && row.mismatch.hint ) html += '<span class="sub">' + origin + esc( row.mismatch.hint ) + '</span>';

	for ( const h of row.hints || [] ) html += '<span class="sub">' + origin + esc( h.text ) + '</span>';

	/*
	 * A finding of the report: the head ( "missing", rule, severity, origin ) in bold, the statement after it. The
	 * class names the finding and its severity, so a test - and a stylesheet, should one ever want it - can find
	 * each finding at its field.
	 */
	// ⚠️ A finding that brings its whole card stands below the row instead - see `findingCards()`.
	for ( const f of ( row.findings || [] ).filter( ( x ) => ! x.card ) )
		html += '<span class="sub spev-finding spev-finding-' + esc( f.severity ) + '" data-finding="' + esc( f.finding ) + '">'
			+ '<b class="spev-origin">' + esc( f.head ) + '</b> ' + esc( f.text ) + '</span>';

	return html;

}

/**
 * The whole cards of the report's findings on these rows or cells, below the row they belong to.
 *
 * ⚠️ `card` EXISTS ONLY WHEN THE VALIDATION EXPLORER HANDS IT OVER ( `attachFindings()` with the cards of its list ). The
 * card is the explorer's own markup of the finding, already escaped there, without its id, its "location in the document"
 * and its jumps into the XML listing, which do not exist inside the element. Without cards this function writes nothing,
 * and the drawing stays what `compare-render.mjs` holds against PHP.
 *
 * @param {Array<object>} rows
 * @return {string}
 */

function findingCards( rows ) {

	let html = '';

	for ( const row of rows )
		for ( const f of ( row && row.findings ) || [] )
			// ⚠️ `data-finding` like the one-line finding above, so that "Position" in the report can find the card ( viewer 2.38.0 ).
			if ( f.card ) html += '<div class="spev-finding-card" data-finding="' + esc( f.finding ) + '">' + f.card + '</div>';

	return html === '' ? '' : '<div class="spev-finding-cards">' + html + '</div>';

}

/**
 * Does a row, cell or totals line carry a totals mismatch, a field hint or a finding of a report?
 *
 * @param {object} row
 * @return {boolean}
 */

function hasHint( row ) {

	return Boolean( row && ( ( row.mismatch && row.mismatch.hint ) || ( row.hints && row.hints.length ) || ( row.findings && row.findings.length ) ) );

}

/**
 * The kit's signal edge for a marked value: red for a hint, a mismatch or an error, yellow when the only marks are
 * warnings or information of a report.
 *
 * ⚠️ `edge-err` STAYS THE ANSWER FOR EVERYTHING THAT EXISTED BEFORE. Hints and mismatches were red before the
 * findings came; only a field that carries nothing but a warning of the report
 * gets the yellow edge, as a warning gets it in the view "Findings".
 *
 * @param {object} row
 * @return {string} 'edge-err' | 'edge-warn'
 */

function edge( row ) {

	if ( ( row.mismatch && row.mismatch.hint ) || ( row.hints && row.hints.length ) ) return 'edge-err';

	return ( row.findings || [] ).some( ( f ) => f.severity === 'error' ) ? 'edge-err' : 'edge-warn';

}

function subhead( row, tooltipFor ) {

	return '<div class="doc-subhead">' + esc( row.label ) + bt( row.bt, tooltipFor ) + '</div>';

}

function cardIsEmpty( rows ) {

	for ( const row of rows ) {
		if ( row.type === 'subhead' ) continue;
		if ( row.value !== undefined && String( row.value ).trim() !== '' ) return false;
		// ⚠️ A hint is content - a card whose only "value" is a missing mandatory field must stay visible.
		if ( hasHint( row ) ) return false;
	}

	return true;

}

function renderCard( block, tooltipFor ) {

	const rows = block.rows || [];
	const span = Number( block.span ) === 2;
	const empty = cardIsEmpty( rows );

	const cls = 'doc-block' + ( span ? ' full' : '' ) + ( empty ? ' blank' : '' ) + ( block.ext ? ' spev-ext' : '' );

	/**
	 * ⚠️ **A SUB-HEADING AND ITS FIELDS GO INTO ONE SHARED CONTAINER.** The kit
	 * hides the **whole** subgroup with `.only-filled .doc-subgroup.blank`; if heading and fields
	 * stood side by side in the flat list, the heading would remain above nothing as soon as its
	 * fields disappear. The model delivers the rows flat, so they are grouped here.
	 *
	 * ⚠️ **WHAT STANDS BEFORE THE FIRST SUB-HEADING BELONGS TO NO SUBGROUP.** Those are the
	 * fields of the block itself; packed into a container they would disappear as well as soon as
	 * the first subgroup is hidden.
	 */
	let body = '';
	let offen = null;

	const schliessen = () => {

		if ( offen === null ) return;

		body += '<div class="doc-subgroup' + ( offen.leer ? ' blank' : '' ) + ( offen.ext ? ' spev-ext' : '' ) + '">' + offen.html + '</div>';
		offen = null;

	};

	for ( const row of rows ) {

		if ( row.type === 'subhead' ) {

			schliessen();
			offen = { html: subhead( row, tooltipFor ), leer: true, ext: Boolean( row.ext ) };

			continue;

		}

		const zeile = field( row, tooltipFor );
		const belegt = ( row.value !== undefined && String( row.value ).trim() !== '' ) || hasHint( row );

		if ( offen === null ) {
			body += zeile;
			continue;
		}

		offen.html += zeile;
		if ( belegt ) offen.leer = false;

	}

	schliessen();

	return '<section class="' + cls + '">' + blockHead( block.title, bt( block.bt, tooltipFor ) )
		+ '<div class="doc-block-body">' + body + '</div></section>';

}

/**
 * Does a table row carry a value at all?
 *
 * ⚠️ **THE FIRST CELL DOES NOT COUNT.** In the allowance/charge table it holds the kind - "allowance"
 * or "charge" -, and the builder writes that itself, even for a placeholder row. If it counted,
 * **every** row would be filled, and the switch "only filled fields" would never hide anything.
 *
 * @param {Array} row
 * @return {boolean}
 */

function rowIsEmpty( row ) {

	for ( let i = 1; i < row.length; i++ )
		if ( String( row[ i ].v ?? '' ).trim() !== '' || hasHint( row[ i ] ) ) return false;

	return true;

}

function renderTable( block, tooltipFor ) {

	const columns = block.columns || [];
	const rows = block.rows || [];

	if ( ! rows.length ) return '';

	/**
	 * ⚠️ **A TABLE WHOSE ROWS ARE ALL EMPTY IS AN EMPTY CARD** - and it must be, so that
	 * the switch "only filled fields" hides it. The allowances and charges appear even without
	 * entries, so the case occurs on almost every document.
	 */
	const allEmpty = rows.every( rowIsEmpty );

	const span = Number( block.span ) === 2;
	const cls = 'doc-block' + ( span ? ' full' : '' ) + ( allEmpty ? ' blank' : '' );

	let thead = '';
	for ( const col of columns )
		thead += '<th' + ( col.num ? ' class="num"' : '' ) + '>' + esc( col.label ) + '</th>';

	let tbody = '';
	for ( const row of rows ) {

		let tds = '';
		for ( const cell of row ) {

			let v = esc( cell.v );
			if ( cell.strong ) v = '<b>' + v + '</b>';

			const badge = bt( cell.bt, tooltipFor );

			// The arithmetic mark ( BT-116 / BT-117 ) sits on the cell, not on the row - the rest of the
			// VAT line may well be right.
			const cls = ( ( cell.num ? 'num' : '' ) + ( hasHint( cell ) ? ' ' + edge( cell ) : '' ) ).trim();

			tds += '<td' + ( cls !== '' ? ' class="' + cls + '"' : '' ) + '>'
				+ ( badge !== '' ? '<div class="spev-cell-bt">' + badge + '</div>' : '' ) + v + mismatchHint( cell ) + '</td>';

		}

		/*
		 * ⚠️ **MARKED PER ROW, NOT ONLY PER TABLE.** A document can carry two allowances and no
		 * charge; then two filled rows and one placeholder row stand in the same
		 * table. Without the mark per row the placeholder row stays, even when the
		 * reader wants to see only filled fields.
		 */
		tbody += '<tr' + ( rowIsEmpty( row ) ? ' class="blank"' : '' ) + '>' + tds + '</tr>';

		const cards = findingCards( row );

		if ( cards !== '' )
			tbody += '<tr class="spev-finding-tr"><td colspan="' + Math.max( 1, row.length ) + '">' + cards + '</td></tr>';

	}

	const headBt = Array.isArray( block.bt ) ? block.bt : [ block.bt ];
	let headBtHtml = '';
	for ( const code of headBt )
		headBtHtml += bt( code, tooltipFor );

	/*
	 * ⚠️ **THE TABLE STANDS IN THE BLOCK WITHOUT `.doc-block-body`.** That container pads all around, and
	 * a table is meant to run up to the frame: its cells bring their own padding,
	 * and its header row is a continuous surface that would otherwise float in a white margin.
	 */
	const kopf = blockHead( block.title, headBtHtml );
	const table = '<div class="spev-table-wrap"><table class="tbl"><thead><tr>' + thead + '</tr></thead><tbody>' + tbody + '</tbody></table></div>';

	return '<section class="' + cls + '">' + kopf + table + '</section>';

}

/**
 * The hints and findings of a totals row, on a line of their own below label and amount.
 *
 * ⚠️ NOT INSIDE `.totals-amount`. The kit sets that cell `white-space: nowrap` in monospace, in a grid column `auto`; a
 * sentence there widened the column until the label broke word by word and the amount left the frame ( measured on
 * 22.10.2026 with a Hybrid Check finding at BT-112, finding B205 ). `.spev-totals-note` spans both columns instead.
 * The PHP twin writes the same markup, so that `compare-render.mjs` stays green.
 *
 * @param {object} row
 * @return {string}
 */

function totalsNote( row ) {

	const note = mismatchHint( row );

	return note === '' ? '' : '<div class="spev-totals-note">' + note + '</div>';

}

function renderTotals( block, tooltipFor ) {

	const rows = block.rows || [];

	if ( ! rows.length ) return '';

	let list = '';
	for ( const row of rows ) {

		let value = String( row.value ?? '' ).trim();
		if ( value === '' ) value = '—';

		// `grand` and `due` are the names the kit knows for the two highlighted rows.
		const cls = 'totals-row' + ( row.variant ? ' ' + row.variant : '' ) + ( hasHint( row ) ? ' ' + edge( row ) : '' );

		list += '<div class="' + cls + '"><span class="totals-label">' + esc( row.label ) + bt( row.bt, tooltipFor )
			+ '</span><span class="totals-amount">' + esc( value ) + '</span>' + totalsNote( row ) + '</div>'
			+ findingCards( [ row ] );

	}

	const kopf = blockHead( block.title, bt( block.bt, tooltipFor ) );

	/*
	 * ⚠️ **NO `.totals` AROUND THE ROWS, ALTHOUGH THE KIT HAS IT.** That component sets surface,
	 * border and cut corners once more - the same values `.doc-block` around it has already
	 * set, and thus a second line. The rows do not need it: `.totals-row`
	 * stands on its own and brings its own dividing line.
	 *
	 * ⚠️ **NO `.doc-block-body` EITHER.** The totals rows pad themselves ( 7/14 px ) and
	 * run up to the frame, just like the rows of a table.
	 *
	 * ⚠️ **`.full` IS FIXED HERE AND DOES NOT COME FROM `block.span`.** The totals block closes the
	 * document; in a grid column it would stand on the left with an empty right half next to it. The
	 * kit row `.totals-row` is `minmax( 0, 1fr ) auto` - label on the left, amount on the right -, so
	 * it carries any width.
	 */
	return '<section class="doc-block full">' + kopf + list + '</section>';

}

function block( item, tooltipFor ) {

	if ( ! item || ! item.kind ) return '';

	switch ( item.kind ) {
		case 'card': return renderCard( item, tooltipFor );
		case 'table': return renderTable( item, tooltipFor );
		case 'totals': return renderTotals( item, tooltipFor );
	}

	return '';

}

function grid( items, tooltipFor ) {

	let inner = '';
	for ( const b of items ) inner += block( b, tooltipFor );

	if ( inner === '' ) return '';

	return '<div class="doc-grid">' + inner + '</div>';

}

function group( section, tooltipFor ) {

	let inner = '';
	for ( const b of section.items || [] ) inner += block( b, tooltipFor );

	if ( inner === '' ) return '';

	/*
	 * ⚠️ **`.doc-group > .doc-grid` PADS THE GRID, AND THE KIT DOES THAT ITSELF.** The
	 * line group must therefore not get an inner measure of its own; an additional wrapper in between
	 * would take the rule's grip away.
	 */
	return '<section class="doc-group">' + head( 'doc-group-head', section.title, bt( section.bt, tooltipFor ) ) + '<div class="doc-grid">' + inner + '</div></section>';

}

/**
 * @param {object} model	The model from ModelAssembler.build().
 * @param {(code:string)=>string} [tooltipFor]	Optional BT/BG code -> tooltip text lookup.
 * @return {string}
 *
 * ⚠️ **THE LANGUAGE DOES NOT COME AS AN ARGUMENT BUT FROM THE STATE THAT `build()` HAS SET.**
 * Almost all texts are already translated in the model; only the three labels of the
 * header area are created here ( see the file header ), and they use `Labels.mjs`. That
 * holds because `render()` always directly follows `build()` - the same order in which the
 * PHP side runs both through **one** `Context` instance.
 *
 * ⚠️ Whoever applies `render()` to a model that an **earlier** `build()` produced in another language
 * gets the language of the last `build()` in the header area. Carrying the language on the model
 * would be more robust - and would change the model schema that `test/compare-model.mjs`
 * holds character by character against the PHP output.
 */

export function render( model, tooltipFor = null ) {

	let html = '<div class="spev-invoice spev-tpl-quba">';

	if ( model.hero )
		html += hero( model.hero, tooltipFor );

	/**
	 * ⚠️ **`.note`, NOT `.docfoot`.** The kit's `.docfoot` is a document footer: it carries a top
	 * border and a top margin and expects to sit at the end of the document. This line stands above
	 * the invoice, where it warns before the reader starts reading, so it takes the kit's notice
	 * component instead.
	 *
	 * ⚠️ **`info`, THE BLUE ONE.** The line stands above every invoice, whatever it contains; it is a
	 * standing piece of information about the service and says nothing about this invoice. Yellow is
	 * kept for what concerns the invoice in front of the reader, see the hint notice below.
	 */
	if ( model.disclaimer )
		html += '<div class="note info">' + esc( model.disclaimer ) + '</div>';

	/**
	 * ⚠️ **THE HINT NOTICE STANDS ABOVE THE INVOICE, ONCE, AND ONLY WHEN A FIELD CARRIES A HINT.** It says
	 * that the marked fields are hints, not a complete validation, and
	 * the validators check all rules. Written once here instead of into every hint line, because a page
	 * with ten repetitions of the same caveat is read as noise.
	 *
	 * ⚠️ **`warn`, THE YELLOW ONE.** The line appears only because this invoice has marked fields, and it
	 * warns the reader not to take the marks for a full validation. That is a caveat about this invoice,
	 * the same colour as the notes of the add-ons above it; red stays with the marked fields themselves.
	 */
	if ( model.hintNotice )
		html += '<div class="note warn">' + esc( model.hintNotice ) + '</div>';

	for ( const section of model.sections || [] ) {

		const layout = section.layout || 'grid';

		html += layout === 'group' ? group( section, tooltipFor ) : grid( section.items || [], tooltipFor );

	}

	html += '</div>';

	return html;

}
