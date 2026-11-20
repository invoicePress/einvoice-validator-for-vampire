<?php
/**
 * The rule and business-term data: what a rule id means, what a BT number is called.
 *
 * @package SP_EInvoice_Validation_Explainer
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) )
	exit;

/*
 * ⚠️ PORTED FROM `einvoice-validation-explorer-for-kosit`, AND KEPT IN STEP WITH IT.
 *
 * The validator shows its own report in the same plain-language view as the Validation Explorer,
 * whether or not the explorer is installed. The view itself lives in libs/einvoice-report; what a
 * library cannot carry - the rule lookup and the translatable strings with a literal text domain -
 * has to live in each plugin. A change to the explorer's copy of this file belongs here as well.
 */


/**
 * class: SPPV_Rules
 *
 * ============================================================================================
 * TWO SOURCES, TWO DIRECTIONS
 * ============================================================================================
 *
 *   data/*.json (19 files, data/INDEX.json)  rule id -> severity, description, the BT/BG fields it touches
 *   factur-x_en16931_with_rules.json         BT/BG id -> business term, description, usage note in de/en/fr
 *
 * The explainer needs both, and in both directions: a finding names a rule and wants its fields, a
 * document row names a field and wants its term.
 *
 * ⚠️ UNTIL 2026-09-03 THIS READ A SINGLE business_rules_complete.json THAT NO LONGER EXISTS - it was
 * replaced on 2026-09-02 by the per-standard split under data/ (data/INDEX.json), and rule() silently
 * returned an empty array for every id in between (checked: no error, no notice - the read()-tolerates-
 * absence design in this class exists precisely so a missing data file degrades quietly, which here
 * meant the gap went unnoticed until a renderer feature that depends on rule()['fields'] was built
 * against it and measured zero results). load_rules() now walks every file data/INDEX.json lists, in
 * the order the index gives them - EN16931-CII first, same "the more general statement wins on a
 * shared rule id" precedent the single-file version already used, still enforced by first-occurrence-
 * wins below.
 *
 * ============================================================================================
 * ⚠️ THE FILES ARE SEVERAL MB TOTAL. THEY ARE LOADED LAZILY AND ONLY THE PART THAT IS NEEDED.
 * ============================================================================================
 *
 * A page that merely contains the shortcode must not pay for that. Nothing here touches the disk
 * until a report has actually been uploaded, and the index built from the rules files keeps only what
 * the view uses - not the Schematron test expressions, which are the bulk of each file.
 *
 * ============================================================================================
 * ⚠️ THREE THINGS COUNT AS "NO TEXT"
 * ============================================================================================
 *
 * null, the empty string, AND the literal "0". The last one is an export artefact: an empty
 * spreadsheet cell that came through as a number. Measured in the supplied data it appears in
 * businessTerm.en, businessTerm.fr and description.de. Treating "0" as content would print a bare
 * zero where a field name belongs.
 *
 * The language fallback is de -> en -> fr, in that order, because the German texts are the ones the
 * data set is most complete in and English is the specification's own language.
 *
 * @since 1.0.0
 */

class SPPV_Rules
{


	const DATA_INDEX = 'data/INDEX.json';
	const DATA_DIR   = 'data/';
	const FILE_TERMS = 'factur-x_en16931_with_rules.json';

	/**
	 * The rule sets of the extensions, by the file name the rules index lists them under ( both tiers ).
	 * The first group is the profile name the "Extended Profiles" add-on uses in its own index - see
	 * protected_rules().
	 *
	 * @since 08.10.2026
	 */
	const EXTENSION_RULE_SETS = '/^(factur-x-extended|france-extended-ctc-cii|france-extended-ctc-ubl)(?:\.explainer)?\.json$/';


	/** @var array|null rule id => array( severity, description[], fields[], standard ) */
	private static $rules = null;

	/** @var array|null BT id => array( term[], description[], note[] ) */
	private static $terms = null;

	/**
	 * Overrides for the shared einvoice-reference-data package - see set_shared_rules_dir() and
	 * set_shared_terms_file(). Null means "not configured": load_rules()/load_terms() then use
	 * dir() . DATA_DIR / dir() . FILE_TERMS exactly as before this class knew about the shared
	 * package. Separate from dir()/sppv_data_dir on purpose: the package does not mirror this
	 * plugin's own "data/" + bare-filename layout ( it uses "rules/" and "fields/<name>.json" - see
	 * libs/einvoice-reference-data/STATUS.md ), so one directory filter cannot redirect both paths
	 * at once.
	 *
	 * @since 1.1.0
	 */

