<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Reads a valitool validation report ( `urn:valitool.org:validationreport:*` ) into the shared model.
 *
 * @package SpecialPress\EInvoice\Report
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Report;

use DOMDocument;
use DOMXPath;
use Exception;

// Same direct-access guard as Report.php in this package - see that file's comment.
if ( ! defined( 'ABSPATH' ) )
	exit;


// ⚠️ GUARDED - see Report.php's own comment on this same line for why.

if ( ! class_exists( __NAMESPACE__ . '\\ReaderValitool' ) ) :

/**
 * class: ReaderValitool
 *
 * ============================================================================================
 * The valitool report format
 * ============================================================================================
 *
 * valitool is the format the suite's corpus actually carries: 23 reports under
 * `_einvoice-shared-data/examples/invoices/**`, produced by the official FeRD validation tool. The
 * other readers recognise it as XML but not as a known validation report format.
 *
 * ⚠️ THIS FORMAT CARRIES SOMETHING THE KOSIT REPORT DOES NOT HAVE: the **BT assignment per message**,
 * in `<vr:relatedBTList><vr:relatedBT ID="BT-49">`. For the KoSIT report the explainer must look it up
 * in its own rule catalogue; here it stands in the report itself. It is therefore read and stored in
 * `finding['fields']` - a field the other readers leave empty.
 *
 * ============================================================================================
 * ⚠️ THE LEVEL MAPPING IS MEASURED, NOT GUESSED
 * ============================================================================================
 *
 * `<vr:errorType>` carries a number without a legend in the document. Counted over all 23 reports:
 *
 *   errorType 1   4 messages    report stays `isValid=true`, but sets `hasWarnings=true`    -> warning
 *   errorType 2   1 message     report stands at `isValid=false`                            -> error
 *   errorType 3  24 messages    one per report, always the demo version notice, no code     -> information
 *
 * A different mapping would have turned the one real error of the corpus into a warning.
 *
 * ⚠️ `errorTypeDE` IS NOT THE LEVEL. It stands next to `errorType` and carries other values
 * ( 1 for an errorType 2, 5 for an errorType 1 ) - a subject classification, not a
 * severity. Whoever takes it for one mixes the levels up.
 *
 * @since 1.4.0
 */

class ReaderValitool
{


	/** Der Namensraum, ohne Fassungsnummer - `2p12` heute, morgen etwas anderes. */
	const NS_PREFIX = 'urn:valitool.org:validationreport';


	/**
	 * Does the content look like a valitool report?
	 *
	 * ⚠️ BY THE NAMESPACE, NOT BY THE ROOT NAME. `validationReport` is a name other tools could
	 * use as well; the namespace belongs to valitool alone. And compared without the
	 * version number, otherwise the reader fails with the next `2p13`.
	 *
	 * @param	string	$raw
	 * @return	bool
	 * @since	1.4.0
	 */

	public static function detect( $raw )
	{
		return( strpos( (string) $raw, self::NS_PREFIX ) !== false );
	}


	/**
	 * @param	string	$raw
	 * @return	Report
	 * @throws	Exception
	 * @since	1.4.0
	 */

	public static function read( $raw )
	{
		$dom = self::load( (string) $raw );
		$xp  = new DOMXPath( $dom );

		// The prefix in the document is freely chosen - our own is registered and used.
		$ns = $dom->documentElement->namespaceURI;

		if ( ! is_string( $ns ) || $ns === '' )
			throw new Exception( 'This valitool report carries no namespace.' );

		$xp->registerNamespace( 'v', $ns );

		$report = new Report();

		self::read_meta( $xp, $report );
		self::read_steps( $xp, $report );
		self::read_findings( $xp, $report );

		/**
		 * ⚠️ NO DOCUMENT TREE. The valitool report does NOT reproduce the checked document - it only names
		 * line, column and XPath per message. `$report->document` therefore stays empty, and
		 * `link_findings()` has nothing to link. That is no read error but a property of the
		 * format; an interface that wants to show the document part must put the XML next to it
		 * itself. Noted, so that nobody takes the empty view for a
		 * failure.
		 */

		if ( $report->findings )
			$report->notices[] = 'This report format lists findings with their XPath, line and column, but does not carry the document itself. The document view stays empty.';

		return( $report );
	}


