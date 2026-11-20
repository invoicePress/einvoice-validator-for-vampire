<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * One source, several consumers: hashes this package's own src/ + assets/ against
 * every Composer-vendored copy in a consuming plugin. A mismatch means someone edited a vendor/ copy
 * directly instead of the source, or forgot `composer update` after a source change - either way the
 * physical copies (Explainer, Validator, and the JS port in einvoice-core-js, checked separately
 * via that package's own PHP/JS parity test) would silently drift apart.
 *
 * Usage: php verify-consumers.php (run after any change to src/ or assets/, or before a release)
 */

declare( strict_types = 1 );

$root = __DIR__;

$consumers = [
	'einvoice-validation-explorer-for-kosit' => dirname( __DIR__, 2 ) . '/einvoice-validation-explorer-for-kosit/vendor/specialpress/einvoice-report',
	'einvoice-validator-for-peppol-en16931'            => dirname( __DIR__, 2 ) . '/einvoice-validator-for-peppol-en16931/vendor/specialpress/einvoice-report',
];

function collect_files( string $base ): array {

	$out       = [];
	$baseNorm  = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';

	foreach ( [ 'src', 'assets' ] as $sub ) {

		$dir = $base . '/' . $sub;

		if ( ! is_dir( $dir ) )
			continue;

		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file )
			if ( $file->isFile() ) {
				$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $baseNorm ) );
				$out[ $relative ] = hash_file( 'sha256', $file->getPathname() );
			}

	}

	ksort( $out );

	return $out;

}

$sourceFiles = collect_files( $root );

if ( empty( $sourceFiles ) ) {
	fwrite( STDERR, "FAILED: no source files found under $root/src or $root/assets\n" );
	exit( 1 );
}

$sourceHash = hash( 'sha256', json_encode( $sourceFiles ) );

echo "Source ($root): " . count( $sourceFiles ) . " files, combined hash $sourceHash\n";

$ok = true;

foreach ( $consumers as $name => $path ) {

	if ( ! is_dir( $path ) ) {
		echo "SKIP  $name: not vendored ($path does not exist)\n";
		continue;
	}

	$consumerFiles = collect_files( $path );
	$consumerHash  = hash( 'sha256', json_encode( $consumerFiles ) );

	if ( $consumerHash === $sourceHash ) {
		echo "OK    $name: identical (" . count( $consumerFiles ) . " files)\n";
		continue;
	}

	$ok = false;
	echo "DRIFT $name: hash differs\n";

	foreach ( $sourceFiles as $file => $hash ) {
		if ( ! isset( $consumerFiles[ $file ] ) )
			echo "  - $file: missing in consumer\n";
		elseif ( $consumerFiles[ $file ] !== $hash )
			echo "  - $file: content differs\n";
	}

	foreach ( $consumerFiles as $file => $hash )
		if ( ! isset( $sourceFiles[ $file ] ) )
			echo "  - $file: exists only in consumer\n";

}

if ( ! $ok ) {
	fwrite( STDERR, "\nFAILED: at least one consumer has drifted from the source - see above.\n" );
	exit( 1 );
}

echo "\nAll vendored copies match the source.\n";