	private static $shared_rules_dir = null;
	private static $shared_terms_file = null;

	/**
	 * Which index file inside the rules directory is read - see load_rules().
	 *
	 * @since 1.2.11
	 */

	private static $shared_rules_index = 'INDEX.json';


	/**
	 * Whether a lookup asked for a field of an extension ( BT-X-…, BG-X-… ) that no loaded catalogue
	 * knows - see extension_fields_missing().
	 *
	 * @since 08.10.2026
	 */

	private static $extension_missing = false;


	/**
	 * Point rule loading at libs/einvoice-reference-data's rules/ directory ( e.g.
	 * "vendor/specialpress/einvoice-reference-data/dist/rules" ) instead of this plugin's own
	 * data/. Not calling this keeps the original, fully self-contained behaviour.
	 *
	 * @param	string|null	$path
	 * @param	string		$index	which index inside that directory to read - `INDEX.explainer.json`
	 *								for the projected tier, `INDEX.json` for the full one
	 * @return	void
	 * @since	1.1.0
	 */

	public static function set_shared_rules_dir( $path, $index = 'INDEX.json' )
	{
		self::$shared_rules_dir = $path !== null ? rtrim( str_replace( '\\', '/', (string) $path ), '/' ) . '/' : null;

		self::$shared_rules_index = (string) $index;

		self::$rules = null;
	}


	/**
	 * Point term/field-catalogue loading at libs/einvoice-reference-data's fields/en16931.json
	 * instead of this plugin's own factur-x_en16931_with_rules.json. Not calling this keeps the
	 * original, fully self-contained behaviour.
	 *
	 * @param	string|null	$path
	 * @return	void
	 * @since	1.1.0
	 */

	public static function set_shared_terms_file( $path )
	{
		self::$shared_terms_file = $path;
		self::$terms = null;
	}


	/**
	 * The data directory. Filterable so an add-on can ship additional rule sets.
	 *
	 * @return	string
	 * @since	1.0.0
	 */

	public static function dir()
	{
		return( (string) apply_filters( 'sppv_data_dir', SPPV_DIR ) );
	}


	/**
	 * Read one text out of a { de, en, fr } block, with fallback.
	 *
	 * @param	mixed	$block	the language block, or a plain string, or null
	 * @param	string	$lang	preferred language, '' for the site language
	 * @return	string
	 * @since	1.0.0
	 */

	public static function text( $block, $lang = '' )
	{
		if ( is_string( $block ) )
			return( self::clean( $block ) );

		if ( ! is_array( $block ) )
			return( '' );

		if ( $lang === '' )
			$lang = self::language();

		foreach ( array( $lang, 'de', 'en', 'fr' ) as $try )
		{
			$value = self::clean( $block[ $try ] ?? '' );

			if ( $value !== '' )
				return( $value );
		}

		return( '' );
	}


	/**
	 * Which language text() actually resolved to for the same $block/$lang - '' when $block is a
	 * plain string ( no language choice was made ) or when nothing was found at all.
	 *
	 * The Explainer reads the FULL rule catalogue ( more than 11,764 rules ), and most rule texts are only
	 * available in English (9,757 of the catalogue, per einvoice-reference-data's own measurement) - a
	 * silent de->en->fr fallback would show an English sentence to a German-language visitor with no
	 * indication that it is not a translation. The renderer uses this to decide whether to show a notice,
	 * exactly the same decision the public catalogue pages ( einvoice-reference-data's build-site.php,
	 * 'notice_only_english' ) already make for the same underlying data.
	 *
	 * @param	mixed	$block
	 * @param	string	$lang
	 * @return	string	'de' | 'en' | 'fr' | ''
	 * @since	2.1.0
	 */

	public static function text_language( $block, $lang = '' )
	{
		if ( ! is_array( $block ) )
			return( '' );

		if ( $lang === '' )
			$lang = self::language();

		foreach ( array( $lang, 'de', 'en', 'fr' ) as $try )
			if ( self::clean( $block[ $try ] ?? '' ) !== '' )
				return( $try );

		return( '' );
	}


