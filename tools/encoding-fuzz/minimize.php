<?php
/**
 * Shrinks a failing input while preserving its failure signature.
 *
 *     php tools/encoding-fuzz/minimize.php --failure artifacts/.../failure.json
 *     php tools/encoding-fuzz/minimize.php --input artifacts/.../input.bin --signature scrub-mismatch:scrub_fb
 *
 * Strategy: delta-debugging style chunk removal at halving granularity,
 * then per-byte removal, then byte canonicalization toward 'a'. The
 * minimized artifact lands next to the original (or in --output-dir).
 *
 * Exit codes: 0 minimized, 1 signature did not reproduce, 2 harness error.
 */

namespace EncodingFuzz;

require __DIR__ . '/lib/autoload.php';

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );
ini_set( 'memory_limit', '512M' );

$options = Cli::parse_args(
	$argv,
	array(
		'failure'    => '',
		'input'      => '',
		'signature'  => '',
		'external'   => 'auto',
		'output-dir' => '',
	)
);

$input      = null;
$signature  = $options['signature'];
$source_dir = $options['output-dir'];

if ( '' !== $options['failure'] ) {
	$manifest = json_decode( (string) file_get_contents( $options['failure'] ), true );
	if ( ! is_array( $manifest ) || ! isset( $manifest['input_base64'] ) ) {
		fwrite( STDERR, "Cannot read failure manifest {$options['failure']}\n" );
		exit( 2 );
	}
	$input = base64_decode( $manifest['input_base64'], true );
	if ( '' === $signature ) {
		$signature = $manifest['signatures'][0] ?? '';
	}
	if ( '' === $source_dir ) {
		$source_dir = dirname( $options['failure'] );
	}
} elseif ( '' !== $options['input'] ) {
	$input = file_get_contents( $options['input'] );
	if ( false === $input ) {
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

if ( '' === $signature ) {
	fwrite( STDERR, "No signature given and none found in the manifest.\n" );
	exit( 2 );
}

Bootstrap::load_targets();

$oracles = Oracles::build( Cli::resolve_externals( $options['external'] ) );
if ( ! $oracles->has_required() ) {
	fwrite( STDERR, "mbstring oracle unavailable; cannot minimize.\n" );
	exit( 2 );
}

$checks = new Checks( $oracles );

$reproduces = static function ( string $candidate ) use ( $checks, $signature ): bool {
	foreach ( $checks->run( $candidate ) as $failure ) {
		if ( $failure['signature'] === $signature ) {
			return true;
		}
	}
	return false;
};

if ( ! $reproduces( $input ) ) {
	fwrite( STDERR, "Signature {$signature} does not reproduce on the given input.\n" );
	exit( 1 );
}

$current = $input;
$tries   = 0;

// Phase 1: chunk removal at halving granularity (ddmin-style).
$chunk = (int) ceil( strlen( $current ) / 2 );
while ( $chunk >= 1 ) {
	$progress = false;

	for ( $at = 0; $at < strlen( $current ); ) {
		$candidate = substr( $current, 0, $at ) . substr( $current, $at + $chunk );
		++$tries;

		if ( '' !== $candidate && strlen( $candidate ) < strlen( $current ) && $reproduces( $candidate ) ) {
			$current  = $candidate;
			$progress = true;
			// Re-test the same offset against the shortened input.
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

// Phase 2: canonicalize bytes toward a printable 'a'.
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
file_put_contents( "{$out_dir}/minimized.bin", $current );
file_put_contents(
	"{$out_dir}/minimized.json",
	json_encode(
		array(
			'signature'      => $signature,
			'original_size'  => strlen( $input ),
			'minimized_size' => strlen( $current ),
			'tries'          => $tries,
			'input_base64'   => base64_encode( $current ),
			'input_hex'      => strlen( $current ) <= 256 ? bin2hex( $current ) : null,
			'environment'    => Cli::environment_metadata( $oracles ),
			'git'            => Cli::git_metadata( Bootstrap::repo_root() ),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	)
);

echo "Minimized {$signature}: " . strlen( $input ) . ' -> ' . strlen( $current ) . " bytes in {$tries} tries.\n";
echo 'Hex: ' . bin2hex( substr( $current, 0, 128 ) ) . ( strlen( $current ) > 128 ? '…' : '' ) . "\n";
echo "Artifacts: {$out_dir}/minimized.bin, {$out_dir}/minimized.json\n";

$oracles->shutdown();
exit( 0 );
