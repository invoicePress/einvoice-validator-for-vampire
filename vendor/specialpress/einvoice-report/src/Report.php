<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The parsed validation report - the single shape every reader produces.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report;

// This file sits inside a publicly reachable wp-content/plugins/ tree once vendored into a WP plugin -
// same guard every other portable class in this project carries. A harness that needs this class
// outside WordPress defines ABSPATH itself before requiring it (see this project's own convention).
if ( ! defined( 'ABSPATH' ) )
	exit;


/**
 * class: Report
 *
 * ============================================================================================
 * WHY THE REPORT MODEL LIVES IN THIS PACKAGE
 * ============================================================================================
 *
 * The model lives here so the Validator can build a "Report Lite" view without depending on the
 * Explainer at all, and the Explainer reads through this same package instead of owning the model -
 * see that plugin's own `class-spx-report.php`, a one-line `class_alias()` onto this class, not a copy.
 *
 * ⚠️ DELIBERATELY DUMB. No BT/BG mapping, no explanation text, no plain-language rendering -
 * that is what makes this safe for the Validator to show on its own ( "Report Lite" ) without
 * pulling in the Explainer's rule catalogue. A reader fills this, a renderer reads
 * it - Report Lite's renderer lives in this same package ( see Lite/Renderer.php ), the Explainer's
 * richer one stays in its own plugin.
 *
 * ============================================================================================
 * THE PARTS
 * ============================================================================================
 *
 *   meta      what the report says about itself and the document it checked
 *   steps     the validation steps and their tallies, in report order
 *   findings  one entry per rule violation, in the order the validator reported them
 *   document  the document as a flat list of rows, in document order
 *   notices   problems the reader hit but survived
 *
 * The document is FLAT, not a tree, and carries a `level` per row instead - that is how the report
 * itself models it.
 *
 * @since 1.0.0
 */

// ⚠️ GUARDED, NOT A PLAIN `class Report {`. This package is vendored into more than one plugin at
// once ( Composer into the Explainer's vendor/, a raw copy into the Validator's libs/ - see each
// plugin's own bootstrap comment ) - two PHYSICALLY DIFFERENT files declaring the same namespaced
// class. Without this guard, having both plugins active fatals with "Cannot redeclare class" the
// moment the second file is required. Alternative `if (...): ... endif;` syntax specifically so the
// class body below needs no re-indentation - same idiom as einvoice-host-wp's RateLimit.php.

if ( ! class_exists( __NAMESPACE__ . '\\Report' ) ) :

class Report
{


	/**
	 * Report and document metadata.
	 *
	 * Keys, all optional and all strings:
	 *
	 *   source            'xml' | 'html' - which reader produced this
	 *   reference         the validator's own reference for the run ( HTML source only - see
	 *                     ReaderXml::read_meta()'s own comment for why the XML source never sets this )
	 *   validated_at      time of validation, as the report wrote it
	 *   document_type     "UBL Storecove 3.0.19 (Invoice)" and the like
	 *   customization     the customisation URN of the matched KoSIT scenario, e.g.
	 *                     "urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended" - XML source
	 *                     only; this, not document_type, decides the profile
	 *   syntax           'CII' | 'UBL' | ''
	 *   compliant         'yes' | 'no' | ''
	 *   seller            recognised seller name
	 *   invoice_number    recognised invoice number
	 *   invoice_date      recognised invoice date
	 *   engine            the validator's own name/version string, e.g. "KoSIT Validator 1.6.3" -
	 *                     XML source only, read from /rep:report/rep:engine/rep:name ( the HTML
	 *                     rendering carries no equivalent field, measured - see ReaderXml )
	 *   config_version    a version-looking token pulled from the matched scenario's free-text
	 *                     description, e.g. "3.0.2" out of "XRechnung Schematron rules version 3.0.2" -
	 *                     BEST EFFORT ONLY, unset when nothing matched rather than a wrong guess ( no
	 *                     dedicated schema element carries this, see ReaderXml::read_config_version() )
	 *
	 * @var array
	 * @since 1.0.0
	 */

	public $meta = array();


	/**
	 * The validation steps and their tallies, in report order.
	 *
	 * One entry: array( 'step' => string, 'errors' => int, 'warnings' => int, 'infos' => int )
	 *
	 * @var array
	 * @since 1.0.0
	 */

