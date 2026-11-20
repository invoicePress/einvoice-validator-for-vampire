<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The service contract, version 2, on a WordPress site: `/wp-json/einvoice/v1/…`.
 *
 * Every plugin of the family calls `ServiceRoutes::init()` on `plugins_loaded`; the first call registers, the others
 * change nothing. The routes are those of `libs/einvoice-core-js/docs/contract.md`:
 *
 *   GET  /health                         public
 *   GET  /info                           public; what this site offers
 *   POST /validate/{engine}              kosit | mustang | vampire, answered by the validator plugin that is installed
 *
 * ⚠️ **THE DATA ROUTES ARE NOT HERE.** `/data/{store}` and the table behind it belong to `DataRoutes`, which only the
 * viewer and the outbound module start. This class must not name `Store` or `DataRoutes`: the release build follows such
 * references and would put both back into the ZIP of every validator ( see `DataRoutes.php` ).
 *
 * ⚠️ **ERRORS IN THE FORM OF THE CONTRACT, NOT AS `WP_Error`.** WordPress answers a `WP_Error` with `{ code, message,
 * data }`, the contract with `{ error, message }`. The permission callbacks therefore let every request through, and
 * each callback checks itself and answers with `error()`. Otherwise a refused app would see `code` where every other
 * source sends `error`, and the client could not tell `unauthorized` from `forbidden`.
 *
 * ⚠️ **THIS SITE NEVER VALIDATES BY ITSELF.** `/validate/{engine}` hands the request to the validator plugin that is
 * installed for that engine ( filter `einvoice_service_validators` ). Without one the answer is 503 `unavailable`, and
 * `/info` says so; it never answers a KoSIT request with another engine.
 *
 * @package SpecialPress\EInvoice\Host
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;

if ( ! class_exists( __NAMESPACE__ . '\\ServiceRoutes' ) )
{

class ServiceRoutes
{

	const NS = 'einvoice/v1';

	const CONTRACT = 2;

	const ENGINES = array( 'kosit', 'mustang', 'vampire' );

	/** @var bool */
	private static $done = false;

	/** @return void */
	public static function init()
	{
		if ( self::$done )
			return;

		self::$done = true;

		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'allowed_headers' ) );
		add_filter( 'rest_exposed_cors_headers', array( __CLASS__, 'exposed_headers' ) );
	}

	/** @return void */
	public static function routes()
	{
		$open  = '__return_true';

		register_rest_route( self::NS, '/health', array( 'methods' => 'GET', 'callback' => static function () {
			return( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
		}, 'permission_callback' => $open ) );

		register_rest_route( self::NS, '/info', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'info' ), 'permission_callback' => $open ) );

		register_rest_route( self::NS, '/validate/(?P<engine>kosit|mustang|vampire)', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'validate' ), 'permission_callback' => $open,
		) );
	}

	/**
	 * The validators installed on this site, each `engine => array( 'version' => …, 'handle' => callable( WP_REST_Request ):
	 * WP_REST_Response, 'allowed' => callable( WP_REST_Request, ?array $key ): bool )`.
	 *
	 * @return array<string,array>
	 */
	public static function validators()
	{
		$v = apply_filters( 'einvoice_service_validators', array() );

		return( is_array( $v ) ? array_intersect_key( $v, array_flip( self::ENGINES ) ) : array() );
	}

	/** @return \WP_REST_Response */
	public static function info()
	{
		$validate = array();

		foreach ( self::ENGINES as $engine )
		{
			$v = self::validators()[ $engine ] ?? null;

			$validate[ $engine ] = is_array( $v ) && is_callable( $v[ 'handle' ] ?? null )
				? array( 'available' => true, 'version' => (string) ( $v[ 'version' ] ?? '' ) )
				: array( 'available' => false, 'reason' => 'no validator for this engine on this site' );
		}

		$mail = apply_filters( 'einvoice_service_mail', array( 'available' => false ) );
		// ⚠️ Set by `DataRoutes::init()`; this class does not ask that class itself ( see the head of this file ).
		$data = apply_filters( 'einvoice_service_data', array( 'available' => false ) );

		return( new \WP_REST_Response( array(
			'server'   => 'wordpress',
			'contract' => self::CONTRACT,
			'version'  => function_exists( 'einvoice_host_wp_boot_v2' ) ? (string) einvoice_host_wp_boot_v2() : (string) ( $GLOBALS[ 'einvoice_host_wp_v1' ][ 'version' ] ?? '' ),
			'validate' => $validate,
			'data'     => is_array( $data ) ? $data : array( 'available' => false ),
			'mail'     => is_array( $mail ) ? $mail : array( 'available' => false ),
			'inbox'    => array( 'available' => false ),
		), 200 ) );
	}

	/** @return \WP_REST_Response */
	public static function validate( $request )
	{
		$engine = (string) $request[ 'engine' ];
		$v      = self::validators()[ $engine ] ?? null;

		if ( ! is_array( $v ) || ! is_callable( $v[ 'handle' ] ?? null ) )
			return( self::error( 'unavailable', 'This site has no validator for ' . $engine . '.', 503 ) );

		/*
		 * ⚠️ A VALIDATOR MAY ALSO SERVE GUESTS. A validator page on this site is open to visitors when its plugin says so
		 * ( role and daily limit ); the plugin decides through `allowed`. A key only counts when it names the engine.
		 */
		$sent = (string) $request->get_header( 'x_api_key' );
		$key  = $sent !== '' ? AppKeys::find( $sent ) : null;

		if ( $sent !== '' && ( $key === null || ! in_array( $engine, (array) ( $key[ 'validate' ] ?? array() ), true ) ) )
			return( self::error( 'unauthorized', 'The key may not validate with ' . $engine . '.', 401 ) );

		if ( $key === null && (string) $request->get_header( 'origin' ) === 'null' )
			return( self::error( 'unauthorized', 'A page opened from the disk needs a key.', 401 ) );

		// ⚠️ Without a rule of its own a validator is open to logged-in users only, never to every guest.
		$open = $key !== null || ( is_callable( $v[ 'allowed' ] ?? null ) ? (bool) call_user_func( $v[ 'allowed' ], $request, null ) : is_user_logged_in() );

		if ( ! $open )
			return( self::error( 'forbidden', 'Validation is not open to this visitor.', 403 ) );

		return( call_user_func( $v[ 'handle' ], $request ) );
	}

	/**
	 * @param	string	$code
	 * @param	string	$message
	 * @param	int		$status
	 * @return	\WP_REST_Response
	 */
	public static function error( $code, $message, $status )
	{
		return( new \WP_REST_Response( array( 'error' => (string) $code, 'message' => (string) $message ), (int) $status ) );
	}

	/** @return string[] */
	public static function allowed_headers( $headers )
	{
		return( array_values( array_unique( array_merge( (array) $headers, array( 'X-Api-Key', 'X-Filename', 'Authorization', 'Content-Type', 'X-WP-Nonce' ) ) ) ) );
	}

	/** @return string[] */
	public static function exposed_headers( $headers )
	{
		return( array_values( array_unique( array_merge( (array) $headers, array( 'X-Validation-Result', 'Content-Disposition', 'Retry-After' ) ) ) ) );
	}

}

}
