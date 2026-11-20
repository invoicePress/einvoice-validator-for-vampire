<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The loader of einvoice-host-wp: every copy announces itself, and each class file is loaded from the newest copy that has it.
 *
 * A plugin of the family includes this file from its own copy of the library, at load time, and uses the classes of the
 * library from `plugins_loaded` on, never earlier:
 *
 *     require_once( __DIR__ . '/libs/einvoice-host-wp/load.php' );
 *     add_action( 'plugins_loaded', static function () { \SpecialPress\EInvoice\Host\ServiceRoutes::init(); } );
 *
 * ⚠️ **WHY NOT `require_once` OF EVERY CLASS, AS UP TO 1.8.0.** Each plugin brings its own copy, and the copy that
 * loads first defines the classes for all of them, alphabetically by plugin folder. On 26.10.2026, 21 of 30 copies
 * differed ( `STATUS.md` ), so a site with an old plugin next to a new one ran the old classes, and a method the new
 * plugin relied on was missing. With this loader the newest copy wins, whatever loads first.
 *
 * ⚠️ **PER FILE, NOT PER COPY ( `…_v2`, from 2.5.1 ).** Since 05.11.2026 a release ZIP carries only the files of the library
 * its plugin calls ( Ralf: every package ships only what it uses; `spx_prune_host_wp()` in `_claude-tools/build-release.php` ).
 * A validator brings no `Store.php`. The loader of 2.0.0 took the whole folder of the newest copy, and a validator's slim copy
 * would then have left the viewer without its data store. Now every file of `src/` comes from the newest copy that has it,
 * and a file no copy has stays unloaded, which is right, because no active plugin calls it.
 *
 * ⚠️ **THE FUNCTIONS BELOW CARRY THEIR VERSION IN THE NAME AND NEVER CHANGE.** They are defined by the first copy that
 * loads; a later copy with a changed body would never run. Whatever must change goes into the classes, which come from
 * the newest copy. A truly new loader gets new function names ( `…_v3` ) and runs next to this one. A plugin built before
 * 05.11.2026 still runs the `…_v1` functions of its own copy; both loaders use `class_exists()` guards of the classes,
 * so a class is defined once, by whichever loader runs first.
 *
 * ⚠️ **NOTHING OF THE LIBRARY BEFORE `plugins_loaded`.** Until every plugin has announced its copy, nobody knows which one
 * is the newest. A plugin that calls a class of the library in its main file would load whichever copy happens to be
 * first, and the choice would be lost for the whole request.
 *
 * @package SpecialPress\EInvoice\Host
 * @since 2.0.0
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;

if ( ! function_exists( 'einvoice_host_wp_announce_v2' ) )
{
	/**
	 * Notes one copy of the library.
	 *
	 * @param	string	$version
	 * @param	string	$dir	the folder of that copy, with this file in it
	 * @return	void
	 */
	function einvoice_host_wp_announce_v2( $version, $dir )
	{
		$GLOBALS[ 'einvoice_host_wp_v2' ][] = array( 'version' => (string) $version, 'dir' => rtrim( (string) $dir, '/\\' ) );
	}

	/**
	 * Loads every class file once, each from the newest copy that has it; for two copies of the same version the one that
	 * announced first. Runs on `plugins_loaded` before every plugin; also callable directly by a test harness.
	 *
	 * @return	string	the newest version announced
	 */
	function einvoice_host_wp_boot_v2()
	{
		static $loaded = null;

		if ( $loaded !== null )
			return( $loaded );

		$copies = (array) ( $GLOBALS[ 'einvoice_host_wp_v2' ] ?? array() );

		if ( ! $copies )
			return( $loaded = '' );

		// ⚠️ A stable sort: copies of the same version keep the order in which they announced themselves.
		$order = array_keys( $copies );
		usort( $order, static function ( $a, $b ) use ( $copies ) {
			$c = version_compare( $copies[ $b ][ 'version' ], $copies[ $a ][ 'version' ] );
			return( $c !== 0 ? $c : $a <=> $b );
		} );

		$pick = array();

		foreach ( $order as $i )
			foreach ( (array) glob( $copies[ $i ][ 'dir' ] . '/src/*.php' ) as $file )
			{
				$name = basename( (string) $file );

				if ( ! isset( $pick[ $name ] ) )
					$pick[ $name ] = $file;
			}

		foreach ( $pick as $file )
			require_once( $file );

		$loaded = $copies[ $order[ 0 ] ][ 'version' ];

		do_action( 'einvoice_host_wp_loaded', $loaded );

		return( $loaded );
	}

	if ( function_exists( 'add_action' ) )
		add_action( 'plugins_loaded', 'einvoice_host_wp_boot_v2', -1000 );
}

einvoice_host_wp_announce_v2( '2.5.2', __DIR__ );