	public $steps = array();


	/**
	 * The findings, in report order.
	 *
	 * One entry:
	 *
	 *   position   'val-sch.2.1'  the validator's own position id
	 *   rule       'BR-E-08'      the rule id, or '' when the report gives none
	 *   severity   'error' | 'warning' | 'information'
	 *   message    the validator's text, verbatim
	 *   path       the XPath the validator pointed at, or ''
	 *   rows       int[]          indices into $document, filled by the resolver, may stay empty
	 *
	 * @var array
	 * @since 1.0.0
	 */

	public $findings = array();


	/**
	 * The document, flat, in document order.
	 *
	 *   pos     '0005'   the report's own position, kept verbatim as the anchor
	 *   level   int      nesting depth, 1-based
	 *   name    string   element name as the report gives it
	 *   value   string   the value, '' when the element is a container
	 *   attr    string   attributes, '' when there are none
	 *   path    string   reconstructed XPath, filled by build_paths()
	 *   status  ''|'error'|'warning'  filled by link_findings()
	 *
	 * @var array
	 * @since 1.0.0
	 */

	public $document = array();


	/**
	 * Problems the reader hit but survived.
	 *
	 * @var array
	 * @since 1.0.0
	 */

	public $notices = array();


	/**
	 * How many findings of a severity there are.
	 *
	 * @param	string	$severity	'error', 'warning' or 'information'
	 * @return	int
	 * @since	1.0.0
	 */

	public function count( $severity )
	{
		$n = 0;

		foreach ( $this->findings as $f )
			if ( ( $f['severity'] ?? '' ) === $severity )
				$n++;

		return( $n );
	}


	/**
	 * Whether the report itself says the document was rejected.
	 *
	 * ⚠️ ASK THE REPORT, DO NOT COUNT - see the class doc block. Deriving "rejected" from "errors > 0"
	 * would usually agree, but a report can carry errors from a step that does not block.
	 *
	 * @return	bool
	 * @since	1.0.0
	 */

	public function rejected()
	{
		$stated = (string) ( $this->meta['compliant'] ?? '' );

		if ( $stated === 'no' )
			return( true );

		if ( $stated === 'yes' )
			return( false );

		return( $this->count( 'error' ) > 0 );
	}


	/**
	 * Reconstruct an XPath for every document row - see ReaderXml/ReaderHtml for how a row's `level`
	 * and `name` are populated; this only ever reads those two fields plus writes `path`.
	 *
	 * @return	void
	 * @since	1.0.0
	 */

	public function build_paths()
	{
		$stack = array();   // level => the INDEXED segment, e.g. "IncludedNote[3]"
		$seen  = array();   // full indexed parent path + "/" + name => how often seen

		foreach ( $this->document as $i => $row )
		{
			$level = (int) $row['level'];

			// ⚠️ The local name, not `name` as it stands - see the same line in
			// Report.buildPaths() for why a prefix here makes every field lookup miss.
			$name  = self::local_part( $row['name'] );

			foreach ( array_keys( $stack ) as $l )
				if ( $l >= $level )
					unset( $stack[ $l ] );

			$parent = '';

			for ( $l = 1; $l < $level; $l++ )
				if ( isset( $stack[ $l ] ) )
					$parent .= '/' . $stack[ $l ];

			$key = $parent . '/' . $name;

			$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;

			$segment = $name . '[' . $seen[ $key ] . ']';

			$this->document[ $i ]['path'] = $parent . '/' . $segment;

			$stack[ $level ] = $segment;
		}
	}


	/**
	 * Attach findings to document rows and mark those rows.
	 *
	 * @return	void
	 * @since	1.0.0
	 */