	/**
	 * @param	string	$raw
	 * @return	DOMDocument
	 * @throws	Exception
	 * @since	1.4.0
	 */

	private static function load( $raw )
	{
		$previous = libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		$ok  = $dom->loadXML( $raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $ok || $dom->documentElement === null )
			throw new Exception( 'This valitool report could not be parsed as XML.' );

		return( $dom );
	}


	/**
	 * @param	DOMXPath	$xp
	 * @param	Report		$report
	 * @return	void
	 * @since	1.4.0
	 */

	private static function read_meta( DOMXPath $xp, Report $report )
	{
		$report->meta['source'] = 'valitool';

		$map = array(
			'validated_at'  => 'v:checkDateTime',
			'seller'        => 'v:documentSeller',
			'invoice_number' => 'v:documentNumber',
			'invoice_date'  => 'v:documentDateTime',
			'engine'        => 'v:checkerVersionXML',
		);

		foreach ( $map as $key => $path )
		{
			$value = self::one( $xp, '/*/' . $path );

			if ( $value !== '' )
				$report->meta[ $key ] = $value;
		}

		// `documentDateTime` kommt als "20260211" - als Datum lesbar machen, sonst nicht anfassen.
		$date = (string) ( $report->meta['invoice_date'] ?? '' );

		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $date, $m ) )
			$report->meta['invoice_date'] = $m[1] . '-' . $m[2] . '-' . $m[3];

		$report->meta['document_type'] = trim(
			self::one( $xp, '/*/v:validationDetailsEmbedding/v:versionFromXML' ) . ' '
			. self::one( $xp, '/*/v:validationDetailsEmbedding/v:documentTypeFromXML' )
		);

		/**
		 * ⚠️ THE SYNTAX STANDS NOWHERE AS A FIELD - it is read from the root name of the CHECKED document,
		 * which the report carries in its XPath details. ZUGFeRD/Factur-X is always CII;
		 * a UBL document would not arrive here at all, because valitool does not check it. Still derived
		 * instead of hard-wired: hard-wired would be a claim about future versions.
		 */

		$firstPath = self::one( $xp, '(/*//v:details/v:xPath)[1]' );

		if ( $firstPath !== '' )
			$report->meta['syntax'] = stripos( $firstPath, 'CrossIndustryInvoice' ) !== false ? 'CII' : 'UBL';

		// The report states the verdict itself - do not derive it from the messages, see Report::rejected().
		$valid = self::one( $xp, '/*/v:isValid' );

		if ( $valid !== '' )
			$report->meta['compliant'] = $valid === 'true' ? 'yes' : 'no';
	}


	/**
	 * The individual checks as steps.
	 *
	 * ⚠️ VALITOOL DOES NOT COUNT PER STEP. The KoSIT report names its own error/warning count for each
	 * check step; here there is only a `true`/`false` per check. The numbers therefore stay
	 * 0, and a failed step is counted as **one** error - otherwise a zero would stand
	 * next to a step marked red in the overview.
	 *
	 * @param	DOMXPath	$xp
	 * @param	Report		$report
	 * @return	void
	 * @since	1.4.0
	 */

	private static function read_steps( DOMXPath $xp, Report $report )
	{
		$checks = array(
			'v:validationDetailsPDF/v:isValid'                  => 'PDF/A',
			'v:validationDetailsEmbedding/v:isValidFileEmbedding' => 'File embedding',
			'v:validationDetailsEmbedding/v:isValidFilename'    => 'Attachment filename',
			'v:validationDetailsEmbedding/v:isValidXMP'         => 'XMP metadata',
			'v:validationDetailsXML/v:isValidSchema'            => 'XML Schema',
			'v:validationDetailsXML/v:isValidNamespaces'        => 'Namespaces',
			'v:validationDetailsXML/v:isValidEncoding'          => 'Encoding',
			'v:validationDetailsXML/v:isValidCodelists'         => 'Code lists',
			'v:validationDetailsXML/v:isValidFormat'            => 'Format rules',
			'v:validationDetailsXML/v:isValidVAT'               => 'VAT rules',
			'v:validationDetailsXML/v:isValidBusinessRules'     => 'Business rules',
		);

		foreach ( $checks as $path => $label )
		{
			$value = self::one( $xp, '/*/' . $path );

			// ⚠️ 'undefined' occurs in the corpus ( canExtractXML ) - neither true nor false, so
			// no step. Counting an 'undefined' as an error would be an invented statement.
			if ( $value !== 'true' && $value !== 'false' )
				continue;

			$report->steps[] = array(
				'step'     => $label,
				'errors'   => $value === 'false' ? 1 : 0,
				'warnings' => 0,
				'infos'    => 0,
			);
		}
	}


	/**
	 * @param	DOMXPath	$xp
	 * @param	Report		$report
	 * @return	void
	 * @since	1.4.0
	 */

	private static function read_findings( DOMXPath $xp, Report $report )
	{
		$nodes = $xp->query( '/*//v:details' );

		if ( $nodes === false )
			return;

		foreach ( $nodes as $node )
		{
			$type = self::one( $xp, 'v:errorType', $node );

			$severity = $type === '2' ? 'error' : ( $type === '1' ? 'warning' : 'information' );

			$message = self::one( $xp, 'v:errorDescription', $node );
			$code    = self::one( $xp, 'v:errorCode', $node );

			/**
			 * ⚠️ THE DEMO VERSION NOTICE HANGS ON EVERY MESSAGE, SEPARATED BY `---`.
			 *
			 * Measured over the corpus: it stands once per report as a message of its own ( errorType 3,
			 * without a code ) AND is additionally glued onto the end of every real message. If it stayed,
			 * every location would read like a licence warning. Separated, not deleted: the part
			 * before it is the message, the part after it goes into the report as a notice - once.
			 */

			$parts = explode( '---', $message, 2 );
			$text  = trim( $parts[0] );
			$extra = isset( $parts[1] ) ? trim( $parts[1] ) : '';

			if ( $extra !== '' && ! in_array( $extra, $report->notices, true ) )
				$report->notices[] = $extra;

			// A pure notice message without text of its own ( the demo version notice itself ) - it
			// now stands in the notices and needs no location row.
			if ( $text === '' && $code === '' )
				continue;

			// ⚠️ The `[CODE]-` prefix only repeats what `errorCode` already says.
			if ( $code !== '' )
				$text = (string) preg_replace( '/^\[' . preg_quote( $code, '/' ) . '\]\s*-\s*/', '', $text );

			$fields = array();

			$bts = $xp->query( 'v:relatedBTList/v:relatedBT', $node );

			if ( $bts !== false )
				foreach ( $bts as $bt )
				{
					$id = trim( (string) $bt->getAttribute( 'ID' ) );

					if ( $id !== '' )
						$fields[] = $id;
				}

			$line = self::one( $xp, 'v:lineNumber', $node );
			$col  = self::one( $xp, 'v:columnNumber', $node );

			$report->findings[] = array(
				'position' => self::one( $xp, 'v:errorCounter', $node ),
				'rule'     => $code,
				'severity' => $severity,
				'message'  => $text,
				'path'     => self::one( $xp, 'v:xPath', $node ),
				'rows'     => array(),

				// Only this format delivers the two - see the class docblock.
				'fields'   => $fields,
				'line'     => $line === '' ? null : (int) $line,
				'column'   => $col === '' ? null : (int) $col,
			);
		}
	}


	/**
	 * A single text value, empty when not present.
	 *
	 * @param	DOMXPath	$xp
	 * @param	string		$path
	 * @param	mixed		$context
	 * @return	string
	 * @since	1.4.0
	 */

	private static function one( DOMXPath $xp, $path, $context = null )
	{
		$nodes = $context === null ? $xp->query( $path ) : $xp->query( $path, $context );

		if ( $nodes === false || $nodes->length === 0 )
			return( '' );

		return( trim( (string) $nodes->item( 0 )->textContent ) );
	}

}

endif;
