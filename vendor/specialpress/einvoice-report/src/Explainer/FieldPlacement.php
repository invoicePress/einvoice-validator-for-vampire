<?php
/**
 * Which field of the invoice each finding of a report belongs to - the data behind the view "Invoice".
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report\Explainer;

use SpecialPress\EInvoice\Report\Report;

if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * class: FieldPlacement
 *
 * The view "Invoice" draws the invoice with the element `<einvoice-viewer>` and puts every finding at the
 * field it is about. The element knows business terms and the order of the invoice lines, not XPaths; this
 * class translates the report's location of each finding into exactly that: a business term, the number of the
 * invoice line ( 0 for the document level ) and which occurrence of the term within that line or level is meant.
 * Twin of `src/explainer/FieldPlacement.mjs` in `libs/einvoice-core-js`; both write the same placements,
 * compared by `test/compare-report-views.mjs`.
 *
 * One placement per finding:
 *
 *   finding     index into `$report->findings`
 *   rule        the rule id, '' when the report gives none
 *   severity    'error' | 'warning' | 'information'
 *   bt          the business term the finding is shown at, '' when it has no field ( then also in `unplaced` )
 *   line        1-based number of the invoice line ( BG-25 ) the finding sits in, 0 for the document level
 *   occurrence  1-based: the how-many-th occurrence of `bt` within that line or level
 *   missing     true when the field is not in the invoice at all - the view shows an empty, marked row
 *   also        the other fields of the rule the invoice carries, each as array( bt, line, occurrence, missing ); the
 *               view shows the finding at each of them too ( see also_fields() )
 *   origin      'norm' for a rule of a standard, 'profile' for a house rule of a check profile
 *   profile     the name of that profile, '' for a rule of a standard
 *   text        the statement the field shows: the plain-language explanation, otherwise the validator's text
 *
 * ⚠️ THE ORDER OF THE KEYS IS PART OF THE CONTRACT. The parity run compares the JSON text of both sides; a key in
 * another position counts as a difference.
 *
 * ⚠️ NOTHING IS GUESSED - THE SAME RULE AS `Renderer::row_terms()`. A finding whose location names no field and
 * whose rule names no field under that location stays without a field and is counted in "Invoice" with a jump
 * to "Messages". A wrong field would be worse than none: the reader would look for the error in the wrong
 * place.
 *
 * ⚠️ THE ROWS OF THE DOCUMENT ARE THE ONLY SOURCE. A KoSIT or VAMPIRE report brings them; for a Mustang report
 * the caller attaches the invoice through `Frontend\DocumentView` first. Without rows every finding is unplaced,
 * and that is what the view then says.
 *
 * @since 1.15.0
 */

if ( ! class_exists( __NAMESPACE__ . '\\FieldPlacement' ) ) :

class FieldPlacement
{


	/**
	 * The element names that open an invoice line ( BG-25 ) - CII, UBL invoice and UBL credit note.
	 *
	 * ⚠️ THE INDEX OF THIS STEP IS THE NUMBER OF THE LINE, AND THAT HOLDS ONLY BECAUSE THE LINES ARE SIBLINGS. All
	 * BG-25 of a document are children of one parent in every syntax, so `InvoiceLine[3]` is the third line in
	 * document order, which is the order in which the viewer draws its line groups ( `all( tree, 'BG-25' )` ).
	 */
	const LINE_ELEMENTS = array( 'IncludedSupplyChainTradeLineItem', 'InvoiceLine', 'CreditNoteLine' );


	/**
	 * The placement of every finding.
	 *
	 * @param	Report	$report
	 * @param	Terms	$terms	the caller's rule and field lookup, as for the renderer
	 * @return	array	array( 'placements' => list, 'unplaced' => list of finding indices )
	 * @since	1.15.0
	 */

