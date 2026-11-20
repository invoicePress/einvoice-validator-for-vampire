<?php
/**
 * Settings page ( Settings > eInvoice Validator PHP ) and the systemcheck.
 *
 * ⚠️ FOUR SETTINGS, NOT THIRTY. The sibling plugin registers 32 options, and 26 of them describe how
 * to reach some OTHER validator - daemon URL, HTTP transport, SOAP template, three kinds of
 * authentication, API keys. This plugin has nothing to reach: the validator is in the same process.
 * What is left is what the site owner actually decides - how large an upload may be and how many a
 * visitor gets per day.
 *
 * @package SP_EInvoice_Validator_PHP
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;


class SPPV_Settings
{


	const GROUP = 'sppv_settings';
	const PAGE  = 'sppv-settings';


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public static function init()
	{
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );

		// ⚠️ admin-post.php, NOT options.php. The rule package is a file upload with its own checks and
		// its own messages; the Settings API form cannot carry a file and would answer every refusal
		// with "Settings saved".
		add_action( 'admin_post_sppv_rule_package_upload', array( __CLASS__, 'handle_rule_package_upload' ) );
		add_action( 'admin_post_sppv_rule_package_revert', array( __CLASS__, 'handle_rule_package_revert' ) );

		// The rule profiles of the Rule Studio, for the same reason: a file upload with its own checks.
		add_action( 'admin_post_sppv_rule_profile_upload', array( __CLASS__, 'handle_rule_profile_upload' ) );
		add_action( 'admin_post_sppv_rule_profile_delete', array( __CLASS__, 'handle_rule_profile_delete' ) );
	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public static function menu()
	{
		/*
		 * ⚠️ BELOW THE MENU ENTRY "eInvoice Validator", NOT UNDER SETTINGS ( Ralf, 22.10.2026: one entry for the
		 * validators, one for the explorers ). The shared class of einvoice-host-wp adds the entry with the first plugin of
		 * the group and sends an old address under Settings on. Without the class ( an older copy of the library won ) the
		 * page stays under Settings, as before.
		 */
		if ( ! class_exists( '\\SpecialPress\\EInvoice\\Host\\AdminMenu' ) )
		{
			foreach ( array( '/libs/einvoice-host-wp/src/AdminMenu.php', '/vendor/specialpress/einvoice-host-wp/src/AdminMenu.php' ) as $file )
			{
				if ( is_readable( dirname( __DIR__ ) . $file ) )
				{
					require_once dirname( __DIR__ ) . $file;
					break;
				}
			}
		}

		if ( class_exists( '\\SpecialPress\\EInvoice\\Host\\AdminMenu' ) )
		{
			\SpecialPress\EInvoice\Host\AdminMenu::add( 'validator', array(
				'slug'      => self::PAGE,
				'title'     => __( 'eInvoice Validator for VAMPIRE PHP', 'einvoice-validator-for-vampire' ),
				'menu'      => 'VAMPIRE',
				'callback'  => array( __CLASS__, 'page' ),
				'version'   => SPPV_VERSION,
				'shortcode' => '[' . SPPV_SHORTCODE . ']',
				'labels'    => array(
					'overview'         => __( 'Overview', 'einvoice-validator-for-vampire' ),
					'intro'            => __( 'The validators active on this site. Each one has its own page with its settings.', 'einvoice-validator-for-vampire' ),
					'column_name'      => __( 'Plugin', 'einvoice-validator-for-vampire' ),
					'column_version'   => __( 'Version', 'einvoice-validator-for-vampire' ),
					'column_shortcode' => __( 'Shortcode', 'einvoice-validator-for-vampire' ),
					'open'             => __( 'Open', 'einvoice-validator-for-vampire' ),
				),
			) );

			return;
		}

		add_options_page(
			esc_html__( 'eInvoice Validator for VAMPIRE PHP', 'einvoice-validator-for-vampire' ),
			esc_html__( 'eInvoice Validator for VAMPIRE PHP', 'einvoice-validator-for-vampire' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public static function register()
	{

		register_setting( self::GROUP, 'sppv_max_filesize_mb', array(
			'type'              => 'integer',
			'sanitize_callback' => array( __CLASS__, 'sanitize_filesize' ),
			'default'           => 8,
		) );

		/*
		 * ⚠️ THE TABLE PER ROLE REPLACES THE SINGLE NUMBER `sppv_daily_limit`, WHICH IS NO LONGER REGISTERED. Registered
		 * without its input field, saving the page would write null into it. Its stored value stays and is the
		 * default of every role until the table is first saved ( RoleRateLimit::roleLimits() ).
		 */
		register_setting( self::GROUP, SPPV_Plugin::ratelimit()->optionRoleLimits(), array(
			'type'              => 'array',
			'sanitize_callback' => array( 'SpecialPress\EInvoice\Host\RoleRateLimit', 'sanitizeRoleLimits' ),
		) );

		register_setting( self::GROUP, 'sppv_trust_proxy', array(
			'type'              => 'boolean',
			'sanitize_callback' => static function ( $value ) { return( (bool) $value ); },
			'default'           => false,
		) );

		register_setting( self::GROUP, 'sppv_rule_report', array(
			'type'              => 'boolean',
			'sanitize_callback' => static function ( $value ) { return( (bool) $value ); },
			'default'           => false,
		) );

		// The switch "Rule text per line" of the positive report, see field_rule_text().
		register_setting( self::GROUP, 'sppv_rule_text', array(
			'type'              => 'boolean',
			'sanitize_callback' => static function ( $value ) { return( (bool) $value ); },
			'default'           => false,
		) );

		// The multi-edition check. ⚠️ An unknown value falls back to 'off', never to the most expensive one.
		register_setting( self::GROUP, 'sppv_multi_edition', array(
			'type'              => 'string',
			'sanitize_callback' => static function ( $value ) { return( in_array( $value, array( 'off', 'cascade', 'matrix' ), true ) ? $value : 'off' ); },
			'default'           => 'off',
		) );

		add_settings_section( 'sppv_main', '', '__return_empty_string', self::PAGE );

		add_settings_field(
			'sppv_max_filesize_mb',
			esc_html__( 'Maximum upload size', 'einvoice-validator-for-vampire' ),
			array( __CLASS__, 'field_filesize' ),
			self::PAGE,
			'sppv_main'
		);

		add_settings_field(
			'sppv_role_limits',
			esc_html__( 'Daily uploads per role', 'einvoice-validator-for-vampire' ),
			array( __CLASS__, 'field_limit' ),
			self::PAGE,
			'sppv_main'
		);

		add_settings_field(
			'sppv_trust_proxy',
			esc_html__( 'Behind a proxy or CDN', 'einvoice-validator-for-vampire' ),
			array( __CLASS__, 'field_proxy' ),
			self::PAGE,
			'sppv_main'
		);

		add_settings_field(
			'sppv_rule_report',
			esc_html__( 'Positive test report', 'einvoice-validator-for-vampire' ),
			array( __CLASS__, 'field_rule_report' ),
			self::PAGE,
			'sppv_main'
		);

		add_settings_field(
			'sppv_rule_text',
			esc_html__( 'Rule text per line', 'einvoice-validator-for-vampire' ),
			array( __CLASS__, 'field_rule_text' ),
			self::PAGE,
			'sppv_main'
		);

		add_settings_field(
			'sppv_multi_edition',
			esc_html__( 'Check against every edition', 'einvoice-validator-for-vampire' ),
			array( __CLASS__, 'field_multi_edition' ),
			self::PAGE,
			'sppv_main'
		);

		// ⚠️ ONE OPTION FOR ALL EINVOICE TOOLS, NOT A SETTING OF THIS PLUGIN - see EInvoicePress_Design_Kit.
		EInvoicePress_Design_Kit::register_setting( self::GROUP, SPPV_DIR . 'vendor/specialpress/einvoice-report/assets/css/', false, true, self::kit_upload_texts() );

		add_settings_field(
			EInvoicePress_Design_Kit::OPTION,
			esc_html__( 'Design kit', 'einvoice-validator-for-vampire' ),
			array( __CLASS__, 'field_design_kit' ),
			self::PAGE,
			'sppv_main'
		);

	}


	/**
	 * The texts of the kit upload under the select, in this plugin's text domain.
	 *
	 * ⚠️ They are passed to register_setting() too, because the messages are needed while the form is
	 * saved, a request in which field_design_kit() does not run.
	 *
	 * @return	array<string,string>
	 */

	private static function kit_upload_texts()
	{
		return( array(
			'label'      => __( 'Upload a kit of your own', 'einvoice-validator-for-vampire' ),
			'hint'       => __( 'A token file ( .css ) as the tool kit ships it, at most 32 KB. Only its custom properties under :root and :host are kept. After saving, the kit is chosen for every eInvoice tool.', 'einvoice-validator-for-vampire' ),
			'failed'     => __( 'The kit could not be stored. Please try again.', 'einvoice-validator-for-vampire' ),
			'too_large'  => __( 'The kit file is larger than 32 KB.', 'einvoice-validator-for-vampire' ),
			'not_tokens' => __( 'This file is not a token file of a design kit. It may only hold custom properties under :root and :host, among them --bg and --accent.', 'einvoice-validator-for-vampire' ),
			'stored'     => __( 'The kit was stored and is now chosen for every eInvoice tool.', 'einvoice-validator-for-vampire' ),
		) );
	}


	/**
	 * The select of the design kit, shared by all eInvoice tools.
	 *
	 * @return	void
	 * @since	1.6.0
	 */

	public static function field_design_kit()
	{
		EInvoicePress_Design_Kit::render_field(
			SPPV_DIR . 'vendor/specialpress/einvoice-report/assets/css/',
			false,
			__( 'The choice applies to all eInvoice tools on this site. Kit H is the default.', 'einvoice-validator-for-vampire' ),
			self::kit_upload_texts()
		);
	}


	/**
	 * @param	mixed	$value
	 * @return	int
	 * @since	1.0.0
	 */

	public static function sanitize_filesize( $value )
	{
		$mb = (int) $value;

		return( $mb > 0 && $mb <= 64 ? $mb : 8 );
	}


	/**
	 * @param	mixed	$value
	 * @return	int
	 * @since	1.0.0
	 */

	public static function sanitize_limit( $value )
	{
		$limit = (int) $value;

		return( $limit >= 0 && $limit <= 1000 ? $limit : 20 );
	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public static function field_filesize()
	{
		printf(
			'<input type="number" min="1" max="64" name="sppv_max_filesize_mb" value="%d" class="small-text" /> MB<p class="description">%s</p>',
			(int) get_option( 'sppv_max_filesize_mb', 8 ),
			esc_html__( 'The server\'s own upload limit still applies and wins when it is lower.', 'einvoice-validator-for-vampire' )
		);
	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public static function field_limit()
	{
		SPPV_Plugin::ratelimit()->renderRoleLimitsField( array(
			'guest' => __( 'Guest ( not logged in )', 'einvoice-validator-for-vampire' ),
			'role'  => __( 'Role', 'einvoice-validator-for-vampire' ),
			'limit' => __( 'Uploads per day', 'einvoice-validator-for-vampire' ),
			'help'  => __( 'Per calendar day. Empty = unlimited, 0 = no access. Logged-in users are counted by user, guests by IP address. A user with several roles gets the most generous value; administrators are never limited.', 'einvoice-validator-for-vampire' ),
		) );
	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public static function field_proxy()
	{
		printf(
			'<label><input type="checkbox" name="sppv_trust_proxy" value="1" %s /> %s</label><p class="description">%s</p>',
			checked( (bool) get_option( 'sppv_trust_proxy', false ), true, false ),
			esc_html__( 'Read the visitor address from X-Forwarded-For', 'einvoice-validator-for-vampire' ),
			esc_html__( 'Only switch this on when a proxy really sits in front - otherwise a visitor can claim any address and the daily limit stops working.', 'einvoice-validator-for-vampire' )
		);
	}


	/**
	 * The switch for the positive test report.
	 *
	 * ⚠️ OFF BY DEFAULT, AS IN THE LIBRARY. Off leaves every response exactly as it was. On, the visitor gets the
	 * section "Checked rules" in the report and two downloads with every rule of the rule sets used and its
	 * result.
	 *
	 * ⚠️ THE ENGINE IN THE PLUGIN MAY BE TOO OLD FOR IT. The field then says so and stays disabled; a
	 * checked box that does nothing would be worse than none.
	 *
	 * @return	void
	 * @since	09.10.2026
	 */

	public static function field_rule_report()
	{
		$supported = SPPV_Validator::supports_rule_report();

		printf(
			'<label><input type="checkbox" name="sppv_rule_report" value="1" %s %s /> %s</label><p class="description">%s</p>',
			checked( (bool) get_option( 'sppv_rule_report', false ), true, false ),
			disabled( $supported, false, false ),
			esc_html__( 'Show every checked rule with its result', 'einvoice-validator-for-vampire' ),
			$supported
				// ⚠️ The setting governs the section in the report and both downloads, not only one file.
				? esc_html__( 'The report gets a section "Checked rules" that names every rule of the rule sets that were used, each with its result: satisfied, violated, not applicable or not checked. It also names each rule set with its edition, the time of the check and a checksum of the document. The visitor can download the full report with every rule as JSON and a print version as HTML, which the browser can save as PDF. The section is technical evidence of a check, not an audit opinion.', 'einvoice-validator-for-vampire' )
				: esc_html__( 'The validator engine in this installation cannot produce this list yet. It comes with the next update of the plugin.', 'einvoice-validator-for-vampire' )
		);
	}


	/**
	 * The switch "Rule text per line" of the positive report ( Konzept v10.1, 5.5.4 ).
	 *
	 * ⚠️ OFF BY DEFAULT. The rule text is the one column of the section "Checked rules" that wraps; with it many rules
	 * take two lines or more, and the print version grew to between 1.1 and 1.9 times its pages ( three invoices of the
	 * corpus, measured 01.11.2026, figures in `libs/einvoice-report/STATUS.md` ). The JSON download is the engine's
	 * original report and keeps every text either way.
	 *
	 * ⚠️ IT ONLY MATTERS WITH THE POSITIVE REPORT. The box stays usable without it, because a disabled box would make the
	 * order of the two clicks matter; the description says that it has no effect alone.
	 *
	 * @return	void
	 * @since	01.11.2026
	 */

	public static function field_rule_text()
	{
		printf(
			'<label><input type="checkbox" name="sppv_rule_text" value="1" %s /> %s</label><p class="description">%s</p>',
			checked( (bool) get_option( 'sppv_rule_text', false ), true, false ),
			esc_html__( 'Add the text of the rule to every line of the checked rules', 'einvoice-validator-for-vampire' ),
			esc_html__( 'Only with the positive test report. Each line of the section "Checked rules" and of its print version then also shows what the rule demands. The print version then becomes up to twice as long. The download as JSON always contains the texts.', 'einvoice-validator-for-vampire' )
		);
	}


	/**
	 * The multi-edition check: off, the cascade, or the full matrix.
	 *
	 * ⚠️ DISABLED, NOT HIDDEN, WITHOUT THE EDITION PACKAGES. The engine of this installation may lack the class or
	 * the rule packages per edition; the field then says why, like the field of the positive report above.
	 *
	 * @return	void
	 * @since	09.10.2026
	 */

	/**
	 * The sentence that a setting keeps the check on the server, shown only while the add-on VAMPIRE JS is active.
	 *
	 * ⚠️ THE OPERATOR MUST KNOW WHY THE BROWSER CHECK DOES NOT HAPPEN. Both the edition table and the rule profiles need
	 * VAMPIRE PHP; with either of them switched on, `SPPV_Plugin::browser_config()` sends every check to the server, and
	 * without this sentence the add-on would look broken.
	 *
	 * @return	void
	 * @since	1.13.0
	 */

	private static function browser_note()
	{
		if ( ! apply_filters( 'sppv_browser_check', false ) )
			return;

		printf( '<p class="description">%s</p>', esc_html__( 'The add-on VAMPIRE JS is active. While this setting is used, invoices are checked on the server, because the check in the browser cannot apply it.', 'einvoice-validator-for-vampire' ) );
	}


	public static function field_multi_edition()
	{
		$supported = SPPV_Validator::supports_cascade();
		$value     = (string) get_option( 'sppv_multi_edition', 'off' );

		$options = array(
			'off'     => __( 'Off: the single check only', 'einvoice-validator-for-vampire' ),
			'cascade' => __( 'Cascade: from the newest edition down until one accepts the invoice', 'einvoice-validator-for-vampire' ),
			'matrix'  => __( 'Full matrix: every edition', 'einvoice-validator-for-vampire' ),
		);

		echo '<select name="sppv_multi_edition"' . ( $supported ? '' : ' disabled' ) . '>';

		foreach ( $options as $key => $label )
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $label ) );

		echo '</select>';

		printf( '<p class="description">%s</p>', $supported
			? esc_html__( 'A ZUGFeRD or Factur-X invoice does not say which edition it follows. With this setting, every upload is also checked against the editions of its format, and a table above the report shows the result of each edition. The full matrix takes up to seven times as long.', 'einvoice-validator-for-vampire' )
			: esc_html__( 'The validator engine in this installation carries no rule packages per edition. They come with an update of the plugin or of the rule package.', 'einvoice-validator-for-vampire' ) );

		self::browser_note();
	}


	/**
	 * @return	void
	 * @since	1.0.0
	 */

	public static function page()
	{

		if ( ! current_user_can( 'manage_options' ) )
			return;

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'eInvoice Validator for VAMPIRE PHP', 'einvoice-validator-for-vampire' ); ?></h1>

			<?php
			/*
			 * ⚠️ THE TAG COMES FROM `SPPV_SHORTCODE`, NOT FROM THE TRANSLATED TEXT. Otherwise it would travel through
			 * all language files at every rename, and whoever forgets one names a tag to the administrator that is
			 * not registered.
			 */
			?>
			<p><?php echo sprintf(
				/* translators: %s is the literal shortcode tag, not translated. */
				esc_html__( 'Place the %s shortcode on a page to offer the upload form.', 'einvoice-validator-for-vampire' ),
				'<code>[' . esc_html( SPPV_SHORTCODE ) . ']</code>'
			); ?></p>

			<?php self::render_rule_package_notice(); ?>

			<form method="post" action="options.php">
				<?php
					settings_fields( self::GROUP );
					do_settings_sections( self::PAGE );
					submit_button();
				?>
			</form>

			<?php self::render_rule_packages(); ?>
			<?php self::render_rule_profiles(); ?>
			<?php self::render_systemcheck(); ?>
			<?php self::render_scope(); ?>

		</div>
		<?php

	}


	/**
	 * The section "Rule packages": which rule sets are in use, the upload form, and the way back.
	 *
	 * ⚠️ A FORM OF ITS OWN, OUTSIDE THE SETTINGS FORM ABOVE. HTML does not nest forms, and the settings
	 * form posts to options.php without `enctype="multipart/form-data"`.
	 *
	 * @return	void
	 * @since	09.10.2026
	 */

	private static function render_rule_packages()
	{

		$uploaded = SPPV_Rule_Packages::installed();
		$active   = ( SPPV_Rule_Packages::active_dir() !== '' );

		echo '<h2>' . esc_html__( 'Rule packages', 'einvoice-validator-for-vampire' ) . '</h2>';

		if ( $active && $uploaded !== null )
		{
			echo '<p>' . sprintf(
				/* translators: 1: edition of the rule package, e.g. "2026-10-09"; 2: date and time of the upload. */
				esc_html__( 'This site checks with the uploaded rule package, edition %1$s, installed on %2$s.', 'einvoice-validator-for-vampire' ),
				'<strong>' . esc_html( (string) $uploaded[ 'edition' ] ) . '</strong>',
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $uploaded[ 'installed' ] ) )
			) . '</p>';
		}
		else
		{
			echo '<p>' . esc_html__( 'This site checks with the rule packages bundled with the plugin.', 'einvoice-validator-for-vampire' ) . '</p>';

			// ⚠️ The option names a package, but its files are gone ( a restore or a cleanup ). Say so,
			// otherwise the operator believes the uploaded edition is in use.
			if ( $uploaded !== null )
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'An uploaded rule package is registered, but its files are missing. Upload it again or switch back to the bundled rule packages.', 'einvoice-validator-for-vampire' ) . '</p></div>';
		}

		$rows = SPPV_Rule_Packages::read_rulesets( SPPV_Validator::rules_dir() );

		if ( $rows !== array() )
		{
			echo '<table class="widefat" style="max-width:900px"><thead><tr>';
			echo '<th>' . esc_html__( 'Rule set', 'einvoice-validator-for-vampire' ) . '</th>';
			echo '<th>' . esc_html__( 'Edition', 'einvoice-validator-for-vampire' ) . '</th>';
			echo '<th>' . esc_html__( 'Licence of the rule set', 'einvoice-validator-for-vampire' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row )
				echo '<tr><td><code>' . esc_html( $row[ 'id' ] ) . '</code></td><td>' . esc_html( $row[ 'version' ] ) . '</td><td>' . esc_html( $row[ 'license' ] !== '' ? $row[ 'license' ] : '–' ) . '</td></tr>';

			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'Origin, rights holder and licence text of every rule set are in the file NOTICE and the folder licences next to the rule sets.', 'einvoice-validator-for-vampire' ) . '</p>';
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="sppv_rule_package_upload" />
			<?php wp_nonce_field( 'sppv_rule_package_upload' ); ?>
			<p>
				<label for="sppv-rule-package"><?php echo esc_html__( 'Install a rule package ( ZIP file )', 'einvoice-validator-for-vampire' ); ?></label><br />
				<input type="file" id="sppv-rule-package" name="sppv_rule_package" accept=".zip,application/zip" required />
			</p>
			<p class="description"><?php echo esc_html__( 'The package replaces the rule sets this site checks with. The validator engine itself stays as it is. A package that does not fit this plugin is refused, and the current rule sets stay in use.', 'einvoice-validator-for-vampire' ); ?></p>
			<?php submit_button( __( 'Install rule package', 'einvoice-validator-for-vampire' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php

		if ( $uploaded !== null )
		{
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="sppv_rule_package_revert" />
				<?php wp_nonce_field( 'sppv_rule_package_revert' ); ?>
				<?php submit_button( __( 'Switch back to the bundled rule packages', 'einvoice-validator-for-vampire' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php
		}

	}


	/**
	 * The section "Rule profiles": the profiles of the Rule Studio this site applies, the upload and the delete.
	 *
	 * ⚠️ A REFUSED PROFILE IS LISTED IN RED, NOT HIDDEN. A file that sits in the folder and cannot be loaded stops
	 * every profile ( SPPV_Validator::validate() ), and the operator must see which file that is.
	 *
	 * @return	void
	 * @since	13.10.2026
	 */

	private static function render_rule_profiles()
	{

		echo '<h2>' . esc_html__( 'Rule profiles', 'einvoice-validator-for-vampire' ) . '</h2>';
		echo '<p>' . esc_html__( 'A rule profile from the Rule Studio adds your own rules to the official ones, or switches official rules off, replaces them or changes their severity. Every finding of a profile is marked with the profile\'s name in the report, so a rule of your own never looks like a rule of the standard.', 'einvoice-validator-for-vampire' ) . '</p>';

		self::browser_note();

		$rows = SPPV_Rule_Profiles::overview();

		if ( $rows === array() )
		{
			echo '<p>' . esc_html__( 'No rule profile is installed. Invoices are checked against the official rule sets only.', 'einvoice-validator-for-vampire' ) . '</p>';
		}
		else
		{
			echo '<table class="widefat" style="max-width:900px"><thead><tr>';
			echo '<th>' . esc_html__( 'Profile', 'einvoice-validator-for-vampire' ) . '</th>';
			echo '<th>' . esc_html__( 'Applies to', 'einvoice-validator-for-vampire' ) . '</th>';
			echo '<th>' . esc_html__( 'Content', 'einvoice-validator-for-vampire' ) . '</th>';
			echo '<th></th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row )
			{
				echo '<tr><td>';

				if ( $row[ 'error' ] !== '' )
				{
					echo '<code>' . esc_html( $row[ 'file' ] ) . '</code><br /><div class="notice notice-error inline"><p>' . esc_html( sprintf(
						/* translators: %s is the engine's English reason why the file was refused. */
						__( 'This file cannot be loaded, and no profile is applied while it is here: %s', 'einvoice-validator-for-vampire' ),
						$row[ 'error' ]
					) ) . '</p></div>';
				}
				else
				{
					echo '<code>' . esc_html( $row[ 'ref' ] ) . '</code>';

					if ( $row[ 'title' ] !== '' )
						echo '<br />' . esc_html( $row[ 'title' ] );
				}

				echo '</td><td>' . esc_html( $row[ 'syntax' ] );

				foreach ( $row[ 'extends' ] as $e )
					echo '<br /><code>' . esc_html( $e ) . '</code>';

				echo '</td><td>';

				if ( $row[ 'error' ] === '' )
					echo esc_html( sprintf(
						/* translators: 1: number of own rules; 2: number of official rules switched off. */
						_n( '%1$d own rule, %2$d official rules switched off', '%1$d own rules, %2$d official rules switched off', $row[ 'rules' ], 'einvoice-validator-for-vampire' ),
						$row[ 'rules' ],
						$row[ 'disabled' ]
					) );

				echo '</td><td>';
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sppv_rule_profile_delete" />
					<input type="hidden" name="profile" value="<?php echo esc_attr( $row[ 'name' ] ); ?>" />
					<?php wp_nonce_field( 'sppv_rule_profile_delete' ); ?>
					<?php submit_button( __( 'Delete', 'einvoice-validator-for-vampire' ), 'link-delete small', 'submit', false ); ?>
				</form>
				<?php
				echo '</td></tr>';
			}

			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'The profiles are applied in the order of their names. A profile written for UBL does not apply to a CII invoice, and the other way round.', 'einvoice-validator-for-vampire' ) . '</p>';
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="sppv_rule_profile_upload" />
			<?php wp_nonce_field( 'sppv_rule_profile_upload' ); ?>
			<p>
				<label for="sppv-rule-profile"><?php echo esc_html__( 'Install a rule profile from the Rule Studio ( .json or .js file )', 'einvoice-validator-for-vampire' ); ?></label><br />
				<input type="file" id="sppv-rule-profile" name="sppv_rule_profile" accept=".json,.js,application/json,text/javascript" required />
			</p>
			<p class="description"><?php echo esc_html__( 'A profile with the same name replaces the installed one. A file that is not a valid profile is refused, and the installed profiles stay as they are.', 'einvoice-validator-for-vampire' ); ?></p>
			<?php submit_button( __( 'Install rule profile', 'einvoice-validator-for-vampire' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php

	}


	/**
	 * admin-post: install an uploaded rule profile.
	 *
	 * @return	void
	 * @since	13.10.2026
	 */

	public static function handle_rule_profile_upload()
	{

		if ( ! current_user_can( 'manage_options' ) )
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'einvoice-validator-for-vampire' ), 403 );

		check_admin_referer( 'sppv_rule_profile_upload' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is server-generated and checked with is_uploaded_file(); the name is only used for its extension.
		$file = isset( $_FILES[ 'sppv_rule_profile' ] ) && is_array( $_FILES[ 'sppv_rule_profile' ] ) ? $_FILES[ 'sppv_rule_profile' ] : array();
		$tmp  = isset( $file[ 'tmp_name' ] ) ? (string) $file[ 'tmp_name' ] : '';

		if ( $tmp === '' || ! is_uploaded_file( $tmp ) || (int) ( $file[ 'error' ] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK )
		{
			self::remember_notice( 'error', esc_html__( 'No file received. Choose the profile file and try again.', 'einvoice-validator-for-vampire' ) );
		}
		else
		{
			try
			{
				$ref = SPPV_Rule_Profiles::install( $tmp, sanitize_file_name( (string) ( $file[ 'name' ] ?? '' ) ) );

				self::remember_notice( 'success', sprintf(
					/* translators: %s is the profile, e.g. "kunde-ust@1.0.0". */
					esc_html__( 'The rule profile %s is installed. From now on this site applies it.', 'einvoice-validator-for-vampire' ),
					esc_html( $ref )
				) );
			}
			catch ( \Throwable $e )
			{
				self::remember_notice( 'error', $e->getMessage() );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;

	}


	/**
	 * admin-post: delete one rule profile.
	 *
	 * @return	void
	 * @since	13.10.2026
	 */

	public static function handle_rule_profile_delete()
	{

		if ( ! current_user_can( 'manage_options' ) )
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'einvoice-validator-for-vampire' ), 403 );

		check_admin_referer( 'sppv_rule_profile_delete' );

		$name = isset( $_POST[ 'profile' ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ 'profile' ] ) ) : '';

		if ( SPPV_Rule_Profiles::delete( $name ) )
			self::remember_notice( 'success', sprintf(
				/* translators: %s is the profile name, e.g. "kunde-ust". */
				esc_html__( 'The rule profile %s is deleted.', 'einvoice-validator-for-vampire' ),
				esc_html( $name )
			) );
		else
			self::remember_notice( 'error', esc_html__( 'The rule profile could not be deleted.', 'einvoice-validator-for-vampire' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;

	}


	/**
	 * admin-post: install an uploaded rule package.
	 *
	 * ⚠️ THE REFUSAL TEXT COMES FROM SPPV_Rule_Packages AND IS ALREADY ESCAPED THERE. It is kept for one
	 * page view in a transient per user and printed with esc_html() once more; escaping twice turns an
	 * apostrophe into visible "&#039;" in French and English, so the transient holds the unescaped
	 * form ( html_entity_decode ) and the output escapes it exactly once.
	 *
	 * @return	void
	 * @since	09.10.2026
	 */

	public static function handle_rule_package_upload()
	{

		if ( ! current_user_can( 'manage_options' ) )
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'einvoice-validator-for-vampire' ), 403 );

		check_admin_referer( 'sppv_rule_package_upload' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is server-generated and checked with is_uploaded_file(); sanitizing would break Windows paths.
		$file = isset( $_FILES[ 'sppv_rule_package' ] ) && is_array( $_FILES[ 'sppv_rule_package' ] ) ? $_FILES[ 'sppv_rule_package' ] : array();
		$tmp  = isset( $file[ 'tmp_name' ] ) ? (string) $file[ 'tmp_name' ] : '';

		if ( $tmp === '' || ! is_uploaded_file( $tmp ) || (int) ( $file[ 'error' ] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK )
		{
			self::remember_notice( 'error', esc_html__( 'No file received. Choose the ZIP file of the rule package and try again.', 'einvoice-validator-for-vampire' ) );
		}
		else
		{
			try
			{
				$info = SPPV_Rule_Packages::install( $tmp );

				self::remember_notice( 'success', sprintf(
					/* translators: %s is the edition of the rule package, e.g. "2026-10-09". */
					esc_html__( 'The rule package, edition %s, is installed. From now on this site checks with it.', 'einvoice-validator-for-vampire' ),
					esc_html( (string) $info[ 'edition' ] )
				) );
			}
			catch ( \Throwable $e )
			{
				self::remember_notice( 'error', $e->getMessage() );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;

	}


	/**
	 * admin-post: back to the bundled rule packages.
	 *
	 * @return	void
	 * @since	09.10.2026
	 */

	public static function handle_rule_package_revert()
	{

		if ( ! current_user_can( 'manage_options' ) )
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'einvoice-validator-for-vampire' ), 403 );

		check_admin_referer( 'sppv_rule_package_revert' );

		SPPV_Rule_Packages::revert();

		self::remember_notice( 'success', esc_html__( 'This site checks with the rule packages bundled with the plugin again. The uploaded package is deleted.', 'einvoice-validator-for-vampire' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;

	}


	/**
	 * @param	string	$type		'success' or 'error'
	 * @param	string	$message	escaped text
	 * @return	void
	 * @since	09.10.2026
	 */

	private static function remember_notice( $type, $message )
	{
		set_transient( 'sppv_rule_package_notice_' . get_current_user_id(), array(
			'type'    => ( $type === 'success' ? 'success' : 'error' ),
			'message' => html_entity_decode( (string) $message, ENT_QUOTES, 'UTF-8' ),
		), 5 * MINUTE_IN_SECONDS );
	}


	/**
	 * Print and forget the message of the last upload or switch.
	 *
	 * @return	void
	 * @since	09.10.2026
	 */

	private static function render_rule_package_notice()
	{

		$key    = 'sppv_rule_package_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) )
			return;

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice[ 'type' ] === 'success' ? 'success' : 'error' ),
			esc_html( (string) $notice[ 'message' ] )
		);

	}


	/**
	 * ⚠️ RENDERED FRESH ON EVERY PAGE LOAD, NEVER CACHED. A green line that survives the host
	 * lowering `memory_limit` would defeat the whole point of the check.
	 *
	 * @return	void
	 * @since	1.0.0
	 */

	private static function render_systemcheck()
	{

		echo '<h2>' . esc_html__( 'Systemcheck', 'einvoice-validator-for-vampire' ) . '</h2>';
		echo '<table class="widefat" style="max-width:900px"><tbody>';

		foreach ( ( new SPPV_Validator() )->check() as $line )
		{
			$colour = $line[ 'ok' ] ? '#067647' : '#b91c1c';
			$symbol = $line[ 'ok' ] ? '&#10003;' : '&#10007;';

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $symbol is always one of the two hardcoded HTML-entity literals above ('&#10003;'/'&#10007;'), never user input; esc_html() would double-encode the '&' and print the raw entity text instead of the glyph.
			echo '<tr><td style="color:' . esc_attr( $colour ) . ';font-weight:bold;width:24px">' . $symbol . '</td>';
			echo '<td>' . esc_html( $line[ 'label' ] );

			if ( $line[ 'detail' ] !== '' )
				echo '<br /><span class="description">' . esc_html( $line[ 'detail' ] ) . '</span>';

			if ( ! $line[ 'ok' ] && $line[ 'next_step' ] !== '' )
				echo '<br /><span class="description" style="color:' . esc_attr( $colour ) . '">' . esc_html( $line[ 'next_step' ] ) . '</span>';

			echo '</td></tr>';
		}

		echo '</tbody></table>';

	}


	/**
	 * What this installation can and cannot check - read from the shipped scenario list, not from a
	 * hand-kept text.
	 *
	 * ⚠️ A LIST WRITTEN BY HAND GOES STALE AND NOBODY NOTICES. Which rule packages travel with the
	 * plugin is a build decision ( see the library's devkit/Cli/export-runtime.php ); if it changes,
	 * this table changes with it, because it reads the same file the validator reads.
	 *
	 * @return	void
	 * @since	1.0.0
	 */

	private static function render_scope()
	{

		// rules_dir(): after an upload the table must show the scenarios of the package that is in use.
		$file = SPPV_Validator::rules_dir() . '/rulesets/scenarios.json';

		if ( ! is_file( $file ) )
			return;

		$raw  = file_get_contents( $file );
		$data = ( $raw === false ? null : json_decode( $raw, true ) );

		if ( ! is_array( $data ) )
			return;

		echo '<h2>' . esc_html__( 'What this installation checks', 'einvoice-validator-for-vampire' ) . '</h2>';
		echo '<table class="widefat" style="max-width:900px"><thead><tr>';
		echo '<th>' . esc_html__( 'Document type', 'einvoice-validator-for-vampire' ) . '</th>';
		echo '<th>' . esc_html__( 'Rule packages', 'einvoice-validator-for-vampire' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $data as $scenario )
		{
			if ( ! is_array( $scenario ) )
				continue;

			$refs = isset( $scenario[ 'rulesetRefs' ] ) && is_array( $scenario[ 'rulesetRefs' ] ) ? $scenario[ 'rulesetRefs' ] : array();

			// ⚠️ Escape each entry, THEN join. Escaping the joined string would turn the <code> tags
			// into visible text - the classic order mistake in exactly this kind of loop.
			$cells = array();

			foreach ( $refs as $ref )
				$cells[] = '<code>' . esc_html( (string) $ref ) . '</code>';

			echo '<tr><td>' . esc_html( (string) ( $scenario[ 'name' ] ?? '?' ) ) . '</td>';
			echo '<td>' . wp_kses( implode( ', ', $cells ), array( 'code' => array() ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'A document matching none of these is reported as "no scenario applies" - deliberately, because that is a different statement from "checked and accepted".', 'einvoice-validator-for-vampire' ) . '</p>';

	}


}
