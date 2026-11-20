<?php
// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Reads the JSON report of VAMPIRE PHP and VAMPIRE JS into the shared model.
 *
 * The format is documented in `vampire-php/docs/developer/report-formats.md`, schema version 1.1, with a promise of
 * stability: fields are not removed or redefined within one `schemaVersion`, new ones may come. This reader therefore
 * reads what it knows and ignores the rest.
 *
 * ⚠️ **THE JSON CARRIES NO INVOICE.** `document` is a file name, not the document; the view "Invoice" needs the invoice
 * next to the report, as with Mustang. The other form of VAMPIRE, the XML in the format of the KoSIT validator, carries
 * the document and is read by `ReaderXml`.
 *
 * ⚠️ **`accepted: null` IS NO VERDICT, NOT A REJECTION.** VAMPIRE writes null when the package lacks a rule set of the
 * scenario ( `missingRulesets` ); the document was not checked. `compliant` stays empty then, and a notice names the
 * missing rule sets. Reading null as false would call an unchecked invoice rejected.
 *
 * ⚠️ **`notices` MUST BE SHOWN.** VAMPIRE fills it with sentences a licence requires to accompany the report ( FeRD for
 * the Factur-X rule sets ). They go into `notices` of the model, which every view prints.
 *
 * ⚠️ **ONLY THE FILE NAME OF `document`.** The command line writes the path it was given; a path of the machine that
 * validated has no business in a report shown to somebody else.
 *
 * ⚠️ THE TWIN IS `libs/einvoice-core-js/src/report/ReaderVampire.mjs`, line for line.
 *
 * @since 1.17.0
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report;

use Exception;

// Same direct-access guard as Report.php in this package - see that file's comment.
if ( ! defined( 'ABSPATH' ) )
	exit;

// ⚠️ GUARDED - see Report.php's own comment on this same line for why.
if ( ! class_exists( __NAMESPACE__ . '\\ReaderVampire' ) ) :

class ReaderVampire
{

	/** The stages of the cascade in the order VAMPIRE runs them, with the name the view shows. */
	const STAGES = array(
		'wellformed' => 'Well-formedness',
		'xsd'        => 'Schema (XSD)',
		'syntax'     => 'Syntax rules',
		'business'   => 'Business rules',
	);


	/**
	 * Is this a JSON report of VAMPIRE?
	 *
	 * ⚠️ THREE KEYS, NOT ONE. `findings` alone appears in many JSON files; `schemaVersion` together with `stages` and
	 * `findings` is the shape of this report and of nothing else the tools read.
	 *
	 * @param	string	$content
	 * @return	bool
	 */

	public static function detect( $content ): bool
	{
		$text = ltrim( (string) $content, "\xEF\xBB\xBF \t\r\n" );

		if ( $text === '' || $text[0] !== '{' )
			return( false );

		return( strpos( $text, '"schemaVersion"' ) !== false && strpos( $text, '"stages"' ) !== false && strpos( $text, '"findings"' ) !== false );
	}


	/**
	 * @param	string	$content
	 * @return	Report
	 * @throws	Exception
	 */

