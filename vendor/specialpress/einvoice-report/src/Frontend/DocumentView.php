<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Joins a validation report to the document it is about, so that every message is marked at its
 * node.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report\Frontend;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use SpecialPress\EInvoice\Report\Report;

// Same direct-access guard as Report.php in this package - see that file's comment.
if ( ! defined( 'ABSPATH' ) )
	exit;


// ⚠️ GUARDED - see Report.php's own comment on this same line for why.

if ( ! class_exists( __NAMESPACE__ . '\\DocumentView' ) ) :

/**
 * class: DocumentView
 *
 * ============================================================================================
 * Why this class exists
 * ============================================================================================
 *
 * Measured on 23.09.2026 over the corpus:
 *
 *   KoSIT report      43 of 43 messages land on a document row — **100 %**
 *   valitool report    0 of  6                                — **0 %**
 *
 * The difference is **not** the quality of the location: both name an XPath for every message. The
 * KoSIT report carries the checked document **inside itself**, the valitool report does not. The
 * existing `Report::link_findings()` links messages with `$report->document` - and that stays
 * empty when the report brings no document.
 *
 * This class closes the gap: it takes the document **separately** - in the frontend that is the file
 * the user uploaded anyway - builds from it the same flat row shape that `Report::$document` already
 * has, and resolves every location on it with `DOMXPath`.
 *
 * ⚠️ THE SAME ROW SHAPE, ON PURPOSE. `pos`, `level`, `name`, `value`, `attr`, `path`, `status` -
 * so the existing `Explainer\Renderer` renders the result **unchanged**. A second presentation
 * would have produced two views of the same document that drift apart.
 *
 * ============================================================================================
 * ⚠️ FOUR WAYS TO THE ROW, IN THIS ORDER
 * ============================================================================================
 *
 * 1. **The XPath hits a node.** The normal case.
 *
 * 2. **The context node case.** If the XPath fails, its last segment is cut off and the parent
 *    path is tried. If that hits, the message is about a field that is **missing** - the parent
 *    node is marked and the missing field is named. Without this way exactly the kind of message
 *    that occurs most often would stay unassignable: "field X is missing".
 *
 * 3. **Line and column.** valitool names both; where no XPath works, the message is assigned by the
 *    line number. ⚠️ `DOMDocument` knows `getLineNo()`, but only for elements and only when the
 *    document was not normalised - that is why the line is **recorded** while building.
 *
 * 4. **None of these.** Then the message is listed in `unresolved()` with a **reason**. The
 *    requirement is "0 unassignable messages **without a reason**" - not zero unassignable
 *    messages. A message that points at the schema and not at a node is not assignable and should
 *    stay so; it must not be kept quiet.
 *
 * @since 1.6.0
 */

class DocumentView
{


	/** @var array<int,array> Messages without a row, each with a reason. */
	private $unresolved = array();


	/**
	 * Join report and document.
	 *
	 * ⚠️ CHANGES THE REPORT. `$report->document` is filled ( overwritten when the report already
	 * brought one ) and every message gets its `rows`. That is intended: afterwards every existing
	 * view renders the document without knowing about this class.
	 *
	 * @param	Report	$report
	 * @param	string	$xml	The checked document, raw.
	 * @return	Report			The same report, returned for convenience.
	 * @since	1.6.0
	 */

