<?php
/**
 * Test orchestrator: runs a candidate implementation against a task's
 * test cases, each in an isolated subprocess with a timeout.
 *
 * Usage:
 *   php run-tests.php <candidate.php> <tests.json> [--generate]
 *
 * tests.json format:
 *   { "function": "fn_name",
 *     "cases": [ { "id": "case-id", "args": [...], "expected": <value> }, ... ] }
 *
 * With --generate, each case's "expected" is overwritten with the
 * candidate's actual output and tests.json is rewritten. Use ONCE with
 * the reference implementation, then freeze and review.
 *
 * Output: JSON summary to stdout. Exit 0 if all cases pass, 1 otherwise.
 */

const CASE_TIMEOUT_SECONDS = 10;

function run_case_subprocess( string $candidate_file, string $function, array $args ): array {
	$spec = json_encode(
		array(
			'candidate_file' => $candidate_file,
			'function'       => $function,
			'args'           => $args,
		),
		JSON_INVALID_UTF8_SUBSTITUTE
	);

	$proc = proc_open(
		array( PHP_BINARY, __DIR__ . '/run-case.php' ),
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);

	if ( ! is_resource( $proc ) ) {
		return array( 'status' => 'harness-error', 'error' => 'proc_open failed' );
	}

	fwrite( $pipes[0], $spec );
	fclose( $pipes[0] );

	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );

	$stdout   = '';
	$stderr   = '';
	$deadline = microtime( true ) + CASE_TIMEOUT_SECONDS;

	while ( true ) {
		$status = proc_get_status( $proc );
		$stdout .= stream_get_contents( $pipes[1] );
		$stderr .= stream_get_contents( $pipes[2] );

		if ( ! $status['running'] ) {
			break;
		}

		if ( microtime( true ) > $deadline ) {
			proc_terminate( $proc, 9 );
			proc_close( $proc );
			return array(
				'status' => 'timeout',
				'error'  => 'Execution exceeded ' . CASE_TIMEOUT_SECONDS . 's (possible infinite loop).',
			);
		}

		usleep( 20000 );
	}

	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );

	$decoded = json_decode( $stdout, true );
	if ( ! is_array( $decoded ) ) {
		return array(
			'status' => 'crash',
			'error'  => 'Subprocess produced no valid JSON. stderr: ' . substr( $stderr, 0, 2000 ),
		);
	}

	return $decoded;
}

function values_equal( $expected, $actual ): bool {
	// Strict scalar identity; recursive for arrays (key order matters
	// for associative arrays, as JSON round-trips preserve order).
	return $expected === $actual;
}

function main( array $argv ): int {
	$generate = in_array( '--generate', $argv, true );
	$argv     = array_values( array_filter( $argv, fn( $a ) => '--generate' !== $a ) );

	if ( count( $argv ) < 3 ) {
		fwrite( STDERR, "Usage: php run-tests.php <candidate.php> <tests.json> [--generate]\n" );
		return 2;
	}

	$candidate_file = realpath( $argv[1] );
	$tests_file     = realpath( $argv[2] );

	if ( false === $candidate_file || false === $tests_file ) {
		fwrite( STDERR, "Candidate or tests file not found.\n" );
		return 2;
	}

	$tests = json_decode( file_get_contents( $tests_file ), true );
	if ( ! is_array( $tests ) || ! isset( $tests['function'], $tests['cases'] ) ) {
		fwrite( STDERR, "Invalid tests.json (need 'function' and 'cases').\n" );
		return 2;
	}

	$results = array();
	$passed  = 0;

	foreach ( $tests['cases'] as $i => &$case ) {
		$id  = $case['id'] ?? "case-{$i}";
		$run = run_case_subprocess( $candidate_file, $tests['function'], $case['args'] );

		if ( $generate ) {
			if ( 'ok' !== ( $run['status'] ?? '' ) ) {
				fwrite( STDERR, "GENERATE FAILED for {$id}: " . ( $run['error'] ?? $run['status'] ) . "\n" );
				return 1;
			}
			$case['expected'] = $run['result'];
			$results[]        = array( 'id' => $id, 'status' => 'generated', 'expected' => $run['result'] );
			continue;
		}

		if ( 'ok' === ( $run['status'] ?? '' ) && values_equal( $case['expected'], $run['result'] ) ) {
			$status = 'pass';
			++$passed;
		} elseif ( 'ok' === ( $run['status'] ?? '' ) ) {
			$status = 'fail';
		} else {
			$status = $run['status']; // error | timeout | crash | harness-error
		}

		$results[] = array(
			'id'             => $id,
			'status'         => $status,
			'expected'       => $case['expected'] ?? null,
			'actual'         => $run['result'] ?? null,
			'error'          => $run['error'] ?? null,
			'doing_it_wrong' => $run['doing_it_wrong'] ?? array(),
			'trigger_error'  => $run['trigger_error'] ?? array(),
		);
	}
	unset( $case );

	if ( $generate ) {
		file_put_contents(
			$tests_file,
			json_encode( $tests, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
		);
	}

	$total = count( $tests['cases'] );
	echo json_encode(
		array(
			'candidate' => $candidate_file,
			'function'  => $tests['function'],
			'passed'    => $generate ? null : $passed,
			'total'     => $total,
			'cases'     => $results,
		),
		JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n";

	return ( $generate || $passed === $total ) ? 0 : 1;
}

exit( main( $argv ) );