	public static function place( Report $report, Terms $terms ): array
	{
		if ( $report->document && ( ! isset( $report->document[0]['path'] ) || $report->document[0]['path'] === '' ) )
			$report->build_paths();

		$syntax   = (string) ( $report->meta['syntax'] ?? '' );
		$profiles = self::profiles( $report );
		$keys     = array();

		foreach ( $report->document as $i => $row )
			$keys[ $i ] = self::key( (string) ( $row['path'] ?? '' ) );

		$termOf = self::row_terms( $report, $terms, $keys );
		$out    = array();
		$none   = array();

		foreach ( $report->findings as $i => $finding )
		{
			$rule   = (string) ( $finding['rule'] ?? '' );
			$anchor = ( $finding['rows'] ?? array() )[0] ?? null;
			$known  = $rule !== '' ? $terms->rule( $rule ) : array();

			$bt         = '';
			$missing    = false;
			$line       = 0;
			$occurrence = 0;
			$ownField   = false;

			if ( $anchor !== null && isset( $report->document[ $anchor ] ) )
			{
				$anchor = (int) $anchor;
				$line   = self::line_of( (string) $report->document[ $anchor ]['path'] );
				$own    = $termOf[ $anchor ] ?? '';

				if ( $own !== '' && strpos( $own, 'BT-' ) === 0 )
				{
					// (a) The location is the field itself.
					$bt         = $own;
					$ownField   = true;
					$occurrence = self::occurrence( $report, $keys, $anchor, $line );
					$missing    = self::is_empty( $report, $anchor );
				}
				else
				{
					/*
					 * The location is a group or the root: the rule's fields decide. The first field of the rule
					 * that sits below this location and is there takes the finding; when none of them is there,
					 * the first one is missing ( b ). See the end of from_rule() for why in this order.
					 */
					$hit = self::from_rule( $report, $terms, $keys, $anchor, $line, (array) ( $known['fields'] ?? array() ), $syntax );

					if ( $hit !== null )
						list( $bt, $occurrence, $missing ) = $hit;
				}
			}

			/*
			 * ⚠️ THE OTHER FIELDS ONLY FOR A LOCATION THAT IS NOT A FIELD ITSELF. When the validator points at a field
			 * ( a ), the finding is about that field: PEPPOL-EN16931-R051 names every amount of the invoice and BT-5,
			 * and its finding for one amount would otherwise stand at every amount - measured on 04.11.2026 with
			 * `ubl-base-totals-errors`, thirteen findings at the same thirteen fields. A group or the root ( b ) says
			 * only where to look, and there the rule's fields are what the error is made of ( BR-53 ).
			 */
			$also = $ownField ? array() : self::also_fields( $report, $terms, $keys, $anchor, (array) ( $known['fields'] ?? array() ), $syntax, $bt, $line, $occurrence );

			/*
			 * ⚠️ A FINDING WITHOUT A FIELD OF ITS OWN TAKES THE FIRST OTHER FIELD OF ITS RULE. The location named no
			 * field and no field of the rule lies below it, but the rule names one the invoice carries elsewhere: it
			 * is part of the error, so the reader finds the finding there rather than only under "Messages".
			 */
			if ( $bt === '' && $also )
			{
				$first      = array_shift( $also );
				$bt         = $first['bt'];
				$line       = $first['line'];
				$occurrence = $first['occurrence'];
				$missing    = $first['missing'];
			}

			if ( $bt === '' )
			{
				$none[]     = (int) $i;
				$line       = 0;
				$occurrence = 0;
			}

			$explanation = $terms->text( $known['description'] ?? '' );

			$out[] = array(
				'finding'    => (int) $i,
				'rule'       => $rule,
				'severity'   => (string) ( $finding['severity'] ?? '' ),
				'bt'         => $bt,
				'line'       => $line,
				'occurrence' => $occurrence,
				'missing'    => $missing,
				'also'       => $also,
				'origin'   => ( $profiles[ $i ] ?? '' ) !== '' ? 'profile' : 'norm',
				'profile'    => (string) ( $profiles[ $i ] ?? '' ),
				'text'       => $explanation !== '' ? $explanation : (string) ( $finding['message'] ?? '' ),
			);
		}

		return( array( 'placements' => $out, 'unplaced' => $none ) );
	}