	public function link_findings()
	{
		if ( ! $this->document )
			return;

		if ( ! isset( $this->document[0]['path'] ) || $this->document[0]['path'] === '' )
			$this->build_paths();

		foreach ( $this->findings as $i => $finding )
		{
			$path = (string) ( $finding['path'] ?? '' );

			if ( $path === '' )
				continue;

			$rows = array();

			foreach ( $this->document as $j => $row )
				if ( self::path_matches( $path, (string) $row['path'] ) )
					$rows[] = $j;

			$this->findings[ $i ]['rows'] = $rows;

			// ⚠️ Three levels: error, warning, info. An information marked its row as a warning until 19.10.2026 ( Ralf ).
			$mark = self::row_status( (string) ( $finding['severity'] ?? '' ) );

			foreach ( $rows as $j )
				if ( self::STATUS_RANK[ $mark ] > ( self::STATUS_RANK[ $this->document[ $j ]['status'] ?? '' ] ?? 0 ) )
					$this->document[ $j ]['status'] = $mark;
		}
	}


	/** The order of the row levels: the stronger statement wins on a row. */
	const STATUS_RANK = array( '' => 0, 'info' => 1, 'warning' => 2, 'error' => 3 );


	/**
	 * The level of a finding as the status of a row: `error`, `warning` or `info`.
	 *
	 * @param	string	$severity	`error`, `warning`, `information`
	 * @return	string
	 */

	public static function row_status( string $severity ): string
	{
		return( $severity === 'error' ? 'error' : ( $severity === 'warning' ? 'warning' : 'info' ) );
	}


	/**
	 * Find the document row a BT/BG field's OWN XPath (from profile data, not from a finding)
	 * addresses. Silent on ambiguity - more than one candidate and no way to prefer one means no
	 * value is shown at all, rather than a wrong one dressed up as a match. Used by the Explainer's
	 * own rule/BT lookup, not by Report Lite.
	 *
	 * @param	string		$xpath		CII or UBL, matching $near_index's syntax
	 * @param	int|null	$near_index	a document row index whose subtree is searched first, or null
	 * @return	array		'row' => int|null, 'ambiguous' => bool
	 * @since	1.0.0
	 */

	public function resolve_field( $xpath, $near_index = null )
	{
		$rows = $this->field_rows( $xpath, $near_index );

		if ( count( $rows ) === 1 )
			return( array( 'row' => $rows[0], 'ambiguous' => false ) );

		return( array( 'row' => null, 'ambiguous' => count( $rows ) > 1 ) );
	}


	/**
	 * Every document row a BT/BG field's own XPath addresses - twin of `Report.fieldRows()`.
	 *
	 * ⚠️ **A STEP WITHOUT AN INDEX MEANS "ANY", NOT "THE FIRST".** The field XPaths of the profile data
	 * name no index ( `…/IncludedSupplyChainTradeLineItem/…/LineTotalAmount` for BT-131 ). Until
	 * 23.10.2026 they went through normalise_path(), which adds `[1]` to every step, and so a line-level
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
	 * @param	string		$xpath		CII or UBL, matching $near_index's syntax
	 * @param	int|null	$near_index	a document row index whose subtree is searched first, or null
	 * @return	int[]
	 * @since	1.17.1
	 */

	public function field_rows( $xpath, $near_index = null ): array
	{
		if ( ! $this->document )
			return( array() );

		// ⚠️ An empty path counts as missing - see the same check in Report.fieldRows().
		if ( ! isset( $this->document[0]['path'] ) || $this->document[0]['path'] === '' )
			$this->build_paths();

		$xpath = trim( (string) preg_replace( '/\s+\([^)]*\)\s*$/', '', (string) $xpath ) );
		$steps = self::field_pattern( $xpath );

		if ( ! $steps )
			return( array() );

		$matches = array();

		foreach ( $this->document as $j => $row )
			if ( self::pattern_matches( (string) ( $row['path'] ?? '' ), $steps, false ) )
				$matches[] = $j;

		if ( ! $matches )
			foreach ( $this->document as $j => $row )
				if ( self::pattern_matches( (string) ( $row['path'] ?? '' ), $steps, true ) )
					$matches[] = $j;

		for ( $a = $near_index; $matches && $a !== null && isset( $this->document[ $a ] ); $a = self::parent_row( $this->document, $a ) )
		{
			$scoped = array();

			foreach ( $matches as $j )
				if ( $j === $a || self::row_in_subtree( $this->document, $a, $j ) )
					$scoped[] = $j;

			if ( $scoped )
				return( $scoped );
		}

		return( $matches );
	}