	public function attach( Report $report, $xml )
	{
		$this->unresolved = array();

		$dom = $this->load( (string) $xml );

		if ( $dom === null )
		{
			$this->unresolved[] = array( 'index' => null, 'reason' => 'The document could not be parsed as XML, so no finding could be placed in it.' );

			return( $report );
		}

		$rows  = array();
		$index = array();   // SplObjectStorage would be cleaner; a key from the path is enough here

		$siblings = array();

		$this->walk( $dom->documentElement, 1, $rows, $index, '', $siblings );

		$report->document = $rows;

		/*
		 * ⚠️ THE SYNTAX COMES FROM THE DOCUMENT WHEN THE REPORT DOES NOT STATE IT. A Mustang
		 * report names neither UBL nor CII, so `meta['syntax']` would stay empty, the field lookup would fall
		 * back to the CII path, and every field of a UBL invoice would look for a CII element. The root
		 * element says unambiguously which syntax the invoice is. A syntax the report states wins.
		 * Twin of the same block in DocumentView.mjs.
		 */
		if ( (string) ( $report->meta['syntax'] ?? '' ) === '' )
		{
			$syntax = self::syntax_of( $dom->documentElement );

			if ( $syntax !== '' )
				$report->meta['syntax'] = $syntax;
		}

		$xp = new DOMXPath( $dom );

		$this->register_namespaces( $dom, $xp );

		foreach ( $report->findings as $i => $finding )
		{
			$row = $this->resolve( $finding, $xp, $index, $rows );

			if ( $row === null )
				continue;

			$report->findings[ $i ]['rows'] = array( $row );

			// ⚠️ Three levels: error, warning, info. An information marked its row as a warning until 19.10.2026 ( Ralf ).
			$severity = \SpecialPress\EInvoice\Report\Report::row_status( (string) ( $finding['severity'] ?? '' ) );

			// The stronger statement wins on a row - the same rule that Report::link_findings() applies.
			if ( \SpecialPress\EInvoice\Report\Report::STATUS_RANK[ $severity ] > ( \SpecialPress\EInvoice\Report\Report::STATUS_RANK[ $report->document[ $row ]['status'] ?? '' ] ?? 0 ) )
				$report->document[ $row ]['status'] = $severity;
		}

		return( $report );
	}


	/**
	 * The messages that landed on no row - each with a reason.
	 *
	 * @return	array	array{ index: int|null, reason: string, rule: string, path: string }[]
	 * @since	1.6.0
	 */

	public function unresolved()
	{
		return( $this->unresolved );
	}


	/**
	 * @param	string	$xml
	 * @return	DOMDocument|null
	 * @since	1.6.0
	 */

