<?php
/**
 * Plugin Name: eInvoice Validator for VAMPIRE PHP
 * Plugin URI:  https://invoicepress.de/en/solutions/einvoice-tools/
 * Description: Validate e-invoice XML (XRechnung 3.0.2, ZUGFeRD / Factur-X EN16931 + EXTENDED, PEPPOL BIS Billing 3.0) in pure PHP - no Java, no daemon, no external service. Upload via shortcode, read the report, download it as XML.
 * Version:     2.0.2
 * Requires PHP: 8.2
 * Author:      Ralf Fuhrmann
 * Author URI:  https://www.specialpress.de
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: einvoice-validator-for-vampire
 * Domain Path: /languages
 *
 * The validation engine is VAMPIRE PHP ( `invoicepress/vampire-php`, GPL-2.0-or-later ), bundled under
 * `vendor/invoicepress/vampire-php` with its rule packages. Each rule package carries the licence of its
 * source; `vendor/invoicepress/vampire-php/NOTICE` lists them.
 */

declare( strict_types = 1 );

// block direct access to this file

if ( ! defined( 'ABSPATH' ) )
	exit;

// ⚠️ Integrity check first ( einvoice-integrity.php, written by the release build ): a missing, empty or cut-short file
// keeps this plugin off with a notice instead of taking the whole site down. The working tree has no such file.
if ( is_file( __DIR__ . '/einvoice-integrity.php' ) && ! ( include __DIR__ . '/einvoice-integrity.php' ) )
	return;


/**
 * ⚠️ THIS PLUGIN REGISTERS ITS BUNDLED TRANSLATIONS ITSELF, ALTHOUGH WORDPRESS OFTEN DOES IT FOR IT.
 *
 * The source texts are English, so a German or French site shows its language only if the .mo
 * under /languages is found. Since WordPress 6.8 wp-settings.php registers the `Domain Path` folder
 * of every SITE-activated plugin, and just-in-time loading then finds the file without this call.
 * Without it the plugin stays English in these cases:
 *
 * - NETWORK-activated on a multisite. The loop for network plugins ( `wp_get_active_network_plugins()`
 *   in wp-settings.php ) registers no path; measured with the WordPress 7.1.3 core of the development
 *   site, where a network-activated copy stayed English on a German site.
 * - WordPress 6.7 and older, which `Requires at least` still allows. Their wp-settings.php registers
 *   no plugin folder, and their just-in-time loader only looks in wp-content/languages/.
 * - A test harness that includes this file by hand instead of activating it. No site meets this case,
 *   but a harness without this call would report the translations as missing.
 *
 * ⚠️ ON `init`, NEVER EARLIER. Since WordPress 6.7 this call only registers the folder; the file is
 * read on the first __() of the domain. A __() of this domain before `after_setup_theme` triggers the
 * notice "_load_textdomain_just_in_time was called incorrectly", and any text translated before this
 * hook stays English. Priority 0, so that texts translated on `init` itself already find the folder.
 */
