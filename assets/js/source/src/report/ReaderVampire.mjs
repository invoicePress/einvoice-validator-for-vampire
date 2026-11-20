// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Reads the JSON report of VAMPIRE PHP and VAMPIRE JS into the shared model.
 *
 * ⚠️ LINE-FOR-LINE PORT of `libs/einvoice-report/src/ReaderVampire.php`. The reasons ( no invoice in the JSON, `accepted:
 * null` is no verdict, `notices` must be shown, only the file name of `document` ) stand there and are not repeated here.
 * JSON needs no parser of its own, so this twin has none of the delicate places of the XML readers.
 *
 * @since 1.17.0
 */

import { Report } from './Report.mjs';

/**
 * ⚠️ PHP's `\s` with the `u` flag, written out: JavaScript's `\s` counts U+FEFF and not U+0085, PCRE the other way round.
 */
const PHP_SPACE_U = /[\t\n\v\f\r \u0085\u00a0\u1680\u180e\u2000-\u200a\u2028\u2029\u202f\u205f\u3000]+/gu;

/** The stages of the cascade in the order VAMPIRE runs them, with the name the view shows. */
const STAGES = [
	[ 'wellformed', 'Well-formedness' ],
	[ 'xsd', 'Schema (XSD)' ],
	[ 'syntax', 'Syntax rules' ],
	[ 'business', 'Business rules' ],
];

export class ReaderVampire {

	/**
	 * Is this a JSON report of VAMPIRE?
	 *
	 * @param {string} content
	 * @return {boolean}
	 */
	static detect( content ) {
		const text = String( content || '' ).replace( /^[﻿ \t\r\n]+/, '' );
		if ( text === '' || text[ 0 ] !== '{' ) return false;
		return text.includes( '"schemaVersion"' ) && text.includes( '"stages"' ) && text.includes( '"findings"' );
	}

	/**
	 * @param {string} content
	 * @return {Report}
	 * @throws {Error}
	 */
	static read( content ) {

		let data;
		try { data = JSON.parse( String( content ).replace( /^﻿/, '' ) ); } catch ( e ) { data = null; }

		if ( ! data || typeof data !== 'object' || ! Array.isArray( data.findings ) )
			throw new Error( 'This does not look like a VAMPIRE report ( no list of findings ).' );

		const report = new Report();

		report.meta.source = 'vampire';

		const rr = data.ruleReport && typeof data.ruleReport === 'object' ? data.ruleReport : {};
		const engine = rr.engine && typeof rr.engine === 'object' ? ( String( rr.engine.name ?? '' ) + ' ' + String( rr.engine.version ?? '' ) ).trim() : '';

		report.meta.engine = engine !== '' ? engine : 'VAMPIRE';

		if ( typeof data.scenario === 'string' && data.scenario.trim() !== '' ) report.meta.document_type = data.scenario.trim();
		if ( typeof data.document === 'string' && data.document.trim() !== '' ) report.meta.reference = baseName( data.document );
		if ( Array.isArray( data.rulesets ) && data.rulesets.length ) report.meta.standard = data.rulesets.map( String ).join( ', ' );
		if ( typeof rr.validatedAt === 'string' ) report.meta.validated_at = rr.validatedAt;
		if ( rr.document && typeof rr.document.syntax === 'string' ) report.meta.syntax = rr.document.syntax.toLowerCase();

		const accepted = Object.prototype.hasOwnProperty.call( data, 'accepted' ) ? data.accepted : null;

		if ( accepted === true ) report.meta.compliant = 'yes';
		else if ( accepted === false ) report.meta.compliant = 'no';

		const missing = Array.isArray( data.missingRulesets ) ? data.missingRulesets.map( String ) : [];

		if ( accepted === null )
			report.notices.push( missing.length ? 'This report states no verdict: the validator lacked a rule set of the scenario, so the document was not checked against it.' : 'This report states no verdict.' );

		for ( const f of data.findings ) {

			if ( ! f || typeof f !== 'object' ) continue;

			const severity = String( f.severity ?? '' );

			report.findings.push( {
				position: String( f.stage ?? '' ),
				rule: String( f.rule ?? '' ),
				severity: severity === 'fatal' ? 'error' : ( severity === 'warning' ? 'warning' : 'information' ),
				message: String( f.text ?? '' ).replace( PHP_SPACE_U, ' ' ).replace( /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '' ),
				path: String( f.location ?? '' ),
				rows: [],
				criterion: '',
				fields: Array.isArray( f.fields ) ? f.fields.map( String ) : [],
			} );

		}

		for ( const [ key, label ] of STAGES ) {

			const status = data.stages && data.stages[ key ] !== undefined ? String( data.stages[ key ] ) : '';

			if ( status === '' || status === 'notrun' || status === 'skipped' ) continue;

			const tally = { error: 0, warning: 0, information: 0 };

			for ( const f of report.findings ) if ( f.position === key ) tally[ f.severity ]++;

			report.steps.push( { step: label, errors: tally.error, warnings: tally.warning, infos: tally.information } );

		}

		if ( Array.isArray( data.notices ) )
			for ( const n of data.notices )
				if ( typeof n === 'string' && n.trim() !== '' ) report.notices.push( n.trim() );

		return report;

	}

}

/** The file name of a path, for both kinds of separator. */
function baseName( path ) {
	const parts = String( path ).trim().split( /[\\/]/ );
	return parts[ parts.length - 1 ];
}
