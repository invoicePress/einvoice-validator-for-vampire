<?php
/**
 * The lookup interface the explainer renderer needs.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report\Explainer;

/*
 * ⚠️ **THE GUARD AGAINST DOUBLE LOADING APPLIES TO THE INTERFACE AS WELL**, not only to the classes
 * next to it. Every plugin brings its own copy of this package. If one plugin loads the file through
 * its autoloader and a second one then loads the same interface with `require_once` from its own
 * copy, the page ends with "Cannot declare interface … already in use". Measured on
 * 07.10.2026 with KoSIT validator and KoSIT explorer together, as soon as the validator loads first.
 *
 * ⚠️ `interface_exists( …, false )`: without calling the autoloader. With `true` the check itself would
 * trigger the loading and would no longer be a check.
 */
if ( ! interface_exists( __NAMESPACE__ . '\\Terms', false ) ) :

/**
 * interface: Terms
 *
 * ⚠️ WHY THIS INTERFACE EXISTS AT ALL.
 *
 * The package description of `specialpress/einvoice-report` says: "No BT/BG mapping, no
 * explanation text - that stays in the eInvoice Validation Explainer." That is the boundary of the
 * package: it reads reports, it knows no business terms. The renderer that lives next to it needs
 * both - so it gets them handed in instead of fetching them.
 *
 * The five methods cover exactly what the renderer asks of the rule catalogue. It needs no more, and
 * this interface must never get more: every further method would be a piece of the explainer seeping
 * into the report package.
 *
 * @since 1.4.0
 */

interface Terms
{

	/**
	 * Everything that is known about a rule.
	 *
	 * @param	string	$id		Rule id, e.g. "BR-S-02".
	 * @return	array			At least `fields` ( list of BT/BG ids ) and `description`;
	 *                          an empty array when the rule is unknown. `translation` may stand
	 *                          next to them, either flat as `array( 'source' =>
	 *                          'machine', 'reviewed' => false )` or per language as `array( 'de' =>
	 *                          array( … ) )`. The renderer then shows the note that the text is
	 *                          machine-translated and not yet reviewed.
	 *                          ⚠️ That is a key and not a new method: a new method of the
	 *                          interface would make every plugin with an older implementation fatal.
	 */

	public function rule( string $id ): array;


	/**
	 * The XPath a BT/BG id points to in a syntax.
	 *
	 * @param	string	$id		BT/BG id.
	 * @param	string	$syntax	'CII' or 'UBL'.
	 * @return	string			Empty when unknown or not present in this syntax.
	 */

	public function fieldXpath( string $id, string $syntax ): string;


	/**
	 * Resolve a multilingual value into the language of the interface.
	 *
	 * @param	mixed	$value	A string or a language map.
	 * @return	string
	 */

	public function text( $value ): string;


	/**
	 * In WHICH language text() answered.
	 *
	 * ⚠️ The difference to text() is the whole point: a silent de→en→fr fallback chain would present
	 * an English sentence as a translation. The renderer compares the result with
	 * language() and writes a notice when the two differ.
	 *
	 * @param	mixed	$value
	 * @param	string	$preferred
	 * @return	string	Language code, or empty when there was nothing to resolve.
	 */

	public function textLanguage( $value, string $preferred ): string;


	/**
	 * The language of the interface.
	 *
	 * @return	string	Language code, e.g. 'de'.
	 */

	public function language(): string;

}

endif;