add_action( 'init', static function ()
{
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- the bundled de/fr .mo must load when network-activated and on WordPress 6.7, where just-in-time loading does not register the Domain Path; see the comment above.
	load_plugin_textdomain( 'einvoice-validator-for-vampire', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 0 );

// ⚠️ SPPV_VERSION IS THE CACHE BUSTER OF EVERY ASSET - the stylesheet, sppv-uploader.js, pdf.js, the
// model bundle AND the `cssUrl` the report component loads INSIDE its own shadow root. An asset that
// changes without this number changing keeps its old copy in every browser that was here before.
// Cost us the whole Kit-H rewrite once: the report still rendered in system-ui with pastel pills,
// because `einvoice-report.css?ver=1.0.0` was the 4539-byte Kit-A file from the browser's cache while
// the 9647-byte Kit-H file lay on disk.
define( 'SPPV_VERSION', '2.0.2' );

/**
 * The shortcode tag.
 *
 * ⚠️ **IT IS CALLED `sppv_validator`.** WordPress.org requires the plugin's own prefix for everything a plugin
 * registers globally. No alias on another tag: the plugin never shipped under one, so no page carries it.
 */
define( 'SPPV_SHORTCODE', 'sppv_validator' );
define( 'SPPV_FILE',    __FILE__ );
define( 'SPPV_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SPPV_URL',     plugin_dir_url( __FILE__ ) );


// ⚠️ PHP 8.2 IS A HARD FLOOR, AND THE CHECK RUNS BEFORE ANYTHING ELSE LOADS. The bundled validator
// library uses named arguments, readonly classes and enums; on PHP 8.1 the very first `require` of
// one of its class files is a parse error, which WordPress shows as a white screen with no hint at
// the cause. "Requires PHP" in the header above only stops the INSTALLER - it does not stop an
// existing install whose host later downgrades PHP, which is exactly when this would bite.

if ( version_compare( PHP_VERSION, '8.2', '<' ) )
{

	add_action( 'admin_notices', static function ()
	{
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: %s is the PHP version running on this server. */
			esc_html__( 'eInvoice Validator for VAMPIRE PHP needs PHP 8.2 or newer. This server runs PHP %s, so the plugin stays inactive.', 'einvoice-validator-for-vampire' ),
			esc_html( PHP_VERSION )
		);
		echo '</p></div>';
	} );

	return;

}


// ⚠️ THE PHP EXTENSIONS ARE A HARD FLOOR AS WELL, checked at the same point. VAMPIRE PHP reads the invoice with DOM, computes
// amounts with bcmath and runs the string functions of XPath through mbstring ( `Xpath/Runtime/FunctionLibrary.php` ). All
// three are optional in PHP; without one the first check ends in a fatal "Call to undefined function". The plugin then
// stays inactive and names what is missing, as the wordpress.org review asked on 05.11.2026.
$sppv_missing = array_values( array_filter( array( 'dom', 'libxml', 'mbstring', 'bcmath' ), static function ( $ext ) {
	return( ! extension_loaded( $ext ) );
} ) );

if ( $sppv_missing )
{

	add_action( 'admin_notices', static function () use ( $sppv_missing )
	{
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: %s is a list of PHP extensions, e.g. "mbstring, bcmath". */
			esc_html__( 'eInvoice Validator for VAMPIRE PHP needs the PHP extensions %s, which this server does not load, so the plugin stays inactive. Ask your host to enable them.', 'einvoice-validator-for-vampire' ),
			esc_html( implode( ', ', $sppv_missing ) )
		);
		echo '</p></div>';
	} );

	return;

}

unset( $sppv_missing );


// ⚠️ SHARED HOST PACKAGE, REQUIRED BEFORE THIS PLUGIN'S OWN CLASSES. Rate limiting and the shared
// einvoice/v1 REST nonce route live in libs/einvoice-host-wp/ ( SpecialPress\EInvoice\Host ). Required
// by hand, not through Composer, and as THIS PLUGIN'S OWN COPY - requiring a path one level up only
// resolves inside the dev checkout, and a standalone install fatals on activation. The package guards
// its class declarations with class_exists(), so several sibling plugins can be active at once.

require_once( SPPV_DIR . 'libs/einvoice-host-wp/einvoice-host-wp.php' );

// ⚠️ FROM 2.0.0 ALSO THE LOADER OF einvoice-host-wp 2.0. It announces this copy, and the newest copy on the site loads the rest
// of the library on `plugins_loaded`: the routes of the service contract, the data store and the keys for apps. The line
// above stays, because this plugin uses the daily limit and the REST helper before that hook.
require_once( SPPV_DIR . 'libs/einvoice-host-wp/load.php' );


// The report model and its readers ( Report Lite, and the `einvoice/report_html` filter the
// Validation Explainer hooks ). Composer-vendored from libs/einvoice-report, same as the sibling.

if ( file_exists( SPPV_DIR . 'vendor/autoload.php' ) )
	require_once( SPPV_DIR . 'vendor/autoload.php' );