	/**
	 * A field from the rule, for a finding located at a group or at the root.
	 *
	 * @param	Report	$report
	 * @param	Terms	$terms
	 * @param	array	$keys		row index => key()
	 * @param	int		$anchor
	 * @param	int		$line
	 * @param	array	$fields		the rule's business terms
	 * @param	string	$syntax
	 * @return	array|null	array( bt, occurrence, missing ) or null
	 * @since	1.15.0
	 */

	private static function from_rule( Report $report, Terms $terms, array $keys, int $anchor, int $line, array $fields, string $syntax ): ?array
	{
		$anchorKey = $keys[ $anchor ] ?? '';
		$present   = null;
		$absent    = null;

		foreach ( $fields as $bt )
		{
			$bt = (string) $bt;

			// ⚠️ Only business terms: a group ( BG ) has no row of its own in the viewer.
			if ( strpos( $bt, 'BT-' ) !== 0 )
				continue;

			$xpath = trim( $terms->fieldXpath( $bt, $syntax ) );

			/*
			 * ⚠️ AN ALTERNATIVE OR AN ATTRIBUTE IS NOT LOOKED AT. "A | B" names two elements, and an attribute has
			 * no row of its own in the document table of a report. A predicate or a condition is cut off: it says
			 * which of several siblings is meant, and the key compares elements, not values.
			 *
			 * ⚠️ THE `@` COUNTS ONLY OUTSIDE A PREDICATE. BT-110 in CII is `…/ram:TaxTotalAmount[@currencyID = …]`,
			 * an element with a condition on its attribute; with the `@` counted anywhere, every rule that names
			 * BT-110 would lose it here.
			 *
			 * ⚠️ "MISSING" STATES A FACT, NOT A DUTY. A rule that names only an optional field puts its finding on that
			 * field, and when the field is absent the view says so. Measured on 11.10.2026: PEPPOL-EN16931-R054 names
			 * only BT-6, and in the Mustang report of `zugferd-e05-totals-errors` it stands at the empty row of BT-6
			 * with "Missing in the invoice" - true, although BT-6 is not mandatory. The cardinalities of the field
			 * catalogue cannot tell the two apart reliably: the head of `InvoiceHints.mjs` records 53 business terms
			 * on which the node catalogues and `en16931.json` disagree, and wrong cardinalities in `en16931.json`.
			 */
			if ( $xpath === '' || strpos( $xpath, '|' ) !== false || strpos( (string) preg_replace( '/\[[^\]]*\]/', '', $xpath ), '@' ) !== false )
				continue;

			$fieldKey = self::key( $xpath );

			if ( $fieldKey === '' )
				continue;

			// Does the field belong below the location? Only then can its absence be read there.
			$under = $anchorKey === '' || strpos( $fieldKey, $anchorKey . '/' ) === 0;

			if ( ! $under )
				continue;

			$row = null;

			for ( $k = $anchor + 1; isset( $report->document[ $k ] ) && (int) $report->document[ $k ]['level'] > (int) $report->document[ $anchor ]['level']; $k++ )
				if ( ( $keys[ $k ] ?? '' ) === $fieldKey )
				{
					$row = $k;
					break;
				}

			/*
			 * ⚠️ AT THE ROOT, "BELOW" MEANS THE DOCUMENT LEVEL. A field of an invoice line below the root is in
			 * every line or in none; the root cannot say which line a missing one belongs to.
			 */
			if ( $anchorKey === '' && self::line_of_key( $fieldKey ) )
				continue;

			if ( $row === null && $absent === null )
				$absent = array( $bt, self::occurrence( $report, $keys, $anchor, $line ), true );

			if ( $row !== null && $present === null )
				$present = array( $bt, self::occurrence( $report, $keys, $row, $line ), self::is_empty( $report, $row ) );
		}

		/*
		 * ⚠️ A FIELD THAT IS THERE WINS OVER ONE THAT IS NOT. A rule names optional fields too: BR-S-08 lists the
		 * exemption reason ( BT-120 ) next to the category ( BT-118 ), and an invoice with category S carries no
		 * reason. "Missing" is only said when none of the rule's fields below the location is there - BR-02 at
		 * the root with BT-1, BR-25 at a line with BT-153, BR-CO-26 at the seller with BT-29, BT-30 and BT-31.
		 */
		return( $present ?? $absent );
	}


