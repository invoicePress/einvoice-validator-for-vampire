<?php
/**
 * The values of a finding, computed from the rule itself instead of read from its text.
 *
 * ⚠️ PORT TWIN of `libs/einvoice-core-js/src/explainer/RuleValues.mjs`; the reasons for every step stand there in
 * full. The steps, the gates and their order are the same on both sides, and `test/compare-rule-values.mjs` of
 * einvoice-core-js compares the two results finding by finding.
 *
 * In short: for a finding with rule id R at row L, the assertion R of a VAMPIRE rule package is evaluated on a tree
 * rebuilt from the document rows. Only when it fails there as it failed in the report are values attached: for every
 * field the comparison reads, the largest sub-expression that reads only that field is evaluated, and its result is
 * the value the validator compared. Otherwise the finding keeps the value read from the rule text.
 *
 * ⚠️ **A CLASS OF ITS OWN, NOT A METHOD OF `Report` OR `Renderer`.** Every plugin bundles its own copy of this
 * package, and the copy loaded first wins. A new method in `Report` would be missing whenever an older copy got
 * there first; a class with a new name exists only in the copies that have it. `Renderer::explain_finding()` reads
 * `$finding['ruleValues']` when it is there and ignores it otherwise, so an older Renderer next to this class simply
 * shows the old values.
 *
 * ⚠️ **NO EVALUATOR IN THIS PACKAGE.** The XPath interpreter is VAMPIRE PHP's ( `EinvoiceValidator\Xpath\Interpreter`,
 * GPL, PHP 8.1 ). This file stays PHP 7.4 and calls it only through `vampire_engine()`, which answers null when the
 * classes are not loaded - then nothing here runs.
 *
 * @package SpecialPress\EInvoice\Report
 * @since   1.18.0
 */

namespace SpecialPress\EInvoice\Report\Explainer;

use SpecialPress\EInvoice\Report\Report;

if ( ! defined( 'ABSPATH' ) )
	exit;


class RuleValues
{

	/** The comparison operators whose two sides are the values a reader wants to see. */
	const COMPARISONS = array( '=', '!=', '<', '<=', '>', '>=', 'eq', 'ne', 'lt', 'le', 'gt', 'ge' );

	/** The key of a path that reaches no field of the catalogue. */
	const UNKNOWN = '?';

	/** The namespaces of the two syntaxes, used when a row names no prefix the syntax knows. */
	const NS = array(
		'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
		'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
		'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
		'inv' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
		'cn'  => 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
		'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
		'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
		'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
		'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100',
	);

	/** The CII elements that belong to `rsm` besides the root. */
	const CII_RSM = array( 'ExchangedDocumentContext', 'ExchangedDocument', 'SupplyChainTradeTransaction' );


	/**
	 * An engine factory over VAMPIRE PHP, or null when VAMPIRE PHP is not loaded.
	 *
	 * @return	callable|null	function( array $namespaces ): object with evaluate(), truth(), strings(), nodes(), split()
	 */

	public static function vampire_engine()
	{
		if ( ! class_exists( '\EinvoiceValidator\Xpath\Interpreter' ) || ! class_exists( '\EinvoiceValidator\Xpath\Ast\AstSerializer' ) )
			return( null );

		return( static function ( array $namespaces ) {
			return( new RuleValuesVampireEngine( $namespaces ) );
		} );
	}


	/**
	 * Computes the values for every finding and stores them on the finding as `ruleValues` - twin of `attach()`.
	 *
	 * @param	Report		$report
	 * @param	object		$terms		with fieldXpath(), fieldIds() and rule()
	 * @param	array		$options	array( 'packages' => decoded rule packages, 'engine' => factory from vampire_engine() )
	 * @return	Report
	 */

	public static function attach( Report $report, $terms, array $options = array() ): Report
	{
		$packages = isset( $options['packages'] ) && is_array( $options['packages'] ) ? array_values( $options['packages'] ) : array();
		$factory  = isset( $options['engine'] ) && is_callable( $options['engine'] ) ? $options['engine'] : null;

		if ( $factory === null || ! $packages || ! is_array( $report->findings ) )
			return( $report );

		$syntax = strtoupper( (string) ( $report->meta['syntax'] ?? '' ) );
		$index  = self::index( $packages );
		$state  = (object) array( 'tree' => null, 'engines' => array(), 'globals' => array(), 'groups' => null, 'occurrences' => array() );

		foreach ( $report->findings as $i => $finding )
			$report->findings[ $i ]['ruleValues'] = self::one( $finding, $report, $terms, $syntax, $index, $factory, $state );

		return( $report );
	}