	/**
	 * Empty, or the "0" artefact, or real text.
	 *
	 * @param	mixed	$value
	 * @return	string
	 * @since	1.0.0
	 */

	private static function clean( $value )
	{
		if ( $value === null )
			return( '' );

		$s = trim( (string) $value );

		return( ( $s === '' || $s === '0' ) ? '' : $s );
	}


	/**
	 * The two-letter language of the current site.
	 *
	 * @return	string
	 * @since	1.0.0
	 */

	public static function language()
	{
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$short  = strtolower( substr( (string) $locale, 0, 2 ) );

		return( in_array( $short, array( 'de', 'en', 'fr' ), true ) ? $short : 'en' );
	}


	/**
	 * Everything known about a rule id.
	 *
	 * @param	string	$id
	 * @return	array	'severity', 'description', 'fields', 'standard'; empty when unknown
	 * @since	1.0.0
	 */

	public static function rule( $id )
	{
		self::load_rules();

		$id = trim( (string) $id );

		return( self::$rules[ $id ] ?? array() );
	}


	/**
	 * The business term of a BT / BG id, in the site language.
	 *
	 * @param	string	$id
	 * @return	string	'' when unknown
	 * @since	1.0.0
	 */

	public static function term( $id )
	{
		self::load_terms();

		$entry = self::$terms[ trim( (string) $id ) ] ?? null;

		return( $entry ? self::text( $entry['term'] ) : '' );
	}


	/**
	 * The full entry of a BT / BG id - term, description and usage note.
	 *
	 * @param	string	$id
	 * @return	array
	 * @since	1.0.0
	 */

	public static function field( $id )
	{
		self::load_terms();

		return( self::$terms[ trim( (string) $id ) ] ?? array() );
	}


	/**
	 * Load and index the rules file.
	 *
	 * @return	void
	 * @since	1.0.0
	 */

	private static function load_rules()
	{
		if ( self::$rules !== null )
			return;

		self::$rules = array();

		$rulesDir = self::$shared_rules_dir !== null ? self::$shared_rules_dir : ( self::dir() . self::DATA_DIR );

		/**
		 * ⚠️ THE INDEX FILE DECIDES WHICH TIER IS LOADED, and the same directory holds both.
		 *
		 * `INDEX.explainer.json` lists the projected rule sets ( id, severity, message, business
		 * terms ); `INDEX.json` lists the full ones, which additionally carry the Schematron
		 * expression, the variable bindings and the provenance. Together the full sets weigh about
		 * 19 MB against 2.4 MB for the projection, and this plugin displays none of the extra.
		 *
		 * A free plugin that bundles the full tier is a build error, so the caller sets the index
		 * explicitly. The default stays `INDEX.json` so an installation without the shared package
		 * keeps reading this plugin's own `data/` directory exactly as before.
		 */
		$index = self::read( $rulesDir . self::$shared_rules_index );

		if ( ! isset( $index['dateien'] ) || ! is_array( $index['dateien'] ) )
			return;

		foreach ( $index['dateien'] as $filename => $meta )
		{
			$standard = (string) ( $meta['standard'] ?? $filename );

			/**
			 * ⚠️ AN EXTENSION RULE SET IS READ FROM THE ADD-ON, AT THE SAME PLACE IN THE ORDER.
			 *
			 * Skipped by name, not by absence: the release ZIP leaves these files out, but a work tree
			 * still carries them in vendor/ and data/, and a development site must show what a customer
			 * sees. Reading the add-on's copy in the slot of the free file keeps "the first occurrence
			 * wins" exactly as before - appending it after the loop would let FACTUR-X-EN16931 or a
			 * CIUS explain an id that EXTENDED-CTC-FR explained until now.
			 */
			$data = preg_match( self::EXTENSION_RULE_SETS, (string) $filename, $extension )
				? self::protected_rules( $extension[1] )
				: self::read( $rulesDir . $filename );

			if ( ! isset( $data['rules'] ) || ! is_array( $data['rules'] ) )
				continue;

			foreach ( $data['rules'] as $rule )
			{
				$id = trim( (string) ( $rule['id'] ?? '' ) );

				if ( $id === '' )
					continue;

				/**
				 * ⚠️ THE FIRST OCCURRENCE WINS, and the order data/INDEX.json lists files in decides.
				 *
				 * A rule id can appear in more than one rule set - BR-CL-* exists in EN 16931 and again
				 * in a national CIUS with the same wording. The index lists EN16931-CII first, which is
				 * the more general statement and the right one to show when the report does not say
				 * which set it came from.
				 */

				if ( isset( self::$rules[ $id ] ) )
					continue;

				/**
				 * ⚠️ `test` IS DELIBERATELY NOT KEPT. It is the Schematron expression and makes up most
				 * of each file's size - BR-E-08 alone is 1400 characters of XPath. The view never shows
				 * it, so holding it in memory for every request would be pure cost.
				 */

				self::$rules[ $id ] = array(
					'severity'    => (string) ( $rule['severity'] ?? '' ),
					'description' => $rule['description'] ?? '',
					'fields'      => array_values( (array) ( $rule['fields'] ?? array() ) ),
					'standard'    => $standard,
				);

				// The mark "machine-translated, not reviewed" travels with the text, so the renderer can say
				// so - see protected_rules(). Only when present; the free files carry none.
				if ( isset( $rule['translation'] ) && is_array( $rule['translation'] ) )
					self::$rules[ $id ]['translation'] = $rule['translation'];
			}
		}
	}


