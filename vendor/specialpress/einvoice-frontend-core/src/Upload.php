<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Decides whether an uploaded file may be validated at all.
 *
 * @package SpecialPress\EInvoice\FrontendCore
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\FrontendCore;

// Same direct-access guard the sibling packages use.
if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! class_exists( __NAMESPACE__ . '\\Upload' ) ) :

/**
 * class: Upload
 *
 * ============================================================================================
 * Whether an uploaded file may be checked, decided once for every frontend
 * ============================================================================================
 *
 * Four frontend plugins make the same acceptance decision. Writing it once is not only less work: an
 * acceptance rule that stands in four places is forgotten in three of them as soon as it changes.
 *
 * ⚠️ **THIS CLASS READS NO FILE AND KNOWS NO WORDPRESS.** It is handed the `$_FILES` entry and the limit
 * and returns a verdict. That way it can be tested without WordPress, which is impossible for code that
 * mixes checking, reading and passing on in one method.
 *
 * ⚠️ **`is_uploaded_file()` STAYS WITH THE CALLER.** It is a statement about the current request, not
 * about the data; in a test it would always be `false`, and a class that behaves differently in real
 * use than in a test is not a tested class. The caller checks it and passes it in as `$is_uploaded`.
 *
 * @since 1.0.0
 */

class Upload
{


	/** What the acceptance check can reject: stable identifiers, so that the caller can translate them. */
	const OK            = 'ok';
	const NO_FILE       = 'no-file';
	const UPLOAD_FAILED = 'upload-failed';
	const TOO_LARGE     = 'too-large';
	const NOT_XML       = 'not-xml';
	const EMPTY_FILE    = 'empty';


	/**
	 * May this file be checked?
	 *
	 * @param	array	$file			The entry from `$_FILES` ( name, size, error, tmp_name ).
	 * @param	int		$max_bytes		Upper limit in bytes.
	 * @param	bool	$is_uploaded	Result of `is_uploaded_file()` at the caller.
	 * @param	string	$mime			Result of `finfo_file()` at the caller, '' if it cannot be determined.
	 * @return	string					One of the constants above.
	 * @since	1.0.0
	 */

	public static function accept( array $file, $max_bytes, $is_uploaded, $mime = '' )
	{
		if ( ! $is_uploaded || trim( (string) ( $file['tmp_name'] ?? '' ) ) === '' )
			return( self::NO_FILE );

		// ⚠️ A MISSING `error` IS AN ERROR, NOT "all fine". PHP always sets the field; when it is missing,
		// the entry does not come from a real upload.
		if ( ! isset( $file['error'] ) || (int) $file['error'] !== UPLOAD_ERR_OK )
			return( self::UPLOAD_FAILED );

		if ( isset( $file['size'] ) && (int) $file['size'] > (int) $max_bytes )
			return( self::TOO_LARGE );

		if ( isset( $file['size'] ) && (int) $file['size'] === 0 )
			return( self::EMPTY_FILE );

		if ( ! self::looks_like_xml( (string) ( $file['name'] ?? '' ), (string) $mime ) )
			return( self::NOT_XML );

		return( self::OK );
	}


	/**
	 * Extension and MIME type together.
	 *
	 * ⚠️ **AN EMPTY MIME TYPE IS ALLOWED.** `finfo` is not available on every web space; making it
	 * mandatory would switch the tool off exactly where it is needed. The extension stays the hard
	 * condition, the MIME type the additional one.
	 *
	 * ⚠️ **`text/plain` BELONGS IN THE LIST.** `finfo` reports it for an XML file without an XML
	 * declaration in its first line, and such files exist in the corpus. Without this entry the
	 * frontend would reject valid documents with the misleading message "please choose an XML file".
	 *
	 * @param	string	$name
	 * @param	string	$mime
	 * @return	bool
	 * @since	1.0.0
	 */

	public static function looks_like_xml( $name, $mime )
	{
		$ext = strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );

		if ( $ext !== 'xml' )
			return( false );

		return( in_array( (string) $mime, array( 'application/xml', 'text/xml', 'text/plain', '' ), true ) );
	}

}

endif;