	/**
	 * @param	array	$packages
	 * @return	array	rule id => list of array( pkg index, rule, assertion )
	 */

	public static function index( array $packages ): array
	{
		$out = array();

		foreach ( $packages as $p => $pkg )
			foreach ( (array) ( $pkg['patterns'] ?? array() ) as $pattern )
				foreach ( (array) ( $pattern['rules'] ?? array() ) as $rule )
					foreach ( (array) ( $rule['assertions'] ?? array() ) as $assertion )
					{
						$id = (string) ( $assertion['id'] ?? '' );

						if ( $id === '' )
							continue;

						$out[ $id ][] = array( 'p' => $p, 'pkg' => $pkg, 'rule' => $rule, 'assertion' => $assertion );
					}

		return( $out );
	}


	private static function none( string $reason ): array
	{
		return( array( 'source' => 'text', 'reason' => $reason, 'ruleset' => '', 'values' => array() ) );
	}


	private static function one( array $finding, Report $report, $terms, string $syntax, array $index, callable $factory, $state ): array
	{
		$rule_id = (string) ( $finding['rule'] ?? '' );
		$cands   = $index[ $rule_id ] ?? array();

		if ( ! $cands )
			return( self::none( 'no-rule' ) );

		if ( $state->tree === null )
			$state->tree = self::build_tree( $report->document, $syntax );

		// A path that names several nodes, or a finding without a row, is resolved here; its k-th mention goes to the
		// k-th node the rule fails at ( see the JS twin for the measurement ).
		$anchor     = ( $finding['rows'] ?? array() )[0] ?? null;
		$path       = (string) ( $finding['path'] ?? '' );
		$by_path    = self::nodes_for_path( $path, $state->tree );
		$occurrence = 0;

		if ( ! $by_path && $anchor !== null && isset( $state->tree['nodes'][ $anchor ] ) )
			$starts = array( $state->tree['nodes'][ $anchor ] );
		else
		{
			$starts = $by_path;
			$key    = $rule_id . '|' . $path;

			$occurrence                  = $state->occurrences[ $key ] ?? 0;
			$state->occurrences[ $key ] = $occurrence + 1;
		}

		if ( ! $starts )
			return( self::none( 'no-place' ) );

		$known = is_object( $terms ) && method_exists( $terms, 'rule' ) ? (array) $terms->rule( $rule_id ) : array();
		$last  = 'no-context';

		foreach ( $cands as $cand )
		{
			try
			{
				$result = self::try_candidate( $cand, $report, $terms, $syntax, $known, $factory, $state, $starts, $occurrence );

				if ( $result['source'] === 'rule' )
					return( $result );

				$last = $result['reason'];
			}
			catch ( \Throwable $e )
			{
				$last = 'error';
			}
		}

		return( self::none( $last ) );
	}


