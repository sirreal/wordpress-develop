<?php
/**
 * Re-runs every check against one saved or re-derived decoder payload.
 *
 *     php tools/html-decoder-fuzz/replay.php --failure artifacts/.../failure.json
 *     php tools/html-decoder-fuzz/replay.php --seed 123 --case 45
 *
 * Exit codes: 0 clean, 1 findings reproduced, 2 harness error.
 */

namespace HtmlDecoderFuzz;

require __DIR__ . '/lib/autoload.php';

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );
ini_set( 'memory_limit', '512M' );

$options = Cli::parse_args(
	$argv,
	array(
		'failure'   => '',
		'input'     => '',
		'seed'      => -1,
		'case'      => -1,
		'context'   => 'both',
		'mode'      => 'oracle',
		'max-bytes' => 4096,
	)
);

Cli::require_int_at_least( $options, 'max-bytes', 1 );
Cli::require_one_of( $options, 'context', array( 'text', 'attribute', 'both' ) );
Cli::require_one_of( $options, 'mode', Cli::valid_modes() );

Bootstrap::load_targets();

$payload = null;
$context = $options['context'];
$mode    = $options['mode'];
$source  = null;

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
	$source  = "failure manifest {$options['failure']}";
} elseif ( '' !== $options['input'] ) {
	$payload = file_get_contents( $options['input'] );
	if ( false === $payload ) {
		fwrite( STDERR, "Cannot read input file {$options['input']}\n" );
		exit( 2 );
	}
	$source = "input file {$options['input']}";
} elseif ( $options['seed'] >= 0 && $options['case'] >= 0 ) {
	$generator = new Generator( new Prng( "{$options['seed']}:{$options['case']}" ), $options['max-bytes'], Bootstrap::named_reference_names() );
	if ( 'bytes' === $mode ) {
		$generated = $generator->generate_bytes();
	} elseif ( 'names' === $mode ) {
		$generated = $generator->generate_name_sweep( $options['case'] );
	} elseif ( 'legacy-followers' === $mode ) {
		$generated = $generator->generate_legacy_follower_sweep( $options['case'] );
	} elseif ( 'prefix-families' === $mode ) {
		$generated = $generator->generate_prefix_family_sweep( $options['case'] );
	} elseif ( 'numeric-boundaries' === $mode ) {
		$generated = $generator->generate_numeric_boundary_sweep( $options['case'] );
	} elseif ( 'corpus' === $mode ) {
		$generated = $generator->generate_corpus_mutation( $options['case'] );
	} elseif ( 'token-map' === $mode ) {
		$generated = $generator->generate_token_map_sweep( $options['case'] );
	} elseif ( 'coverage' === $mode ) {
		$generated = $generator->generate();
	} else {
		$generated = $generator->generate();
	}
	$payload   = $generated['payload'];
	$context   = $generated['context'];
	$source    = "seed {$options['seed']} case {$options['case']} (mode {$mode}, strategy {$generated['strategy']}, context {$context})";
} else {
	fwrite( STDERR, "Provide --failure, --input, or --seed with --case.\n" );
	exit( 2 );
}

if ( ! is_string( $payload ) ) {
	fwrite( STDERR, "Payload could not be loaded.\n" );
	exit( 2 );
}

$oracles = Oracles::build();
foreach ( $oracles->drain_events() as $event ) {
	fwrite( STDERR, "oracle event: {$event['oracle']}: {$event['detail']}\n" );
}
if ( Cli::mode_uses_oracle( $mode ) && ! $oracles->has_required() ) {
	fwrite( STDERR, "Required oracle unavailable; cannot replay.\n" );
	exit( 2 );
}

$checks   = new Checks( $oracles );
$failures = 'bytes' === $mode ? $checks->run_without_oracle( $context, $payload ) : $checks->run( $context, $payload );

echo "Replaying {$source}\n";
echo "Mode: {$mode}\n";
echo "Context: {$context}\n";
echo 'Payload: ' . strlen( $payload ) . ' bytes, sha256 ' . hash( 'sha256', $payload ) . "\n";
echo 'Hex preview: ' . bin2hex( substr( $payload, 0, 96 ) ) . ( strlen( $payload ) > 96 ? '...' : '' ) . "\n";
echo 'Oracles: ' . implode( ', ', $oracles->names() ) . "\n\n";

if ( array() === $failures ) {
	echo "All checks passed.\n";
	exit( 0 );
}

echo count( $failures ) . " failure(s):\n";
foreach ( $failures as $failure ) {
	echo "- {$failure['signature']}\n";
	echo '  ' . json_encode( $failure['detail'], JSON_UNESCAPED_SLASHES ) . "\n";
}

exit( 1 );
