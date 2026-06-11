<?php
/**
 * Shrinks a failing decoder payload while preserving its signature.
 *
 *     php tools/html-decoder-fuzz/minimize.php --failure artifacts/.../failure.json
 *
 * Exit codes: 0 minimized, 1 signature did not reproduce, 2 harness error.
 */

namespace HtmlDecoderFuzz;

require __DIR__ . '/lib/autoload.php';

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );
ini_set( 'memory_limit', '512M' );

$options = Cli::parse_args(
	$argv,
	array(
		'failure'    => '',
		'input'      => '',
		'context'    => 'both',
		'mode'       => 'oracle',
		'signature'  => '',
		'output-dir' => '',
	)
);

Cli::require_one_of( $options, 'context', array( 'text', 'attribute', 'both' ) );
Cli::require_one_of( $options, 'mode', Cli::valid_modes() );

Bootstrap::load_targets();

$payload    = null;
$context    = $options['context'];
$mode       = $options['mode'];
$signature  = $options['signature'];
$source_dir = $options['output-dir'];

if ( '' !== $options['failure'] ) {
	$manifest = json_decode( (string) file_get_contents( $options['failure'] ), true );
	if ( ! is_array( $manifest ) || ! isset( $manifest['payload_base64'] ) ) {
		fwrite( STDERR, "Cannot read failure manifest {$options['failure']}\n" );
		exit( 2 );
	}
	$payload = base64_decode( $manifest['payload_base64'], true );
	$context = $manifest['context'] ?? $context;
	$mode    = $manifest['mode'] ?? 'oracle';
	if ( ! in_array( $context, array( 'text', 'attribute', 'both' ), true ) ) {
		fwrite( STDERR, "Invalid context in failure manifest: {$context}\n" );
		exit( 2 );
	}
	if ( ! in_array( $mode, Cli::valid_modes(), true ) ) {
		fwrite( STDERR, "Invalid mode in failure manifest: {$mode}\n" );
		exit( 2 );
	}
	if ( '' === $signature ) {
		$signature = $manifest['signatures'][0] ?? '';
	}
	if ( '' === $source_dir ) {
		$source_dir = dirname( $options['failure'] );
	}
} elseif ( '' !== $options['input'] ) {
	$payload = file_get_contents( $options['input'] );
	if ( false === $payload ) {
		fwrite( STDERR, "Cannot read input file {$options['input']}\n" );
		exit( 2 );
	}
	if ( '' === $source_dir ) {
		$source_dir = dirname( $options['input'] );
	}
} else {
	fwrite( STDERR, "Provide --failure or --input.\n" );
	exit( 2 );
}

if ( ! is_string( $payload ) ) {
	fwrite( STDERR, "Payload could not be loaded.\n" );
	exit( 2 );
}

if ( '' === $signature ) {
	fwrite( STDERR, "No signature given and none found in the manifest.\n" );
	exit( 2 );
}

$oracles = Oracles::build();
if ( Cli::mode_uses_oracle( $mode ) && ! $oracles->has_required() ) {
	fwrite( STDERR, "Required oracle unavailable; cannot minimize.\n" );
	exit( 2 );
}

$checks = new Checks( $oracles );

$reproduces = static function ( string $candidate ) use ( $checks, $context, $mode, $signature ): bool {
	$failures = 'bytes' === $mode ? $checks->run_without_oracle( $context, $candidate ) : $checks->run( $context, $candidate );
	foreach ( $failures as $failure ) {
		if ( $failure['signature'] === $signature ) {
			return true;
		}
	}
	return false;
};

if ( ! $reproduces( $payload ) ) {
	fwrite( STDERR, "Signature {$signature} does not reproduce on the given payload.\n" );
	exit( 1 );
}

$current = $payload;
$tries   = 0;

$chunk = (int) ceil( max( 1, strlen( $current ) ) / 2 );
while ( $chunk >= 1 ) {
	$progress = false;

	for ( $at = 0; $at < strlen( $current ); ) {
		$candidate = substr( $current, 0, $at ) . substr( $current, $at + $chunk );
		++$tries;

		if ( strlen( $candidate ) < strlen( $current ) && $reproduces( $candidate ) ) {
			$current  = $candidate;
			$progress = true;
		} else {
			$at += max( 1, intdiv( $chunk, 2 ) );
		}
	}

	if ( ! $progress && $chunk > 1 ) {
		$chunk = intdiv( $chunk, 2 );
	} elseif ( ! $progress ) {
		break;
	}
}

for ( $at = 0; $at < strlen( $current ); $at++ ) {
	if ( 'a' === $current[ $at ] ) {
		continue;
	}

	$candidate        = $current;
	$candidate[ $at ] = 'a';
	++$tries;

	if ( $reproduces( $candidate ) ) {
		$current = $candidate;
	}
}

$out_dir = '' !== $source_dir ? $source_dir : '.';
if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0777, true ) ) {
	fwrite( STDERR, "Cannot create output dir {$out_dir}\n" );
	exit( 2 );
}

$payload_path  = "{$out_dir}/minimized-payload.txt";
$manifest_path = "{$out_dir}/minimized.json";
$manifest      = json_encode(
	array(
		'mode'            => $mode,
		'context'         => $context,
		'signature'       => $signature,
		'original_size'   => strlen( $payload ),
		'minimized_size'  => strlen( $current ),
		'tries'           => $tries,
		'payload_base64'  => base64_encode( $current ),
		'payload_hex'     => strlen( $current ) <= 256 ? bin2hex( $current ) : null,
		'environment'     => Cli::environment_metadata( $oracles ),
		'git'             => Cli::git_metadata( Bootstrap::repo_root() ),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
if ( false === $manifest || ! Cli::write_file( $payload_path, $current ) || ! Cli::write_file( $manifest_path, $manifest ) ) {
	fwrite( STDERR, "Cannot write minimized artifacts under {$out_dir}\n" );
	exit( 2 );
}

echo "Minimized {$signature}: " . strlen( $payload ) . ' -> ' . strlen( $current ) . " bytes in {$tries} tries.\n";
echo 'Hex: ' . bin2hex( substr( $current, 0, 128 ) ) . ( strlen( $current ) > 128 ? '...' : '' ) . "\n";
echo "Artifacts: {$payload_path}, {$manifest_path}\n";

exit( 0 );
