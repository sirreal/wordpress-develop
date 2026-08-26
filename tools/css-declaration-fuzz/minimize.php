#!/usr/bin/env php
<?php
/** Byte-level delta debugger for self-contained declaration-fuzzer failures. */

require_once __DIR__ . '/lib/autoload.php';

$options   = \CssDeclarationFuzz\parse_cli_options( $argv );
$seed      = \CssDeclarationFuzz\option_int( $options, 'seed', 1 );
$signature = \CssDeclarationFuzz\option_string( $options, 'signature', null );
$encoded   = \CssDeclarationFuzz\option_string( $options, 'style-base64', null );

if ( \CssDeclarationFuzz\option_bool( $options, 'help', false ) || null === $signature ) {
	echo "Usage: php tools/css-declaration-fuzz/minimize.php --seed N --signature NAME [--style-base64 BASE64]\n";
	exit( null === $signature ? 1 : 0 );
}

if ( null !== $encoded ) {
	$input = base64_decode( $encoded, true );
	if ( false === $input ) {
		fwrite( STDERR, "--style-base64 is not valid base64.\n" );
		exit( 1 );
	}
} else {
	$input = \CssDeclarationFuzz\CaseGenerator::generate( $seed )['style'];
}

$has_signature = static function ( string $style ) use ( $seed, $signature ): bool {
	$result = \CssDeclarationFuzz\Worker::run_case( $seed, $style );
	foreach ( $result['failures'] as $failure ) {
		if ( $signature === $failure['signature'] ) {
			return true;
		}
	}
	return false;
};

if ( ! $has_signature( $input ) ) {
	fwrite( STDERR, "The requested signature does not reproduce from raw style bytes. It may depend on the generated structured model.\n" );
	exit( 1 );
}

$original = $input;
$n        = 2;
while ( strlen( $input ) >= 2 ) {
	$chunk   = (int) ceil( strlen( $input ) / $n );
	$reduced = false;
	for ( $at = 0; $at < strlen( $input ); $at += $chunk ) {
		$candidate = substr( $input, 0, $at ) . substr( $input, $at + $chunk );
		if ( $has_signature( $candidate ) ) {
			$input   = $candidate;
			$n       = max( 2, $n - 1 );
			$reduced = true;
			break;
		}
	}
	if ( ! $reduced ) {
		if ( $n >= strlen( $input ) ) {
			break;
		}
		$n = min( strlen( $input ), $n * 2 );
	}
}

$simple_bytes = array( 'a', '0', ' ', ';', ':', '!', '(', ')', '[', ']', '{', '}', '\\', "\0" );
for ( $at = 0; $at < strlen( $input ); $at++ ) {
	foreach ( $simple_bytes as $byte ) {
		if ( $input[ $at ] === $byte ) {
			continue;
		}
		$candidate = substr( $input, 0, $at ) . $byte . substr( $input, $at + 1 );
		if ( $has_signature( $candidate ) ) {
			$input = $candidate;
			break;
		}
	}
}

echo \CssDeclarationFuzz\json_encode_safe(
	array(
		'kind'               => 'css-declaration-fuzz-minimized',
		'seed'               => $seed,
		'signature'          => $signature,
		'originalLength'     => strlen( $original ),
		'minimizedLength'    => strlen( $input ),
		'styleBase64'        => base64_encode( $input ),
		'stylePrintable'     => \CssDeclarationFuzz\printable_bytes( $input ),
		'replayCommand'      => 'php tools/css-declaration-fuzz/replay.php --seed ' . $seed . ' --style-base64 ' . escapeshellarg( base64_encode( $input ) ),
	)
) . "\n";
