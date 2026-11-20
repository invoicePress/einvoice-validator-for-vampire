<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Puts the reports of several validators on the same document side by side.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report\Frontend;

use SpecialPress\EInvoice\Report\Report;

// Same direct-access guard the sibling classes use.
if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! class_exists( __NAMESPACE__ . '\\Comparison' ) ) :

/**
 * class: Comparison
 *
 * ============================================================================================
 * The comparison check
 * ============================================================================================
 *
 * One file is uploaded and checked in parallel by KoSIT, Mustang and VAMPIRE PHP; the results stand
 * side by side in the explainer layout, with the differences marked.
 *
 * This class is the **comparison**, not the call. Who starts the three validators - a vServer,
 * the service or a script on the development machine - does not matter to it; it gets finished
 * `Report` models and puts them against each other.
 *
 * ============================================================================================
 * ⚠️ THE RULE ID IS WHAT IS COMPARED, AND THAT CHOICE HAS CONSEQUENCES
 * ============================================================================================
 *
 * Two validators report "the same thing" when they name the same rule. That is the only identifier
 * all three carry - message texts are worded differently per validator, translated, and partly
 * sprinkled with file paths ( Mustang appends `[ID BR-CO-10] from /xslt/…` ).
 *
 * ⚠️ **WHAT HAS NO ID IS NOT DROPPED SILENTLY.** A message without a rule id gets a substitute key
 * from its text and appears as a row that only one validator knows. Leaving it out would be the
 * most convenient way to claim an agreement that does not exist.
 *
 * ⚠️ **"BOTH FOUND NOTHING" IS AGREEMENT, BUT NOT A ROW.** It stands in the summary, not in the
 * list - a table that shows a green row for every rule not violated would be unreadable.
 *
 * ⚠️ **A VALIDATOR THAT FAILED HAS NOT "FOUND NOTHING".** One that delivered no report stands at
 * `null`, not at `absent`. The difference is everything: `absent` means "checked and found good",
 * `null` means "not checked". Mixing them up would be the most dangerous line of this class.
 *
 * @since 1.7.0
 */

class Comparison
{


	/** The validator reported the rule. */
	const PRESENT = 'present';

	/** The validator checked and did NOT report this rule. */
	const ABSENT = 'absent';

	/** The validator delivered no report. */
	const MISSING = null;


	/**
	 * @param	array	$reports	[ 'kosit' => Report|null, 'mustang' => Report|null, … ]
	 *								⚠️ `null` means "no report", not "no findings".
	 * @return	array
	 * @since	1.7.0
	 */

