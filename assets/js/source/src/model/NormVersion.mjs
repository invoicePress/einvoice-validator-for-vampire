// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Which version of EN 16931 a document follows. The versions and the BT-24 prefixes
 * that select them stand in `en16931-versions.json`, written by `tools/build-en16931-structure.mjs`; today
 * the only version is EN 16931-1:2017.
 *
 * ⚠️ **THIS MODULE IS SEPARATE FROM NormStructure.mjs ON PURPOSE.** TreeReader.mjs needs only the version,
 * and every viewer bundle contains TreeReader. The per-term structure ( about 10 KB ) would otherwise travel
 * in every bundle unused. See the comment at the end of the build tool.
 *
 * ⚠️ **A NEW VERSION OF THE STANDARD IS DATA, NOT CODE.** EN 16931-1:2026 is a new entry in the JSON with
 * its BT-24 prefix; nothing here changes.
 */

import DATA from './en16931-versions.json' with { type: 'json' };

const VERSIONS = DATA.versions;

export const DEFAULT_VERSION = Object.keys( VERSIONS ).find( ( v ) => VERSIONS[ v ].default ) ?? Object.keys( VERSIONS )[ 0 ];



/**
 * The keys of all known versions, e.g. [ '2017' ].
 *
 * @return {string[]}
 */
export function normVersions() {

	return Object.keys( VERSIONS );

}



/**
 * The name of a version as the standard writes it, e.g. 'EN 16931-1:2017'; '' for an unknown key.
 *
 * @param {string} version
 * @return {string}
 */
export function normStandard( version ) {

	return VERSIONS[ version ]?.standard ?? '';

}



/**
 * The version of the standard a document follows, from its specification identifier ( BT-24 ).
 *
 * ⚠️ **THE LONGEST MATCHING PREFIX WINS**, so a later version may name a prefix that begins like an
 * earlier one. A BT-24 that matches nothing ( Factur-X MINIMUM, ZATCA, OIOUBL, an empty value ) gets the
 * default version, because every document this suite reads today is read against 2017.
 *
 * @param {string} specification The raw value of BT-24.
 * @return {string} A version key, e.g. '2017'.
 */
export function normVersionOf( specification ) {

	const spec = String( specification ?? '' ).trim();
	let best = DEFAULT_VERSION;
	let bestLength = 0;

	for ( const [ version, entry ] of Object.entries( VERSIONS ) )
		for ( const prefix of entry.specification || [] )
			if ( spec.startsWith( prefix ) && prefix.length > bestLength ) {

				best = version;
				bestLength = prefix.length;

			}

	return best;

}
