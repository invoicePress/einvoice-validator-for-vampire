<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Loader for the shared eInvoice WordPress host package ( SpecialPress\EInvoice\Host ).
 *
 * Not a Composer package on purpose: two of the four consuming plugins ( the Explainer and the
 * Validator ) have no `composer.json` at all today, and this package is a handful of small classes,
 * not a dependency tree - the same "required by hand, no Composer round trip" idiom the Viewer's own
 * `includes/core/` already uses for exactly this reason.
 *
 * Usage from a consuming plugin's bootstrap file:
 *
 *   require_once( dirname( __DIR__ ) . '/libs/einvoice-host-wp/einvoice-host-wp.php' );
 *
 * @package SpecialPress\EInvoice\Host
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


/**
 * ⚠️ `SupplierStore` MUST BE IN THIS LIST AS WELL. The Composer autoloader would find it, but this file
 * exists precisely for the consumers that have no `composer.json` and require the classes by hand. A
 * consumer that calls `SupplierStore::all()` without it gets "Class not found", a fatal error while the
 * page is built, not an empty list.
 *
 * ⚠️ A test setup that loads the classes through Composer does not reproduce this loading path and
 * therefore cannot catch a missing entry here.
 *
 * ⚠️ ONLY THE FILES THAT ARE THERE. Since 05.11.2026 a release ZIP carries only the classes its plugin calls
 * ( `spx_prune_host_wp()` in `_claude-tools/build-release.php` ); a validator has no `SupplierStore.php`. A plain
 * `require_once` of a missing file is a fatal error on activation.
 */
foreach ( array( 'RateLimit', 'RoleRateLimit', 'RoleGate', 'Rest', 'MasterDataStore', 'SupplierStore', 'PdfPostProcessor' ) as $einvoice_host_wp_class )
{
	if ( is_file( __DIR__ . '/src/' . $einvoice_host_wp_class . '.php' ) )
		require_once( __DIR__ . '/src/' . $einvoice_host_wp_class . '.php' );
}

unset( $einvoice_host_wp_class );
