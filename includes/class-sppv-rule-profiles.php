<?php
/**
 * Rule profiles from the Rule Studio: uploaded on the settings page, stored below the uploads folder and
 * applied by the validator on top of the official rule sets.
 *
 * A rule profile adds the operator's own rules, switches official rules off, replaces them or changes their
 * severity. The engine marks every finding of a profile with its name, and the report shows it as a rule of
 * that profile, never as a rule of the standard ( `einvoice-report`, FieldPlacement::profiles() ).
 *
 * ⚠️ A PROFILE IS DATA, NEVER CODE. The Rule Studio exports `<name>.json` for this side and `<name>.js` for the
 * apps. Both are accepted here; a `.js` file is read with `RuleProfileLoader::parseScript()`, which matches it
 * character by character against the one fixed form the Studio writes and never executes it. Every upload is
 * stored as `<name>.json`, so the folder holds one form only.
 *
 * ⚠️ A PROFILE THAT CANNOT BE LOADED IS NEVER LEFT OUT SILENTLY. The engine throws for a broken profile on
 * purpose: a missing profile would let the operator believe its rules had run. `load()` passes that on, and
 * SPPV_Validator turns it into a visible notice above the report ( "checked without the rule profiles" ).
 *
 * @package SP_EInvoice_Validator_PHP
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


class SPPV_Rule_Profiles
{


	/** The option that holds the random part of the folder name ( see dir() ). */
	const OPTION_KEY = 'sppv_rule_profiles_key';


	/**
	 * The folder of the profiles, below the uploads folder.
	 *
	 * ⚠️ THE FOLDER NAME CARRIES A RANDOM PART. A profile can name the operator's suppliers, amounts and
	 * internal rules. `SPPV_Rule_Packages::protect()` writes the `.htaccess` that denies every request; on nginx
	 * that file has no effect, and the random name is then the only protection.
	 *
	 * @param	bool	$create		create and protect the folder when it is missing
	 * @return	string	absolute path without a trailing slash, or '' when the uploads folder is unusable
	 * @since	13.10.2026
	 */

	public static function dir( $create = false )
	{

		$base = SPPV_Rule_Packages::base_dir();

		if ( $base === '' )
			return( '' );

		$key = (string) get_option( self::OPTION_KEY, '' );

		if ( $key === '' )
		{
			if ( ! $create )
				return( '' );

			$key = strtolower( wp_generate_password( 12, false, false ) );
			update_option( self::OPTION_KEY, $key, false );
		}

		// ⚠️ dirname( $base ): the profiles sit NEXT TO the rule packages, not inside. Reverting to the bundled
		// rule packages deletes that folder's content, and the profiles must survive it.
		$dir = dirname( $base ) . '/rule-profiles-' . $key;

		if ( $create && ! is_dir( $dir ) )
		{
			wp_mkdir_p( $dir );
			SPPV_Rule_Packages::protect( dirname( $base ) );
			SPPV_Rule_Packages::protect( $dir );
		}

		return( is_dir( $dir ) ? $dir : '' );

	}


	/**
	 * The profile files of the folder, sorted by name; the order is the order the engine applies them in.
	 *
	 * @return	string[]	absolute paths
	 * @since	13.10.2026
	 */

	public static function files()
	{

		$dir = self::dir();

		if ( $dir === '' )
			return( array() );

		$files = glob( $dir . '/*.json' );
		$files = is_array( $files ) ? $files : array();

		sort( $files );

		return( $files );

	}


	/**
	 * Every profile of the folder with its state, for the settings page.
	 *
	 * @return	array	list of array{ file: string, name: string, ref: string, title: string, syntax: string, rules: int, disabled: int, extends: string[], error: string }
	 * @since	13.10.2026
	 */

	public static function overview()
	{

		$out = array();

		foreach ( self::files() as $file )
		{
			$row = array( 'file' => basename( $file ), 'name' => basename( $file, '.json' ), 'ref' => '', 'title' => '', 'syntax' => '', 'rules' => 0, 'disabled' => 0, 'extends' => array(), 'error' => '' );

			try
			{
				$p = \EinvoiceValidator\Profile\RuleProfileLoader::fromFile( $file );

				$row[ 'ref' ]      = $p->ref;
				$row[ 'title' ]    = (string) $p->title;
				$row[ 'syntax' ]   = strtoupper( $p->syntax );
				$row[ 'rules' ]    = count( $p->rules );
				$row[ 'disabled' ] = count( $p->disable );

				foreach ( $p->extends as $e )
					$row[ 'extends' ][] = trim( (string) ( $e[ 'ruleset' ] ?? '' ) . ' ' . (string) ( $e[ 'versions' ] ?? '' ) );
			}
			catch ( \Throwable $e )
			{
				$row[ 'error' ] = $e->getMessage();
			}

			$out[] = $row;
		}

		return( $out );

	}


	/**
	 * The profiles for the engine.
	 *
	 * ⚠️ `fromDirectory()` READS AN `index.json` FIRST WHEN THERE IS ONE, and then ONLY the profiles it lists.
	 * This plugin never writes such a list, and install() removes one that was copied in by hand; otherwise a
	 * profile uploaded later would sit in the folder and never run.
	 *
	 * @return	array	list of \EinvoiceValidator\Profile\RuleProfile; empty without a profile
	 * @throws	\EinvoiceValidator\Exception\RuleProfileException	when a profile in the folder is refused
	 * @since	13.10.2026
	 */

	public static function load()
	{

		if ( self::files() === array() )
			return( array() );

		return( \EinvoiceValidator\Profile\RuleProfileLoader::fromDirectory( self::dir() ) );

	}


	/**
	 * Check an uploaded profile and store it as `<name>.json`; a profile of the same name is replaced.
	 *
	 * @param	string	$tmp_file		the uploaded file
	 * @param	string	$original_name	its name on the operator's computer; only the extension is used
	 * @return	string					`name@version` of the stored profile
	 * @throws	\RuntimeException		with an escaped message when the file is refused
	 * @since	13.10.2026
	 */

	public static function install( $tmp_file, $original_name )
	{

		$ext = strtolower( (string) pathinfo( (string) $original_name, PATHINFO_EXTENSION ) );

		if ( $ext !== 'json' && $ext !== 'js' )
			throw new \RuntimeException( esc_html__( 'A rule profile is a .json or .js file exported from the Rule Studio.', 'einvoice-validator-for-vampire' ) );

		if ( (int) filesize( $tmp_file ) > \EinvoiceValidator\Profile\RuleProfileValidator::LIMIT_BYTES )
			throw new \RuntimeException( esc_html__( 'The file is too large to be a rule profile.', 'einvoice-validator-for-vampire' ) );

		$text = (string) file_get_contents( $tmp_file );

		// ⚠️ The same BOM the engine strips: an editor on Windows writes one, and json_decode() fails on it.
		if ( strpos( $text, "\xEF\xBB\xBF" ) === 0 )
			$text = substr( $text, 3 );

		try
		{
			// ⚠️ The list of profiles the Studio writes beside the profile ( index.js ) is not a profile. Its
			// script registers "index", which parseScript() refuses with a sentence nobody would understand.
			if ( $ext === 'js' && strpos( $text, '"vampire/profiles/index.json"' ) !== false )
				throw new \RuntimeException( esc_html__( 'This is the list of profiles ( index.js ), not a profile. Upload the file named after the profile instead.', 'einvoice-validator-for-vampire' ) );

			$json    = $ext === 'js' ? \EinvoiceValidator\Profile\RuleProfileLoader::parseScript( $text ) : $text;
			$profile = \EinvoiceValidator\Profile\RuleProfileValidator::validate( $json );
		}
		catch ( \EinvoiceValidator\Exception\RuleProfileException $e )
		{
			throw new \RuntimeException( esc_html( sprintf(
				/* translators: %s is the engine's English reason, e.g. "rules[2].assert: unknown function". */
				__( 'The rule profile was refused: %s', 'einvoice-validator-for-vampire' ),
				$e->getMessage()
			) ) );
		}

		$dir = self::dir( true );

		if ( $dir === '' )
			throw new \RuntimeException( esc_html__( 'The uploads folder cannot be written, so the profile cannot be stored.', 'einvoice-validator-for-vampire' ) );

		// See load(): a hand-made list would hide every profile it does not name.
		foreach ( array( 'index.json', 'index.js' ) as $list )
			if ( is_file( $dir . '/' . $list ) )
				wp_delete_file( $dir . '/' . $list );

		// ⚠️ The file name is the profile name; RuleProfileLoader::fromFile() refuses any other.
		if ( file_put_contents( $dir . '/' . $profile->name . '.json', $json ) === false )
			throw new \RuntimeException( esc_html__( 'The uploads folder cannot be written, so the profile cannot be stored.', 'einvoice-validator-for-vampire' ) );

		return( $profile->ref );

	}


	/**
	 * Delete one profile.
	 *
	 * @param	string	$name	the profile name, as in the file name
	 * @return	bool			true when a file was deleted
	 * @since	13.10.2026
	 */

	public static function delete( $name )
	{

		$name = (string) $name;
		$dir  = self::dir();

		// ⚠️ The pattern of the engine, not sanitize_file_name(): only a name the engine accepts can be in the
		// folder, and nothing else may reach the path below - no dot, no slash.
		if ( $dir === '' || preg_match( \EinvoiceValidator\Profile\RuleProfileValidator::NAME_PATTERN, $name ) !== 1 )
			return( false );

		$file = $dir . '/' . $name . '.json';

		if ( ! is_file( $file ) )
			return( false );

		wp_delete_file( $file );

		return( ! is_file( $file ) );

	}


}