	public static function read( $content ): Report
	{
		$data = json_decode( ltrim( (string) $content, "\xEF\xBB\xBF" ), true );

		if ( ! is_array( $data ) || ! isset( $data['findings'] ) || ! is_array( $data['findings'] ) )
			throw new Exception( 'This does not look like a VAMPIRE report ( no list of findings ).' );

		$report = new Report();

		$report->meta['source'] = 'vampire';

		$rr     = isset( $data['ruleReport'] ) && is_array( $data['ruleReport'] ) ? $data['ruleReport'] : array();
		$engine = isset( $rr['engine'] ) && is_array( $rr['engine'] ) ? trim( (string) ( $rr['engine']['name'] ?? '' ) . ' ' . (string) ( $rr['engine']['version'] ?? '' ) ) : '';

		$report->meta['engine'] = $engine !== '' ? $engine : 'VAMPIRE';

		if ( isset( $data['scenario'] ) && is_string( $data['scenario'] ) && trim( $data['scenario'] ) !== '' )
			$report->meta['document_type'] = trim( $data['scenario'] );

		if ( isset( $data['document'] ) && is_string( $data['document'] ) && trim( $data['document'] ) !== '' )
			$report->meta['reference'] = self::base_name( $data['document'] );

		if ( isset( $data['rulesets'] ) && is_array( $data['rulesets'] ) && $data['rulesets'] )
			$report->meta['standard'] = implode( ', ', array_map( 'strval', $data['rulesets'] ) );

		if ( isset( $rr['validatedAt'] ) && is_string( $rr['validatedAt'] ) )
			$report->meta['validated_at'] = $rr['validatedAt'];

		if ( isset( $rr['document']['syntax'] ) && is_string( $rr['document']['syntax'] ) )
			$report->meta['syntax'] = strtolower( $rr['document']['syntax'] );

		$accepted = array_key_exists( 'accepted', $data ) ? $data['accepted'] : null;

		if ( $accepted === true )
			$report->meta['compliant'] = 'yes';
		elseif ( $accepted === false )
			$report->meta['compliant'] = 'no';

		$missing = isset( $data['missingRulesets'] ) && is_array( $data['missingRulesets'] ) ? array_map( 'strval', $data['missingRulesets'] ) : array();

		if ( $accepted === null )
			// ⚠️ A FIXED SENTENCE, NO LIST IN IT: the views translate a notice by its exact wording ( `Renderer::notice()` ), and
			// a sentence with the names put in would stay English. The names are codes and need no translation.
			$report->notices[] = $missing ? 'This report states no verdict: the validator lacked a rule set of the scenario, so the document was not checked against it.' : 'This report states no verdict.';

		foreach ( $data['findings'] as $f )
		{
			if ( ! is_array( $f ) )
				continue;

			$severity = (string) ( $f['severity'] ?? '' );

			$report->findings[] = array(
				'position'  => (string) ( $f['stage'] ?? '' ),
				'rule'      => (string) ( $f['rule'] ?? '' ),
				'severity'  => $severity === 'fatal' ? 'error' : ( $severity === 'warning' ? 'warning' : 'information' ),
				// ⚠️ Collapsed: the XRechnung rule sets carry their text with the indentation of the Schematron source.
				'message'   => trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $f['text'] ?? '' ) ) ),
				'path'      => (string) ( $f['location'] ?? '' ),
				'rows'      => array(),
				'criterion' => '',
				'fields'    => isset( $f['fields'] ) && is_array( $f['fields'] ) ? array_values( array_map( 'strval', $f['fields'] ) ) : array(),
			);
		}

		foreach ( self::STAGES as $key => $label )
		{
			$status = isset( $data['stages'][ $key ] ) ? (string) $data['stages'][ $key ] : '';

			if ( $status === '' || $status === 'notrun' || $status === 'skipped' )
				continue;

			$tally = array( 'error' => 0, 'warning' => 0, 'information' => 0 );

			foreach ( $report->findings as $f )
				if ( $f['position'] === $key )
					$tally[ $f['severity'] ]++;

			$report->steps[] = array( 'step' => $label, 'errors' => $tally['error'], 'warnings' => $tally['warning'], 'infos' => $tally['information'] );
		}

		if ( isset( $data['notices'] ) && is_array( $data['notices'] ) )
			foreach ( $data['notices'] as $n )
				if ( is_string( $n ) && trim( $n ) !== '' )
					$report->notices[] = trim( $n );

		return( $report );
	}


	/**
	 * The file name of a path, for both kinds of separator.
	 *
	 * @param	string	$path
	 * @return	string
	 */

	private static function base_name( string $path ): string
	{
		$parts = preg_split( '#[\\\\/]#', trim( $path ) );

		return( (string) end( $parts ) );
	}

}

endif;