	private static function try_candidate( array $cand, Report $report, $terms, string $syntax, array $known, callable $factory, $state, array $starts, int $occurrence ): array
	{
		$pkg  = $cand['pkg'];
		$p    = $cand['p'];
		$tree = $state->tree;

		if ( ! isset( $state->engines[ $p ] ) )
			$state->engines[ $p ] = $factory( (array) ( $pkg['namespaces'] ?? array() ) );

		$engine = $state->engines[ $p ];

		if ( ! isset( $state->globals[ $p ] ) )
			$state->globals[ $p ] = self::bind_lets( $engine, (array) ( $pkg['globalLets'] ?? array() ), $tree['document'], array() );

		$globals = $state->globals[ $p ];

		// The context node is the row of the finding, or the nearest ancestor the rule selects ( see the JS twin ).
		$selected = array();

		foreach ( $engine->nodes( $engine->evaluate( $cand['rule']['context'], $tree['document'], $globals ) ) as $n )
			$selected[ $n->getNodePath() ] = true;

		$failing     = array();
		$seen        = array();
		$saw_context = false;

		foreach ( $starts as $start )
		{
			$n = $start;

			while ( $n instanceof \DOMElement && ! isset( $selected[ $n->getNodePath() ] ) )
				$n = $n->parentNode;

			if ( ! $n instanceof \DOMElement || isset( $seen[ $n->getNodePath() ] ) )
				continue;

			$seen[ $n->getNodePath() ] = true;
			$saw_context               = true;

			$v = self::bind_lets( $engine, (array) ( $cand['rule']['lets'] ?? array() ), $n, $globals );

			if ( ! $engine->truth( $engine->evaluate( $cand['assertion']['test'], $n, $v ) ) )
				$failing[] = array( 'node' => $n, 'vars' => $v );
		}

		if ( ! $saw_context )
			return( self::none( 'no-context' ) );

		if ( ! $failing )
			return( self::none( 'rule-passes' ) );

		$pick = $failing[ min( $occurrence, count( $failing ) - 1 ) ];
		$node = $pick['node'];
		$vars = $pick['vars'];

		$base     = $tree['paths'][ $node->getNodePath() ] ?? array();
		$var_alts = array();

		foreach ( (array) ( $pkg['globalLets'] ?? array() ) as $l )
			$var_alts[ (string) $l['name'] ] = self::expr_alts( $l['expr'], array(), $var_alts );

		foreach ( (array) ( $cand['rule']['lets'] ?? array() ) as $l )
			$var_alts[ (string) $l['name'] ] = self::expr_alts( $l['expr'], $base, $var_alts );

		$found = self::find_comparison( $cand['assertion']['test'], $engine, $node, $vars, $var_alts, $base );

		if ( $found === null )
			return( self::none( 'no-comparison' ) );

		$refs = array();

		foreach ( array_merge( (array) ( $cand['assertion']['fieldRefs'] ?? array() ), (array) ( $known['fields'] ?? array() ) ) as $bt )
			$refs[ (string) $bt ] = true;

		$groups    = self::field_groups( $terms, $syntax, $state );
		$ctx       = array( 'base' => $base, 'groups' => $groups, 'varAlts' => $found['varAlts'] );
		$eval_vars = $found['vars'];
		$values    = array();

		foreach ( array( $found['cmp']['left'], $found['cmp']['right'] ) as $operand )
			foreach ( self::touches( $operand, $ctx ) as $key )
			{
				if ( $key === self::UNKNOWN )
					continue;

				$targets = self::targets( $groups[ $key ]['bts'], $refs );

				if ( ! $targets || self::all_set( $targets, $values ) )
					continue;

				$sub = self::sole_subtree( $operand, $key, $ctx );

				if ( $sub === null )
					continue;

				$strings = self::value_strings( self::strip_offset( $sub ), $engine, $node, $eval_vars );

				if ( count( $strings ) !== 1 )
					continue;

				foreach ( $targets as $bt )
					if ( ! isset( $values[ $bt ] ) )
						$values[ $bt ] = $strings[0];
			}

		// The item a quantified rule failed for ( the rate under BR-S-08 ) is a value of its own field.
		foreach ( (array) ( $found['bound'] ?? array() ) as $bound )
		{
			$keys = array_values( array_filter( self::touches( array( 'node' => 'VarRef', 'name' => $bound ), $ctx ), static function ( $k ) { return( $k !== self::UNKNOWN ); } ) );

			if ( count( $keys ) !== 1 )
				continue;

			$targets = self::targets( $groups[ $keys[0] ]['bts'], $refs );
			$strings = $engine->strings( $eval_vars[ $bound ] );

			if ( count( $strings ) === 1 )
				foreach ( $targets as $bt )
					if ( ! isset( $values[ $bt ] ) )
						$values[ $bt ] = $strings[0];
		}

		if ( ! $values )
			return( self::none( 'no-field' ) );

		uksort( $values, array( self::class, 'bt_compare' ) );

		return( array( 'source' => 'rule', 'reason' => '', 'ruleset' => (string) ( $pkg['id'] ?? '' ) . '@' . (string) ( $pkg['version'] ?? '' ), 'values' => $values ) );
	}


	/** BG before BT, then by number - twin of `btCompare()`; the reason for sorting stands at `sortedValues()`. */
	public static function bt_compare( $a, $b ): int
	{
		$parse = static function ( $s ) {
			return( preg_match( '/^(B[GT])-(X-)?(\d+)(?:-(\d+))?$/', (string) $s, $m )
				? array( $m[1], ! empty( $m[2] ) ? 1 : 0, (int) $m[3], (int) ( $m[4] ?? 0 ) )
				: null );
		};

		$x = $parse( $a );
		$y = $parse( $b );

		if ( $x === null || $y === null )
			return( strcmp( (string) $a, (string) $b ) <=> 0 );

		for ( $i = 0; $i < 4; $i++ )
			if ( $x[ $i ] !== $y[ $i ] )
				return( $x[ $i ] < $y[ $i ] ? -1 : 1 );

		return( 0 );
	}


	/** The fields of a group that get its value: the one the rule names, or the only member ( see the JS twin ). */
	private static function targets( array $members, array $refs ): array
	{
		$named = array_values( array_filter( $members, static function ( $bt ) use ( $refs ) { return( isset( $refs[ $bt ] ) ); } ) );

		if ( count( $named ) === 1 )
			return( $named );

		return( ! $named && count( $members ) === 1 ? $members : array() );
	}