	/**
	 * The steps of a field XPath as [ 'name', 'index' ], `index` null where the XPath states none - twin of
	 * `Report.fieldPattern()`. Prefixes and predicates go the same way as in normalise_path().
	 *
	 * @param	string	$xpath
	 * @return	array
	 * @since	1.17.1
	 */

	public static function field_pattern( $xpath ): array
	{
		$p = rtrim( (string) preg_replace( '#/+#', '/', str_replace( '\\', '/', trim( (string) $xpath ) ) ), '/' );

		$out = array();

		foreach ( explode( '/', ltrim( $p, '/' ) ) as $step )
		{
			if ( $step === '' )
				continue;

			$index = preg_match_all( '/\[(\d+)\]/', $step, $m ) ? (string) end( $m[1] ) : null;

			$step = preg_replace( '/\[[^\]]*\]/', '', $step );
			$step = preg_replace( '/^Q\{[^}]*\}/', '', $step );
			$step = trim( (string) preg_replace( '/^(?:\*|[A-Za-z][A-Za-z0-9_.-]*):/', '', $step ) );

			if ( $step !== '' )
				$out[] = array( 'name' => $step, 'index' => $index );
		}

		return( $out );
	}


	/**
	 * Whether a row path like `/A[1]/B[2]` fits a field pattern - twin of `Report.patternMatches()`.
	 *
	 * @param	string	$path		the indexed path build_paths() gave the row
	 * @param	array	$steps		from field_pattern()
	 * @param	bool	$suffix		true: the pattern only has to match the end of a longer path
	 * @return	bool
	 * @since	1.17.1
	 */

	public static function pattern_matches( string $path, array $steps, bool $suffix ): bool
	{
		$segs = array_values( array_filter( explode( '/', $path ), static function ( $s ) { return( $s !== '' ); } ) );

		if ( $suffix ? count( $segs ) <= count( $steps ) : count( $segs ) !== count( $steps ) )
			return( false );

		$offset = count( $segs ) - count( $steps );

		foreach ( $steps as $i => $step )
		{
			if ( ! preg_match( '/^(.*)\[(\d+)\]$/', $segs[ $offset + $i ], $m ) )
				return( false );

			if ( $m[1] !== $step['name'] || ( $step['index'] !== null && $m[2] !== $step['index'] ) )
				return( false );
		}

		return( true );
	}


	/**
	 * Is $candidate a descendant of $anchor in the flat, level-tagged document?
	 *
	 * @param	array	$document
	 * @param	int		$anchor
	 * @param	int		$candidate
	 * @return	bool
	 * @since	1.0.0
	 */

	private static function row_in_subtree( array $document, $anchor, $candidate )
	{
		if ( $candidate <= $anchor || ! isset( $document[ $anchor ] ) )
			return( false );

		$level = (int) $document[ $anchor ]['level'];

		for ( $k = $anchor + 1; $k < $candidate; $k++ )
			if ( ( (int) ( $document[ $k ]['level'] ?? 0 ) ) <= $level )
				return( false );

		return( ( (int) ( $document[ $candidate ]['level'] ?? 0 ) ) > $level );
	}


	/**
	 * The supporting documents ( BG-24 ) of the document, read from its flat rows - twin of
	 * `attachments()` in `libs/einvoice-core-js/src/report/Report.mjs`.
	 *
	 * ⚠️ ONLY THE HEADER LEVEL. In CII the element is BG-24 only when its parent is
	 * `ApplicableHeaderTradeAgreement`; under an invoice line the same element is the line object
	 * identifier ( BT-128 ). In UBL it sits directly under the root, which is level 2.
	 *
	 * ⚠️ AND NOT THE TYPE CODES 130 AND 50. CII and UBL use the same element for the invoiced object
	 * identifier ( BT-18 ) and the tender reference ( BT-17 ).
	 *
	 * ⚠️ THE FILE NAME ONLY WHEN THE ROW NAMES IT. A document view built from the invoice itself
	 * ( `Frontend\DocumentView` ) writes attributes as `filename="Aufmass.pdf"`. The document table of a
	 * KoSIT HTML report keeps the attribute values but drops their names; which value is the file name
	 * is not stated there and is not guessed here. The label then falls back to the description
	 * ( BT-123 ) and the reference ( BT-122 ).
	 *
	 * @return	array	list of array{ row: int, id: string, description: string, fileName: string, label: string }
	 * @since	1.12.0
	 */

