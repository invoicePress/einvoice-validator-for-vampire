<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The keys with which an app uses a WordPress site as its source ( service contract, version 2 ).
 *
 * An app runs from the disk or from another address and cannot use the login cookie of the site; it sends a key in
 * `X-Api-Key` instead. Each key names what it may do: which stores it may read and write, and whether it may validate.
 *
 * ⚠️ **ONLY THE HASH IS STORED.** The key is shown once when it is created and then never again; the option holds its
 * SHA-256. A copy of the database therefore carries no usable key.
 *
 * ⚠️ **A KEY ALSO ADMITS THE ORIGIN `null`.** An app opened from the disk sends `Origin: null`, which any HTML file anyone
 * mails the user sends as well. Without a key such a request is refused; with a key it is the key that proves who calls.
 *
 * @package SpecialPress\EInvoice\Host
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace SpecialPress\EInvoice\Host;

if ( ! defined( 'ABSPATH' ) )
	exit;

if ( ! class_exists( __NAMESPACE__ . '\\AppKeys' ) )
{

class AppKeys
{

	const OPTION = 'einvoice_app_keys';

	/**
	 * Creates a key and returns it in plain text, the only time it is visible.
	 *
	 * @param	string		$label	what the key is for, e.g. "Belegeingang auf dem Rechner im Büro"
	 * @param	string[]	$read	store patterns it may read, e.g. "company.*"
	 * @param	string[]	$write	store patterns it may write
	 * @param	string[]	$validate	engines it may use, e.g. "kosit"
	 * @return	string
	 */
	public static function create( $label, array $read, array $write, array $validate = array() )
	{
		$key  = 'eik_' . bin2hex( random_bytes( 24 ) );
		$keys = self::all();

		$keys[ hash( 'sha256', $key ) ] = array(
			'label'    => sanitize_text_field( (string) $label ),
			'read'     => array_values( array_filter( array_map( 'strval', $read ), array( __CLASS__, 'is_pattern' ) ) ),
			'write'    => array_values( array_filter( array_map( 'strval', $write ), array( __CLASS__, 'is_pattern' ) ) ),
			'validate' => array_values( array_intersect( array_map( 'strval', $validate ), array( 'kosit', 'mustang', 'vampire' ) ) ),
			'created'  => gmdate( 'c' ),
		);

		update_option( self::OPTION, $keys, false );

		return( $key );
	}

	/**
	 * @param	string	$hash
	 * @return	void
	 */
	public static function revoke( $hash )
	{
		$keys = self::all();
		unset( $keys[ (string) $hash ] );
		update_option( self::OPTION, $keys, false );
	}

	/** @return array<string,array> hash => record */
	public static function all()
	{
		$keys = get_option( self::OPTION, array() );

		return( is_array( $keys ) ? $keys : array() );
	}

	/**
	 * @param	string	$key	as sent in X-Api-Key
	 * @return	array|null	the record, null when the key is unknown
	 */
	public static function find( $key )
	{
		$key = trim( (string) $key );

		if ( $key === '' )
			return( null );

		$keys = self::all();
		$hash = hash( 'sha256', $key );

		/*
		 * ⚠️ `hash_equals()` OVER THE STORED HASHES, NOT AN ARRAY LOOKUP. A lookup by key compares in the time the hash
		 * table needs; walking the list with a constant-time compare gives an attacker no timing to measure.
		 */
		foreach ( $keys as $stored => $record )
			if ( hash_equals( (string) $stored, $hash ) )
				return( $record );

		return( null );
	}

	/**
	 * @param	array	$record
	 * @param	string	$what	'read' | 'write'
	 * @param	string	$store
	 * @return	bool
	 */
	public static function may( array $record, $what, $store )
	{
		foreach ( (array) ( $record[ $what ] ?? array() ) as $pattern )
		{
			if ( $pattern === $store )
				return( true );

			// "company.*" covers every store of the owner "company", nothing else.
			if ( substr( $pattern, -2 ) === '.*' && strpos( $store, substr( $pattern, 0, -1 ) ) === 0 )
				return( true );
		}

		return( false );
	}

	/** @return bool */
	public static function is_pattern( $p )
	{
		return( (bool) preg_match( '/^[a-z0-9][a-z0-9-]{0,31}\.(\*|[a-z0-9][a-z0-9.-]{0,63})$/', (string) $p ) );
	}

}

}