	/**
	 * The other fields of the rule that the invoice carries, each with its line and occurrence.
	 *
	 * ⚠️ EVERY FIELD THAT TAKES PART IN THE ERROR, NOT ONLY THE ONE THE FINDING IS SHOWN AT ( since 04.11.2026 ). BR-53
	 * names the VAT currency ( BT-6 ) and the VAT total in accounting currency ( BT-111 ); the finding stood only at the
	 * missing BT-111, and the reader did not see that the BT-6 the invoice carries is what triggers the rule. The rows
	 * come from `Report::field_rows()`, the same search with which the renderer marks the rows of a rule's fields in
	 * the document tree, so "Invoice" and "XML" name the same fields.
	 *
	 * ⚠️ ONLY FIELDS THE INVOICE CARRIES. A field the rule names but the invoice does not carry is not added: a rule
	 * names optional fields too ( see the end of from_rule() ), and "Missing in the invoice" at each of them would be
	 * wrong. The one missing field a finding is about is already its own placement.
	 *
	 * ⚠️ THE SAME EXCLUSIONS AS from_rule(): no group, no alternative, no attribute outside a predicate.
	 *
	 * @param	Report		$report
	 * @param	Terms		$terms
	 * @param	array		$keys		row index => key()
	 * @param	int|null	$anchor		the row of the finding's location, or null
	 * @param	array		$fields		the rule's business terms
	 * @param	string		$syntax
	 * @param	string		$bt			the placement of the finding itself, left out here
	 * @param	int			$line
	 * @param	int			$occurrence
	 * @return	array		list of array( 'bt', 'line', 'occurrence', 'missing' )
	 * @since	1.19.0
	 */

	private static function also_fields( Report $report, Terms $terms, array $keys, $anchor, array $fields, string $syntax, string $bt, int $line, int $occurrence ): array
	{
		$out  = array();
		$seen = array( $bt . '|' . $line . '|' . $occurrence => true );
		$near = ( $anchor !== null && isset( $report->document[ $anchor ] ) ) ? (int) $anchor : null;

		foreach ( $fields as $id )
		{
			$id = (string) $id;

			if ( strpos( $id, 'BT-' ) !== 0 )
				continue;

			$xpath = trim( $terms->fieldXpath( $id, $syntax ) );

			if ( $xpath === '' || strpos( $xpath, '|' ) !== false || strpos( (string) preg_replace( '/\[[^\]]*\]/', '', $xpath ), '@' ) !== false )
				continue;

			foreach ( $report->field_rows( $xpath, $near ) as $row )
			{
				$l   = self::line_of( (string) $report->document[ $row ]['path'] );
				$occ = self::occurrence( $report, $keys, (int) $row, $l );
				$key = $id . '|' . $l . '|' . $occ;

				if ( isset( $seen[ $key ] ) )
					continue;

				$seen[ $key ] = true;
				$out[] = array( 'bt' => $id, 'line' => $l, 'occurrence' => $occ, 'missing' => self::is_empty( $report, (int) $row ) );
			}
		}

		return( $out );
	}


