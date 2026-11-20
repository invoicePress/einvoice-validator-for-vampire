<?php
/**
 * The matrix of the multi-edition check, as the visitor sees it.
 *
 * The engine decides ( `\EinvoiceValidator\Cascade\Cascade`, VAMPIRE PHP ): which family, which edition, why, and
 * per edition the verdict and the rules in which it differs from the other editions. This class only turns that
 * array into the markup of the kit: a verdict bar for the chosen edition and one table row per edition.
 *
 * ⚠️ RENDERED ON THE SERVER, INTO THE SAME `html` THE UPLOADER ALREADY SHOWS. `sppv-uploader.js` puts the view HTML
 * into the page as it comes; a matrix built in JavaScript would need a second copy of every label in the script's
 * text table, and the two would drift.
 *
 * ⚠️ THE REPORT BELOW THE MATRIX IS THE REPORT OF THE CHOSEN EDITION WHEN THAT EDITION CHECKS WITH RULES OF ITS OWN
 * ( since 1.12.1, Ralf's answer of 21.10.2026 ). Otherwise it is the single check, and the panel says which of the two
 * stands there, so that nobody reads the report of 2.5.2 as the report of the edition the cascade chose.
 *
 * @package SP_EInvoice_Validator_PHP
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


class SPPV_Matrix_View
{


	/**
	 * @param	array	$r			the result of `Cascade::validateString()`
	 * @param	bool	$chosenReport	true when the report below is that of the chosen edition
	 * @return	string	HTML, '' when the document belongs to no family with editions
	 * @since	09.10.2026
	 */

	public static function render( array $r, bool $chosenReport = false )
	{

		if ( empty( $r[ 'family' ] ) || empty( $r[ 'steps' ] ) )
			return( '' );

		$chosen  = (array) ( $r[ 'chosen' ] ?? array() );
		$version = (string) ( $chosen[ 'version' ] ?? '' );
		$step    = null;

		foreach ( $r[ 'steps' ] as $s )
			if ( $s[ 'ran' ] && (string) $s[ 'version' ] === $version )
				$step = $s;

		$verdict = $step !== null ? (string) $step[ 'verdict' ] : 'no-verdict';

		/*
		 * ⚠️ ONLY A REJECTION IS RED. A red bar for every verdict other than "accepted" and "warnings" would also
		 * catch "no verdict" ( an edition whose rule package is not included ) - and then say "this invoice breaks
		 * a rule" under the words "no verdict". The single check draws the same case orange
		 * ( SPPV_Plugin::no_verdict_panel() ), and the two must not disagree on one page.
		 */
		$bar = $verdict === 'accepted' ? 'pass' : ( $verdict === 'rejected' ? 'fail' : 'warn' );

		$html = '<section class="panel sppv-matrix"><div class="panel-head"><h2>' . esc_html__( 'Check against every edition', 'einvoice-validator-for-vampire' ) . '</h2></div><div class="panel-body pad">';

		$html .= '<div class="verdict ' . esc_attr( $bar ) . '"><div class="verdict-main"><div>'
			. '<div class="verdict-title">' . esc_html( sprintf(
				/* translators: 1: the format family, e.g. "ZUGFeRD / Factur-X"; 2: a verdict such as "accepted"; 3: an edition, e.g. "2.4". */
				__( '%1$s: %2$s by edition %3$s', 'einvoice-validator-for-vampire' ),
				(string) $r[ 'family' ][ 'title' ],
				self::verdict_label( $verdict ),
				$version
			) ) . '</div>'
			// The reason in the language of the site; see reason() for why the engine's sentence is not used as it is.
			. '<div class="verdict-desc">' . esc_html( self::reason( $r ) ) . '</div>'
			. '</div></div><div class="verdict-stamp">' . esc_html( $version ) . '</div></div>';

		$html .= '<table class="tbl zebra sppv-matrix-table"><thead><tr>'
			. '<th>' . esc_html__( 'Edition', 'einvoice-validator-for-vampire' ) . '</th>'
			. '<th>' . esc_html__( 'Result', 'einvoice-validator-for-vampire' ) . '</th>'
			. '<th>' . esc_html__( 'Errors', 'einvoice-validator-for-vampire' ) . '</th>'
			. '<th>' . esc_html__( 'Warnings', 'einvoice-validator-for-vampire' ) . '</th>'
			. '<th>' . esc_html__( 'Rules that differ from the other editions', 'einvoice-validator-for-vampire' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $r[ 'steps' ] as $s )
		{
			$v    = (string) $s[ 'verdict' ];
			$diff = '';

			if ( is_array( $s[ 'differs' ] ?? null ) )
			{
				foreach ( $s[ 'differs' ][ 'fired' ] as $k )
					$diff .= '<code class="sppv-fired">' . esc_html( (string) $k ) . '</code> ';

				foreach ( $s[ 'differs' ][ 'notFired' ] as $k )
					$diff .= '<code class="sppv-missing"><del>' . esc_html( (string) $k ) . '</del></code> ';
			}

			$html .= '<tr' . ( (string) $s[ 'version' ] === $version ? ' class="sppv-chosen"' : '' ) . '>'
				. '<td>' . esc_html( (string) $s[ 'version' ] ) . '</td>'
				. '<td><span class="badge ' . esc_attr( self::badge( $v ) ) . '">' . esc_html( self::verdict_label( $v ) ) . '</span>'
				. ( ! empty( $s[ 'schemaFailed' ] ) ? ' <span class="badge mute">' . esc_html__( 'schema', 'einvoice-validator-for-vampire' ) . '</span>' : '' ) . '</td>'
				. '<td>' . ( is_array( $s[ 'counts' ] ?? null ) ? (int) $s[ 'counts' ][ 'fatal' ] : '' ) . '</td>'
				. '<td>' . ( is_array( $s[ 'counts' ] ?? null ) ? (int) $s[ 'counts' ][ 'warning' ] : '' ) . '</td>'
				. '<td>' . $diff . '</td>'
				. '</tr>';
		}

		$html .= '</tbody></table>';

		$html .= '<p class="description">' . ( $chosenReport
			? esc_html( sprintf(
				/* translators: %s: an edition, e.g. "2.4". */
				__( 'The report below is the check with edition %s, the edition chosen above. The table above checks the same document against every edition of its format.', 'einvoice-validator-for-vampire' ),
				$version
			) )
			: esc_html__( 'The report below is the single check with the rule sets of this installation. The table above checks the same document against every edition of its format.', 'einvoice-validator-for-vampire' ) ) . '</p>';

		return( $html . '</div></section>' );

	}


	/**
	 * Why the cascade chose this edition, in the language of the site.
	 *
	 * ⚠️ BUILT HERE FROM `reasonCode`, NOT TAKEN FROM `chosen.reason`. The engine writes its sentence in German or
	 * English only ( `SPPV_Validator::engine_language()` ); on a French site the panel would carry one English sentence
	 * under French headings, and a page never shows two languages. The English texts below are the engine's sentences
	 * word for word ( `CascadeResult::reason()` ), and the German translation is the engine's German sentence, so a
	 * German or English site reads exactly what the engine wrote.
	 *
	 * ⚠️ THE NEWER EDITIONS ARE EVERY STEP BEFORE THE CHOSEN ONE, run or not, as `CascadeResult::build()` counts them.
	 * A code this plugin does not know yet ( a newer engine ) falls back to the engine's own sentence.
	 *
	 * @param	array	$r	the result of `Cascade::validateString()`
	 * @return	string
	 * @since	09.10.2026
	 */

	private static function reason( array $r )
	{

		$chosen  = (array) ( $r[ 'chosen' ] ?? array() );
		$code    = (string) ( $chosen[ 'reasonCode' ] ?? '' );
		$version = (string) ( $chosen[ 'version' ] ?? '' );
		$valid   = (string) ( $chosen[ 'validVersion' ] ?? '' );
		$date    = (string) ( $r[ 'issueDate' ] ?? '' );
		$newer   = array();

		foreach ( (array) ( $r[ 'steps' ] ?? array() ) as $s )
		{
			if ( ! empty( $s[ 'ran' ] ) && (string) $s[ 'version' ] === $version )
				break;

			$newer[] = (string) $s[ 'version' ];
		}

		switch ( $code )
		{
			case 'first-accepting':
				return( empty( $newer )
					/* translators: %s: an edition, e.g. "2.5.2". */
					? sprintf( __( 'The newest edition %s accepts the invoice.', 'einvoice-validator-for-vampire' ), $version )
					: sprintf(
						/* translators: 1: a list of editions, e.g. "2.5.2, 2.5"; 2: an edition, e.g. "2.4". */
						__( 'The newer editions %1$s do not accept the invoice; %2$s is the newest edition that accepts it.', 'einvoice-validator-for-vampire' ),
						implode( ', ', $newer ),
						$version
					) );

			case 'none-accepting':
				/* translators: %s: an edition, e.g. "2.5.2". */
				return( sprintf( __( 'None of the checked editions accepts the invoice; the report of the newest edition %s applies.', 'einvoice-validator-for-vampire' ), $version ) );

			case 'validity':
				/* translators: 1: a date as YYYY-MM-DD; 2: an edition, e.g. "3.0.2". */
				return( sprintf( __( 'The invoice date %1$s lies in the validity period of edition %2$s.', 'einvoice-validator-for-vampire' ), $date, $version ) );

			case 'validity-not-carried':
				/* translators: 1: a date as YYYY-MM-DD; 2: the edition the date falls into; 3: the edition that was used. */
				return( sprintf( __( 'The invoice date %1$s lies in the validity period of edition %2$s. Its rule package is not included; the invoice was checked with %3$s, the nearest included edition.', 'einvoice-validator-for-vampire' ), $date, $valid, $version ) );

			case 'outside-validity':
				/* translators: 1: a date as YYYY-MM-DD; 2: an edition, e.g. "3.0.2". */
				return( sprintf( __( 'The invoice date %1$s lies in no known validity period; the invoice was checked with edition %2$s.', 'einvoice-validator-for-vampire' ), $date, $version ) );

			case 'no-date':
				/* translators: %s: an edition, e.g. "2.5.2". */
				return( sprintf( __( 'The invoice states no readable invoice date; it was checked with the newest included edition %s.', 'einvoice-validator-for-vampire' ), $version ) );

			case 'no-carried':
				return( __( 'This package contains no rule packages per edition for this format; the invoice was checked as in a single check.', 'einvoice-validator-for-vampire' ) );

			default:
				return( (string) ( $chosen[ 'reason' ] ?? '' ) );
		}

	}


	/**
	 * @param	string	$v	accepted | warnings | rejected | no-verdict | not-run
	 * @return	string
	 */

	private static function verdict_label( $v )
	{

		switch ( $v )
		{
			case 'accepted':   return( __( 'accepted', 'einvoice-validator-for-vampire' ) );
			case 'warnings':   return( __( 'accepted with warnings', 'einvoice-validator-for-vampire' ) );
			case 'rejected':   return( __( 'rejected', 'einvoice-validator-for-vampire' ) );
			case 'no-verdict': return( __( 'no result', 'einvoice-validator-for-vampire' ) );
			default:           return( __( 'not checked', 'einvoice-validator-for-vampire' ) );
		}

	}


	/**
	 * @param	string	$v
	 * @return	string	a badge modifier of the kit
	 */

	private static function badge( $v )
	{

		return( $v === 'accepted' ? 'ok' : ( $v === 'warnings' ? 'warn' : ( $v === 'rejected' ? 'err' : 'mute' ) ) );

	}


}
