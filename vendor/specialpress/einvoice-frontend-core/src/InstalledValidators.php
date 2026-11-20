<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Which validator plugins are installed on this site, and how a browser reaches them.
 *
 * @package SpecialPress\EInvoice\FrontendCore
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\FrontendCore;

// Same direct-access guard the sibling packages use.
if ( ! defined( 'ABSPATH' ) )
	exit;


if ( ! class_exists( __NAMESPACE__ . '\\InstalledValidators' ) ) :

/**
 * class: InstalledValidators
 *
 * ============================================================================================
 * The setting `validation.engine = 'installed'`: the full check from whichever validator is installed
 * ============================================================================================
 *
 * A plugin that wants the full check from "whichever validator is installed" asks this class, never a
 * particular validator. The check goes through the frontend core to the validator installed on the
 * site, never to a plugin named in code.
 *
 * ⚠️ **THE THREE VALIDATORS ARE RECOGNISED BY THEIR VERSION CONSTANT, NOT BY `is_plugin_active()`.**
 * The constant exists exactly when the plugin's main file has run, which is what matters for a REST
 * route: an active plugin whose main file stopped early ( too old a PHP, for example ) registers no
 * route. `is_plugin_active()` would also need `wp-admin/includes/plugin.php` on the front end.
 *
 * ⚠️ **THE ROUTES DIFFER, AND THAT IS ON PURPOSE.** All three register in the shared namespace
 * `einvoice/v1`; KoSIT uses `/validate`, VAMPIRE `/validate-php` and Mustang `/validate-mustang`,
 * because a second registration of the same path silently replaces the first ( see
 * `register_rest_routes()` in `class-sppv-plugin.php` ). The table below is the only place that maps a
 * validator to its path.
 *
 * ⚠️ **ASK ONLY AFTER `plugins_loaded`.** Plugins are included one after another; asked from a main file,
 * a validator whose folder sorts later has not defined its constant yet. `admin_enqueue_scripts`, where
 * `einvoice-inbound` asks, runs long after every plugin is loaded.
 *
 * ⚠️ **NO WORDPRESS NEEDED FOR THE TABLE ITSELF.** `apply_filters()` and `rest_url()` are only called
 * when they exist, so the class can be measured in a harness without loading WordPress.
 *
 * @since 1.1.0
 */

class InstalledValidators
{


	/**
	 * The known validators, in the order of preference when the operator has not chosen one.
	 *
	 * ⚠️ KoSIT FIRST, BECAUSE IT IS THE REFERENCE. The KoSIT validator is the one the German public
	 * sector itself publishes; when several are installed, its verdict is the one an operator is asked
	 * about. VAMPIRE follows because it runs without Java, Mustang last.
	 */
	const KNOWN = array(
		'kosit'   => array( 'label' => 'eInvoice Validator for KoSIT',   'constant' => 'SPKO_VERSION', 'route' => '/validate' ),
		'vampire' => array( 'label' => 'eInvoice Validator for VAMPIRE PHP', 'constant' => 'SPPV_VERSION', 'route' => '/validate-php' ),
		'mustang' => array( 'label' => 'eInvoice Validator for Mustang', 'constant' => 'SPMV_VERSION', 'route' => '/validate-mustang' ),
	);

	/** The REST namespace all validators share - the same as `Rest::NAMESPACE_` in `einvoice-host-wp`. */
	const REST_NAMESPACE = 'einvoice/v1';


	/**
	 * The installed validators.
	 *
	 * Filter `einvoice/installed_validators` lets a further validator announce itself with
	 * `$list[ 'id' ] = array( 'label' => …, 'route' => '/…' )`, or lets a site hide one. A filtered entry
	 * without a route starting with `/` is dropped; a browser could not reach it.
	 *
	 * @return	array	id => array{ label: string, route: string }
	 * @since	1.1.0
	 */

	public static function all(): array
	{
		$list = array();

		foreach ( self::KNOWN as $id => $v )
			if ( defined( $v[ 'constant' ] ) )
				$list[ $id ] = array( 'label' => $v[ 'label' ], 'route' => $v[ 'route' ] );

		if ( function_exists( 'apply_filters' ) )
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a deliberate cross-plugin hook in the shared `einvoice/` family, like `einvoice/report_html`.
			$list = (array) apply_filters( 'einvoice/installed_validators', $list );

		$out = array();

		foreach ( $list as $id => $v )
		{
			if ( ! is_string( $id ) || $id === '' || ! is_array( $v ) )
				continue;

			$route = (string) ( $v[ 'route' ] ?? '' );

			if ( $route === '' || $route[ 0 ] !== '/' )
				continue;

			$out[ $id ] = array( 'label' => (string) ( $v[ 'label' ] ?? $id ), 'route' => $route );
		}

		return( $out );
	}


	/**
	 * The validator to use: the stored choice when it is still installed, else the first installed one.
	 *
	 * @param	string	$preferred	the operator's stored choice, possibly empty
	 * @return	string				the id, or '' when none is installed
	 * @since	1.1.0
	 */

	public static function pick( string $preferred = '' ): string
	{
		$all = self::all();

		if ( $preferred !== '' && isset( $all[ $preferred ] ) )
			return( $preferred );

		foreach ( $all as $id => $v )
			return( (string) $id );

		return( '' );
	}


	/**
	 * The absolute REST address of a validator, for `fetch()` in the browser.
	 *
	 * ⚠️ `rest_url()`, NOT A HAND-BUILT `/wp-json/…`. A site without pretty permalinks answers REST only
	 * under `?rest_route=`, and a site in a subfolder has its own prefix; `rest_url()` knows both.
	 *
	 * @param	string	$id
	 * @return	string			'' when the validator is not installed or WordPress is not loaded
	 * @since	1.1.0
	 */

	public static function url( string $id ): string
	{
		$all = self::all();

		if ( ! isset( $all[ $id ] ) || ! function_exists( 'rest_url' ) )
			return( '' );

		return( (string) rest_url( self::REST_NAMESPACE . $all[ $id ][ 'route' ] ) );
	}


}

endif;
