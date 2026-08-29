<?php
/**
 * Re-runs every check against one saved or re-derived input.
 *
 *     php tools/encoding-fuzz/replay.php --failure artifacts/.../failure.json
 *     php tools/encoding-fuzz/replay.php --input artifacts/.../input.bin
 *     php tools/encoding-fuzz/replay.php --seed 123 --case 45 [--max-bytes 65536]
 *
 * Exit codes: 0 all checks pass, 1 failures reproduced, 2 harness error.
 */

namespace EncodingFuzz;

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
		'max-bytes' => 65536,
		'external'  => 'auto',
	)
);

$input  = null;
$source = null;

if ( '' !== $options['failure'] ) {
	$manifest = json_decode( (string) file_get_contents( $options['failure'] ), true );
	if ( ! is_array( $manifest ) || ! isset( $manifest['input_base64'] ) ) {
		fwrite( STDERR, "Cannot read failure manifest {$options['failure']}\n" );
		exit( 2 );
	}
	$input  = base64_decode( $manifest['input_base64'], true );
	$source = "failure manifest {$options['failure']}";
} elseif ( '' !== $options['input'] ) {
	$input = file_get_contents( $options['input'] );
	if ( false === $input ) {
		fwrite( STDERR, "Cannot read input file {$options['input']}\n" );
		exit( 2 );
	}
	$source = "input file {$options['input']}";
} elseif ( $options['seed'] >= 0 && $options['case'] >= 0 ) {
	$prng      = new Prng( "{$options['seed']}:{$options['case']}" );
	$generator = new Generator( $prng, $options['max-bytes'] );
	$generated = $generator->generate();
	$input     = $generated['bytes'];
	$source    = "seed {$options['seed']} case {$options['case']} (strategy {$generated['strategy']})";
} else {
	fwrite( STDERR, "Provide --failure, --input, or --seed with --case.\n" );
	exit( 2 );
}

Bootstrap::load_targets();

$oracles = Oracles::build( Cli::resolve_externals( $options['external'] ) );
foreach ( $oracles->drain_events() as $event ) {
	fwrite( STDERR, "oracle event: {$event['oracle']}: {$event['detail']}\n" );
}
if ( ! $oracles->has_required() ) {
	fwrite( STDERR, "mbstring oracle unavailable; cannot replay.\n" );
	exit( 2 );
}

$checks   = new Checks( $oracles );
$failures = $checks->run( $input );

echo "Replaying {$source}\n";
echo 'Input: ' . strlen( $input ) . " bytes, sha256 " . hash( 'sha256', $input ) . "\n";
echo 'Hex preview: ' . bin2hex( substr( $input, 0, 64 ) ) . ( strlen( $input ) > 64 ? '…' : '' ) . "\n";
echo 'Oracles: ' . implode( ', ', $oracles->names() ) . "\n\n";

if ( array() === $failures ) {
	echo "All checks passed.\n";
	$oracles->shutdown();
	exit( 0 );
}

echo count( $failures ) . " failure(s):\n";
foreach ( $failures as $failure ) {
	echo "- {$failure['signature']}\n";
	echo '  ' . json_encode( $failure['detail'], JSON_UNESCAPED_SLASHES ) . "\n";
}

$oracles->shutdown();
exit( 1 );