	/**
	 * The how-many-th row with the same key the row is, counted within its invoice line or within the document
	 * level.
	 *
	 * ⚠️ COUNTED BY THE PATH, NOT BY THE BUSINESS TERM. For a missing field the row is the group the field is
	 * missing from, and "the second VAT breakdown" is the second `ApplicableTradeTax` below the settlement, which
	 * is the second row of BT-116 to BT-121 in the viewer's table of the VAT breakdown.
	 *
	 * @param	Report	$report
	 * @param	array	$keys
	 * @param	int		$row
	 * @param	int		$line
	 * @return	int
	 * @since	1.15.0
	 */

	private static function occurrence( Report $report, array $keys, int $row, int $line ): int
	{
		$n = 1;

		for ( $k = 0; $k < $row; $k++ )
			if ( ( $keys[ $k ] ?? '' ) === ( $keys[ $row ] ?? '' ) && self::line_of( (string) $report->document[ $k ]['path'] ) === $line )
				$n++;

		return( $n );
	}


	/**
	 * Whether a row is an element without a value and without children - a field that is written but empty.
	 *
	 * ⚠️ AN EMPTY FIELD IS A MISSING FIELD FOR THE READER. The mutated sample `ubl-vatcat-mandatory-errors.xml`
	 * carries `<cbc:ID/>`: BR-02 says the invoice number is missing, and the row exists. Marking it only as "there"
	 * would draw an empty row without the word that says what is wrong with it ( b ).
	 *
	 * @param	Report	$report
	 * @param	int		$row
	 * @return	bool
	 * @since	1.15.0
	 */

	private static function is_empty( Report $report, int $row ): bool
	{
		if ( trim( (string) ( $report->document[ $row ]['value'] ?? '' ) ) !== '' )
			return( false );

		$next = $report->document[ $row + 1 ] ?? null;

		return( $next === null || (int) $next['level'] <= (int) $report->document[ $row ]['level'] );
	}


	/**
	 * The number of the invoice line a path lies in, 0 for the document level.
	 *
	 * @param	string	$path
	 * @return	int
	 * @since	1.15.0
	 */

	public static function line_of( string $path ): int
	{
		foreach ( explode( '/', Report::normalise_path( $path ) ) as $seg )
			if ( preg_match( '/^([A-Za-z]+)\[([0-9]+)\]$/', $seg, $m ) && in_array( $m[1], self::LINE_ELEMENTS, true ) )
				return( (int) $m[2] );

		return( 0 );
	}


	/**
	 * Whether a key ( without indices ) lies in an invoice line.
	 *
	 * @param	string	$key
	 * @return	bool
	 * @since	1.15.0
	 */

	private static function line_of_key( string $key ): bool
	{
		foreach ( explode( '/', $key ) as $seg )
			if ( in_array( $seg, self::LINE_ELEMENTS, true ) )
				return( true );

		return( false );
	}


	/**
	 * A path without prefixes, indices, predicates, condition and root element - the form in which a row of the
	 * document and a field of the catalogue are compared.
	 *
	 * ⚠️ THE ROOT GOES BECAUSE THE CATALOGUE WRITES `/Invoice` FOR A CREDIT NOTE TOO, and VAMPIRE writes `/*`.
	 *
	 * @param	string	$path
	 * @return	string	'' for the root itself
	 * @since	1.15.0
	 */

	public static function key( string $path ): string
	{
		$path = (string) preg_replace( '/\s\(.*$/s', '', $path );
		$path = (string) preg_replace( '/\[[^\]]*\]/', '', $path );
		$segs = explode( '/', ltrim( (string) preg_replace( '/\[[0-9]+\]/', '', Report::normalise_path( $path ) ), '/' ) );

		array_shift( $segs );

		return( implode( '/', $segs ) );
	}