	private static function all_set( array $targets, array $values ): bool
	{
		foreach ( $targets as $bt )
			if ( ! isset( $values[ $bt ] ) )
				return( false );

		return( true );
	}


	private static function bind_lets( $engine, array $lets, \DOMNode $node, array $scope ): array
	{
		foreach ( $lets as $l )
			$scope[ (string) $l['name'] ] = $engine->evaluate( $l['expr'], $node, $scope );

		return( $scope );
	}


	/** Twin of `findComparison()`. */
	private static function find_comparison( $ast, $engine, \DOMNode $node, array $vars, array $var_alts, array $base ): ?array
	{
		if ( ! is_array( $ast ) )
			return( null );

		$type = (string) ( $ast['node'] ?? '' );

		if ( $type === 'ParenExpr' )
			return( self::find_comparison( $ast['inner'], $engine, $node, $vars, $var_alts, $base ) );

		if ( $type === 'QuantifiedExpr' )
		{
			$name = (string) $ast['var'];
			$pick = null;

			foreach ( $engine->split( $engine->evaluate( $ast['in'], $node, $vars ) ) as $item )
			{
				$try          = $vars;
				$try[ $name ] = $item;

				if ( ! $engine->truth( $engine->evaluate( $ast['satisfies'], $node, $try ) ) )
				{
					$pick = $item;
					break;
				}
			}

			if ( $pick === null )
				return( null );

			$vars[ $name ]     = $pick;
			$var_alts[ $name ] = self::expr_alts( $ast['in'], $base, $var_alts );

			$inner = self::find_comparison( $ast['satisfies'], $engine, $node, $vars, $var_alts, $base );

			if ( $inner === null )
				return( null );

			$inner['bound'] = array_merge( array( $name ), (array) ( $inner['bound'] ?? array() ) );

			return( $inner );
		}

		if ( $type !== 'BinaryOp' )
			return( null );

		$op = (string) $ast['op'];

		if ( in_array( $op, self::COMPARISONS, true ) )
			return( array( 'cmp' => $ast, 'vars' => $vars, 'varAlts' => $var_alts, 'bound' => array() ) );

		if ( $op === 'or' )
		{
			$left = self::find_comparison( $ast['left'], $engine, $node, $vars, $var_alts, $base );

			return( $left !== null ? $left : self::find_comparison( $ast['right'], $engine, $node, $vars, $var_alts, $base ) );
		}

		if ( $op === 'and' )
		{
			$left_true = $engine->truth( $engine->evaluate( $ast['left'], $node, $vars ) );

			return( self::find_comparison( $left_true ? $ast['right'] : $ast['left'], $engine, $node, $vars, $var_alts, $base ) );
		}

		return( null );
	}


	/** Twin of `exprAlts()`. */
	private static function expr_alts( $expr, array $base, array $var_alts ): array
	{
		if ( ! is_array( $expr ) )
			return( array() );

		switch ( (string) ( $expr['node'] ?? '' ) )
		{
			case 'PathExpr':
				return( self::static_paths( $expr, $base, $var_alts ) );

			case 'VarRef':
				return( $var_alts[ (string) $expr['name'] ] ?? array() );

			case 'ParenExpr':
				return( self::expr_alts( $expr['inner'], $base, $var_alts ) );

			case 'FunctionCall':
				$out = array();

				foreach ( (array) ( $expr['args'] ?? array() ) as $a )
					$out = array_merge( $out, self::expr_alts( $a, $base, $var_alts ) );

				return( $out );

			case 'BinaryOp':
				return( (string) $expr['op'] === '|' ? array_merge( self::expr_alts( $expr['left'], $base, $var_alts ), self::expr_alts( $expr['right'], $base, $var_alts ) ) : array() );
		}

		return( array() );
	}


	/** Twin of `fieldGroups()`: key => array( steps, bts ). */
	private static function field_groups( $terms, string $syntax, $state ): array
	{
		if ( $state->groups !== null )
			return( $state->groups );

		$groups = array();
		$ids    = is_object( $terms ) && method_exists( $terms, 'fieldIds' ) ? (array) $terms->fieldIds() : array();

		foreach ( $ids as $bt )
		{
			$xpath = (string) $terms->fieldXpath( (string) $bt, $syntax );
			$xpath = (string) preg_replace( '/\s+\([^)]*\)\s*$/', '', $xpath );
			$steps = array_map( static function ( $s ) { return( $s['name'] ); }, Report::field_pattern( $xpath ) );

			if ( ! $steps )
				continue;

			$key = implode( '/', array_slice( $steps, 1 ) );

			if ( ! isset( $groups[ $key ] ) )
				$groups[ $key ] = array( 'steps' => $steps, 'bts' => array() );

			$groups[ $key ]['bts'][] = (string) $bt;
		}

		$state->groups = $groups;

		return( $groups );
	}


