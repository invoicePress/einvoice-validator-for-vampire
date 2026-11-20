<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Integrity check of this plugin: written by the release build, never edited by hand.
 *
 * The main file includes it first and stops loading the plugin when it returns false. A file that is missing, empty or cut
 * short then costs this plugin, not the website: on 06.11.2026 one empty file of one plugin took a whole site down.
 *
 * ⚠️ TWO SIZES PER FILE COUNT AS WHOLE: as built, and without carriage returns. An FTP program that transfers PHP as text
 * may rewrite the line endings, and the file is just as good.
 *
 * ⚠️ CHECKED ON EVERY REQUEST, NOTHING IS REMEMBERED. A first version kept a passed check for an hour; the file emptied
 * afterwards then went unnoticed and the site broke anyway ( measured 06.11.2026 ). An upload changes the files while the
 * site runs, so only a fresh check counts.
 *
 * ⚠️ ONLY THE PLUGIN'S OWN CODE, NOT `vendor/`. The own files load when the plugin starts; reading the sizes of the 4,700 PHP
 * files of einvoice-api took 200 ms on Windows, its 56 own files 2.8 ms ( measured 06.11.2026 ). A shared class in `vendor/`
 * is loaded through libs/einvoice-autoload/newest.php, which skips an empty copy and takes the class from another plugin.
 *
 * @package SpecialPress\EInvoice\Integrity
 */

if ( ! defined( 'ABSPATH' ) )
	exit;

return( ( static function ( $dir, $name, $files )
{
	$bad = array();

	foreach ( $files as $rel => $sizes )
	{
		$file = $dir . '/' . $rel;
		$size = is_file( $file ) ? (int) filesize( $file ) : -1;

		if ( $size !== (int) $sizes[ 0 ] && $size !== (int) $sizes[ 1 ] )
			$bad[] = $rel;
	}

	if ( ! $bad )
		return( true );

	// ⚠️ The text domain of the plugin is not loaded yet; the two languages of the family are chosen by the site language.
	$notice = static function () use ( $name, $bad )
	{
		if ( ! current_user_can( 'activate_plugins' ) )
			return;

		$de   = strpos( (string) ( function_exists( 'determine_locale' ) ? determine_locale() : get_locale() ), 'de' ) === 0;
		$list = esc_html( implode( ', ', array_slice( $bad, 0, 3 ) ) . ( count( $bad ) > 3 ? ' …' : '' ) );

		$one = count( $bad ) === 1;

		if ( $de )
			$text = $one
				? '<strong>%1$s</strong> wurde nicht geladen, weil eine Datei fehlt oder unvollständig ist: %3$s. Bitte das Plugin neu einspielen; die übrige Website läuft weiter.'
				: '<strong>%1$s</strong> wurde nicht geladen, weil %2$d Dateien fehlen oder unvollständig sind, zum Beispiel %3$s. Bitte das Plugin neu einspielen; die übrige Website läuft weiter.';
		else
			$text = $one
				? '<strong>%1$s</strong> was not loaded because one file is missing or incomplete: %3$s. Please install the plugin again; the rest of the site keeps running.'
				: '<strong>%1$s</strong> was not loaded because %2$d files are missing or incomplete, for example %3$s. Please install the plugin again; the rest of the site keeps running.';

		echo '<div class="notice notice-error"><p>', sprintf( $text, esc_html( $name ), count( $bad ), $list ), '</p></div>';
	};

	if ( function_exists( 'add_action' ) )
	{
		add_action( 'admin_notices', $notice );
		add_action( 'network_admin_notices', $notice );
	}

	if ( function_exists( 'error_log' ) )
		error_log( $name . ': not loaded, incomplete files: ' . implode( ', ', array_slice( $bad, 0, 10 ) ) );

	return( false );

} )( __DIR__, 'eInvoice Validator for VAMPIRE PHP', array (
  'includes/class-einvoicepress-design-kit.php' => 
  array (
    0 => 28985,
    1 => 28985,
  ),
  'includes/class-einvoicepress-request-language.php' => 
  array (
    0 => 6205,
    1 => 6205,
  ),
  'includes/class-sppv-app-texts.php' => 
  array (
    0 => 7723,
    1 => 7723,
  ),
  'includes/class-sppv-batch-texts.php' => 
  array (
    0 => 9138,
    1 => 9138,
  ),
  'includes/class-sppv-explainer-view.php' => 
  array (
    0 => 25873,
    1 => 25873,
  ),
  'includes/class-sppv-matrix-view.php' => 
  array (
    0 => 10290,
    1 => 10290,
  ),
  'includes/class-sppv-plugin.php' => 
  array (
    0 => 61195,
    1 => 61195,
  ),
  'includes/class-sppv-rule-packages.php' => 
  array (
    0 => 25751,
    1 => 25751,
  ),
  'includes/class-sppv-rule-profiles.php' => 
  array (
    0 => 9029,
    1 => 9029,
  ),
  'includes/class-sppv-rule-values.php' => 
  array (
    0 => 7957,
    1 => 7957,
  ),
  'includes/class-sppv-rules.php' => 
  array (
    0 => 29066,
    1 => 29066,
  ),
  'includes/class-sppv-settings.php' => 
  array (
    0 => 36813,
    1 => 36813,
  ),
  'includes/class-sppv-validator.php' => 
  array (
    0 => 26744,
    1 => 26744,
  ),
  'includes/class-spvp-js-texts.php' => 
  array (
    0 => 6991,
    1 => 6991,
  ),
  'libs/einvoice-host-wp/einvoice-host-wp.php' => 
  array (
    0 => 1918,
    1 => 1918,
  ),
  'libs/einvoice-host-wp/load.php' => 
  array (
    0 => 4455,
    1 => 4455,
  ),
  'libs/einvoice-host-wp/src/AdminMenu.php' => 
  array (
    0 => 10093,
    1 => 10093,
  ),
  'libs/einvoice-host-wp/src/AppKeys.php' => 
  array (
    0 => 3997,
    1 => 3997,
  ),
  'libs/einvoice-host-wp/src/ManualLink.php' => 
  array (
    0 => 5852,
    1 => 5852,
  ),
  'libs/einvoice-host-wp/src/Rest.php' => 
  array (
    0 => 5678,
    1 => 5678,
  ),
  'libs/einvoice-host-wp/src/RoleRateLimit.php' => 
  array (
    0 => 14797,
    1 => 14797,
  ),
  'libs/einvoice-host-wp/src/ServiceRoutes.php' => 
  array (
    0 => 7118,
    1 => 7118,
  ),
) ) );
