<?php
/**
 * Rule package updates: a ZIP file of new rule sets, uploaded on the settings page.
 *
 * ⚠️ NO LICENCE KEY. Rule packages are free like the engine; nothing here asks anybody whether the site may use a
 * package, and the plugin never fetches one by itself. The operator downloads the file and uploads it here.
 *
 * ⚠️ A RULE PACKAGE IS DATA, NEVER CODE. The ZIP carries rule sets ( JSON ), scenarios, XML schemas,
 * code lists, NOTICE and licence texts - and nothing else. The list of allowed paths below is the
 * guard: a ZIP with a `.php` file, a path with `..` or a file that is not named in its own manifest is
 * refused as a whole. The engine itself is only ever replaced by an update of the plugin.
 *
 * The package is built by `vampire-php/devkit/Cli/build-rule-package.php`; its layout is the same
 * as the bundled `vendor/invoicepress/vampire-php/` without `src/`, so the validator only needs a different
 * `baseDir`.
 *
 * @package SP_EInvoice_Validator_PHP
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


class SPPV_Rule_Packages
{


	/** The option that names the installed package; absent means "the bundled rule sets". */
	const OPTION = 'sppv_rule_package';

	/** The manifest's file name and the format it must declare - see build-rule-package.php. */
	const MANIFEST       = 'rule-package.json';
	const FORMAT         = 'vampire-rule-package';
	const FORMAT_VERSION = 1;

	/**
	 * Limits for one package. Measured: the eight core rule sets give 57 entries and
	 * 10.8 MB unpacked; all 18 rule sets of the library give about 20 MB. The limits leave room for
	 * growth and stop a ZIP bomb long before it fills the disk.
	 */
	const MAX_ENTRIES = 2000;
	const MAX_BYTES   = 200 * 1024 * 1024;


	/**
	 * The folder the packages live in, below the uploads folder.
	 *
	 * ⚠️ THE UPLOADS FOLDER IS PUBLIC BY DEFAULT, AND THE PACKAGES NEED NO VISITOR ACCESS. `protect()`
	 * writes an `.htaccess` that denies every request and an empty `index.php`, and the folder of each
	 * package carries a random part in its name. On nginx the `.htaccess` has no effect; the random
	 * name is then the only protection, and the readme should say so when it is written.
	 *
	 * @return	string	absolute path without a trailing slash, or '' when the uploads folder is unusable
	 * @since	09.10.2026
	 */

	public static function base_dir()
	{

		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads[ 'error' ] ) || empty( $uploads[ 'basedir' ] ) )
			return( '' );

		return( rtrim( (string) $uploads[ 'basedir' ], '/\\' ) . '/einvoice-validator-for-vampire/rule-packages' );

	}


	/**
	 * The installed package as stored, or null when the bundled rule sets are in use.
	 *
	 * @return	array|null	array{ dir: string, edition: string, created: string, installed: int, rulesets: array }
	 * @since	09.10.2026
	 */

	public static function installed()
	{

		$info = get_option( self::OPTION );

		if ( ! is_array( $info ) || empty( $info[ 'dir' ] ) )
			return( null );

		return( $info );

	}


	/**
	 * The folder of the installed package, if it is still complete on disk.
	 *
	 * ⚠️ CHECKED ON EVERY CALL, NOT TRUSTED FROM THE OPTION. A backup restore, a migration or a cleanup
	 * plugin can remove the files while the option stays. The validator then falls back to the bundled
	 * rule sets instead of failing on a missing scenarios.json for every visitor.
	 *
	 * @return	string	absolute path, or '' when no complete package is installed
	 * @since	09.10.2026
	 */

	public static function active_dir()
	{

		$info = self::installed();
		$base = self::base_dir();

		if ( $info === null || $base === '' )
			return( '' );

		// The stored name is a plain folder name; a path in it would point outside the package folder.
		$name = basename( (string) $info[ 'dir' ] );
		$dir  = $base . '/' . $name;

		return( is_file( $dir . '/rulesets/scenarios.json' ) ? $dir : '' );

	}


	/**
	 * The edition of the bundled engine.
	 *
	 * ⚠️ AN OLDER EXPORT OF THE LIBRARY HAS NO CONSTANT `VERSION`; it is then edition 1.0.0, which is what its
	 * composer.json said.
	 *
	 * @return	string
	 * @since	09.10.2026
	 */

	public static function engine_version()
	{

		return( defined( '\EinvoiceValidator\Validator::VERSION' ) ? (string) constant( '\EinvoiceValidator\Validator::VERSION' ) : '1.0.0' );

	}


	/**
	 * Install a rule package from an uploaded ZIP.
	 *
	 * The order matters and must not be shuffled: read and check the manifest, check every entry
	 * against the allowed paths and the manifest, extract into a NEW folder while checking each
	 * file's SHA-256, prove that the validator can load the scenarios from there, and only then switch
	 * the option. Until the last step the running site keeps validating with what it had.
	 *
	 * @param	string	$zip_file	path of the uploaded file ( already checked with is_uploaded_file() )
	 * @return	array				the stored package info, see installed()
	 * @throws	RuntimeException	with a translated, escaped message when the package is refused
	 * @since	09.10.2026
	 */

	public static function install( $zip_file )
	{

		if ( ! class_exists( 'ZipArchive' ) )
			throw new \RuntimeException( esc_html__( 'This server cannot open ZIP files ( the PHP extension zip is missing ). Ask your host to enable it.', 'einvoice-validator-for-vampire' ) );

		$base = self::base_dir();

		if ( $base === '' || ! wp_mkdir_p( $base ) )
			throw new \RuntimeException( esc_html__( 'The uploads folder is not writable, so the rule package cannot be stored.', 'einvoice-validator-for-vampire' ) );

		self::protect( dirname( $base ) );
		self::protect( $base );

		$zip = new \ZipArchive();

		if ( $zip->open( (string) $zip_file ) !== true )
			throw new \RuntimeException( esc_html__( 'The file is not a readable ZIP archive.', 'einvoice-validator-for-vampire' ) );

		try
		{
			$manifest = self::read_manifest( $zip );
			self::check_entries( $zip, $manifest );

			$name   = 'package-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 12, false, false ) );
			$target = $base . '/' . $name;

			self::extract( $zip, $manifest, $target );
		}
		finally
		{
			$zip->close();
		}

		try
		{
			self::smoke_test( $target );
		}
		catch ( \Throwable $e )
		{
			self::remove_tree( $target );

			$message = sprintf(
				/* translators: %s is the error message of the validator engine. */
				esc_html__( 'The rule package could not be loaded: %s', 'einvoice-validator-for-vampire' ),
				esc_html( $e->getMessage() )
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is escaped above; the flagged token is the $previous exception of the chain.
			throw new \RuntimeException( $message, 0, $e );
		}

		$previous = self::installed();

		$info = array(
			'dir'       => $name,
			'edition'   => (string) $manifest[ 'edition' ],
			'created'   => (string) $manifest[ 'created' ],
			'installed' => time(),
			'trial'     => ! empty( $manifest[ 'trial' ] ),
			'rulesets'  => self::ruleset_summary( $manifest ),
		);

		update_option( self::OPTION, $info, false );

		// ⚠️ THE OLD PACKAGE GOES ONLY AFTER THE SWITCH. A request that started a moment earlier may still
		// read from it; removing it first would fail that request with a missing file.
		if ( $previous !== null && basename( (string) $previous[ 'dir' ] ) !== $name )
			self::remove_tree( $base . '/' . basename( (string) $previous[ 'dir' ] ) );

		return( $info );

	}


	/**
	 * Back to the rule sets bundled with the plugin; the uploaded package is deleted.
	 *
	 * @return	void
	 * @since	09.10.2026
	 */

	public static function revert()
	{

		$info = self::installed();

		delete_option( self::OPTION );

		$base = self::base_dir();

		if ( $info !== null && $base !== '' )
			self::remove_tree( $base . '/' . basename( (string) $info[ 'dir' ] ) );

	}


	/**
	 * Read and check the manifest.
	 *
	 * @param	ZipArchive	$zip
	 * @return	array
	 * @throws	RuntimeException
	 * @since	09.10.2026
	 */

	private static function read_manifest( \ZipArchive $zip )
	{

		$raw  = $zip->getFromName( self::MANIFEST );
		$data = ( $raw === false ? null : json_decode( (string) $raw, true ) );

		if ( ! is_array( $data ) || ( $data[ 'format' ] ?? '' ) !== self::FORMAT )
			throw new \RuntimeException( esc_html__( 'This ZIP is not a rule package for VAMPIRE: the file rule-package.json is missing or unreadable.', 'einvoice-validator-for-vampire' ) );

		if ( (int) ( $data[ 'formatVersion' ] ?? 0 ) !== self::FORMAT_VERSION )
			throw new \RuntimeException( esc_html__( 'This rule package was made for a newer plugin. Update the plugin first, then upload the package again.', 'einvoice-validator-for-vampire' ) );

		// ⚠️ A TRIAL PACKAGE IS BUILT WITHOUT A LICENCE TEXT THAT RULE 29 REQUIRES, AND IT SAYS SO IN ITS
		// NOTICE. It must never become live on a customer's site; the constant exists for tests only.
		if ( ! empty( $data[ 'trial' ] ) && ! ( defined( 'SPPV_ALLOW_TRIAL_RULE_PACKAGES' ) && SPPV_ALLOW_TRIAL_RULE_PACKAGES ) )
			throw new \RuntimeException( esc_html__( 'This rule package is a trial build for measurements and must not be installed.', 'einvoice-validator-for-vampire' ) );

		$min = (string) ( $data[ 'engine' ][ 'minVersion' ] ?? '' );

		if ( $min === '' || version_compare( self::engine_version(), $min, '<' ) )
			throw new \RuntimeException( esc_html__( 'This rule package needs a newer validator engine than the one in this plugin. Update the plugin first, then upload the package again.', 'einvoice-validator-for-vampire' ) );

		if ( ! isset( $data[ 'files' ] ) || ! is_array( $data[ 'files' ] ) || ! isset( $data[ 'files' ][ 'rulesets/scenarios.json' ], $data[ 'files' ][ 'NOTICE' ] ) )
			throw new \RuntimeException( esc_html__( 'The rule package is incomplete: the scenario list or the NOTICE is missing.', 'einvoice-validator-for-vampire' ) );

		// ⚠️ EDITION PACKAGES WITHOUT THEIR TABLE ARE REFUSED, NOT INSTALLED HALF. Without
		// `rulesets/versions/cascade.json` the engine finds no cascade, the setting stays locked, and
		// several megabytes of rule sets would lie in the uploads folder unused and unexplained.
		if ( ! isset( $data[ 'files' ][ 'rulesets/versions/cascade.json' ] ) )
			foreach ( array_keys( $data[ 'files' ] ) as $listed )
				if ( strpos( (string) $listed, 'rulesets/versions/' ) === 0 )
					throw new \RuntimeException( esc_html__( 'The rule package is incomplete: it contains rule packages per edition, but not the table that connects them ( rulesets/versions/cascade.json ).', 'einvoice-validator-for-vampire' ) );

		$edition = (string) ( $data[ 'edition' ] ?? '' );

		if ( preg_match( '/^[0-9A-Za-z][0-9A-Za-z.\-]{0,31}$/', $edition ) !== 1 )
			throw new \RuntimeException( esc_html__( 'The rule package names no valid edition.', 'einvoice-validator-for-vampire' ) );

		return( $data );

	}


	/**
	 * Whether a path inside the ZIP may be installed at all.
	 *
	 * ⚠️ AN ALLOW LIST, NOT A DENY LIST. A deny list for `.php` misses `.phtml`, `.phar` and whatever
	 * the next server maps to PHP. Only the five kinds of data a rule package carries pass.
	 *
	 * ⚠️ THE EDITION PACKAGES OF THE CASCADE ( `rulesets/versions/` ) PASS UNDER THE SAME RULES, ONE FOLDER DEEPER:
	 * rule sets and the cascade table as `.json` directly in `versions/`, schemas and code lists as `.xsd` /
	 * `.xml` below `versions/xsd/` and `versions/codedb/`. Nothing else in `versions/` passes - no subfolder of
	 * another name, no other extension. The optional group `versions/` is written into the one pattern on
	 * purpose: a second pattern for the folder would be a second place where the extensions are listed, and the
	 * two would drift at the first change.
	 *
	 * @param	string	$path
	 * @return	bool
	 * @since	09.10.2026
	 */

	public static function allowed_path( $path )
	{

		$path = (string) $path;

		if ( $path === '' || strpos( $path, '\\' ) !== false || strpos( $path, '..' ) !== false || $path[0] === '/' || strpos( $path, ':' ) !== false )
			return( false );

		return( preg_match( '#^(NOTICE|licences/[A-Za-z0-9._-]+\.txt|rulesets/(versions/)?[A-Za-z0-9._@-]+\.json|rulesets/(versions/)?(xsd|codedb)/[A-Za-z0-9._/-]+\.(xsd|xml))$#', $path ) === 1 );

	}


	/**
	 * Every entry against the allowed paths and the manifest, and the manifest against the entries.
	 *
	 * @param	ZipArchive	$zip
	 * @param	array		$manifest
	 * @return	void
	 * @throws	RuntimeException
	 * @since	09.10.2026
	 */

	private static function check_entries( \ZipArchive $zip, array $manifest )
	{

		if ( $zip->numFiles > self::MAX_ENTRIES )
			throw new \RuntimeException( esc_html__( 'The rule package contains too many files.', 'einvoice-validator-for-vampire' ) );

		$seen  = array();
		$bytes = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ )
		{
			$stat = $zip->statIndex( $i );
			$name = is_array( $stat ) ? (string) $stat[ 'name' ] : '';

			// Directory entries carry no data; some ZIP tools write them, others do not.
			if ( $name !== '' && substr( $name, -1 ) === '/' )
				continue;

			if ( $name === self::MANIFEST )
				continue;

			if ( ! self::allowed_path( $name ) || ! isset( $manifest[ 'files' ][ $name ] ) )
			{
				$message = sprintf(
					/* translators: %s is a path inside the ZIP archive, e.g. "rulesets/x.json". */
					esc_html__( 'The rule package contains a file that does not belong in it: %s', 'einvoice-validator-for-vampire' ),
					esc_html( $name )
				);

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is escaped above.
				throw new \RuntimeException( $message );
			}

			$bytes       += (int) $stat[ 'size' ];
			$seen[ $name ] = true;
		}

		if ( $bytes > self::MAX_BYTES )
			throw new \RuntimeException( esc_html__( 'The rule package is too large.', 'einvoice-validator-for-vampire' ) );

		foreach ( array_keys( $manifest[ 'files' ] ) as $listed )
			if ( ! isset( $seen[ (string) $listed ] ) )
			{
				$message = sprintf(
					/* translators: %s is a path inside the ZIP archive, e.g. "rulesets/x.json". */
					esc_html__( 'The rule package is incomplete. Missing: %s', 'einvoice-validator-for-vampire' ),
					esc_html( (string) $listed )
				);

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is escaped above.
				throw new \RuntimeException( $message );
			}

	}


	/**
	 * Unpack into a new folder, file by file, each one checked against its SHA-256.
	 *
	 * ⚠️ NOT `ZipArchive::extractTo()`. It writes whatever paths the archive names, and the checks
	 * above would then be the only thing between an archive and the file system. Writing each checked
	 * entry ourselves keeps the decision where the check is.
	 *
	 * @param	ZipArchive	$zip
	 * @param	array		$manifest
	 * @param	string		$target
	 * @return	void
	 * @throws	RuntimeException
	 * @since	09.10.2026
	 */

	private static function extract( \ZipArchive $zip, array $manifest, $target )
	{

		if ( ! wp_mkdir_p( $target ) )
			throw new \RuntimeException( esc_html__( 'The uploads folder is not writable, so the rule package cannot be stored.', 'einvoice-validator-for-vampire' ) );

		foreach ( $manifest[ 'files' ] as $name => $sha256 )
		{
			$data = $zip->getFromName( (string) $name );

			if ( $data === false || ! hash_equals( strtolower( (string) $sha256 ), hash( 'sha256', $data ) ) )
			{
				self::remove_tree( $target );

				$message = sprintf(
					/* translators: %s is a path inside the ZIP archive, e.g. "rulesets/x.json". */
					esc_html__( 'A file of the rule package is damaged: %s. Download the package again.', 'einvoice-validator-for-vampire' ),
					esc_html( (string) $name )
				);

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is escaped above.
				throw new \RuntimeException( $message );
			}

			$file = $target . '/' . $name;

			if ( ! wp_mkdir_p( dirname( $file ) ) || file_put_contents( $file, $data ) === false )
			{
				self::remove_tree( $target );
				throw new \RuntimeException( esc_html__( 'The uploads folder is not writable, so the rule package cannot be stored.', 'einvoice-validator-for-vampire' ) );
			}
		}

	}


	/**
	 * Prove that the engine can work with the unpacked folder before anybody relies on it.
	 *
	 * ⚠️ A DOCUMENT THAT MATCHES NO SCENARIO IS ENOUGH HERE, AND IT IS DELIBERATE. The run loads and
	 * evaluates the scenario list of the package; every rule set is loaded later, when the first invoice
	 * of its kind arrives. Each rule set file was checked against its SHA-256 above, so a broken one
	 * cannot get this far.
	 *
	 * @param	string	$dir
	 * @return	void
	 * @throws	Throwable
	 * @since	09.10.2026
	 */

	private static function smoke_test( $dir )
	{

		$resolver  = \EinvoiceValidator\Pipeline\ScenarioResolver::fromJsonFile( $dir . '/rulesets/scenarios.json' );
		$validator = new \EinvoiceValidator\Validator( scenarioResolver: $resolver, baseDir: $dir );

		$validator->validateString( '<?xml version="1.0" encoding="UTF-8"?><rulePackageCheck/>' );

		if ( is_file( $dir . '/rulesets/versions/cascade.json' ) )
			self::smoke_test_versions( $dir );

	}


	/**
	 * The same proof for the edition packages: every file the cascade table names is in the package.
	 *
	 * ⚠️ THE TABLE IS CHECKED HERE, AND NOT ONLY WHEN THE FIRST ZUGFeRD INVOICE ARRIVES. Each file passed its
	 * checksum, but the checksum only proves that the file is the one the manifest lists, not that the
	 * manifest lists every file the table names. A table naming `ZUGFERD-EXTENDED-CII@2.4` without that
	 * rule set would let the cascade report "no verdict" for edition 2.4 on every visitor's invoice.
	 *
	 * ⚠️ A SCHEMA PATH FROM THE TABLE GOES THROUGH allowed_path() AS WELL. The engine reads `xsdPath`
	 * relative to the package folder; a path with `..` in a table would make it read outside the package,
	 * although no such file was ever unpacked.
	 *
	 * ⚠️ THE ENGINE OF THIS PLUGIN MAY NOT KNOW THE CASCADE YET. The package is then still installed, because
	 * it is data that a later engine reads, and the setting "Check against every edition" stays locked
	 * ( SPPV_Validator::supports_cascade() ). Only an engine that has the class also checks that it can
	 * read the table.
	 *
	 * @param	string	$dir
	 * @return	void
	 * @throws	RuntimeException
	 * @since	09.10.2026
	 */

	private static function smoke_test_versions( $dir )
	{

		$table = json_decode( (string) file_get_contents( $dir . '/rulesets/versions/cascade.json' ), true );

		// ⚠️ The messages are translated but not escaped here: install() escapes the whole message once.
		if ( ! is_array( $table ) || ! is_array( $table[ 'families' ] ?? null ) )
			throw new \RuntimeException( __( 'The table of the rule packages per edition is unreadable.', 'einvoice-validator-for-vampire' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped by install().

		foreach ( $table[ 'families' ] as $family )
			foreach ( (array) ( $family[ 'versions' ] ?? array() ) as $edition )
				foreach ( (array) ( $edition[ 'scenarios' ] ?? array() ) as $scenario )
				{
					foreach ( (array) ( $scenario[ 'rulesetRefs' ] ?? array() ) as $ref )
					{
						// ⚠️ TWO SPELLINGS, AS IN THE ENGINE ( `Validator::resolveRulesetFile()` ): the export writes
						// `ZUGFERD-MINIMUM-CII-2.5.2.json` with a dash, because Plugin Check refuses an `@` in a file
						// name, while the table keeps the reference `ZUGFERD-MINIMUM-CII@2.5.2`. Measured:
						// a check for the literal name alone refused every package with versions/.
						$found = false;

						foreach ( array_unique( array( (string) $ref, str_replace( '@', '-', (string) $ref ) ) ) as $spelling )
						{
							$file = 'rulesets/versions/' . $spelling . '.json';

							if ( self::allowed_path( $file ) && is_file( $dir . '/' . $file ) )
								$found = true;
						}

						/*
						 * ⚠️ THE MESSAGE FIRST, THEN A ONE-LINE `throw`. A `phpcs:ignore` covers only the line directly below
						 * it; above a `throw new …( sprintf(` spread over five lines it covered the `throw` and not the two
						 * argument lines the sniff flags ( measured with Plugin Check: four errors here ).
						 * Not escaped here, because install() escapes every message once where it prints it.
						 */
						if ( ! $found )
						{
							$message = sprintf(
								/* translators: %s is the name of a rule set with its edition, e.g. "ZUGFERD-EXTENDED-CII@2.4". */
								__( 'The table of the rule packages per edition names a rule set that is not in the package: %s', 'einvoice-validator-for-vampire' ),
								(string) $ref
							);

							// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped by install().
							throw new \RuntimeException( $message );
						}
					}

					$xsd = (string) ( $scenario[ 'xsdPath' ] ?? '' );

					if ( $xsd !== '' && ( ! self::allowed_path( $xsd ) || ! is_file( $dir . '/' . $xsd ) ) )
					{
						$message = sprintf(
							/* translators: %s is a path inside the rule package, e.g. "rulesets/versions/xsd/zugferd-2.4/basic/FACTUR-X_BASIC.xsd". */
							__( 'The table of the rule packages per edition names a schema that is not in the package: %s', 'einvoice-validator-for-vampire' ),
							$xsd
						);

						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped by install().
						throw new \RuntimeException( $message );
					}
				}

		// The engine's own check of the table: an unknown `cascadeVersion` throws in the constructor.
		if ( class_exists( '\EinvoiceValidator\Cascade\Cascade' ) )
			\EinvoiceValidator\Cascade\Cascade::fromPackage( $dir );

	}


	/**
	 * What the settings page shows of a package: rule set, edition and licence.
	 *
	 * @param	array	$manifest
	 * @return	array	list of array{ id: string, version: string, license: string }
	 * @since	09.10.2026
	 */

	private static function ruleset_summary( array $manifest )
	{

		$out = array();

		foreach ( (array) ( $manifest[ 'rulesets' ] ?? array() ) as $r )
			if ( is_array( $r ) )
				$out[] = array(
					'id'      => (string) ( $r[ 'id' ] ?? '' ),
					'version' => (string) ( $r[ 'version' ] ?? '' ),
					'license' => (string) ( $r[ 'license' ] ?? '' ),
				);

		return( $out );

	}


	/**
	 * The rule sets in a folder, read from the head of each file - for the bundled rule sets, which
	 * have no manifest.
	 *
	 * ⚠️ ONLY THE FIRST 16 KB. The largest rule set is about 24 MB; decoding all of them to show a
	 * table on the settings page would cost a second and a lot of memory on every visit.
	 *
	 * @param	string	$dir	the base folder ( with rulesets/ below it )
	 * @return	array	list of array{ id: string, version: string, license: string }
	 * @since	09.10.2026
	 */

	public static function read_rulesets( $dir )
	{

		$out = array();

		foreach ( glob( rtrim( (string) $dir, '/\\' ) . '/rulesets/*.json' ) ?: array() as $file )
		{
			$name = basename( $file );

			if ( $name === 'scenarios.json' || $name === 'field-bindings.json' )
				continue;

			$head = (string) file_get_contents( $file, false, null, 0, 16384 );
			$pick = static function ( $key ) use ( $head )
			{
				return( preg_match( '/"' . $key . '"\s*:\s*"([^"]*)"/', $head, $m ) === 1 ? $m[1] : '' );
			};

			$out[] = array( 'id' => $pick( 'id' ), 'version' => $pick( 'version' ), 'license' => $pick( 'license' ) );
		}

		return( $out );

	}


	/**
	 * Deny web access to a folder: `.htaccess` for Apache, an empty `index.php` against listings.
	 *
	 * Public, because the folder of the rule profiles ( SPPV_Rule_Profiles ) needs the same.
	 *
	 * @param	string	$dir
	 * @return	void
	 * @since	09.10.2026
	 */

	public static function protect( $dir )
	{

		if ( ! is_file( $dir . '/.htaccess' ) )
			file_put_contents( $dir . '/.htaccess', "# eInvoice Validator for VAMPIRE PHP: rule packages and rule profiles are not for download.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );

		if ( ! is_file( $dir . '/index.php' ) )
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );

	}


	/**
	 * Delete a package folder and everything in it.
	 *
	 * ⚠️ ONLY BELOW base_dir(). The path is built from a stored option, and a damaged option must never
	 * turn this into a delete somewhere else.
	 *
	 * @param	string	$dir
	 * @return	void
	 * @since	09.10.2026
	 */

	private static function remove_tree( $dir )
	{

		$base = self::base_dir();
		$real = realpath( (string) $dir );
		$root = ( $base === '' ? false : realpath( $base ) );

		if ( $real === false || $root === false || strpos( $real, $root . DIRECTORY_SEPARATOR ) !== 0 )
			return;

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		/*
		 * ⚠️ `wp_delete_file()` FOR FILES, PLAIN `rmdir()` FOR THE THEN EMPTY FOLDERS. WordPress has no wrapper for a
		 * folder outside WP_Filesystem, and WP_Filesystem may ask the operator for FTP credentials on some hosts - in
		 * the middle of a rule package switch that has already taken effect. The folder lies under uploads/, and this
		 * plugin created every file in it.
		 */
		foreach ( $items as $item )
		{
			if ( ! $item->isDir() )
			{
				wp_delete_file( $item->getPathname() );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- see the comment above.
			rmdir( $item->getPathname() );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- see the comment above.
		rmdir( $real );

	}


}