	/** Twin of `soleSubtree()`. */
	private static function sole_subtree( array $operand, string $key, array $ctx ): ?array
	{
		$touched = self::touches( $operand, $ctx );

		if ( count( $touched ) === 1 && in_array( $key, $touched, true ) && self::is_value_expr( $operand ) )
			return( $operand );

		if ( ! in_array( $key, $touched, true ) || (string) ( $operand['node'] ?? '' ) === 'PathExpr' )
			return( null );

		foreach ( self::children( $operand ) as $child )
		{
			$found = self::sole_subtree( $child, $key, $ctx );

			if ( $found !== null )
				return( $found );
		}

		return( null );
	}


	/** Twin of `stripOffset()`: a tolerance belongs to the comparison, not to the field. */
	private static function strip_offset( $ast )
	{
		if ( ! is_array( $ast ) )
			return( $ast );

		$type = (string) ( $ast['node'] ?? '' );

		if ( $type === 'ParenExpr' )
			return( self::strip_offset( $ast['inner'] ) );

		if ( $type === 'BinaryOp' && ( $ast['op'] === '+' || $ast['op'] === '-' ) )
		{
			if ( self::is_number_literal( $ast['right'] ) )
				return( self::strip_offset( $ast['left'] ) );

			if ( $ast['op'] === '+' && self::is_number_literal( $ast['left'] ) )
				return( self::strip_offset( $ast['right'] ) );

			return( $ast );
		}

		if ( $type === 'FunctionCall' && count( (array) ( $ast['args'] ?? array() ) ) === 1 && (string) ( $ast['name']['local'] ?? '' ) === 'decimal' )
		{
			$ast['args'] = array( self::strip_offset( $ast['args'][0] ) );

			return( $ast );
		}

		if ( $type === 'PathExpr' && ! empty( $ast['steps'] ) )
		{
			$last = count( $ast['steps'] ) - 1;

			if ( ! empty( $ast['steps'][ $last ]['primary'] ) )
				$ast['steps'][ $last ]['primary'] = self::strip_offset( $ast['steps'][ $last ]['primary'] );
		}

		return( $ast );
	}


	private static function is_number_literal( $ast ): bool
	{
		return( is_array( $ast ) && (string) ( $ast['node'] ?? '' ) === 'Literal' && (string) ( $ast['kind'] ?? '' ) !== 'string' );
	}


	/** The functions that keep a field's value a value of that field - twin of `VALUE_FUNCTIONS`. */
	const VALUE_FUNCTIONS = array( 'decimal', 'number', 'sum', 'round', 'round-half-to-even', 'floor', 'ceiling', 'abs', 'normalize-space', 'string', 'data' );

	/** The casts a single field read may be wrapped in - twin of the list in `valueStrings()`. */
	const CASTS = array( 'decimal', 'number', 'normalize-space', 'string', 'data' );


	/** Twin of `isValueExpr()`: a value of the fields it reads, not a property of them. */
	private static function is_value_expr( $ast ): bool
	{
		if ( ! is_array( $ast ) )
			return( false );

		switch ( (string) ( $ast['node'] ?? '' ) )
		{
			case 'PathExpr':
			case 'VarRef':
				return( true );

			case 'Literal':
				return( (string) ( $ast['kind'] ?? '' ) !== 'string' );

			case 'ParenExpr':
				return( self::is_value_expr( $ast['inner'] ) );

			case 'UnaryOp':
				return( self::is_value_expr( $ast['operand'] ) );

			case 'BinaryOp':
				return( in_array( (string) $ast['op'], array( '+', '-', '*', 'div' ), true ) && self::is_value_expr( $ast['left'] ) && self::is_value_expr( $ast['right'] ) );

			case 'FunctionCall':
				if ( empty( $ast['name'] ) || ! in_array( (string) $ast['name']['local'], self::VALUE_FUNCTIONS, true ) )
					return( false );

				$args = array_values( (array) ( $ast['args'] ?? array() ) );

				return( ! $args || self::is_value_expr( $args[0] ) );
		}

		return( false );
	}


