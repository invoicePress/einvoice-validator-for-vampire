<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Newest copy wins: an autoloader in front of every Composer autoloader of the site.
 *
 * Every plugin of the family brings its own `vendor/` with its own copy of the shared packages ( einvoice-model,
 * einvoice-report, einvoice-identifiers, … ). Composer registers each plugin's loader in front of the earlier ones
 * ( `register( true )` ), so without this file the copy of the plugin that loads LAST defines every shared class for all
 * of them, however old it is. A customer who updates one plugin and keeps an older one then runs whichever copy happens
 * to load last, and a method the new plugin relies on can be missing: a fatal error for the whole site.
 *
 * This loader asks every registered Composer loader for the file of a class and takes the file from the copy whose
 * package carries the highest version. It is included by each plugin's `vendor/autoload.php` right after Composer's own
 * loader ( `_claude-tools/autoload-newest-sync.php` writes the line ), and it moves itself to the front again every time.
 *
 * ⚠️ **THE FUNCTIONS CARRY THEIR VERSION IN THE NAME AND NEVER CHANGE.** The first copy that loads defines them; a later
 * copy with a changed body would never run. A truly new behaviour gets new names ( `…_v2` ) and runs next to this one.
 *
 * ⚠️ **THE VERSION COMES FROM THE PACKAGE'S OWN `composer.json` FIRST.** `_claude-tools/php-lib-sync.php` copies a changed
 * library into the plugins without running Composer, so `vendor/composer/installed.php` can name an older version than
 * the files carry. For third-party packages, which only Composer installs, `installed.php` is right.
 *
 * ⚠️ **ONLY AS GOOD AS THE VERSION NUMBERS.** Two copies with the same version and different code are a tie, and the copy
 * registered first wins. `build-release.php` therefore stops when a shared library changed without a new version.
 *
 * ⚠️ **NOT BEFORE THE PLUGINS HAVE LOADED.** A class requested while the plugin files are still being included is chosen
 * among the copies registered so far. Shared classes belong in hooks from `plugins_loaded` on, as with einvoice-host-wp.
 *
 * @package SpecialPress\EInvoice\Autoload
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) )
	return;

if ( ! function_exists( 'einvoice_autoload_newest_v1' ) )
{
	/**
	 * The version of the package that holds a file of a vendor folder, or '' when it is unknown.
	 *
	 * @param	string	$vendorDir
	 * @param	string	$file
	 * @return	string
	 */
	function einvoice_autoload_version_v1( $vendorDir, $file )
	{
		static $cache = array();

		// ⚠️ Both sides through realpath(): Composer hands out paths such as "vendor/composer/../specialpress/…".
		$vendorDir = rtrim( str_replace( '\\', '/', (string) ( realpath( (string) $vendorDir ) ?: $vendorDir ) ), '/' );
		$rel       = substr( str_replace( '\\', '/', (string) $file ), strlen( $vendorDir ) + 1 );
		$parts     = explode( '/', (string) $rel );

		if ( count( $parts ) < 3 )
			return( '' );

		$package = $parts[ 0 ] . '/' . $parts[ 1 ];
		$key     = $vendorDir . '|' . $package;

		if ( isset( $cache[ $key ] ) )
			return( $cache[ $key ] );

		$version = '';
		$json    = $vendorDir . '/' . $package . '/composer.json';

		if ( is_readable( $json ) )
		{
			$data = json_decode( (string) file_get_contents( $json ), true );

			if ( is_array( $data ) && isset( $data[ 'version' ] ) && is_string( $data[ 'version' ] ) )
				$version = ltrim( $data[ 'version' ], 'v' );
		}

		if ( $version === '' && is_readable( $vendorDir . '/composer/installed.php' ) )
		{
			$installed = include( $vendorDir . '/composer/installed.php' );

			if ( is_array( $installed ) && isset( $installed[ 'versions' ][ $package ][ 'version' ] ) )
				$version = (string) $installed[ 'versions' ][ $package ][ 'version' ];
		}

		return( $cache[ $key ] = $version );
	}

	/**
	 * The autoloader: the file of the class from the copy with the highest package version.
	 *
	 * @param	string	$class
	 * @return	void
	 */
	function einvoice_autoload_newest_v1( $class )
	{
		if ( ! class_exists( '\\Composer\\Autoload\\ClassLoader', false ) || ! method_exists( '\\Composer\\Autoload\\ClassLoader', 'getRegisteredLoaders' ) )
			return;

		$best     = '';
		$bestVers = null;

		foreach ( \Composer\Autoload\ClassLoader::getRegisteredLoaders() as $vendorDir => $loader )
		{
			$file = $loader->findFile( $class );

			if ( ! is_string( $file ) || $file === '' || ! is_file( $file ) || filesize( $file ) === 0 )
				continue;

			$version = einvoice_autoload_version_v1( $vendorDir, (string) realpath( $file ) ?: $file );

			// ⚠️ Strictly greater: on a tie the copy registered first keeps the class, as Composer alone would not.
			if ( $bestVers === null || ( $version !== '' && version_compare( $version, $bestVers, '>' ) ) )
			{
				$best     = $file;
				$bestVers = $version;
			}
		}

		if ( $best !== '' )
			einvoice_autoload_include_v1( $best );
	}

	/**
	 * Includes a file without access to the variables of the autoloader.
	 *
	 * @param	string	$file
	 * @return	void
	 */
	function einvoice_autoload_include_v1( $file )
	{
		include_once( $file );
	}

	/**
	 * Puts the autoloader in front of all others again.
	 *
	 * @return	void
	 */
	function einvoice_autoload_front_v1()
	{
		spl_autoload_unregister( 'einvoice_autoload_newest_v1' );
		spl_autoload_register( 'einvoice_autoload_newest_v1', true, true );
	}

	// ⚠️ After every plugin file: a plugin loaded later, also one built before this file existed, registers its Composer
	// loader in front of this one; `plugin_loaded` ( WordPress 5.1 ) and `plugins_loaded` put it back to the front.
	if ( function_exists( 'add_action' ) )
	{
		add_action( 'plugin_loaded', 'einvoice_autoload_front_v1', PHP_INT_MIN );
		add_action( 'network_plugin_loaded', 'einvoice_autoload_front_v1', PHP_INT_MIN );
		add_action( 'plugins_loaded', 'einvoice_autoload_front_v1', PHP_INT_MIN );
	}
}

einvoice_autoload_front_v1();