	/**
	 * Which business term each row carries - the same choice as `Renderer::row_terms()`: by the full path, only
	 * catalogue paths without condition, predicate, alternative or attribute, and a path shared by several fields
	 * only when exactly one of them is a plain id.
	 *
	 * ⚠️ A COPY OF THE RULE, NOT A CALL. `Renderer::row_terms()` is private and lives in a class that five plugins
	 * carry in their own version; a public method there would be one more thing an older copy lacks. The two must
	 * stay the same rule, and `compare-report-views.mjs` would show a difference through the placements.
	 *
	 * @param	Report	$report
	 * @param	Terms	$terms
	 * @param	array	$keys
	 * @return	array	row index => BT/BG id
	 * @since	1.15.0
	 */

	private static function row_terms( Report $report, Terms $terms, array $keys ): array
	{
		if ( ! method_exists( $terms, 'fieldIds' ) || ! $report->document )
			return( array() );

		$syntax = (string) ( $report->meta['syntax'] ?? '' );
		$byKey  = array();

		foreach ( (array) $terms->fieldIds() as $id )
		{
			$xpath = trim( $terms->fieldXpath( (string) $id, $syntax ) );

			if ( $xpath === '' || preg_match( '/\s\(|\[[^\]]*[^0-9\]][^\]]*\]|\||@/', $xpath ) )
				continue;

			$key = self::key( $xpath );

			if ( $key !== '' )
				$byKey[ $key ][] = (string) $id;
		}

		$chosen = array();

		foreach ( $byKey as $key => $ids )
		{
			if ( count( $ids ) === 1 )
			{
				$chosen[ $key ] = $ids[0];
				continue;
			}

			$plain = array_values( array_filter( $ids, static function ( $id ) { return( (bool) preg_match( '/^B[TG]-[0-9]+$/', $id ) ); } ) );

			if ( count( $plain ) === 1 )
				$chosen[ $key ] = $plain[0];
		}

		$out = array();

		foreach ( $keys as $i => $key )
			if ( isset( $chosen[ $key ] ) )
				$out[ $i ] = $chosen[ $key ];

		return( $out );
	}


	/**
	 * The check profile each finding comes from, '' for a rule of a standard.
	 *
	 * ⚠️ READ FROM THE STEPS, BECAUSE THE KoSIT FORMAT HAS NO PLACE FOR IT. VAMPIRE writes the findings of a
	 * check profile into a validation step of their own whose resource is named `profile:<name>`. The reader keeps
	 * the steps in report order with their counts and the findings in the same order, so the step of a finding
	 * follows from counting. That only holds when the counts add up to the findings; a Mustang report counts the
	 * rules it ran instead, and then nothing is read from the steps.
	 *
	 * ⚠️ A FINDING MAY CARRY `profile` ITSELF. A caller that read a report with its own origins ( the JSON report of
	 * VAMPIRE ) sets it, and it wins.
	 *
	 * Public because the renderer marks the same findings in the view "Messages" ( Renderer::findings() ).
	 *
	 * @param	Report	$report
	 * @return	array	finding index => profile name
	 * @since	1.15.0
	 */

	public static function profiles( Report $report ): array
	{
		$out   = array();
		$total = 0;

		foreach ( $report->steps as $step )
			$total += (int) ( $step['errors'] ?? 0 ) + (int) ( $step['warnings'] ?? 0 ) + (int) ( $step['infos'] ?? 0 );

		if ( $total === count( $report->findings ) )
		{
			$i = 0;

			foreach ( $report->steps as $step )
			{
				$n    = (int) ( $step['errors'] ?? 0 ) + (int) ( $step['warnings'] ?? 0 ) + (int) ( $step['infos'] ?? 0 );
				$name = (string) ( $step['step'] ?? '' );

				for ( $k = 0; $k < $n; $k++, $i++ )
					if ( strpos( $name, 'profile:' ) === 0 )
						$out[ $i ] = substr( $name, 8 );
			}
		}

		foreach ( $report->findings as $i => $finding )
			if ( isset( $finding['profile'] ) && (string) $finding['profile'] !== '' )
				$out[ $i ] = (string) $finding['profile'];

		return( $out );
	}

}

endif;