	/** Twin of `valueStrings()`: a single field read without arithmetic keeps the document's own writing. */
	private static function value_strings( array $ast, $engine, \DOMNode $node, array $vars ): array
	{
		$bare = $ast;

		while ( is_array( $bare ) && ( ( $bare['node'] ?? '' ) === 'ParenExpr' || ( ( $bare['node'] ?? '' ) === 'FunctionCall' && count( (array) ( $bare['args'] ?? array() ) ) === 1 && in_array( (string) ( $bare['name']['local'] ?? '' ), self::CASTS, true ) ) ) )
			$bare = ( $bare['node'] === 'ParenExpr' ) ? $bare['inner'] : $bare['args'][0];

		if ( is_array( $bare ) && ( $bare['node'] ?? '' ) === 'PathExpr' )
		{
			$nodes = $engine->nodes( $engine->evaluate( $bare, $node, $vars ) );

			if ( count( $nodes ) === 1 )
				return( array( trim( (string) $nodes[0]->textContent ) ) );
		}

		return( $engine->strings( $engine->evaluate( $ast, $node, $vars ) ) );
	}


	/**
	 * Twin of `nodesForPath()`: every element a report path names, in document order; a step without an index
	 * means any, `*` any name.
	 *
	 * @return	\DOMElement[]
	 */
	private static function nodes_for_path( string $path, array $tree ): array
	{
		$steps = Report::field_pattern( $path );

		if ( ! $steps )
			return( array() );

		$current = array( $tree['document'] );

		foreach ( $steps as $step )
		{
			$next = array();

			foreach ( $current as $parent )
			{
				$seen = array();

				foreach ( $parent->childNodes as $child )
				{
					if ( ! $child instanceof \DOMElement )
						continue;

					$nth                        = ( $seen[ $child->localName ] ?? 0 ) + 1;
					$seen[ $child->localName ] = $nth;

					if ( ( $step['name'] === '*' || $step['name'] === $child->localName ) && ( $step['index'] === null || (string) $nth === (string) $step['index'] ) )
						$next[] = $child;
				}
			}

			$current = $next;
		}

		return( $current );
	}


	/** Twin of `children()`. */
	private static function children( array $ast ): array
	{
		switch ( (string) ( $ast['node'] ?? '' ) )
		{
			case 'BinaryOp':     return( array( $ast['left'], $ast['right'] ) );
			case 'UnaryOp':      return( array( $ast['operand'] ) );
			case 'ParenExpr':    return( array( $ast['inner'] ) );
			case 'FunctionCall': return( array_values( (array) ( $ast['args'] ?? array() ) ) );
			case 'IfExpr':       return( array( $ast['then'], $ast['else'] ) );
			case 'SequenceExpr': return( array_values( (array) ( $ast['items'] ?? array() ) ) );
		}

		return( array() );
	}


	/**
	 * Twin of `touches()`; the keys in the order they are first seen, as in the JavaScript `Set`.
	 *
	 * @return	string[]
	 */
	private static function touches( $ast, array $ctx ): array
	{
		$out = array();

		self::walk( $ast, $ctx, $out );

		return( array_keys( $out ) );
	}


	private static function walk( $node, array $ctx, array &$out ): void
	{
		if ( ! is_array( $node ) )
			return;

		$type = (string) ( $node['node'] ?? '' );

		if ( $type === 'PathExpr' )
		{
			self::add( self::static_paths( $node, $ctx['base'], $ctx['varAlts'] ), $ctx, $out );
			return;
		}

		if ( $type === 'VarRef' )
		{
			self::add( $ctx['varAlts'][ (string) $node['name'] ] ?? array(), $ctx, $out );
			return;
		}

		foreach ( self::children( $node ) as $child )
			self::walk( $child, $ctx, $out );

		if ( $type === 'IfExpr' )
			self::walk( $node['cond'], $ctx, $out );
	}


	/** An alternative of a union counts only if no other one matches ( see the JS twin ). */
	private static function add( array $alts, array $ctx, array &$out ): void
	{
		$hit = false;

		foreach ( $alts as $alt )
			foreach ( $ctx['groups'] as $key => $group )
				if ( self::glob_match( $alt, $group['steps'] ) )
				{
					$out[ (string) $key ] = true;
					$hit = true;
				}

		if ( ! $hit )
			$out[ self::UNKNOWN ] = true;
	}


	/** Twin of `staticPaths()`. */
	private static function static_paths( array $path, array $base, array $var_alts ): array
	{
		$alts = array( ! empty( $path['absolute'] ) ? array() : $base );

		foreach ( (array) ( $path['steps'] ?? array() ) as $step )
		{
			if ( ! empty( $step['primary'] ) )
			{
				$alts = self::primary_paths( $step['primary'], $alts, $var_alts );
				continue;
			}

			$test = $step['test'] ?? null;
			$name = is_array( $test ) && ( $test['kind'] ?? '' ) === 'name' && ! empty( $test['name'] ) ? (string) $test['name']['local'] : null;
			$next = array();

			foreach ( $alts as $alt )
			{
				switch ( (string) ( $step['axis'] ?? '' ) )
				{
					case 'child':
						if ( $name !== null ) $next[] = array_merge( $alt, array( $name ) );
						break;

					case 'attribute':
						if ( $name !== null ) $next[] = array_merge( $alt, array( '@' . $name ) );
						break;

					case 'self':
						$next[] = $alt;
						break;

					case 'parent':
						$next[] = $alt && end( $alt ) !== '**' ? array_slice( $alt, 0, -1 ) : $alt;
						break;

					case 'descendant-or-self':
						$next[] = $name !== null ? array_merge( $alt, array( '**', $name ) ) : array_merge( $alt, array( '**' ) );
						break;

					case 'descendant':
						if ( $name !== null ) $next[] = array_merge( $alt, array( '**', $name ) );
						break;
				}
			}

			$alts = $next;
		}

		return( $alts );
	}