	/**
	 * Load and index the business terms.
	 *
	 * @return	void
	 * @since	1.0.0
	 */

	private static function load_terms()
	{
		if ( self::$terms !== null )
			return;

		self::$terms = array();

		$termsFile = self::$shared_terms_file !== null ? self::$shared_terms_file : ( self::dir() . self::FILE_TERMS );

		foreach ( self::read( $termsFile ) as $row )
		{
			$id = trim( (string) ( $row['id'] ?? '' ) );

			if ( $id === '' )
				continue;

			$ubl = $row['ublPath'] ?? null;

			// ⚠️ "N/A – kein UBL-Pendant …" is a real, checked statement (facturx-mergeublpaths.php),
			// not a path - must not be handed to the resolver as if it were one.
			if ( ! is_string( $ubl ) || stripos( $ubl, 'N/A' ) === 0 )
				$ubl = '';

			/**
			 * ⚠️ THE CII XPATH STANDS IN DIFFERENT PLACES IN THE TWO TIERS - BOTH ARE READ.
			 *
			 * In the full tier it is nested ( `xpathUnCefact22B.xpath` ), next to three further XPath fragments
			 * nobody here needs. The tier `explainer`, which this plugin ships, lifts it to a flat key `xpath` -
			 * precisely so the fragments need not come along ( see `libs/einvoice-reference-data/build/build.php`,
			 * "The CII XPath lives one level down … lifted to a flat key" ).
			 *
			 * ⚠️ READING ONLY THE FLAT ONE WOULD BE A SILENT FAILURE IN THE OTHER TIER: `field_xpath()` would then
			 * find a path for NONE of the 311 rows, and the display "the affected field with its actual value" -
			 * the point of the whole plugin - would stay empty for every CII document, without an error message.
			 * Accepting both spellings costs nothing and makes a change of tier safe in both directions.
			 */

			$xpathCii = $row['xpath'] ?? ( $row['xpathUnCefact22B']['xpath'] ?? '' );

			self::$terms[ $id ] = array(
				'term'        => $row['businessTerm'] ?? '',
				'description' => $row['description'] ?? '',

				// ⚠️ `usageNote` is NOT contained in the tier `explainer` and is read by no line of this plugin
				// ( measured: no caller of SPPV_Rules::term(), no access to ['note'] ). The key
				// stays so the shape of the entry does not change - it is simply empty then.
				'note'        => $row['usageNote'] ?? '',

				'cardinality' => (string) ( $row['en16931SemanticCardinality'] ?? '' ),
				'datatype'    => (string) ( $row['semanticDataType'] ?? '' ),
				'xpath_cii'   => is_string( $xpathCii ) ? $xpathCii : '',
				'xpath_ubl'   => (string) $ubl,
			);
		}

		self::merge_protected_terms();
	}