// ⚠️ THE VALIDATOR LIBRARY IS AUTOLOADED BY HAND, NOT THROUGH COMPOSER. Its runtime export lies under
// vendor/invoicepress/vampire-php, but Composer of this plugin does not install it, so the class map above does
// not know it; a second Composer autoloader in the same request would fight that one for the same class map.
// A four-line PSR-4 loader for exactly one namespace prefix is smaller and cannot collide.
//
// ⚠️ The realpath() guard is not decoration: $class comes from whatever code triggers the autoload,
// and a namespace separator is a path separator here. Without it, a crafted class name containing
// ".." would reach outside vendor/invoicepress/vampire-php/src/.

spl_autoload_register( static function ( $class )
{

	$prefix = 'EinvoiceValidator\\';

	if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 )
		return;

	$root = SPPV_DIR . 'vendor/invoicepress/vampire-php/src/';
	$file = $root . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	$real = realpath( $file );

	if ( $real === false || strncmp( $real, (string) realpath( $root ), strlen( (string) realpath( $root ) ) ) !== 0 )
		return;

	require_once( $real );

} );


// ⚠️ SHARED CLASS, GUARDED: the design-kit choice is one option for nine plugins, and whichever of them
// loads first owns the class. The copies are byte-identical; _claude-tools/kit-sync.php checks that.
if ( ! class_exists( 'EInvoicePress_Design_Kit' ) )
	require_once( SPPV_DIR . 'includes/class-einvoicepress-design-kit.php' );

// ⚠️ SHARED CLASS, GUARDED, like EInvoicePress_Design_Kit: whichever plugin loads first owns it. It answers a
// request from the front end in the language of the page; _claude-tools/request-language-sync.php keeps the copies equal.
if ( ! class_exists( 'EInvoicePress_Request_Language' ) )
	require_once( SPPV_DIR . 'includes/class-einvoicepress-request-language.php' );

require_once( SPPV_DIR . 'includes/class-sppv-rule-packages.php' );
require_once( SPPV_DIR . 'includes/class-sppv-rule-profiles.php' );
require_once( SPPV_DIR . 'includes/class-sppv-validator.php' );
require_once( SPPV_DIR . 'includes/class-sppv-settings.php' );

/**
 * ⚠️ THE EXPLORER VIEW, BUILT INTO THE VALIDATOR. VAMPIRE writes its report in the
 * KoSIT format, so the same plain-language view as the KoSIT Validation Explorer applies - whether the
 * explorer is installed or not. Rule texts and business terms come from `einvoice-reference-data`, in the
 * `explainer` tier; see the same block in einvoice-validator-for-kosit.php for why the tier matters.
 */
require_once( SPPV_DIR . 'includes/class-sppv-rules.php' );
require_once( SPPV_DIR . 'includes/class-sppv-explainer-view.php' );
// The values of a finding from the rule itself, attached through the filter of the shared reader.
require_once( SPPV_DIR . 'includes/class-sppv-rule-values.php' );
SPPV_Rule_Values::register();
// The matrix of the multi-edition check ( shown only with the setting `sppv_multi_edition` ).
require_once( SPPV_DIR . 'includes/class-sppv-matrix-view.php' );

$sppv_shared_rules_dir  = SPPV_DIR . 'vendor/specialpress/einvoice-reference-data/dist/rules/';
$sppv_shared_terms_file = SPPV_DIR . 'vendor/specialpress/einvoice-reference-data/dist/fields/en16931.explainer.json';

if ( is_dir( $sppv_shared_rules_dir ) )
	SPPV_Rules::set_shared_rules_dir( $sppv_shared_rules_dir, 'INDEX.explainer.json' );

if ( is_file( $sppv_shared_terms_file ) )
	SPPV_Rules::set_shared_terms_file( $sppv_shared_terms_file );

// The texts of the batch check, handed to the page by SPPV_Plugin::enqueue_assets().
require_once( SPPV_DIR . 'includes/class-sppv-batch-texts.php' );
require_once( SPPV_DIR . 'includes/class-sppv-plugin.php' );