	private function load( $xml )
	{
		$previous = libxml_use_internal_errors( true );

		$dom = new DOMDocument();

		// ⚠️ NO preserveWhiteSpace = false. Removing whitespace shifts the
		// line numbers that way 3 needs - and the tree does not become any more useful by it.
		$ok = $dom->loadXML( (string) $xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return( $ok && $dom->documentElement !== null ? $dom : null );
	}


	/**
	 * Bring the document into the flat row shape.
	 *
	 * ⚠️ `path` IS THE INDEXED PATH WITHOUT PREFIXES, BUILT HERE AND NOT LEFT EMPTY. With
	 * `'path' => ''` on every row, `Report::resolve_field()` takes the empty string for an already
	 * built path, and no field of the rule data ever matches a row ( measured 08.10.2026 ). The position
	 * counts siblings of the same local name, which is what an XPath step `ram:Name[2]` means and what
	 * `Report::build_paths()` does. See DocumentView.mjs for the longer account.
	 *
	 * @param	DOMNode		$node
	 * @param	int			$level
	 * @param	array		$rows
	 * @param	array		$index		Node path => row number, for the resolution
	 * @param	string		$parent		The path of the parent row, '' for the root.
	 * @param	array		$siblings	Local name => how often seen under the same parent.
	 * @return	void
	 * @since	1.6.0
	 */

	private function walk( DOMNode $node, $level, array &$rows, array &$index, $parent, array &$siblings )
	{
		if ( ! ( $node instanceof DOMElement ) )
			return;

		$value = '';

		// An element with exactly one text child is a value; one with element children is a container.
		foreach ( $node->childNodes as $child )
			if ( $child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE )
				$value .= $child->nodeValue;

		$hasElementChild = false;

		foreach ( $node->childNodes as $child )
			if ( $child->nodeType === XML_ELEMENT_NODE )
			{
				$hasElementChild = true;
				break;
			}

		$attr = array();

		if ( $node->hasAttributes() )
			foreach ( $node->attributes as $a )
				$attr[] = $a->nodeName . '="' . $a->nodeValue . '"';

		$row = count( $rows );

		$local = (string) preg_replace( '/^.*:/', '', (string) ( $node->localName ?? $node->nodeName ) );

		$siblings[ $local ] = ( $siblings[ $local ] ?? 0 ) + 1;

		$path = $parent . '/' . $local . '[' . $siblings[ $local ] . ']';

		$rows[] = array(
			'pos'    => str_pad( (string) ( $row + 1 ), 4, '0', STR_PAD_LEFT ),
			'level'  => $level,
			'name'   => $node->nodeName,
			'value'  => $hasElementChild ? '' : trim( $value ),
			'attr'   => implode( ' ', $attr ),
			'path'   => $path,
			'status' => '',

			// ⚠️ The line number is recorded HERE. Calling getLineNo() later on the same node
			// would work too - but only as long as nobody touches the tree.
			'line'   => $node->getLineNo(),
		);

		$index[ $this->key( $node ) ] = $row;

		if ( $hasElementChild )
		{
			$children = array();

			foreach ( $node->childNodes as $child )
				$this->walk( $child, $level + 1, $rows, $index, $path, $children );
		}
	}


	/**
	 * The syntax of an invoice, read from its root element - 'CII', 'UBL' or ''.
	 *
	 * ⚠️ BY NAMESPACE AND LOCAL NAME TOGETHER. A root called `Invoice` without the UBL namespace is
	 * not UBL, and the UBL namespace family also covers documents such as `Order-2`, which the
	 * invoice field paths do not fit. Twin of DocumentView.syntaxOf() in JavaScript.
	 *
	 * @param	DOMElement|null	$root
	 * @return	string
	 * @since	1.13.0
	 */

	public static function syntax_of( $root )
	{
		if ( ! ( $root instanceof DOMElement ) )
			return( '' );

		$local = (string) $root->localName;
		$ns    = (string) $root->namespaceURI;

		if ( $local === 'CrossIndustryInvoice' && strpos( $ns, 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice' ) === 0 )
			return( 'CII' );

		if ( ( $local === 'Invoice' || $local === 'CreditNote' ) && strpos( $ns, 'urn:oasis:names:specification:ubl:schema:xsd:' ) === 0 )
			return( 'UBL' );

		return( '' );
	}


	/**
	 * A key that finds a node again unambiguously.
	 *
	 * ⚠️ NOT `spl_object_id()`. The nodes `DOMXPath::query()` returns are **new PHP objects** on every
	 * access over the same C node - two queries for the same element deliver different
	 * `spl_object_id()` values. `getNodePath()`, by contrast, is stable and unambiguous.
	 *
	 * @param	DOMNode	$node
	 * @return	string
	 * @since	1.6.0
	 */

	private function key( DOMNode $node )
	{
		return( (string) $node->getNodePath() );
	}


	/**
	 * Register the namespaces of the document.
	 *
	 * ⚠️ THE KOSIT REPORT DOES NOT NEED THIS - its `xpathLocation` writes
	 * `*:Invoice[namespace-uri()='…']` and gets by without a prefix. Other reports do not:
	 * valitool writes `/rsm:CrossIndustryInvoice/…` with the prefixes of the document. Without
	 * registration each of these queries would run into nothing - silently, because `query()` then
	 * simply returns nothing instead of failing.
	 *
	 * @param	DOMDocument	$dom
	 * @param	DOMXPath	$xp
	 * @return	void
	 * @since	1.6.0
	 */

	private function register_namespaces( DOMDocument $dom, DOMXPath $xp )
	{
		$seen = array();

		$nsXp = new DOMXPath( $dom );

		foreach ( $nsXp->query( '//namespace::*' ) ?: array() as $ns )
		{
			$prefix = (string) $ns->localName;
			$uri    = (string) $ns->nodeValue;

			if ( $prefix === '' || $prefix === 'xml' || $uri === '' )
				continue;

			if ( isset( $seen[ $prefix ] ) )
				continue;

			$seen[ $prefix ] = true;

			$xp->registerNamespace( $prefix, $uri );
		}

		// ⚠️ A document WITHOUT a prefix ( default namespace ) cannot be hit with a prefixed XPath.
		// The default namespace therefore additionally gets the prefix `d`, so that a
		// caller can address it if it wants to.
		$default = $dom->documentElement->lookupNamespaceURI( null );

		if ( is_string( $default ) && $default !== '' && ! isset( $seen['d'] ) )
			$xp->registerNamespace( 'd', $default );
	}


	/**
	 * The row for a message - the four ways from the class docblock.
	 *
	 * @param	array		$finding
	 * @param	DOMXPath	$xp
	 * @param	array		$index
	 * @param	array		$rows
	 * @return	int|null
	 * @since	1.6.0
	 */

	private function resolve( array $finding, DOMXPath $xp, array $index, array $rows )
	{
		$path = trim( (string) ( $finding['path'] ?? '' ) );
		$rule = (string) ( $finding['rule'] ?? '' );

		// -- Weg 1: der XPath trifft -----------------------------------------------------------

		if ( $path !== '' )
		{
			$row = $this->query_row( $xp, $path, $index );

			if ( $row !== null )
				return( $row );

			// -- Weg 2: der Kontextknoten ------------------------------------------------------

			$parent = $this->parent_path( $path );

			if ( $parent !== '' )
			{
				$row = $this->query_row( $xp, $parent, $index );

				if ( $row !== null )
					return( $row );
			}
		}

		// -- Weg 3: Zeile und Spalte -------------------------------------------------------------

		$line = $finding['line'] ?? null;

		if ( $line !== null && (int) $line > 0 )
		{
			$best = null;

			foreach ( $rows as $i => $r )
				if ( (int) ( $r['line'] ?? 0 ) === (int) $line )
				{
					$best = $i;
					break;
				}

			if ( $best !== null )
				return( $best );
		}

		// -- Weg 4: mit Begruendung ausweisen ----------------------------------------------------

		$this->unresolved[] = array(
			'index'  => null,
			'rule'   => $rule,
			'path'   => $path,
			'reason' => $path === ''
				? 'The report gives no location for this finding - it points at the schema or the document as a whole, not at a node.'
				: 'The location in the report does not match any node of this document ( neither the path itself nor its parent ).',
		);

		return( null );
	}


	/**
	 * @param	DOMXPath	$xp
	 * @param	string		$path
	 * @param	array		$index
	 * @return	int|null
	 * @since	1.6.0
	 */

	/**
	 * Rewrite a location XPath so that `DOMXPath` understands it.
	 *
	 * ============================================================================================
	 * ⚠️ `DOMXPath` KNOWS ONLY XPATH 1.0 - AND TWO COMMON NOTATIONS ARE 2.0
	 * ============================================================================================
	 *
	 * Measured on 23.09.2026 on 35 real Mustang messages: without the rewriting **8 landed on no row**,
	 * for exactly two reasons - both silent, because `query()` simply returns nothing for an expression
	 * that hits nothing.
	 *
	 * **1. `*:Name[namespace-uri()='…']` is XPath 2.0.** KoSIT and Mustang write this form for
	 * their `<error>` locations. `DOMXPath` does not know `*:Name` and returns empty. Rewritten to
	 * `*[local-name()='Name' and namespace-uri()='…']` - the same in content, only in 1.0.
	 *
	 * **2. An unprefixed name in XPath 1.0 hits ONLY elements WITHOUT a namespace.** For some messages
	 * Mustang simply writes `/Invoice`. The document, however, has `Invoice` in the
	 * **default namespace** ( `urn:oasis:…:Invoice-2` ) - in XPath 1.0 `/Invoice` does not hit
	 * it. That is the classic default-namespace trap, and it does not look like an error but like
	 * "the message belongs nowhere".
	 *
	 * ⚠️ **ONLY WHAT IS UNAMBIGUOUS IS REWRITTEN.** Predicates, positions and axes stay
	 * untouched. An expression this function does not understand for sure passes through unchanged -
	 * better a location with a reason than a wrongly rewritten one.
	 *
	 * @param	string	$path
	 * @return	array	The forms to try, in the order of their precision.
	 * @since	1.6.0
	 */

	private function variants( $path )
	{
		$path = (string) $path;

		$out = array( $path );

		// 1. `*:Name[namespace-uri()='URI']` -> `*[local-name()='Name' and namespace-uri()='URI']`
		$two = preg_replace(
			"/\\*:([A-Za-z_][\\w.-]*)\\[namespace-uri\\(\\)=('[^']*'|\"[^\"]*\")\\]/",
			"*[local-name()='$1' and namespace-uri()=$2]",
			$path
		);

		if ( is_string( $two ) && $two !== $path )
			$out[] = $two;

		// A `*:Name` without a predicate occurs as well.
		$bare = preg_replace( '/\*:([A-Za-z_][\w.-]*)/', "*[local-name()='$1']", $two === null ? $path : $two );

		if ( is_string( $bare ) && ! in_array( $bare, $out, true ) )
			$out[] = $bare;

		/**
		 * 2. Make unprefixed names namespace-free.
		 *
		 * ⚠️ ONLY WHEN THE PATH CONTAINS NO COLON AT ALL. A mixed path
		 * ( `/rsm:CrossIndustryInvoice/ram:ID` ) is prefixed on purpose and is not touched;
		 * there the namespace registration solves the problem. Only a path without any prefix
		 * like `/Invoice` recognisably means "the element with this name, whatever the namespace".
		 */

		if ( strpos( $path, ':' ) === false && strpos( $path, '(' ) === false )
		{
			$agnostic = preg_replace_callback(
				'#(^|/)([A-Za-z_][\w.-]*)#',
				static function ( $m ) { return $m[1] . "*[local-name()='" . $m[2] . "']"; },
				$path
			);

			if ( is_string( $agnostic ) && ! in_array( $agnostic, $out, true ) )
				$out[] = $agnostic;
		}

		return $out;
	}


	private function query_row( DOMXPath $xp, $path, array $index )
	{
		// ⚠️ `query()` reports a broken expression through a PHP warning, not through `false`
		// alone - and a warning in a WordPress output destroys the page. Suppressed,
		// because an unusable path is a NORMAL case here: it comes from a foreign
		// report about a foreign document.
		$previous = libxml_use_internal_errors( true );

		$hit = null;

		foreach ( $this->variants( $path ) as $candidate )
		{
			$nodes = @$xp->query( $candidate );

			if ( $nodes !== false && $nodes->length > 0 )
			{
				$hit = $nodes->item( 0 );
				break;
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( $hit === null )
			return( null );

		return( $index[ $this->key( $hit ) ] ?? null );
	}


	/**
	 * The parent path - cut off the last segment.
	 *
	 * ⚠️ DO NOT CUT AT THE LAST `/`. A predicate can itself contain slashes, for example
	 * `*:Invoice[namespace-uri()='urn:…:Invoice-2'][1]` - the `urn:` separates nothing, but a
	 * naive `strrpos( $path, '/' )` would fall for every slash INSIDE the namespace URI and
	 * return a torso. The bracket depth is counted instead.
	 *
	 * @param	string	$path
	 * @return	string	Empty when there is no parent path.
	 * @since	1.6.0
	 */

	private function parent_path( $path )
	{
		$depth = 0;
		$quote = '';
		$cut   = -1;

		for ( $i = 0; $i < strlen( $path ); $i++ )
		{
			$c = $path[ $i ];

			if ( $quote !== '' )
			{
				if ( $c === $quote )
					$quote = '';

				continue;
			}

			if ( $c === '"' || $c === "'" )
			{
				$quote = $c;
				continue;
			}

			if ( $c === '[' || $c === '(' )
				$depth++;
			elseif ( $c === ']' || $c === ')' )
				$depth--;
			elseif ( $c === '/' && $depth === 0 )
				$cut = $i;
		}

		if ( $cut <= 0 )
			return( '' );

		return( substr( $path, 0, $cut ) );
	}

}

endif;