	/** Twin of `primaryPaths()`. */
	private static function primary_paths( array $primary, array $alts, array $var_alts ): array
	{
		$type = (string) ( $primary['node'] ?? '' );

		if ( $type === 'ParenExpr' )
			return( self::primary_paths( $primary['inner'], $alts, $var_alts ) );

		if ( $type === 'VarRef' )
			return( $var_alts[ (string) $primary['name'] ] ?? array() );

		if ( $type === 'BinaryOp' && $primary['op'] === '|' )
			return( array_merge( self::primary_paths( $primary['left'], $alts, $var_alts ), self::primary_paths( $primary['right'], $alts, $var_alts ) ) );

		if ( $type === 'PathExpr' )
		{
			$out = array();

			foreach ( $alts as $alt )
				$out = array_merge( $out, self::static_paths( $primary, $alt, $var_alts ) );

			return( $out );
		}

		if ( $type === 'FunctionCall' || $type === 'BinaryOp' || $type === 'UnaryOp' )
		{
			$out = array();

			foreach ( self::children( $primary ) as $arg )
				if ( is_array( $arg ) )
					$out = array_merge( $out, self::primary_paths( $arg, $alts, $var_alts ) );

			return( $out );
		}

		return( array() );
	}


	/** Twin of `globMatch()`. */
	private static function glob_match( array $alt, array $steps ): bool
	{
		$want = array_slice( $steps, 1 );
		$have = $alt && $alt[0] !== '**' ? array_slice( $alt, 1 ) : $alt;

		return( self::glob( $have, 0, $want, 0 ) );
	}


	private static function glob( array $h, int $i, array $w, int $j ): bool
	{
		if ( $i === count( $h ) )
			return( $j === count( $w ) );

		if ( $h[ $i ] === '**' )
		{
			for ( $k = $j; $k <= count( $w ); $k++ )
				if ( self::glob( $h, $i + 1, $w, $k ) )
					return( true );

			return( false );
		}

		return( $j < count( $w ) && $h[ $i ] === $w[ $j ] && self::glob( $h, $i + 1, $w, $j + 1 ) );
	}


	/**
	 * Twin of `buildTree()`: a real DOMDocument from the flat rows.
	 *
	 * ⚠️ THE NODE PATH IS THE KEY, NOT THE OBJECT. libxml hands out new PHP wrappers for the same node on every
	 * access, so `spl_object_id()` is useless here; `getNodePath()` is stable.
	 *
	 * @return	array	array( document, nodes => row index => DOMElement, paths => node path => names from the root )
	 */
	public static function build_tree( array $rows, string $syntax ): array
	{
		$doc   = new \DOMDocument( '1.0', 'UTF-8' );
		$nodes = array();
		$paths = array();
		$stack = array();
		$names = array();
		$n     = count( $rows );

		for ( $j = 0; $j < $n; $j++ )
		{
			$row   = $rows[ $j ];
			$level = max( 1, (int) ( $row['level'] ?? 1 ) );

			while ( $stack && end( $stack )['level'] >= $level )
				array_pop( $stack );

			$parent       = $stack ? end( $stack )['node'] : $doc;
			$raw          = (string) ( $row['name'] ?? '' );
			$local        = (string) preg_replace( '/^.*:/', '', $raw );
			$has_kids     = $j + 1 < $n && (int) ( $rows[ $j + 1 ]['level'] ?? 0 ) > $level;
			$parent_local = $parent instanceof \DOMElement ? $parent->localName : '';
			$root_local   = $stack ? $stack[0]['node']->localName : $local;
			$ns           = self::namespace_of( $raw, $local, $level, $has_kids, $syntax, $parent_local, $root_local );

			$el = $doc->createElementNS( $ns['uri'], $ns['prefix'] !== '' ? $ns['prefix'] . ':' . $local : $local );

			if ( preg_match_all( '/([A-Za-z_][\w.:-]*)="([^"]*)"/', (string) ( $row['attr'] ?? '' ), $mm, PREG_SET_ORDER ) )
				foreach ( $mm as $m )
					if ( strpos( $m[1], ':' ) === false )
						$el->setAttribute( $m[1], $m[2] );

			if ( ! $has_kids && (string) ( $row['value'] ?? '' ) !== '' )
				$el->appendChild( $doc->createTextNode( (string) $row['value'] ) );

			$parent->appendChild( $el );

			$nodes[ $j ] = $el;
			$names[ $j ] = array_merge( $stack ? end( $stack )['names'] : array(), array( $local ) );
			$stack[]     = array( 'level' => $level, 'node' => $el, 'names' => $names[ $j ] );
		}

		/*
		 * ⚠️ THE KEYS ONLY AFTER THE WHOLE TREE STANDS. `getNodePath()` gives the first `ram:ApplicableTradeTax` no index
		 * while it is alone and `[1]` once a second one follows; keys taken while building went stale exactly where an
		 * invoice has two VAT breakdowns, and BR-S-08 lost its basis and rate in PHP only ( measured 25.10.2026 on the
		 * Testbelege error copy of 02.03a ).
		 */
		foreach ( $nodes as $j => $el )
			$paths[ $el->getNodePath() ] = $names[ $j ];

		return( array( 'document' => $doc, 'nodes' => $nodes, 'paths' => $paths ) );
	}