/**
 * Filter: einvoice/engine_base_dir
 *
 * Announces the bundled library **VAMPIRE PHP** to every plugin that wants to use it - above all the free
 * validator plugin, which can thereby offer a source without Java without bundling the library itself.
 *
 * ⚠️ THE NAME: the library `vampire-php` is called **VAMPIRE PHP** ( "Validator And Multi-Platform Invoice
 * Rule Engine" ), its JavaScript counterpart **VAMPIRE JS**. "Engine" stays the general term for KoSIT,
 * Mustang, phive and this library.
 *
 * ⚠️ **THE FILTER NAME `einvoice/engine_base_dir` STAYS.** It is a public hook several plugins hang on; touching it
 * for a rename would be a break without value - and it names the genus, not the product, anyway.
 *
 * ⚠️ THIS IS THE SEAM BETWEEN GPL AND PRODUCT FOR SALE. The free plugin goes to wordpress.org and must not contain
 * the proprietary engine; it knows only a directory name here. If this plugin is deactivated, nobody answers
 * the filter any more, and the source over there says "not installed" in the system check - instead of
 * running into a fatal error on a missing class.
 *
 * ⚠️ The classes are loaded anyway: the autoloader above hangs on this process. The filter therefore only
 * returns the path to the rule packages, not an instance.
 *
 * @since 1.1.0
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'einvoice/engine_base_dir' is a deliberately shared, documented CROSS-PLUGIN hook. A prefix would bind the seam to one of the two plugins, and the other would have to know it.
add_filter( 'einvoice/engine_base_dir', static function ( $dir )
{
	// ⚠️ An existing answer is NOT overwritten. Should a second engine ever lie in the system, the load order of
	// the plugins would otherwise decide which one checks - and that is no decision a load order should make.
	if ( $dir !== '' && $dir !== null )
		return( $dir );

	// ⚠️ rules_dir(), NOT lib_dir(): once a rule package is uploaded, a plugin that asks here must check
	// with the same rule sets as this plugin's own form. The folder holds rulesets/ only; the classes
	// come from the autoloader above in either case.
	$own = SPPV_Validator::rules_dir();

	return( is_file( $own . '/rulesets/scenarios.json' ) ? $own : $dir );
} );


// boot

\SpecialPress\EInvoice\Host\Rest::init();

SPPV_Settings::init();
SPPV_Plugin::instance();


/**
 * The link to the manual in the plugin list: "Manual" under the name, "Manual · Support" in the description line
 * ( Ralf's order F3.10 of 18.10.2026, class ManualLink in einvoice-host-wp ).
 *
 * ⚠️ IN A CLOSURE, SO THAT NO VARIABLE LANDS IN THE GLOBAL SCOPE. The class comes from this plugin's own copy of
 * einvoice-host-wp, under libs/ or vendor/; whichever copy loads first serves every plugin of the site, which is why
 * the class is only required when nobody has loaded it yet.
 */
( static function () {

	if ( ! class_exists( '\SpecialPress\EInvoice\Host\ManualLink' ) )
	{
		foreach ( array( '/libs/einvoice-host-wp/src/ManualLink.php', '/vendor/specialpress/einvoice-host-wp/src/ManualLink.php' ) as $file )
		{
			if ( is_readable( __DIR__ . $file ) )
			{
				require_once __DIR__ . $file;
				break;
			}
		}
	}

	if ( ! class_exists( '\SpecialPress\EInvoice\Host\ManualLink' ) )
		return;

	\SpecialPress\EInvoice\Host\ManualLink::register( __FILE__, static function () {
		return( array(
			'manual'  => __( 'Manual', 'einvoice-validator-for-vampire' ),
			'support' => __( 'Support', 'einvoice-validator-for-vampire' ),
		) );
	}, 'https://wordpress.org/support/plugin/einvoice-validator-for-vampire/' );

} )();