	/**
	 * Add the fields of the extensions from the "Extended Profiles" add-on plugin, when it is active.
	 *
	 * ⚠️ **THE ADD-ON ANNOUNCES ITS FOLDER THROUGH A FILTER, AND THIS CLASS NEVER LOOKS FOR IT.** The
	 * filter `einvoice_protected_data_dir` returns the folder that holds the add-on's `index.json` and
	 * its field packages ( einvoice-viewer-extended-profiles 1.3.0 and later ). Without the add-on the
	 * filter returns '' and nothing is read - the normal case of the free plugin, not an error. A path
	 * guessed from the plugin directory would read a folder of another plugin by name and break the
	 * moment that plugin moved its data.
	 *
	 * ⚠️ **ONLY WHAT THE CORE CATALOGUE LACKS.** The field package of ZUGFeRD / Factur-X EXTENDED
	 * contains the 311 EN 16931 fields as well, partly in the wording of the Factur-X source. They stay
	 * as the core catalogue has them, so the same field never carries two names depending on whether
	 * the add-on is installed. Measured: 963 rows are added, 946 of them
	 * BT-X / BG-X fields and 17 further ids of the extension such as BT-186.
	 *
	 * ⚠️ **ONE PACKAGE PER LANGUAGE, PLAIN STRINGS.** The packages carry `businessTerm` as a string in
	 * one language, the core catalogue as a { de, en, fr } block. text() accepts both. The package of
	 * the site language is read; the add-on ships `lite` in de, en and fr.
	 *
	 * ⚠️ **THE FILE NAME COMES FROM THE ADD-ON'S INDEX AND IS CHECKED BEFORE IT BECOMES A PATH** - the
	 * same pattern `Packages.php` and `DataLoader.mjs` use, so `../` cannot lead out of the folder.
	 *
	 * @return	void
	 * @since	08.10.2026
	 */

