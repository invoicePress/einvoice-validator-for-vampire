<?php
/**
 * Shortcode, assets, AJAX and REST - the part a visitor ever touches.
 *
 * Deliberately built along the same lines as the sibling plugin
 * ( einvoice-validator-for-peppol-en16931 ): same Shadow-DOM construction, same rate limiter, same
 * `einvoice/v1/validate` REST route, same `einvoice/report_html` filter. A visitor should not be able
 * to tell the two apart by looking; the difference is that nothing here needs Java.
 *
 * @package SP_EInvoice_Validator_PHP
 */

declare( strict_types = 1 );

use SpecialPress\EInvoice\Host\RoleRateLimit;
use SpecialPress\EInvoice\Host\Rest;

if ( ! defined( 'ABSPATH' ) )
	exit;


class SPPV_Plugin
{


	/** @var SPPV_Plugin|null */
	private static $instance = null;

	/** @var RoleRateLimit|null */
	private static $ratelimit = null;

	/** @var bool */
	private $enqueued = false;


	/**
	 * @return	SPPV_Plugin
	 * @since	1.0.0
	 */

	public static function instance()
	{
		if ( self::$instance === null )
			self::$instance = new self();

		return( self::$instance );
	}


	/**
	 * The stylesheets of the design kit the operator picked.
	 *
	 * ⚠️ **THE KIT LIVES IN THE VENDORED REPORT LIBRARY, NOT IN `assets/css/`.** The validator ships
	 * `vendor/specialpress/einvoice-report/assets/css/`, and `_claude-tools/kit-sync.php` puts every
	 * kit there. A kit that is missing in that folder falls back to Kit H without a word, see
	 * EInvoicePress_Design_Kit.
	 *
	 * @return	array{tokens:string,kit:string,slug:string}
	 * @since	1.6.0
	 */

	public static function kit_css()
	{
		return( EInvoicePress_Design_Kit::stylesheets(
			SPPV_DIR . 'vendor/specialpress/einvoice-report/assets/css/',
			SPPV_URL . 'vendor/specialpress/einvoice-report/assets/css/',
			SPPV_VERSION
		) );
	}


	/**
	 * The shared rate limiter, bound to this plugin's own option prefix - see
	 * libs/einvoice-host-wp/src/RoleRateLimit.php.
	 *
	 * @return	RoleRateLimit
	 * @since	1.0.0
	 */

	public static function ratelimit()
	{
		if ( self::$ratelimit === null )
			self::$ratelimit = new RoleRateLimit( 'sppv', 20 );

		return( self::$ratelimit );
	}


	/**
	 * The refusal for a visitor the rate limit stops.
	 *
	 * ⚠️ TWO REASONS, TWO SENTENCES. A role set to 0 in the table "Daily uploads per role" has no access at all, and
	 * "try again tomorrow" would promise something that tomorrow does not bring.
	 *
	 * ⚠️ TWO LITERAL __() CALLS, NOT ONE WITH A VARIABLE: only a literal reaches the .pot.
	 *
	 * @return	string
	 */

	public static function limit_message()
	{
		if( ( self::ratelimit()->status()[ 'reason' ] ?? '' ) === 'role' )
			return( __( 'This tool is not available for your account.', 'einvoice-validator-for-vampire' ) );

		return( __( 'You have reached the daily upload limit. Please try again tomorrow.', 'einvoice-validator-for-vampire' ) );
	}