	public function attachments()
	{
		$out = array();
		$doc = $this->document;
		$n   = count( $doc );

		for ( $j = 0; $j < $n; $j++ )
		{
			$name = self::local_part( (string) ( $doc[ $j ]['name'] ?? '' ) );

			if ( $name !== 'AdditionalReferencedDocument' && $name !== 'AdditionalDocumentReference' )
				continue;

			$level = (int) ( $doc[ $j ]['level'] ?? 0 );

			if ( $name === 'AdditionalReferencedDocument' )
			{
				$parent = self::parent_row( $doc, $j );

				if ( $parent === null || self::local_part( (string) ( $doc[ $parent ]['name'] ?? '' ) ) !== 'ApplicableHeaderTradeAgreement' )
					continue;
			}
			elseif ( $level !== 2 )
				continue;

			$id = $description = $fileName = $typeCode = '';

			for ( $k = $j + 1; $k < $n && (int) ( $doc[ $k ]['level'] ?? 0 ) > $level; $k++ )
			{
				$child  = self::local_part( (string) ( $doc[ $k ]['name'] ?? '' ) );
				$direct = (int) ( $doc[ $k ]['level'] ?? 0 ) === $level + 1;
				$value  = trim( (string) ( $doc[ $k ]['value'] ?? '' ) );

				if ( $direct && ( $child === 'IssuerAssignedID' || $child === 'ID' ) && $id === '' )
					$id = $value;
				elseif ( $direct && ( $child === 'Name' || $child === 'DocumentDescription' ) && $description === '' )
					$description = $value;
				elseif ( $direct && ( $child === 'TypeCode' || $child === 'DocumentTypeCode' ) )
					$typeCode = $value;
				elseif ( ( $child === 'AttachmentBinaryObject' || $child === 'EmbeddedDocumentBinaryObject' ) && $fileName === '' )
				{
					if ( preg_match( '/(?:^|\s)filename="([^"]*)"/', (string) ( $doc[ $k ]['attr'] ?? '' ), $m ) )
						$fileName = trim( $m[1] );
				}
			}

			if ( $typeCode === '130' || $typeCode === '50' )
				continue;

			$out[] = array(
				'row'         => $j,
				'id'          => $id,
				'description' => $description,
				'fileName'    => $fileName,
				'label'       => $fileName !== '' ? $fileName : ( $description !== '' ? $description : $id ),
			);
		}

		return( $out );
	}


	/**
	 * The attachments a finding is about - twin of `attachmentsFor()` in `Report.mjs`.
	 *
	 * 1. Does the finding point into an attachment ( its first row lies in a BG-24 subtree, or is the
	 *    BG-24 row itself )? Then that one attachment.
	 * 2. Otherwise, is the rule about attachments ( touches_attachments() )? Then all of them -
	 *    BR-DE-22, for example, demands unique file names across all attachments and points at the
	 *    invoice as a whole.
	 * 3. Otherwise none.
	 *
	 * @param	array	$finding
	 * @param	array	$fields		the rule's business terms
	 * @return	array	entries of attachments()
	 * @since	1.12.0
	 */

	public function attachments_for( array $finding, array $fields )
	{
		$all = $this->attachments();

		if ( ! $all )
			return( array() );

		$anchor = ( $finding['rows'] ?? array() )[0] ?? null;

		if ( $anchor !== null )
			foreach ( $all as $a )
				if ( $a['row'] === (int) $anchor || self::row_in_subtree( $this->document, $a['row'], (int) $anchor ) )
					return( array( $a ) );

		return( self::touches_attachments( $fields ) ? $all : array() );
	}


	/**
	 * Is a rule about attachments - does it name BG-24 or one of BT-122 to BT-125?
	 *
	 * ⚠️ SUFFIXES COUNT. The rule data names `BT-125-2` ( the file name ) and `BT-122-0` ( the type
	 * code ); both are about an attachment as much as `BT-125` is.
	 *
	 * @param	array	$fields
	 * @return	bool
	 * @since	1.12.0
	 */

	public static function touches_attachments( array $fields )
	{
		foreach ( $fields as $f )
			if ( preg_match( '/^(BG-24|BT-12[2-5])(-|$)/', (string) $f ) )
				return( true );

		return( false );
	}


