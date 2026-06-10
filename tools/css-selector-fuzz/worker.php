#!/usr/bin/env php
<?php
/**
 * Runs a batch of fuzz cases in this process.
 *
 * Usage:
 *   php tools/css-selector-fuzz/worker.php --start-seed 1 --count 500 \
 *       [--failures-out FILE] [--progress-file FILE] \
 *       [--determinism-every N] [--max-failures N]
 *
 * Prints a batch summary JSON line to stdout.
 * Exit codes: 0 = clean, 2 = invariant failures found, 1 = fatal.
 */

require_once __DIR__ . '/lib/autoload.php';

$options = \CssSelectorFuzz\parse_cli_options( $argv );

try {
	$summary = \CssSelectorFuzz\Worker::run_batch( $options );
	echo \CssSelectorFuzz\json_encode_safe( $summary ) . "\n";
	exit( 0 === $summary['failures'] ? 0 : 2 );
} catch ( Throwable $e ) {
	fwrite(
		STDERR,
		\CssSelectorFuzz\json_encode_safe(
			array(
				'kind'  => 'css-selector-fuzz-worker-fatal',
				'error' => \CssSelectorFuzz\Worker::describe_throwable( $e ),
			)
		) . "\n"
	);
	exit( 1 );
}