	private function __construct()
	{
		/*
		 * ⚠️ THE BUTTON IN THE VIEWER IS THIS PLUGIN'S FROM 2.0.0 ON ( interface 8 ). The host asks `einvoice_extensions`, and this
		 * plugin answers with the bundle `einvoice-site-validator.bundle.js` and its entry; up to the viewer 2.x the viewer drew the
		 * button and posted to `sppv_validate` itself.
		 */
		add_filter( 'einvoice_extensions', array( $this, 'viewer_extension' ), 10, 2 );

		/*
		 * ⚠️ THIS WEBSITE AS A SOURCE FOR APPS ( contract 2, `POST /wp-json/einvoice/v1/validate/vampire` ). The shared routes of
		 * `einvoice-host-wp` ask `einvoice_service_validators`; this plugin answers with its own engine and nothing else. Who may
		 * call it decides the route: a key of an app that names vampire, or any visitor within the daily limit of this plugin
		 * ( `service_allowed()` ). ⚠️ The routes exist without the viewer as well: `service_routes()` starts them.
		 */
		add_filter( 'einvoice_service_validators', array( $this, 'service_validator' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'service_routes' ) );

		// The texts of Report Lite in this plugin's language ( see lite_texts() ).
		add_filter( 'einvoice/report_lite_texts', array( __CLASS__, 'lite_texts' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'init', array( $this, 'register_explainer_bridge' ) );
		add_action( 'init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		add_action( 'wp_ajax_sppv_nonce', array( $this, 'ajax_nonce' ) );
		add_action( 'wp_ajax_nopriv_sppv_nonce', array( $this, 'ajax_nonce' ) );

		// ⚠️ ALIASES ON TOP OF THE REST ROUTE, NOT A SECOND IMPLEMENTATION - both end up in
		// do_validate(). Same arrangement as the sibling plugin, so the uploader script stays
		// interchangeable between the two.

		add_action( 'wp_ajax_sppv_validate', array( $this, 'ajax_validate' ) );
		add_action( 'wp_ajax_nopriv_sppv_validate', array( $this, 'ajax_validate' ) );
	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public function register_rest_routes()
	{

		// ⚠️ `/validate-php`, NOT `/validate`. The sibling plugin already registers `/validate` in the
		// shared `einvoice/v1` namespace, and both can be active at once - the second registration
		// would silently overwrite the first and every request would land in whichever plugin booted
		// last. A distinct path is the only way both stay reachable.

		Rest::registerRoute( '/validate-php', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_validate' ),
			'permission_callback' => array( 'SpecialPress\EInvoice\Host\Rest', 'verifyNonce' ),
		) );

	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public function register_shortcode()
	{
		add_shortcode( SPPV_SHORTCODE, array( $this, 'render_shortcode' ) );
	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public function register_explainer_bridge()
	{
		if ( class_exists( 'SPX_Plugin' ) )
			add_filter( 'spx_force_enqueue', array( $this, 'maybe_force_explainer_enqueue' ) );
	}


	/**
	 * @param	bool	$force
	 * @return	bool
	 * @since	1.0.0
	 */

	public function maybe_force_explainer_enqueue( $force )
	{
		return( $force || $this->post_has_shortcode() );
	}


	/**
	 * ⚠️ ENQUEUE EARLY, NOT INSIDE THE SHORTCODE. A block theme renders the post content in one pass
	 * BEFORE wp_head() fires, so a lazy enqueue from inside the shortcode can be the first call -
	 * and wp_localize_script() needs the handle to already be registered. Everything is therefore
	 * registered idempotently in both places.
	 *
	 * @return	void
	 * @since	1.0.0
	 */

	public function register_assets()
	{

		$this->register_handles();

		if ( $this->post_has_shortcode() )
			$this->enqueue_assets();

	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	private function register_handles()
	{

		wp_register_style( 'sppv-validator', SPPV_URL . 'assets/css/sppv-validator.css', array(), self::asset_ver( 'assets/css/sppv-validator.css' ) );

		wp_register_script( 'einvoice-host', SPPV_URL . 'libs/einvoice-host-wp/js/einvoice-host.js', array(), self::asset_ver( 'libs/einvoice-host-wp/js/einvoice-host.js' ), true );

		$deps = array( 'einvoice-host' );

		// The report custom element travels with the vendored einvoice-report package. Without
		// Composer ( a plain checkout, or a build that left vendor/ out ) the uploader still works -
		// it then renders the validator's own HTML instead of Report Lite.
		if ( file_exists( SPPV_DIR . 'vendor/specialpress/einvoice-report/assets/js/einvoice-report.js' ) )
		{
			wp_register_script( 'einvoice-report', SPPV_URL . 'vendor/specialpress/einvoice-report/assets/js/einvoice-report.js', array(), self::asset_ver( 'vendor/specialpress/einvoice-report/assets/js/einvoice-report.js' ), true );
			$deps[] = 'einvoice-report';
		}

		wp_register_script( 'sppv-uploader', SPPV_URL . 'assets/js/sppv-uploader.js', $deps, self::asset_ver( 'assets/js/sppv-uploader.js' ), true );

	}


	/**
	 * @return	bool
	 * @since	1.0.0
	 */

	private function post_has_shortcode()
	{

		if ( ! is_singular() )
			return( (bool) apply_filters( 'sppv_force_enqueue', false ) );

		$post = get_post();
		$has  = ( $post && has_shortcode( (string) $post->post_content, SPPV_SHORTCODE ) );

		return( (bool) apply_filters( 'sppv_force_enqueue', $has ) );

	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	private function enqueue_assets()
	{

		$this->register_handles();

		wp_enqueue_style( 'sppv-validator' );
		wp_enqueue_script( 'sppv-uploader' );

		if ( $this->enqueued )
			return;

		$status = self::ratelimit()->status();

		wp_localize_script( 'sppv-uploader', 'SPPV', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			// The language of the page; the script sends it with every request ( EInvoicePress_Request_Language ).
			'lang'      => EInvoicePress_Request_Language::page_language(),
			// ⚠️ TWO ADDRESSES, SEPARATED BY A SPACE. The shell stands first, the design kit after it: a
			// stylesheet of the document does not reach its shadow root, and the kit sets the tokens the shell
			// takes its colours from. `sppv-uploader.js` splits the value and registers one <link> per address -
			// see the header of sppv-validator.css.
			'cssUrl'    => SPPV_URL . 'assets/css/sppv-validator.css?ver=' . self::asset_ver( 'assets/css/sppv-validator.css' )
				. ' ' . self::kit_css()[ 'tokens' ]
				. ' ' . self::kit_css()[ 'kit' ],
			'maxSize'   => $this->max_filesize(),
			'remaining' => $status[ 'remaining' ],

			// Only with the add-on VAMPIRE JS, and null otherwise - see browser_config().
			'browser'   => self::browser_config(),

			/*
			 * The batch check ( from 1.14.3 ): many invoices at once, one overview.
			 *
			 * ⚠️ THE BROWSER SENDS ONE INVOICE PER REQUEST TO THE SAME `sppv_validate` AS THE SINGLE CHECK. No long single
			 * request, no queue on the server: a web server with a time limit per request is never asked for more than one
			 * invoice, a cancelled batch leaves nothing behind, and every invoice counts against the daily limit exactly as a
			 * single check does. The bundle is the batch module of libs/einvoice-core-js, loaded only when the visitor opens
			 * the batch tab.
			 *
			 * ⚠️ ONE AT A TIME ( `concurrency` 1 ). VAMPIRE PHP takes a PHP worker for the length of a check; two parallel
			 * requests of one visitor would take two workers of a shared host, and the batch would not be faster on most.
			 */
			'batch'     => array(
				'bundleUrl'   => SPPV_URL . 'assets/js/einvoice-batch.bundle.js?ver=' . self::asset_ver( 'assets/js/einvoice-batch.bundle.js' ),
				'concurrency' => 1,
				'tool'        => 'eInvoice Validator for VAMPIRE PHP',
				'texts'       => SPPV_Batch_Texts::all(),
			),

			// ⚠️ NAMED .js, NOT pdf.js's OWN .mjs. Found live on a real host: a server that serves
			// `.mjs` as `text/plain` makes the browser refuse the worker - and the browser decides by
			// the Content-Type header, never by the extension. Both files are verbatim copies of
			// pdfjs-dist's own build output, taken from the Viewer plugin, which hit this first.
			'pdfJsUrl'         => SPPV_URL . 'assets/js/pdfjs/pdf.min.js?ver=' . self::asset_ver( 'assets/js/pdfjs/pdf.min.js' ),
			'pdfJsWorkerUrl'   => SPPV_URL . 'assets/js/pdfjs/pdf.worker.min.js?ver=' . self::asset_ver( 'assets/js/pdfjs/pdf.worker.min.js' ),
			'modelBundleUrl'   => SPPV_URL . 'assets/js/einvoice-model.bundle.js?ver=' . self::asset_ver( 'assets/js/einvoice-model.bundle.js' ),

			'i18n'      => array(
				'loading'    => esc_html__( 'Checking the invoice …', 'einvoice-validator-for-vampire' ),
				'extracting' => esc_html__( 'Reading the invoice out of the PDF …', 'einvoice-validator-for-vampire' ),
				'tooLarge'   => esc_html__( 'The file is too large.', 'einvoice-validator-for-vampire' ),
				'wrongType'  => esc_html__( 'Please choose a PDF or XML file.', 'einvoice-validator-for-vampire' ),
				'noXmlInPdf' => esc_html__( 'This PDF carries no e-invoice. A ZUGFeRD or Factur-X PDF has the invoice attached as XML; a PDF that was merely printed does not.', 'einvoice-validator-for-vampire' ),
				'failed'     => esc_html__( 'The upload failed. Please try again.', 'einvoice-validator-for-vampire' ),
				/* translators: %d is the number of uploads the visitor still has left for the current day. */
				'remaining'  => esc_html__( 'Remaining uploads today: %d', 'einvoice-validator-for-vampire' ),
				'reportTitle' => esc_html__( 'Validation report', 'einvoice-validator-for-vampire' ),
				'download'   => esc_html__( 'Download report.xml', 'einvoice-validator-for-vampire' ),
				'downloadHtml' => esc_html__( 'Download report.html', 'einvoice-validator-for-vampire' ),
				'reset'      => esc_html__( 'Reset', 'einvoice-validator-for-vampire' ),
				// Only shown when the setting for the positive report is on and the engine supports it.
				// ⚠️ The same msgids as `rules_download_json` and `rules_download_print` in SPPV_Explainer_View, so
				// the buttons and the apps say the same.
				'downloadRuleReport' => esc_html__( 'Report with every rule ( JSON )', 'einvoice-validator-for-vampire' ),
				'downloadRulePrint'  => esc_html__( 'Print version of the checked rules ( HTML )', 'einvoice-validator-for-vampire' ),
			),
		) );

		$this->enqueued = true;

	}


	/**
	 * What the page needs to check in the browser with VAMPIRE JS, or null when it checks on the server as before.
	 *
	 * ⚠️ **VAMPIRE JS IS A PAID ADD-ON, NOT PART OF THIS PLUGIN** ( Ralf, 22.10.2026 ). This plugin only offers the docking
	 * point: the add-on answers the filter `sppv_browser_check` with true and loads VAMPIRE JS and its drawing script
	 * itself. Without it nothing changes, and this plugin carries no line of VAMPIRE JS.
	 *
	 * ⚠️ **TWO SETTINGS KEEP THE CHECK ON THE SERVER**, because the browser cannot do what they promise:
	 * - "Check against all editions" builds its table from the edition packages of VAMPIRE PHP.
	 * - Rule profiles live in this plugin's settings, and VAMPIRE JS does not read them. A browser check would leave the
	 *   operator's own rules out without a word.
	 * The settings page says so next to both settings.
	 *
	 * ⚠️ THE TEXTS ARE THOSE OF THE SERVER'S VIEW, with the same msgids, so that the page says the same in both ways.
	 *
	 * @return	array<string,mixed>|null
	 * @since	1.13.0
	 */

	public static function browser_config()
	{

		if ( ! apply_filters( 'sppv_browser_check', false ) )
			return( null );

		if ( (string) get_option( 'sppv_multi_edition', 'off' ) !== 'off' || SPPV_Rule_Profiles::files() !== array() )
			return( null );

		$data = SPPV_DIR . 'vendor/specialpress/einvoice-reference-data/dist/';
		$url  = SPPV_URL . 'vendor/specialpress/einvoice-reference-data/dist/';

		return( array(
			'lang'         => strtolower( substr( (string) determine_locale(), 0, 2 ) ),
			'ruleReport'   => (bool) get_option( 'sppv_rule_report', false ),
			// The switch "Rule text per line": the browser check draws the same section as the server ( wp-vampire-browser.mjs ).
			'ruleText'     => (bool) get_option( 'sppv_rule_text', false ),
			'cssUrl'       => SPPV_URL . 'assets/css/sppv-explainer.css?ver=' . self::asset_ver( 'assets/css/sppv-explainer.css' )
				. ' ' . self::kit_css()[ 'tokens' ]
				. ' ' . self::kit_css()[ 'kit' ],
			/*
			 * ⚠️ THE RULES IN THE EXPLAINER CUT, THE FIELDS IN FULL. The rule files are the ones SPPV_Rules reads on the server. The
			 * field catalogue of the explainer cut has no XPath for CII, and the browser then showed no value at a finding of a CII
			 * invoice: 79 of 167 reports equal to the server's with it, 151 with the full catalogue ( measured 22.10.2026 ).
			 */
			'rulesBaseUrl' => is_readable( $data . 'rules/INDEX.explainer.json' ) ? $url . 'rules/' : '',
			'rulesIndex'   => 'INDEX.explainer.json',
			'rulesSkip'    => trim( SPPV_Rules::EXTENSION_RULE_SETS, '/' ),
			'fieldsUrl'    => is_readable( $data . 'js/fields.mjs' ) ? $url . 'js/fields.mjs?ver=' . self::asset_ver( 'vendor/specialpress/einvoice-reference-data/dist/js/fields.mjs' ) : '',
			// ⚠️ Only without the add-on Extended Profiles, as in SPPV_Rules::extension_fields_missing().
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The filter name is the contract of the add-on einvoice-viewer-extended-profiles, which hooks its data folder into it; a renamed filter would never reach the add-on.
			'extensionNotice' => (string) apply_filters( 'einvoice_protected_data_dir', '' ) === '',
			'strings'      => SPPV_Explainer_View::browser_strings(),
			'noticeMap'    => SPPV_Validator::notice_map(),
			'texts'        => array(
				'notLoaded'    => __( 'VAMPIRE JS could not be loaded. Please reload the page.', 'einvoice-validator-for-vampire' ),
				'editionTitle' => __( 'No result: this edition is not included yet', 'einvoice-validator-for-vampire' ),
				/* translators: %s is the edition the document names in BT-24, e.g. "XRechnung 4.0" or "EN 16931-1:2026". */
				'editionDesc'  => __( 'The document names the edition %s in BT-24. This installation contains no rule set for that edition yet, so the document was not checked. This says nothing about whether the invoice is correct.', 'einvoice-validator-for-vampire' ),
				'missingTitle' => __( 'No result: the rule set for this document is not included', 'einvoice-validator-for-vampire' ),
				'missingNone'  => __( 'This installation does not contain the rule set this document needs, so the document was not checked. This says nothing about whether the invoice is correct.', 'einvoice-validator-for-vampire' ),
				/* translators: %s is a list of rule set names with their edition, e.g. "PEPPOL-EN16931-UBL@3.0.21". */
				'missingDesc'  => __( 'This installation does not contain the rule set %s, so the document was not checked. This says nothing about whether the invoice is correct.', 'einvoice-validator-for-vampire' ),
				'stamp'        => __( 'no result', 'einvoice-validator-for-vampire' ),
				'checking'     => __( 'Checking the invoice in your browser …', 'einvoice-validator-for-vampire' ),
				'extensionTitle' => __( 'This report names fields of an extension', 'einvoice-validator-for-vampire' ),
				'extensionDesc'  => __( 'The fields of the extension (BT-X) are shown by their number only, without their value from the document. The Extended Profiles add-on brings the catalogue that finds these values.', 'einvoice-validator-for-vampire' ),
			),
		) );

	}


	/**
	 * @return	int
	 * @since	1.0.0
	 */

	private function max_filesize()
	{
		$mb  = (int) get_option( 'sppv_max_filesize_mb', 8 );
		$max = ( $mb > 0 ? $mb : 8 ) * MB_IN_BYTES;

		return( (int) min( $max, wp_max_upload_size() ) );
	}


	/**
	 * Wraps the format names PDF and XML of an already translated sentence in <code>.
	 *
	 * ⚠️ THE MARKS STAY INSIDE THE SENTENCE. They highlight the file formats where the sentence names
	 * them, exactly as the viewer does it; a separate row of format boxes above or after the sentence
	 * was tried in the viewer and rejected.
	 *
	 * ⚠️ ESCAPE FIRST, WRAP SECOND. The sentence comes from a translation file; escaping after the
	 * wrap would turn the <code> tags into visible text, wrapping before the escape would let markup
	 * from a translation through. "PDF" and "XML" are not translated in any language, so the pattern
	 * finds them in every translation.
	 *
	 * @param	string	$sentence	translated hint sentence
	 * @return	string	escaped HTML with <code> around PDF and XML
	 * @since	1.4.5
	 */

	private static function format_marks( $sentence )
	{

		return( (string) preg_replace( '/\b(PDF|XML)\b/', '<code>$1</code>', esc_html( $sentence ) ) );

	}


	/**
	 * Render the [sppv_validator] shortcode.
	 *
	 * ⚠️ SAME SHADOW-DOM CONSTRUCTION AS THE SISTER PLUGINS. The <template> is moved into a Shadow
	 * DOM by JS, which keeps the theme's CSS out of the widget and the report's own styles out of
	 * the page.
	 *
	 * @param	array	$atts
	 * @return	string
	 * @since	1.0.0
	 */

	public function render_shortcode( $atts )
	{

		/*
		 * ⚠️ THE BATCH CHECK IS ON UNLESS THE PAGE SAYS `batch="no"`. It sends nothing the single check would not send ( one
		 * invoice per request, the same daily limit ), so an operator who offers the single check offers nothing new with
		 * it; a page that wants the old single drop area alone writes `[sppv_validator batch="no"]`.
		 */
		$atts  = shortcode_atts( array( 'batch' => 'yes' ), (array) $atts, SPPV_SHORTCODE );
		$batch = ! in_array( strtolower( (string) $atts[ 'batch' ] ), array( 'no', '0', 'false', 'off' ), true );

		$this->enqueue_assets();

		ob_start();
		?>
		<div class="sppv-host">
			<template>
				<div class="sppv-root">
					<?php if ( $batch ) : ?>
					<div class="tabs sppv-modes" role="tablist" aria-label="<?php echo esc_attr__( 'Kind of check', 'einvoice-validator-for-vampire' ); ?>">
						<button type="button" class="on" data-mode="single" role="tab" aria-selected="true"><?php echo esc_html__( 'Single check', 'einvoice-validator-for-vampire' ); ?></button>
						<button type="button" data-mode="batch" role="tab" aria-selected="false"><?php echo esc_html__( 'Batch check', 'einvoice-validator-for-vampire' ); ?></button>
					</div>
					<?php endif; ?>
					<div class="sppv-single">
						<div class="drop" tabindex="0" role="button" aria-label="<?php echo esc_attr__( 'Upload an e-invoice for validation', 'einvoice-validator-for-vampire' ); ?>">
							<input type="file" class="sppv-file" accept=".pdf,.xml,application/pdf,application/xml,text/xml" hidden />
							<div class="drop-title"><?php echo esc_html__( 'Drag an e-invoice here or click to choose a file', 'einvoice-validator-for-vampire' ); ?></div>
							<div class="drop-sub"><?php echo wp_kses( self::format_marks( __( 'ZUGFeRD / Factur-X PDF or CII / UBL XML', 'einvoice-validator-for-vampire' ) ), array( 'code' => array() ) ); ?></div>
						</div>
						<div class="sppv-message" role="status" aria-live="polite"></div>
						<div class="sppv-result"></div>
					</div>
					<?php if ( $batch ) : ?>
					<div class="sppv-batch" hidden>
						<p class="sppv-batch-lede muted"><?php echo esc_html__( 'Check many invoices at once: a month folder, a ZIP or a selection of files. Each invoice is sent to this website on its own, one after the other, checked there and not stored, and the overview lists the verdict of every file.', 'einvoice-validator-for-vampire' ); ?></p>
						<div class="sppv-batch-root"></div>
						<div class="sppv-batch-message" role="status" aria-live="polite" hidden></div>
						<div class="sppv-batch-result"></div>
					</div>
					<?php endif; ?>
				</div>
			</template>
		</div>
		<?php

		return( (string) ob_get_clean() );

	}


	/**
	 * AJAX: a fresh nonce, so a cached page still works.
	 *
	 * @return	void
	 * @since	1.0.0
	 */

	public function ajax_nonce()
	{
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'sppv_validate' ) ) );
	}


	/**
	 * The actual work, shared between the AJAX action and the REST route.
	 *
	 * ⚠️ THE THREE SERVER-GENERATED MEMBERS OF $upload ARE USED AS IS. wp_unslash() strips
	 * BACKSLASHES, and `tmp_name` is a backslash path on Windows - "C:\Users\x\phpA1B2.tmp" would
	 * become "C:UsersxphpA1B2.tmp" and is_uploaded_file() would fail for every single upload. Only
	 * `name` is client-supplied and gets sanitized.
	 *
	 * @param	array	$upload	one $_FILES-shaped sub-array
	 * @return	array			array{ ok: true, status: 200, data: array } or array{ ok: false, status: int, message: string }
	 * @since	1.0.0
	 */

	private function do_validate( array $upload )
	{

		/**
		 * ⚠️ THE ACCEPTANCE DECISION LIVES IN THE SHARED FRONTEND CORE: `\SpecialPress\EInvoice\FrontendCore\Upload::accept()`,
		 * the same class the validators for KoSIT and Mustang use. A rule that stands in three plugins is forgotten
		 * in two of them as soon as it changes. An empty file is refused before it is read, with its own message.
		 *
		 * ⚠️ `is_uploaded_file()` AND `finfo_file()` STAY HERE. Both are statements about this request
		 * and this file on disk; the core gets their results handed in and can be tested without
		 * WordPress. The messages stay here as well, with literal `__()` calls, because a library has no
		 * text domain WordPress could load.
		 *
		 * ⚠️ `$name` IS ITS OWN ASSIGNMENT, NOT ONLY AN ARGUMENT BELOW. The return value at the end uses it, and with
		 * `strict_types` a missing `$name` makes `pathinfo( null )` throw - only in the success case, which is why
		 * a test of the error path alone does not catch it.
		 */

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-generated path, validated with is_uploaded_file() below.
		$tmp = isset( $upload[ 'tmp_name' ] ) ? (string) $upload[ 'tmp_name' ] : '';

		$is_uploaded = $tmp !== '' && is_uploaded_file( $tmp );
		$mime        = ( $is_uploaded && function_exists( 'finfo_open' ) ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $tmp ) : '';
		$name        = isset( $upload[ 'name' ] ) ? sanitize_file_name( (string) $upload[ 'name' ] ) : '';

		$verdict = \SpecialPress\EInvoice\FrontendCore\Upload::accept(
			array(
				'name'     => $name,
				'size'     => isset( $upload[ 'size' ] ) ? (int) $upload[ 'size' ] : null,
				'error'    => $upload[ 'error' ] ?? null,
				'tmp_name' => $tmp,
			),
			$this->max_filesize(),
			$is_uploaded,
			$mime
		);

		if ( $verdict !== \SpecialPress\EInvoice\FrontendCore\Upload::OK )
		{

			$messages = array(
				\SpecialPress\EInvoice\FrontendCore\Upload::NO_FILE       => array( 400, esc_html__( 'No file received.', 'einvoice-validator-for-vampire' ) ),
				\SpecialPress\EInvoice\FrontendCore\Upload::UPLOAD_FAILED => array( 400, esc_html__( 'The upload failed. Please try again.', 'einvoice-validator-for-vampire' ) ),
				\SpecialPress\EInvoice\FrontendCore\Upload::TOO_LARGE     => array( 413, esc_html__( 'The file is too large.', 'einvoice-validator-for-vampire' ) ),
				\SpecialPress\EInvoice\FrontendCore\Upload::EMPTY_FILE    => array( 400, esc_html__( 'The file is empty.', 'einvoice-validator-for-vampire' ) ),
				\SpecialPress\EInvoice\FrontendCore\Upload::NOT_XML       => array( 415, esc_html__( 'Please choose an XML file.', 'einvoice-validator-for-vampire' ) ),
			);

			$m = $messages[ $verdict ] ?? array( 400, esc_html__( 'The file could not be accepted.', 'einvoice-validator-for-vampire' ) );

			return( array( 'ok' => false, 'status' => $m[0], 'message' => $m[1] ) );

		}

		// ⚠️ READ AS BYTES AND HANDED OVER AS BYTES. Nothing about this request writes the document
		// anywhere: the validator works in memory, so no copy of a customer's invoice ever reaches
		// the uploads folder or outlives the request.

		$content = file_get_contents( $tmp );

		if ( $content === false || $content === '' )
			return( array( 'ok' => false, 'status' => 400, 'message' => esc_html__( 'The file could not be read.', 'einvoice-validator-for-vampire' ) ) );

		/*
		 * The matrix of the multi-edition check, above the report, when the setting asks for it ( 'cascade' or
		 * 'matrix' ) and the engine carries the edition packages. ⚠️ OFF BY DEFAULT: the panel's texts have no German
		 * translation yet, and switching it on before the .po is done would put English into a German page.
		 *
		 * ⚠️ THE MATRIX RUNS FIRST, BECAUSE IT DECIDES WHICH REPORT STANDS BELOW IT ( Ralf, 21.10.2026: "ja" to the report
		 * of the chosen edition ). When the chosen edition has scenarios of its own, the report below and the downloaded
		 * report.xml are those of that edition; otherwise, and whenever that check fails, the single check stands there
		 * and the sentence under the matrix says so.
		 */
		$multi   = (string) get_option( 'sppv_multi_edition', 'off' );
		$matrix  = null;
		$edition = null;

		if ( ( $multi === 'cascade' || $multi === 'matrix' ) && class_exists( 'SPPV_Matrix_View' ) )
		{
			$matrix  = ( new SPPV_Validator() )->cascade( $content, $multi === 'matrix' );
			$edition = is_array( $matrix ) ? SPPV_Validator::edition_scenarios( $matrix ) : null;
		}

		$options = array( 'rule_report' => (bool) get_option( 'sppv_rule_report', false ) );
		$report  = null;

		if ( $edition !== null )
		{
			try
			{
				$report = ( new SPPV_Validator() )->validate( $content, $options + array( 'edition' => $edition ) );
			}
			catch ( \Throwable $e )
			{
				$report  = null;
				$edition = null;
			}
		}

		if ( $report === null )
		{
			try
			{
				$report = ( new SPPV_Validator() )->validate( $content, $options );
			}
			catch ( \Throwable $e )
			{
				return( array( 'ok' => false, 'status' => 422, 'message' => $e->getMessage() ) );
			}
		}

		self::ratelimit()->increment();

		$status = self::ratelimit()->status();
		$stem   = ( $name !== '' ? pathinfo( $name, PATHINFO_FILENAME ) : 'report' );

		// ⚠️ The uploaded document goes along - see report_view(): the view attaches it when the report
		// brings no document tree of its own.
		$view = $this->report_view( $report[ 'xml' ], $report[ 'html' ], $content, $report );

		if ( is_array( $matrix ) )
			$view[ 'html' ] = SPPV_Matrix_View::render( $matrix, $edition !== null ) . (string) $view[ 'html' ];

		$data = array(
			'html'      => $view[ 'html' ],
			'view'      => $view[ 'view' ],
			'cssUrl'    => $view[ 'cssUrl' ],
			'xml'       => $report[ 'xml' ],
			'filename'  => $stem . '-report.xml',
			'remaining' => $status[ 'remaining' ],
		);

		/*
		 * ⚠️ THE ENGINE'S OWN HTML REPORT, PASSED ON AS IT CAME ( Ralf, 21.10.2026: "ja, erster Weg" ). `HtmlReport` of VAMPIRE
		 * PHP writes a page that stands alone, with its style inline, to print or to mail. The plugin neither cuts it out of
		 * the XML report nor changes a word in it; like report.xml it is the original of the engine, and it is written in
		 * German whatever the language of the site, exactly as the KoSIT validator writes its report. No report, no button:
		 * a check that stopped at a rule has neither.
		 */
		if ( (string) ( $report[ 'html' ] ?? '' ) !== '' && (string) ( $report[ 'xml' ] ?? '' ) !== '' )
		{
			$data[ 'htmlReport' ]         = (string) $report[ 'html' ];
			$data[ 'htmlReportFilename' ] = $stem . '-report.html';
		}

		/*
		 * ⚠️ THE POSITIVE REPORT: A SECTION IN THE VIEW AND TWO FILES. The section "Checked rules" is already inside
		 * `html` ( report_view() ). The JSON is the engine's complete report with the rule lines - the original
		 * report of this engine, because the KoSIT-shaped XML has no place for them. The print version is the page
		 * to file next to the invoice. The keys are absent when the setting is off, so no button appears.
		 */
		if ( $report[ 'rule_report' ] !== null )
		{
			$data[ 'ruleReport' ]         = $report[ 'rule_report' ];
			$data[ 'ruleReportFilename' ] = $stem . '-rule-report.json';

			$print = self::rule_report_print( $report );

			if ( $print !== '' )
			{
				$data[ 'rulePrint' ]         = $print;
				$data[ 'rulePrintFilename' ] = $stem . '-checked-rules.html';
			}
		}

		return( array(
			'ok'     => true,
			'status' => 200,
			'data'   => $data,
		) );

	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public function ajax_validate()
	{
		// The language of the page that sent the request, not the profile language of a logged-in user:
		// admin-ajax.php counts as the admin area ( EInvoicePress_Request_Language ).
		EInvoicePress_Request_Language::switch_to_page_language();


		if ( ! check_ajax_referer( 'sppv_validate', 'nonce', false ) )
			wp_send_json_error( array( 'message' => esc_html__( 'Security check failed. Please reload the page.', 'einvoice-validator-for-vampire' ) ), 403 );

		if ( ! self::ratelimit()->status()[ 'allowed' ] )
			wp_send_json_error( array( 'message' => esc_html( self::limit_message() ) ), 429 );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each member is validated individually inside do_validate(); sanitizing the array would corrupt tmp_name on Windows.
		$upload = isset( $_FILES[ 'file' ] ) && is_array( $_FILES[ 'file' ] ) ? $_FILES[ 'file' ] : array();

		$result = $this->do_validate( $upload );

		if ( ! $result[ 'ok' ] )
			wp_send_json_error( array( 'message' => $result[ 'message' ] ), $result[ 'status' ] );

		wp_send_json_success( $result[ 'data' ] );

	}


	/**
	 * POST /wp-json/einvoice/v1/validate-php - nonce verification already happened in the route's
	 * own permission_callback.
	 *
	 * @param	\WP_REST_Request	$request
	 * @return	\WP_REST_Response
	 * @since	1.0.0
	 */

	public function rest_validate( \WP_REST_Request $request )
	{

		if ( ! self::ratelimit()->status()[ 'allowed' ] )
			return( new \WP_REST_Response( array( 'message' => esc_html( self::limit_message() ) ), 429 ) );

		$files  = $request->get_file_params();
		$upload = isset( $files[ 'file' ] ) && is_array( $files[ 'file' ] ) ? $files[ 'file' ] : array();

		$result = $this->do_validate( $upload );

		if ( ! $result[ 'ok' ] )
			return( new \WP_REST_Response( array( 'message' => $result[ 'message' ] ), $result[ 'status' ] ) );

		return( new \WP_REST_Response( $result[ 'data' ], 200 ) );

	}


	/**
	 * Turn the report XML into what the visitor sees.
	 *
	 * The way from report to view lives in the shared frontend core:
	 * `\SpecialPress\EInvoice\FrontendCore\ReportView::build()` reads the report, attaches the document if the
	 * report brings none, renders Report Lite, offers the filter `einvoice/report_html`, and falls back to the
	 * library's own HTML when even the reader fails. On top of that come the explorer view and the two
	 * statements on the rule sets below.
	 *
	 * ⚠️ THIS IS ALSO A TEST OF THE LIBRARY'S OWN REPORT. `einvoice-report`'s reader was written for
	 * the KoSIT validator's output; if it reads OUR report without a change, the format is genuinely
	 * the same one. If it ever cannot, the visitor still gets the validator's own HTML rather than
	 * nothing, and the fallback is visible in the `view` field rather than hidden.
	 *
	 * @param	string	$xml		the report, verbatim
	 * @param	string	$raw_html	the library's own HTML rendering, the last-resort fallback
	 * @param	string	$document	the uploaded document
	 * @param	array	$report		what SPPV_Validator::validate() returned ( verdict, notices, missing )
	 * @return	array				array{ view: string, html: string, cssUrl: string|null }
	 * @since	1.0.0
	 */

	private function report_view( $xml, $raw_html, $document = '', array $report = array() )
	{

		$explainer_css = SPPV_URL . 'assets/css/sppv-explainer.css?ver=' . self::asset_ver( 'assets/css/sppv-explainer.css' )
			. ' ' . self::kit_css()[ 'tokens' ]
			. ' ' . self::kit_css()[ 'kit' ];

		/**
		 * ⚠️ NO VERDICT IS NOT "REJECTED", AND IT IS NOT "PASSED" EITHER ( rule 29 ). Without the rule
		 * sets of its scenario the engine does not check the document; the report carries one note and
		 * no error. The explorer view counts errors, finds none and would show the green bar "No
		 * findings - the document passed". This plugin therefore shows its own panel instead of any
		 * rendering of that report. The raw report and the download keep what the engine wrote.
		 */
		$rule_section = isset( $report[ 'rule_section' ] ) && is_array( $report[ 'rule_section' ] ) ? $report[ 'rule_section' ] : null;

		if ( array_key_exists( 'verdict', $report ) && $report[ 'verdict' ] === null )
			return( array(
				'view'   => 'explainer',
				// ⚠️ The section goes along here as well: it is where "not checked, not part of this package" stands per rule set.
				'html'   => self::no_verdict_panel( (array) ( $report[ 'missing' ] ?? array() ), (string) ( $report[ 'edition' ] ?? '' ), (string) ( $report[ 'aborted' ] ?? '' ) ) . self::notices_panel( (array) ( $report[ 'notices' ] ?? array() ) ) . self::rule_report_section( $rule_section ),
				'cssUrl' => $explainer_css,
			) );

		if ( ! class_exists( '\SpecialPress\EInvoice\FrontendCore\ReportView' ) || ! class_exists( '\SpecialPress\EInvoice\Report\Reader' ) )
			return( array( 'view' => 'raw', 'html' => (string) $raw_html, 'cssUrl' => null ) );

		/*
		 * ⚠️ BEFORE THE FIRST READ OF THE REPORT. `ReportView::build()` reads it, an explorer reads it again through
		 * `einvoice/report_html`, and so does the explorer view below; every read passes `einvoice/report_read`, where
		 * `SPPV_Rule_Values` attaches the values of the rule to the report of this request.
		 */
		SPPV_Rule_Values::remember( (string) $xml, (array) ( $report[ 'rulesets' ] ?? array() ), (array) ( $report[ 'ruleset_dirs' ] ?? array() ) );

		$view =\SpecialPress\EInvoice\FrontendCore\ReportView::build(
			(string) $xml,
			(string) $raw_html,
			SPPV_URL . 'vendor/specialpress/einvoice-report/assets/css/einvoice-report.css?ver=' . self::asset_ver( 'vendor/specialpress/einvoice-report/assets/css/einvoice-report.css' )
			// ⚠️ Second address: the design kit the report view takes its look from. A stylesheet of the
			// document does not reach its shadow root. The own file stands first, the kit after it - see the
			// header of einvoice-report.css.
				. ' ' . self::kit_css()[ 'tokens' ]
				. ' ' . self::kit_css()[ 'kit' ],
			(string) $document
		);

		// The notes of the core belong into the view, not into the response - sppv-uploader.js does not
		// know the key and would drop it without a word.
		unset( $view[ 'notices' ] );

		/**
		 * ⚠️ **THE EXPLORER VIEW, ALSO WITHOUT THE EXPLORER**. Same rule as in the
		 * KoSIT validator: only when the view is still "lite" ( no plugin took over through the filter ),
		 * and an error falls back to "lite", never to nothing.
		 */
		if ( is_array( $view ) && ( $view[ 'view' ] ?? '' ) === 'lite' && SPPV_Rules::available() )
		{
			try
			{
				$view = array(
					'view'   => 'explainer',
					// ⚠️ `filter()` once more by hand: a neighbour's older copy of `Reader` may have been loaded first and calls no filter.
					'html'   => SPPV_Explainer_View::render( SPPV_Rule_Values::filter( \SpecialPress\EInvoice\Report\Reader::read( (string) $xml ), (string) $xml ), $rule_section ),
					'cssUrl' => $explainer_css,
				);
			}
			catch ( \Throwable $e )
			{
				// Report Lite stays - see above.
			}
		}

		/*
		 * ⚠️ THE SECTION ALSO UNDER REPORT LITE, AND UNDER AN OLDER RENDERER. The shared renderer writes it between
		 * findings and document only if its copy in vendor/ knows the second argument ( einvoice-report 1.15.0 ). A
		 * view without it - Report Lite, or the explorer view of an older copy - gets the section appended, so the
		 * rule lines never depend on which view happened to win. The raw view is the engine's own HTML and stays
		 * untouched; the print version below carries the rule lines in that case.
		 */
		if ( $rule_section !== null && is_array( $view ) && ( $view[ 'view' ] ?? '' ) !== 'raw' && isset( $view[ 'html' ] )
			&& strpos( (string) $view[ 'html' ], 'class="rules-card"' ) === false )
			$view[ 'html' ] = (string) $view[ 'html' ] . self::rule_report_section( $rule_section );

		/**
		 * ⚠️ THE VISIBLE NOTICE OF A RULE SET GOES ABOVE EVERY VIEW THAT IS NOT THE RAW ONE. FeRD makes
		 * it a condition for the Factur-X / ZUGFeRD rules ( rule 29 ). The KoSIT-shaped report carries the
		 * sentence only in its embedded explanation, which neither Report Lite nor the explorer view
		 * shows. The raw view is the library's own HTML and has the sentence already.
		 */
		$notices = (array) ( $report[ 'notices' ] ?? array() );

		if ( $notices !== array() && is_array( $view ) && ( $view[ 'view' ] ?? '' ) !== 'raw' && isset( $view[ 'html' ] ) )
			$view[ 'html' ] = self::notices_panel( $notices ) . (string) $view[ 'html' ];

		return( $view );

	}


	/**
	 * The panel for a document that got no verdict: its rule sets are not in this package, the edition it names is
	 * not included yet, or the check stopped at a rule it could not evaluate.
	 *
	 * ⚠️ THE KIT'S ORANGE BAR, NOT THE RED ONE. Red says "this invoice breaks a rule", and nobody has
	 * looked at a rule. The stamp says "no verdict" in the language of the site.
	 *
	 * ⚠️ THREE CAUSES, THREE SENTENCES, ONE STAMP. Each cause sends the reader somewhere else: a missing rule set to
	 * a rule package that contains it, an edition that is not included yet to a later update, an abort at a rule to
	 * the support with the engine's reason. An abort is no upload error and must not be shown in red. The order
	 * of the checks matters: an abort has no `missing` list at all, and an edition is one entry of that list.
	 *
	 * @param	string[]	$missing	the rule set references the scenario needs, e.g. "PEPPOL-EN16931-UBL@3.0.21"
	 * @param	string		$edition	the edition named in BT-24 that is not included, e.g. "XRechnung 4.0", or ''
	 * @param	string		$aborted	the engine's reason when the check stopped at a rule, or ''
	 * @return	string
	 * @since	09.10.2026
	 */

	private static function no_verdict_panel( array $missing, $edition = '', $aborted = '' )
	{

		if ( $aborted !== '' )
		{
			$title = esc_html__( 'No result: the check stopped at a rule it could not evaluate', 'einvoice-validator-for-vampire' );
			$desc  = sprintf(
				/* translators: %s is the reason the validation engine gives, in English, e.g. 'unsupported XPath construct "is" at position 12 in: …'. */
				esc_html__( 'A rule of the rule set could not be evaluated for this document, so the check was stopped. No other finding is shown, and there is no result. This says nothing about whether the invoice is correct. The engine reports: %s', 'einvoice-validator-for-vampire' ),
				'<code>' . esc_html( $aborted ) . '</code>'
			);
		}
		elseif ( $edition !== '' )
		{
			$title = esc_html__( 'No result: this edition is not included yet', 'einvoice-validator-for-vampire' );
			$desc  = sprintf(
				/* translators: %s is the edition the document names in BT-24, e.g. "XRechnung 4.0" or "EN 16931-1:2026". */
				esc_html__( 'The document names the edition %s in BT-24. This installation contains no rule set for that edition yet, so the document was not checked. This says nothing about whether the invoice is correct.', 'einvoice-validator-for-vampire' ),
				'<code>' . esc_html( $edition ) . '</code>'
			);
		}
		else
		{
			$list  = implode( ', ', array_map( 'strval', $missing ) );
			$title = esc_html__( 'No result: the rule set for this document is not included', 'einvoice-validator-for-vampire' );
			$desc  = ( $list === ''
				? esc_html__( 'This installation does not contain the rule set this document needs, so the document was not checked. This says nothing about whether the invoice is correct.', 'einvoice-validator-for-vampire' )
				: sprintf(
					/* translators: %s is a list of rule set names with their edition, e.g. "PEPPOL-EN16931-UBL@3.0.21". */
					esc_html__( 'This installation does not contain the rule set %s, so the document was not checked. This says nothing about whether the invoice is correct.', 'einvoice-validator-for-vampire' ),
					'<code>' . esc_html( $list ) . '</code>'
				) );
		}

		return( '<div class="verdict warn"><div class="verdict-main"><div>'
			. '<div class="verdict-title">' . $title . '</div>'
			. '<div class="verdict-desc">' . $desc . '</div>'
			. '</div></div>'
			. '<div class="verdict-stamp">' . esc_html__( 'no result', 'einvoice-validator-for-vampire' ) . '</div>'
			. '</div>' );

	}


	/**
	 * The visible notices of the rule sets that ran, as one note of the kit.
	 *
	 * @param	string[]	$notices	already in the language of the site ( SPPV_Validator::translate_notice() )
	 * @return	string	'' when there is nothing to say
	 * @since	09.10.2026
	 */

	private static function notices_panel( array $notices )
	{

		if ( $notices === array() )
			return( '' );

		$html = '';

		foreach ( $notices as $notice )
			$html .= '<div>' . esc_html( (string) $notice ) . '</div>';

		return( '<div class="note info sppv-rule-notices" role="note">' . $html . '</div>' );

	}


	/**
	 * The section "Checked rules" on its own, for the views the shared renderer does not draw.
	 *
	 * ⚠️ THE CLASS COMES WITH `einvoice-report` 1.15.0. With an older copy in vendor/ this returns '' and the
	 * downloads stay the only form; nothing breaks, and the JSON still carries every line.
	 *
	 * @param	array|null	$rule_section	the section `ruleReport` of the engine's JSON report
	 * @return	string	HTML, already escaped by the shared class
	 * @since	09.10.2026
	 */

	private static function rule_report_section( $rule_section )
	{

		if ( ! is_array( $rule_section ) || ! class_exists( '\SpecialPress\EInvoice\Report\Explainer\RuleReportSection' ) )
			return( '' );

		return( ( new \SpecialPress\EInvoice\Report\Explainer\RuleReportSection( SPPV_Explainer_View::rule_report_strings(), self::rule_report_options() ) )->render( $rule_section ) );

	}


	/**
	 * The options of the section "Checked rules": the switch "Rule text per line" of the settings.
	 *
	 * ⚠️ ONE PLACE FOR THE THREE DRAWINGS. The section in the report, the section after a view the shared renderer does
	 * not draw, and the print version must agree; a print version with texts under a section without them would be a
	 * second report.
	 *
	 * @return	array{rule_text: bool}
	 * @since	01.11.2026
	 */

	public static function rule_report_options()
	{
		return( array( 'rule_text' => (bool) get_option( 'sppv_rule_text', false ) ) );
	}


	/**
	 * The print version of the positive report: a page of its own in the language of the site.
	 *
	 * ⚠️ A FILE THE VISITOR SAVES, NOT ONE THIS PLUGIN STORES. Same promise as for the document itself: nothing
	 * of this request is written to the server. Saving as PDF happens in the browser's print dialog; why there is
	 * no PDF made on the server yet is in STATUS.md.
	 *
	 * @param	array	$report	what SPPV_Validator::validate() returned
	 * @return	string	a complete HTML document, '' without the section or without the shared class
	 * @since	09.10.2026
	 */

	private static function rule_report_print( array $report )
	{

		$rule_section = isset( $report[ 'rule_section' ] ) && is_array( $report[ 'rule_section' ] ) ? $report[ 'rule_section' ] : null;

		if ( $rule_section === null || ! class_exists( '\SpecialPress\EInvoice\Report\Explainer\RuleReportSection' ) )
			return( '' );

		$lang = strtolower( substr( (string) determine_locale(), 0, 2 ) );

		return( ( new \SpecialPress\EInvoice\Report\Explainer\RuleReportSection( SPPV_Explainer_View::rule_report_strings(), self::rule_report_options() ) )
			->document( $rule_section, $lang, array_key_exists( 'verdict', $report ) ? $report[ 'verdict' ] : null ) );

	}



	/**
	 * The texts of this validator's button in the viewer, translated in this plugin's own text domain.
	 *
	 * ⚠️ THE TEXTS BELONG TO THE VALIDATOR, NOT TO THE VIEWER. The viewer only asks for them; a validator that is
	 * added later brings its button name along, and the viewer needs no new text for it.
	 *
	 * ⚠️ LITERAL __() CALLS, so the texts reach this plugin's .pot.
	 *
	 * @return	array{button:string,loading:string,failed:string}
	 * @since	1.10.0
	 */

	public static function bridge_texts()
	{
		$texts = array(
			'button'  => __( 'Validate with VAMPIRE', 'einvoice-validator-for-vampire' ),
			'loading' => __( 'Running the VAMPIRE validator …', 'einvoice-validator-for-vampire' ),
			'failed'  => __( 'The validation failed. Please try again.', 'einvoice-validator-for-vampire' ),
		);

		/*
		 * ⚠️ THE BROWSER CHECK IN THE VIEWER, WHEN VAMPIRE JS IS THERE ( viewer from 2.40.0 ). The same two conditions as on
		 * this plugin's own page: browser_config() says whether a setting needs the server, and the add-on names the addresses
		 * of its two scripts through `sppv_browser_scripts`. The viewer loads them only on the click, because VAMPIRE JS with
		 * its rule packages weighs about 11 MB and most visitors of a viewer page never press the button. An older viewer
		 * ignores both keys and keeps checking on the server.
		 */
		$browser = self::browser_config();
		$scripts = $browser !== null ? apply_filters( 'sppv_browser_scripts', null ) : null;

		if ( $browser !== null && is_array( $scripts ) && ! empty( $scripts[ 'engine' ] ) && ! empty( $scripts[ 'draw' ] ) )
		{
			$texts[ 'browser' ] = $browser;
			$texts[ 'scripts' ] = array( 'engine' => (string) $scripts[ 'engine' ], 'draw' => (string) $scripts[ 'draw' ] );
		}

		return( $texts );
	}


	/**
	 * The entry of this engine for `einvoice_service_validators`.
	 *
	 * @param	array	$validators
	 * @return	array
	 * @since	2.0.0
	 */

	public function service_validator( $validators )
	{
		$validators = is_array( $validators ) ? $validators : array();
		$validators[ 'vampire' ] = array(
			'version' => SPPV_VERSION,
			'handle'  => array( $this, 'service_validate' ),
			'allowed' => array( __CLASS__, 'service_allowed' ),
		);

		return $validators;
	}


	/**
	 * Starts the shared routes of the service contract on `plugins_loaded`, after the newest copy of `einvoice-host-wp` on the
	 * site has loaded its classes ( `load.php`, priority -1000 ). The viewer starts them as well; `ServiceRoutes::init()`
	 * runs only once.
	 *
	 * @return	void
	 */

	public static function service_routes()
	{
		if ( class_exists( '\\SpecialPress\\EInvoice\\Host\\ServiceRoutes' ) )
			\SpecialPress\EInvoice\Host\ServiceRoutes::init();
	}


	/**
	 * Who may use `/validate/vampire` without a key: every visitor whose role the table "Daily uploads per role" does not
	 * set to 0. The number itself is checked in `service_validate()`, so that a full day answers 429 with the waiting time
	 * and not 403.
	 *
	 * @return	bool
	 */

	public static function service_allowed()
	{
		return( ( self::ratelimit()->status()[ 'reason' ] ?? '' ) !== 'role' );
	}


	/**
	 * `POST /validate/vampire` of contract 2: the invoice as the raw body, its name in `X-Filename`.
	 *
	 * ⚠️ STATUS 200 ALSO FOR AN INVALID INVOICE. The verdict travels in `X-Validation-Result`; only a request that could not be
	 * checked is an error, as `{ error, message }` through `ServiceRoutes::error()`.
	 *
	 * ⚠️ `format=xml` IS THE VALIDATOR'S OWN REPORT, unchanged; the apps read it with the same readers as the explorers.
	 * Without it the answer is a short JSON summary, with the report under `report` when `report=1`.
	 *
	 * @param	\WP_REST_Request	$request
	 * @return	\WP_REST_Response
	 * @since	2.0.0
	 */

	public function service_validate( $request )
	{
		$routes = '\\SpecialPress\\EInvoice\\Host\\ServiceRoutes';
		$bytes  = (string) $request->get_body();
		$name   = sanitize_file_name( rawurldecode( (string) $request->get_header( 'x_filename' ) ) );

		if ( $bytes === '' )
			return $routes::error( 'bad_request', __( 'The request carries no invoice.', 'einvoice-validator-for-vampire' ), 400 );

		// ⚠️ The same size limit as an upload on the validator page; the route is open to guests.
		if ( strlen( $bytes ) > $this->max_filesize() )
			return $routes::error( 'too_large', __( 'The file is too large.', 'einvoice-validator-for-vampire' ), 413 );

		/*
		 * ⚠️ A REQUEST WITHOUT A KEY COUNTS AGAINST THE DAILY LIMIT OF ITS VISITOR, as on the validator page of this site. An app
		 * that names this site as its source would otherwise check without end. A key that was sent is valid here: the route
		 * refuses a wrong one with 401 before this handler runs ( `ServiceRoutes::validate()` ).
		 */
		$keyed = (string) $request->get_header( 'x_api_key' ) !== '';

		if ( ! $keyed && ! self::ratelimit()->status()[ 'allowed' ] )
		{
			$response = $routes::error( 'quota_exceeded', self::limit_message(), 429 );
			// The counter starts again at midnight in the time zone of the site.
			$response->header( 'Retry-After', (string) ( DAY_IN_SECONDS - ( (int) current_time( 'timestamp' ) % DAY_IN_SECONDS ) ) );
			return $response;
		}

		try
		{
			$report = ( new SPPV_Validator() )->validate( $bytes, array() );
			$xml    = (string) ( $report[ 'xml' ] ?? '' );
			$valid  = isset( $report[ 'verdict' ] ) && is_bool( $report[ 'verdict' ] ) ? $report[ 'verdict' ] : null;
		}
		catch ( \Throwable $e )
		{
			return $routes::error( 'validator_error', $e->getMessage(), 502 );
		}

		if ( ! $keyed )
			self::ratelimit()->increment();

		$verdict = $valid === true ? 'valid' : ( $valid === false ? 'invalid' : 'unknown' );

		if ( (string) $request->get_param( 'format' ) === 'xml' )
		{
			$response = new \WP_REST_Response( null, 200 );
			$response->header( 'X-Validation-Result', $verdict );
			$response->header( 'Content-Type', 'application/xml; charset=utf-8' );
			// ⚠️ The raw report, not JSON: `rest_pre_serve_request` below sends it as it is.
			$response->set_data( array( '__raw' => $xml ) );
			add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_raw' ), 10, 2 );
			return $response;
		}

		$out = array( 'validator' => 'vampire', 'version' => SPPV_VERSION, 'file' => $name, 'valid' => $valid );

		if ( $request->get_param( 'report' ) )
			$out[ 'report' ] = $xml;

		$response = new \WP_REST_Response( $out, 200 );
		$response->header( 'X-Validation-Result', $verdict );

		return $response;
	}


	/**
	 * Sends the raw report of `service_validate()` as it is, instead of as JSON.
	 *
	 * @param	bool				$served
	 * @param	\WP_REST_Response	$result
	 * @return	bool
	 * @since	2.0.0
	 */

	public static function serve_raw( $served, $result )
	{
		$data = $result instanceof \WP_REST_Response ? $result->get_data() : null;

		if ( $served || ! is_array( $data ) || ! array_key_exists( '__raw', $data ) )
			return $served;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the validator's XML report, sent as application/xml, never as HTML.
		echo (string) $data[ '__raw' ];

		return true;
	}


	/** The handle of the button in hosts of interface 8. */
	const VIEWER_HANDLE = 'sppv-site-validator';


	/**
	 * Answers a host of interface 8 with the button "Validate with VAMPIRE".
	 *
	 * ⚠️ THE CHECK STAYS ON THIS WEBSITE, with the engine this plugin's settings name. The bundle posts to `sppv_validate`
	 * and draws with `SPPV_renderReport`, which `enqueue_bridge_script()` loads; nothing else is asked, and no other validator
	 * stands in for this one.
	 *
	 * ⚠️ "AVAILABLE FROM ROLE" OF THE VIEWER decides first, as it did for the viewer's own button ( slug `vampire` ).
	 *
	 * @param	array	$list
	 * @param	string	$host
	 * @return	array
	 * @since	2.0.0
	 */

	public function viewer_extension( $list, $host = 'viewer' )
	{
		if ( ! in_array( $host, array( 'viewer', 'inbound', 'outbound' ), true ) )
			return $list;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the viewer's own filter, shared by its add-ons.
		if ( ! apply_filters( 'spev_extension_allowed', true, 'vampire' ) )
			return $list;

		$file = 'assets/js/einvoice-site-validator.bundle.js';

		if ( ! is_readable( SPPV_DIR . $file ) )
			return $list;

		$this->enqueue_bridge_script();

		if ( ! wp_script_is( self::VIEWER_HANDLE, 'registered' ) )
		{
			wp_register_script( self::VIEWER_HANDLE, SPPV_URL . $file, array( 'sppv-uploader' ), SPPV_VERSION . '.' . (string) filemtime( SPPV_DIR . $file ), true );
			wp_add_inline_script( self::VIEWER_HANDLE, '( window.einvoiceSiteValidators = window.einvoiceSiteValidators || [] ).push( ' . wp_json_encode( array(
				'engine'  => 'vampire',
				'prefix'  => 'sppv',
				'render'  => 'SPPV_renderReport',
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'texts'   => self::bridge_texts(),
				'xmlOnly' => false,
			) ) . ' );', 'before' );
			add_filter( 'script_loader_tag', array( __CLASS__, 'viewer_module_tag' ), 10, 2 );
		}

		$list   = is_array( $list ) ? $list : array();
		$list[] = array( 'id' => 'validator-vampire', 'handle' => self::VIEWER_HANDLE );

		return $list;
	}


	/**
	 * The bundle is an ES module; the inline block in front of it stays a classic script.
	 *
	 * @param	string	$tag
	 * @param	string	$handle
	 * @return	string
	 * @since	2.0.0
	 */

	public static function viewer_module_tag( $tag, $handle )
	{
		if ( $handle !== self::VIEWER_HANDLE || strpos( (string) $tag, 'type="module"' ) !== false )
			return $tag;

		return (string) preg_replace( '/<script(?![^>]*\bid="' . self::VIEWER_HANDLE . '-js-(?:before|after)")/', '<script type="module"', (string) $tag, 1 );
	}


	/**
	 * Loads this plugin's report script on a page of the viewer, for its button "Validate with VAMPIRE".
	 *
	 * The viewer sends the invoice to this plugin's own `sppv_validate` and draws the answer with
	 * `window.SPPV_renderReport()`, which this script exposes. Called by the viewer on 'wp_enqueue_scripts'.
	 *
	 * ⚠️ NEVER OVERWRITE A RICHER window.SPPV. If this plugin's own shortcode stands on the same page, its full
	 * configuration wins; `$enqueued` is set right after that richer call, and the shortcode's own call, should it
	 * run later, overwrites this one unconditionally.
	 *
	 * ⚠️ THE STYLESHEET OF THIS PLUGIN IS NOT LOADED HERE. Its `.drop` selectors are written for its own shadow
	 * root and would restyle the viewer's page; the viewer styles the few classes the report head uses itself.
	 *
	 * @return	void
	 * @since	1.10.0
	 */

	public function enqueue_bridge_script()
	{

		if( ! wp_script_is( 'sppv-uploader', 'registered' ) )
			$this->register_handles();

		wp_enqueue_script( 'sppv-uploader' );

		if( $this->enqueued )
			return;

		// ⚠️ THE TWO TEXTS OF THE REPORT HEAD, NOT THE WHOLE TABLE: the bridge only ever draws a report.
		wp_localize_script( 'sppv-uploader', 'SPPV', array(
			'bridgeOnly' => true,
			'i18n'       => array(
				'download' => esc_html__( 'Download report.xml', 'einvoice-validator-for-vampire' ),
				'downloadHtml' => esc_html__( 'Download report.html', 'einvoice-validator-for-vampire' ),
				'reset'    => esc_html__( 'Reset', 'einvoice-validator-for-vampire' ),
				'report'   => esc_html__( 'Validation report', 'einvoice-validator-for-vampire' ),
				// ⚠️ The two buttons of the positive report as well; without them the viewer page showed both in English ( 05.11.2026 ).
				'downloadRuleReport' => esc_html__( 'Report with every rule ( JSON )', 'einvoice-validator-for-vampire' ),
				'downloadRulePrint'  => esc_html__( 'Print version of the checked rules ( HTML )', 'einvoice-validator-for-vampire' ),
			),
		) );

	}


	/**
	 * The cache buster of an own asset: the plugin version plus the modification time of the file.
	 *
	 * ⚠️ THE VERSION ALONE IS NOT ENOUGH WHILE A FASSUNG IS STILL IN TEST. An undelivered ZIP is rebuilt under the same
	 * number, and a browser that had the page open kept the old stylesheet and script: on 18.10.2026 Ralf saw the
	 * filled buttons and "Show invoice" in the title bar after both had long been changed. The file time changes with
	 * every installed copy of a changed file.
	 *
	 * @param	string	$rel	path below the plugin folder
	 * @return	string
	 * @since	18.10.2026
	 */

	private static function asset_ver( $rel )
	{
		$time = @filemtime( SPPV_DIR . $rel );

		return( SPPV_VERSION . ( $time ? '.' . $time : '' ) );
	}


	/**
	 * The texts of Report Lite, the plain view of a report the validator shows when its rule data is missing.
	 *
	 * ⚠️ LITERAL `__()` CALLS IN THIS PLUGIN'S TEXT DOMAIN. The shared library asks for them through the filter
	 * `einvoice/report_lite_texts` ( libs/einvoice-report, Lite\Renderer::t() ), because it has no text domain of its
	 * own. Until 18.10.2026 Report Lite stood in English on every German page.
	 *
	 * @param	array	$texts	what other plugins answered; this plugin's texts are added
	 * @return	array	English text => translation
	 * @since	18.10.2026
	 */

	public static function lite_texts( $texts )
	{
		return( array_merge( (array) $texts, array(
			'Accepted' => __( 'Accepted', 'einvoice-validator-for-vampire' ),
			'Rejected' => __( 'Rejected', 'einvoice-validator-for-vampire' ),
			'%1$d error(s), %2$d warning(s), %3$d notice(s)' => /* translators: 1: errors, 2: warnings, 3: notices */
				__( '%1$d error(s), %2$d warning(s), %3$d notice(s)', 'einvoice-validator-for-vampire' ),
			'No findings.' => __( 'No findings.', 'einvoice-validator-for-vampire' ),
			'Errors' => __( 'Errors', 'einvoice-validator-for-vampire' ),
			'Warnings' => __( 'Warnings', 'einvoice-validator-for-vampire' ),
			'Notices' => __( 'Notices', 'einvoice-validator-for-vampire' ),
			'error' => __( 'error', 'einvoice-validator-for-vampire' ),
			'warning' => __( 'warning', 'einvoice-validator-for-vampire' ),
			'information' => __( 'information', 'einvoice-validator-for-vampire' ),
			'Validation step' => __( 'Validation step', 'einvoice-validator-for-vampire' ),
			'Information' => __( 'Information', 'einvoice-validator-for-vampire' ),
			'Content of the invoice document' => __( 'Content of the invoice document', 'einvoice-validator-for-vampire' ),
			'Pos.' => __( 'Pos.', 'einvoice-validator-for-vampire' ),
			'Element' => __( 'Element', 'einvoice-validator-for-vampire' ),
			'Value' => __( 'Value', 'einvoice-validator-for-vampire' ),
		) ) );
	}

}