	public static function build( array $reports )
	{
		$engines = array_keys( $reports );

		/**
		 * ⚠️ "NO SCENARIO MATCHED" COUNTS AS "NO REPORT", NOT AS "NOTHING OBJECTED".
		 *
		 * For a document it has no scenario for, the KoSIT validator delivers a complete report with
		 * `<rep:reject>` and **zero** rule messages. Whoever only counts the messages reads
		 * "checked and clean" from it - the reverse of the truth.
		 *
		 * Measured on 23.09.2026: with the mutated demo documents, `kosit=no` stood row after row
		 * next to `mustang=error` and `php=error`. That looked like a dramatic difference and was
		 * none - KoSIT had never checked these documents. A comparison check that does not separate
		 * this produces exactly the false statements it is meant to uncover.
		 */

		$checked = array();

		foreach ( $reports as $engine => $report )
			$checked[ $engine ] = $report instanceof Report && ( $report->meta['scenario'] ?? '' ) !== 'none';

		$rows = array();

		foreach ( $reports as $engine => $report )
		{
			if ( ! $checked[ $engine ] )
				continue;

			foreach ( $report->findings as $f )
			{
				$key = self::key( $f );

				if ( ! isset( $rows[ $key ] ) )
					$rows[ $key ] = array(
						'rule'     => trim( (string) ( $f['rule'] ?? '' ) ),
						'by'       => array(),
						'messages' => array(),
					);

				/**
				 * ⚠️ REPEATED MESSAGES OF THE SAME RULE ARE COUNTED, NOT OVERWRITTEN.
				 * A document can violate the same rule at three positions; "reported once"
				 * and "reported three times" are different statements, and when one validator reports
				 * once and another three times, that is a difference.
				 */

				if ( ! isset( $rows[ $key ]['by'][ $engine ] ) )
					$rows[ $key ]['by'][ $engine ] = array( 'state' => self::PRESENT, 'severity' => (string) ( $f['severity'] ?? '' ), 'count' => 0 );

				$rows[ $key ]['by'][ $engine ]['count']++;

				$rows[ $key ]['messages'][ $engine ] = (string) ( $f['message'] ?? '' );
			}
		}

		// Now fill the gaps - and tell `absent` from `null` while doing so.
		foreach ( $rows as $key => $row )
		{
			foreach ( $engines as $engine )
			{
				if ( isset( $row['by'][ $engine ] ) )
					continue;

				$rows[ $key ]['by'][ $engine ] = $checked[ $engine ]
					? array( 'state' => self::ABSENT, 'severity' => '', 'count' => 0 )
					: array( 'state' => self::MISSING, 'severity' => '', 'count' => 0 );
			}

			// Reihenfolge der Spalten festhalten, sonst wechselt sie je Zeile.
			$ordered = array();

			foreach ( $engines as $engine )
				$ordered[ $engine ] = $rows[ $key ]['by'][ $engine ];

			$rows[ $key ]['by'] = $ordered;
			$rows[ $key ]['agreement'] = self::agreement( $ordered );
		}

		/**
		 * ⚠️ SORTED: DIFFERENCES FIRST. Whoever opens the comparison view looks for exactly those. An
		 * alphabetical list forces the reader to search through the agreements.
		 */

		$order = array( 'single' => 0, 'partial' => 1, 'severity' => 2, 'count' => 3, 'full' => 4 );

		uasort( $rows, static function ( $a, $b ) use ( $order ) {

			$d = ( $order[ $a['agreement'] ] ?? 9 ) <=> ( $order[ $b['agreement'] ] ?? 9 );

			return $d !== 0 ? $d : strcmp( (string) $a['rule'], (string) $b['rule'] );

		} );

		$summary = array( 'full' => 0, 'severity' => 0, 'count' => 0, 'partial' => 0, 'single' => 0 );

		foreach ( $rows as $row )
			$summary[ $row['agreement'] ]++;

		$verdicts = array();

		foreach ( $reports as $engine => $report )
			$verdicts[ $engine ] = $checked[ $engine ]
				? ( $report->rejected() ? 'reject' : 'accept' )
				: null;

		return array(
			'engines'  => $engines,
			'checked'  => $checked,
			'rows'     => array_values( $rows ),
			'summary'  => $summary,
			'verdicts' => $verdicts,

			// ⚠️ Does the traffic light say the same? That is the number a user wants to know first -
			// and it is NOT the same as "found the same rules".
			'verdictsAgree' => count( array_unique( array_filter( $verdicts, static function ( $v ) { return $v !== null; } ) ) ) <= 1,
		);
	}


	/**
	 * The key under which two messages count as "the same".
	 *
	 * ⚠️ WITHOUT AN ID THE TEXT IS TAKEN, BUT NORMALISED AND SHORTENED. Two validators never
	 * word it the same; the substitute key is therefore not meant to give the impression that it
	 * could recognise agreement - it only keeps the message visible.
	 *
	 * @param	array	$finding
	 * @return	string
	 * @since	1.7.0
	 */

	private static function key( array $finding )
	{
		$rule = strtoupper( trim( (string) ( $finding['rule'] ?? '' ) ) );

		if ( $rule !== '' )
			return( 'rule:' . $rule );

		$text = strtolower( (string) preg_replace( '/\s+/', ' ', (string) ( $finding['message'] ?? '' ) ) );

		return( 'text:' . substr( trim( $text ), 0, 60 ) );
	}


	/**
	 * How far do the validators agree on this one rule?
	 *
	 *   full      all that checked report it - same level, same count
	 *   severity  all report it, but with different levels
	 *   count     all report it with the same level, but a different number of times
	 *   partial   at least one reports it, at least one does not
	 *   single    only one single validator knows it at all
	 *
	 * ⚠️ VALIDATORS WITHOUT A REPORT DO NOT COUNT. Otherwise every failure would appear as a difference
	 * and make the number useless.
	 *
	 * @param	array	$by
	 * @return	string
	 * @since	1.7.0
	 */

	private static function agreement( array $by )
	{
		$present = array();
		$absent  = 0;

		foreach ( $by as $cell )
		{
			if ( $cell['state'] === self::MISSING )
				continue;

			if ( $cell['state'] === self::PRESENT )
				$present[] = $cell;
			else
				$absent++;
		}

		if ( count( $present ) === 0 )
			return( 'full' );   // does not occur, but a row without a reporter would be no difference

		if ( $absent > 0 )
			return( count( $present ) === 1 ? 'single' : 'partial' );

		$severities = array_unique( array_map( static function ( $c ) { return $c['severity']; }, $present ) );

		if ( count( $severities ) > 1 )
			return( 'severity' );

		$counts = array_unique( array_map( static function ( $c ) { return $c['count']; }, $present ) );

		return( count( $counts ) > 1 ? 'count' : 'full' );
	}

}

endif;