	/**
	 * The name without its namespace prefix.
	 *
	 * @param	string	$name
	 * @return	string
	 * @since	1.12.0
	 */

	private static function local_part( $name )
	{
		return( (string) preg_replace( '/^.*:/', '', (string) $name ) );
	}


	/**
	 * The row of the parent element in the flat, level-tagged document, or null for the root.
	 *
	 * @param	array	$document
	 * @param	int		$row
	 * @return	int|null
	 * @since	1.12.0
	 */

	private static function parent_row( array $document, $row )
	{
		$level = (int) ( $document[ $row ]['level'] ?? 0 );

		for ( $k = $row - 1; $k >= 0; $k-- )
			if ( (int) ( $document[ $k ]['level'] ?? 0 ) < $level )
				return( $k );

		return( null );
	}


	/**
	 * Does a finding's XPath address this document row?
	 *
	 * @param	string	$findingPath	from the report, with namespace prefixes
	 * @param	string	$rowPath		reconstructed, without prefixes
	 * @return	bool
	 * @since	1.0.0
	 */

	public static function path_matches( $findingPath, $rowPath )
	{
		$a = self::normalise_path( $findingPath );
		$b = self::normalise_path( $rowPath );

		if ( $a === '' || $b === '' )
			return( false );

		if ( $a === $b )
			return( true );

		/**
		 * ⚠️ A BARE `*` STEP MATCHES ANY ELEMENT NAME WITH THE SAME INDEX. VAMPIRE
		 * writes the root of a UBL invoice as `/*` - `/*\/cac:LegalMonetaryTotal` -, because the same rule
		 * serves `Invoice` and `CreditNote`. normalise_path() keeps that step as `*[1]`. Measured on 11.10.2026
		 * on the six VAMPIRE reports of `fixtures-report-views/cases.json` in `libs/einvoice-core-js`: without the
		 * wildcard 0 of the 28 findings of the three UBL reports reached a row of the document, with it 28 of 28,
		 * while the CII findings did either way. Only an exact `*` is a wildcard; a name
		 * with a prefix lost its prefix in normalise_path() and is compared by name.
		 */
		if ( strpos( $a, '*[' ) === false )
			return( false );

		$sa = explode( '/', $a );
		$sb = explode( '/', $b );

		if ( count( $sa ) !== count( $sb ) )
			return( false );

		foreach ( $sa as $k => $seg )
			if ( $seg !== $sb[ $k ] && ! ( strpos( $seg, '*[' ) === 0 && substr( $seg, 1 ) === (string) preg_replace( '/^[^\[]*/', '', $sb[ $k ] ) ) )
				return( false );

		return( true );
	}


	/**
	 * Bring a path into the one form both sides can be compared in - three writing conventions seen
	 * in the wild ( plain prefixed, wildcard-with-namespace-predicate, Clark notation `Q{uri}Name` ),
	 * reduced to the same shape. See the Explainer's own historical STATUS.md for the full measured
	 * derivation - unchanged here, only relocated.
	 *
	 * @param	string	$path
	 * @return	string
	 * @since	1.0.0
	 */

	public static function normalise_path( $path )
	{
		$p = trim( (string) $path );

		if ( $p === '' )
			return( '' );

		$p = preg_replace( '#/+#', '/', str_replace( '\\', '/', $p ) );
		$p = rtrim( $p, '/' );

		$out = array();

		foreach ( explode( '/', ltrim( $p, '/' ) ) as $step )
		{
			if ( $step === '' )
				continue;

			$index = '1';

			if ( preg_match_all( '/\[(\d+)\]/', $step, $m ) )
				$index = (string) end( $m[1] );

			$step = preg_replace( '/\[[^\]]*\]/', '', $step );
			$step = preg_replace( '/^Q\{[^}]*\}/', '', $step );
			$step = preg_replace( '/^(?:\*|[A-Za-z][A-Za-z0-9_.-]*):/', '', $step );
			$step = trim( $step );

			if ( $step === '' )
				continue;

			$out[] = $step . '[' . $index . ']';
		}

		return( $out ? '/' . implode( '/', $out ) : '' );
	}

}

endif;