	/** Twin of `namespaceOf()`. */
	private static function namespace_of( string $raw, string $local, int $level, bool $has_kids, string $syntax, string $parent_local, string $root_local ): array
	{
		if ( preg_match( '/^([A-Za-z][\w.-]*):/', $raw, $m ) && isset( self::NS[ $m[1] ] ) && $m[1] !== 'inv' && $m[1] !== 'cn' )
			return( array( 'uri' => self::NS[ $m[1] ], 'prefix' => $m[1] ) );

		if ( $syntax === 'CII' || $root_local === 'CrossIndustryInvoice' )
		{
			if ( $level === 1 || ( $level === 2 && in_array( $local, self::CII_RSM, true ) ) )
				return( array( 'uri' => self::NS['rsm'], 'prefix' => 'rsm' ) );

			if ( $local === 'DateTimeString' )
				return( $parent_local === 'FormattedIssueDateTime' ? array( 'uri' => self::NS['qdt'], 'prefix' => 'qdt' ) : array( 'uri' => self::NS['udt'], 'prefix' => 'udt' ) );

			return( array( 'uri' => self::NS['ram'], 'prefix' => 'ram' ) );
		}

		if ( $level === 1 )
			return( array( 'uri' => $local === 'CreditNote' ? self::NS['cn'] : self::NS['inv'], 'prefix' => '' ) );

		if ( in_array( $local, array( 'UBLExtensions', 'UBLExtension', 'ExtensionContent' ), true ) )
			return( array( 'uri' => self::NS['ext'], 'prefix' => 'ext' ) );

		return( $has_kids ? array( 'uri' => self::NS['cac'], 'prefix' => 'cac' ) : array( 'uri' => self::NS['cbc'], 'prefix' => 'cbc' ) );
	}

}


/**
 * The engine over VAMPIRE PHP - only ever built by `RuleValues::vampire_engine()` after the classes were found.
 *
 * ⚠️ THE TREES ARRIVE AS ARRAYS ( the decoded rule package ) and are turned into VAMPIRE's `Expr` objects on every
 * call. A cache keyed by the array would need a hash of it, which costs about as much as the conversion.
 */
class RuleValuesVampireEngine
{

	/** @var object */
	private $interpreter;


	public function __construct( array $namespaces )
	{
		$this->interpreter = new \EinvoiceValidator\Xpath\Interpreter( $namespaces );
	}


	public function evaluate( array $ast, \DOMNode $node, array $vars )
	{
		return( $this->interpreter->evaluate( \EinvoiceValidator\Xpath\Ast\AstSerializer::fromArray( $ast ), $node, $vars ) );
	}


	public function truth( $value ): bool
	{
		return( $value->effectiveBooleanValue() );
	}


	public function strings( $value ): array
	{
		return( array_map( static function ( $a ) { return( $a->asString() ); }, $value->atomize() ) );
	}


	public function nodes( $value ): array
	{
		return( array_values( array_filter( $value->items(), static function ( $i ) { return( $i instanceof \DOMNode ); } ) ) );
	}


	public function split( $value ): array
	{
		return( array_map( static function ( $i ) { return( \EinvoiceValidator\Xpath\Runtime\Sequence::singleton( $i ) ); }, $value->items() ) );
	}

}