	private static function merge_protected_terms()
	{
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The filter name is the contract of the add-on einvoice-viewer-extended-profiles, which hooks its data folder into it; a renamed filter would never reach the add-on.
		$dir = (string) apply_filters( 'einvoice_protected_data_dir', '' );

		if ( $dir === '' )
			return;

		$dir   = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/';
		$index = self::read( $dir . 'index.json' );
		$lang  = self::language();

		foreach ( (array) ( $index['current'] ?? array() ) as $key => $name )
		{
			$parts = explode( '/', (string) $key );

			if ( count( $parts ) !== 4 || $parts[0] !== 'fields' || $parts[2] !== 'lite' || $parts[3] !== $lang )
				continue;

			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._@-]*\.json$/', (string) $name ) )
				continue;

			$package = self::read( $dir . $name );

			foreach ( (array) ( $package['rows'] ?? array() ) as $row )
			{
				$id = trim( (string) ( $row['id'] ?? '' ) );

				if ( $id === '' || isset( self::$terms[ $id ] ) )
					continue;

				$ubl = $row['ublPath'] ?? null;

				if ( ! is_string( $ubl ) || stripos( $ubl, 'N/A' ) === 0 )
					$ubl = '';

				self::$terms[ $id ] = array(
					'term'        => (string) ( $row['businessTerm'] ?? '' ),
					'description' => (string) ( $row['description'] ?? '' ),
					'note'        => '',
					'cardinality' => (string) ( $row['en16931SemanticCardinality'] ?? '' ),
					'datatype'    => (string) ( $row['semanticDataType'] ?? '' ),
					'xpath_cii'   => is_string( $row['xpath'] ?? null ) ? $row['xpath'] : '',
					'xpath_ubl'   => (string) $ubl,
				);
			}
		}
	}


	/**
	 * The rules of one extension rule set from the "Extended Profiles" add-on, in the shape of a free
	 * rule file - see load_rules().
	 *
	 * ⚠️ **THE FREE PLUGIN DOES NOT EXPLAIN THESE RULE SETS ON ITS OWN.** ZUGFeRD / Factur-X EXTENDED and
	 * EXTENDED-CTC-FR ( CII and UBL ) belong to the add-on, like the fields of the extension. Without the
	 * add-on a finding on such a rule shows the validator's id and message and the sentence that no
	 * plain-language explanation is available - the renderer's normal path for a rule it does not know.
	 * That is not an error and gets no notice of its own.
	 *
	 * ⚠️ **ONE PACKAGE PER LANGUAGE, AND NO ROW SAYS WHICH LANGUAGE ITS TEXT IS IN.** The add-on carries
	 * `rules.<syntax>.<profile>.explainer@<version>.<lang>.json` with a plain `text`. When the package was
	 * built, a missing translation was filled with the English text, and an empty English text stayed
	 * empty. Read alone, the German package would pass an English sentence off as German, and the
	 * renderer would drop its "shown in English, not translated" notice. So every language package of
	 * the profile is read, English first, and another language counts only where its text differs from
	 * the English one. That rebuilds the { en, fr } block of the free file: measured on the packages, 33
	 * of the 953 rules of EXTENDED-CTC-FR-UBL have only a French text, and every other
	 * rule of the three sets has only an English one - in both the packages and the free files.
	 *
	 * ⚠️ **THE FIRST ROW OF AN ID WINS INSIDE EACH PACKAGE**, like the first file in load_rules().
	 *
	 * ⚠️ **THE FILE NAME COMES FROM THE ADD-ON'S INDEX AND IS CHECKED BEFORE IT BECOMES A PATH**, as in
	 * merge_protected_terms().
	 *
	 * @param	string	$profile	'factur-x-extended' | 'france-extended-ctc-cii' | 'france-extended-ctc-ubl'
	 * @return	array	array( 'rules' => … ); empty without the add-on or without a package for $profile
	 * @since	08.10.2026
	 */

	private static function protected_rules( $profile )
	{
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The filter name is the contract of the add-on einvoice-viewer-extended-profiles, which hooks its data folder into it; a renamed filter would never reach the add-on.
		$dir = (string) apply_filters( 'einvoice_protected_data_dir', '' );

		if ( $dir === '' )
			return( array() );

		$dir     = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/';
		$current = (array) ( self::read( $dir . 'index.json' )['current'] ?? array() );
		$prefix  = 'rules/' . $profile . '/explainer/';
		$names   = array();

		foreach ( $current as $key => $name )
			if ( strpos( (string) $key, $prefix ) === 0 && preg_match( '/^[A-Za-z0-9][A-Za-z0-9._@-]*\.json$/', (string) $name ) )
				$names[ substr( (string) $key, strlen( $prefix ) ) ] = (string) $name;

		// ⚠️ English first: the other languages are compared against it.
		uksort( $names, static function ( $a, $b ) { return( ( $a === 'en' ? 0 : 1 ) <=> ( $b === 'en' ? 0 : 1 ) ?: strcmp( $a, $b ) ); } );

		$rules = array();

		foreach ( $names as $lang => $name )
		{
			$seen = array();

			foreach ( (array) ( self::read( $dir . $name )['rows'] ?? array() ) as $row )
			{
				$id   = trim( (string) ( $row['id'] ?? '' ) );
				$text = self::clean( $row['text'] ?? '' );

				if ( $id === '' || isset( $seen[ $id ] ) )
					continue;

				$seen[ $id ] = true;

				if ( ! isset( $rules[ $id ] ) )
					$rules[ $id ] = array(
						'id'          => $id,
						'severity'    => (string) ( $row['severity'] ?? '' ),
						'description' => array(),
						'fields'      => array_values( (array) ( $row['fields'] ?? array() ) ),
					);

				if ( $text !== '' && ( $lang === 'en' || $text !== ( $rules[ $id ]['description']['en'] ?? '' ) ) )
				{
					$rules[ $id ]['description'][ $lang ] = $text;

					/**
					 * ⚠️ THE MARK IS KEPT PER LANGUAGE, NEXT TO THE TEXT IT BELONGS TO. The German package of
					 * Factur-X EXTENDED carries machine translations, each row marked
					 * `translation: { source: "machine", reviewed: false }`. Rebuilding the { en, de } block from
					 * `text` alone would drop the mark, and the renderer would show the machine translation as if
					 * it were the source's own German. Flat would be wrong here: the block also holds the English
					 * text, and a German mark must not label the English fallback. The renderer picks the mark of
					 * the language it actually shows.
					 */
					if ( isset( $row['translation'] ) && is_array( $row['translation'] ) )
						$rules[ $id ]['translation'][ $lang ] = $row['translation'];
				}
			}
		}

		return( $rules === array() ? array() : array( 'rules' => array_values( $rules ) ) );
	}


	/**
	 * Whether the report named fields of an extension that no catalogue here knows, and the add-on is
	 * not active.
	 *
	 * ⚠️ **ASK THIS AFTER RENDERING, NOT BEFORE.** It is set by field_xpath() while the renderer walks
	 * the findings; before that it is always false.
	 *
	 * ⚠️ **WITH THE ADD-ON ACTIVE IT STAYS FALSE**, even if an id is still unknown ( a French
	 * EXT-FR-FE-… field, for instance, is in no package ). The notice it drives tells the reader that the
	 * add-on brings the data; with the add-on installed that sentence would be false.
	 *
	 * @return	bool
	 * @since	08.10.2026
	 */

	public static function extension_fields_missing()
	{
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The filter name is the contract of the add-on einvoice-viewer-extended-profiles, which hooks its data folder into it; a renamed filter would never reach the add-on.
		return( self::$extension_missing && (string) apply_filters( 'einvoice_protected_data_dir', '' ) === '' );
	}

	/**
	 * The XPath a BT/BG id resolves to in a given syntax - used to locate its actual value in a
	 * document, as opposed to term()/field() which describe the field in the abstract.
	 *
	 * @param	string	$id
	 * @param	string	$syntax	'CII' | 'UBL', case-insensitive; anything else falls back to CII
	 * @return	string	'' when unknown or when the field genuinely has no path in that syntax
	 * @since	1.0.0
	 */

	public static function field_xpath( $id, $syntax )
	{
		self::load_terms();

		$entry = self::$terms[ trim( (string) $id ) ] ?? null;

		if ( ! $entry )
		{
			// ⚠️ Remembered, not reported here: the renderer asks for every field a rule names, and
			// the notice belongs above the report, once - see extension_fields_missing().
			if ( preg_match( '/^B[TG]-X-/', trim( (string) $id ) ) )
				self::$extension_missing = true;

			return( '' );
		}

		return( strtoupper( (string) $syntax ) === 'UBL' ? $entry['xpath_ubl'] : $entry['xpath_cii'] );
	}


	/**
	 * Read a JSON file, tolerating its absence.
	 *
	 * ⚠️ A MISSING DATA FILE MUST NOT BE FATAL. The explanation layer is an enrichment: without it the
	 * report is still shown, only with the validator's own wording instead of a business term. Throwing
	 * here would turn a deployment that lost one file into a plugin that shows nothing at all.
	 *
	 * @param	string	$file
	 * @return	array
	 * @since	1.0.0
	 */

	private static function read( $file )
	{
		if ( ! is_readable( $file ) )
			return( array() );

		$raw = file_get_contents( $file );

		if ( $raw === false || $raw === '' )
			return( array() );

		$data = json_decode( $raw, true );

		return( is_array( $data ) ? $data : array() );
	}


	/**
	 * Whether the data files are present at all.
	 *
	 * @return	bool
	 * @since	1.0.0
	 */

	public static function available()
	{
		$rulesDir = self::$shared_rules_dir !== null ? self::$shared_rules_dir : ( self::dir() . self::DATA_DIR );

		/**
		 * ⚠️ THE INDEX THAT load_rules() READS, NOT A FIXED `INDEX.json`. The plugin reads `INDEX.explainer.json`,
		 * and the release ZIP carries only that one. A check that asks for `INDEX.json` reports the rule data as
		 * missing on every installed copy, and the report then falls back to the English Report Lite instead of the
		 * translated explorer view ( measured 18.10.2026 on the release test site ). In the work tree both files
		 * lie side by side, which is why no test there could see it.
		 */
		$index = self::$shared_rules_dir !== null ? self::$shared_rules_index : 'INDEX.json';

		return( is_readable( $rulesDir . $index ) );
	}


	/**
	 * Every BT / BG id the loaded catalogues know, the add-on's included.
	 *
	 * ⚠️ THE BT MARKS IN THE DOCUMENT VIEW HANG ON THIS. The shared renderer asks the Terms adapter for
	 * fieldIds() with method_exists() and maps each document row to its BT id through the field's XPath. Until
	 * 18.10.2026 only the KoSIT Validation Explorer had it; the validators showed the document without a single
	 * BT mark, and the findings named the XML element instead of the business term.
	 *
	 * @return	array<int,string>
	 * @since	18.10.2026
	 */

	public static function field_ids()
	{
		self::load_terms();

		return( array_map( 'strval', array_keys( self::$terms ) ) );
	}


	/**
	 * The semantic data type of a BT id ( "Amount", "Date", … ), '' when unknown.
	 *
	 * @param	string	$id
	 * @return	string
	 * @since	18.10.2026
	 */

	public static function datatype( $id )
	{
		self::load_terms();

		return( (string) ( self::$terms[ trim( (string) $id ) ]['datatype'] ?? '' ) );
	}

}
