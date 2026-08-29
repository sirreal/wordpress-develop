<?php
/**
 * Runs a compact environment matrix for the encoding fuzzer.
 *
 *     php tools/encoding-fuzz/matrix.php
 *
 * Exit codes: 0 pass, 1 matrix failure, 2 harness error.
 */

namespace EncodingFuzz;

require __DIR__ . '/lib/autoload.php';

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );

/**
 * @param string[]              $command
 * @param array<string, string> $env
 * @return array{code: int, stdout: string, stderr: string}
 */
function matrix_run_command( array $command, array $env = array() ): array {
	$process = proc_open(
		$command,
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		null,
		array_merge( $_ENV, $env )
	);

	if ( ! is_resource( $process ) ) {
		return array(
			'code'   => 2,
			'stdout' => '',
			'stderr' => 'proc_open failed',
		);
	}

	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );

	$stdout   = '';
	$stderr   = '';
	$open     = array(
		1 => $pipes[1],
		2 => $pipes[2],
	);
	$deadline = microtime( true ) + 120;

	while ( array() !== $open ) {
		if ( microtime( true ) > $deadline ) {
			proc_terminate( $process, 9 );
			foreach ( $open as $pipe ) {
				fclose( $pipe );
			}
			proc_close( $process );
			return array(
				'code'   => 2,
				'stdout' => $stdout,
				'stderr' => $stderr . "\nmatrix child timed out",
			);
		}

		$read   = array_values( $open );
		$write  = null;
		$except = null;
		$ready  = stream_select( $read, $write, $except, 1, 0 );

		if ( false === $ready ) {
			foreach ( $open as $pipe ) {
				fclose( $pipe );
			}
			proc_close( $process );
			return array(
				'code'   => 2,
				'stdout' => $stdout,
				'stderr' => $stderr . "\nmatrix stream_select failed",
			);
		}

		foreach ( $read as $pipe ) {
			$chunk = stream_get_contents( $pipe );
			if ( false === $chunk || '' === $chunk ) {
				continue;
			}

			if ( $pipe === $pipes[1] ) {
				$stdout .= $chunk;
			} else {
				$stderr .= $chunk;
			}
		}

		foreach ( $open as $index => $pipe ) {
			if ( feof( $pipe ) ) {
				$chunk = stream_get_contents( $pipe );
				if ( is_string( $chunk ) && '' !== $chunk ) {
					if ( 1 === $index ) {
						$stdout .= $chunk;
					} else {
						$stderr .= $chunk;
					}
				}
				fclose( $pipe );
				unset( $open[ $index ] );
			}
		}
	}

	return array(
		'code'   => proc_close( $process ),
		'stdout' => (string) $stdout,
		'stderr' => (string) $stderr,
	);
}

/**
 * @return array{records: array<int, array<string, mixed>>, malformed: string[]}
 */
function matrix_decode_ndjson( string $stdout ): array {
	$records   = array();
	$malformed = array();
	foreach ( explode( "\n", trim( $stdout ) ) as $line ) {
		if ( '' === $line ) {
			continue;
		}

		$record = json_decode( $line, true );
		if ( is_array( $record ) && isset( $record['type'] ) && is_string( $record['type'] ) ) {
			$records[] = $record;
		} else {
			$malformed[] = $line;
		}
	}
	return array(
		'records'   => $records,
		'malformed' => $malformed,
	);
}

/**
 * @param array<int, array<string, mixed>> $records
 */
function matrix_first_record( array $records, string $type ): ?array {
	foreach ( $records as $record ) {
		if ( $type === ( $record['type'] ?? null ) ) {
			return $record;
		}
	}
	return null;
}

/**
 * @param array<int, array<string, mixed>> $records
 */
function matrix_has_oracle_event( array $records, string $oracle ): bool {
	foreach ( $records as $record ) {
		if ( 'oracle-event' === ( $record['type'] ?? null ) && $oracle === ( $record['oracle'] ?? null ) ) {
			return true;
		}
	}
	return false;
}

$cases = array(
	array(
		'name'    => 'current-corpus',
		'command' => array( PHP_BINARY, __DIR__ . '/corpus.php', '--external', 'none' ),
			'env'     => array( 'ENCODING_FUZZ_FORCE_PCRE_U' => '' ),
			'check'   => static function ( array $run ): array {
				$decoded = matrix_decode_ndjson( $run['stdout'] );
				$records = $decoded['records'];
				$start   = matrix_first_record( $records, 'start' );
				$done    = matrix_first_record( $records, 'done' );
				$ok      = 0 === $run['code'] &&
					array() === $decoded['malformed'] &&
					is_array( $start ) &&
					is_array( $done ) &&
					true === ( $start['environment']['pcre_u'] ?? null ) &&
					0 === ( $done['stats']['failures'] ?? null );
				return array( $ok, is_array( $done ) ? json_encode( $done['stats'], JSON_UNESCAPED_SLASHES ) : trim( $run['stderr'] ), 2 === $run['code'] || array() !== $decoded['malformed'] );
			},
		),
	array(
		'name'    => 'forced-no-pcre-corpus',
		'command' => array( PHP_BINARY, __DIR__ . '/corpus.php', '--external', 'none' ),
			'env'     => array( 'ENCODING_FUZZ_FORCE_PCRE_U' => '0' ),
			'check'   => static function ( array $run ): array {
				$decoded = matrix_decode_ndjson( $run['stdout'] );
				$records = $decoded['records'];
				$start   = matrix_first_record( $records, 'start' );
				$done    = matrix_first_record( $records, 'done' );
				$ok      = 0 === $run['code'] &&
					array() === $decoded['malformed'] &&
					is_array( $start ) &&
					is_array( $done ) &&
					false === ( $start['environment']['pcre_u'] ?? null ) &&
					'off' === ( $start['environment']['pcre_u_override'] ?? null ) &&
					0 === ( $done['stats']['failures'] ?? null );
				return array( $ok, is_array( $start ) ? json_encode( $start['environment'], JSON_UNESCAPED_SLASHES ) : trim( $run['stderr'] ), 2 === $run['code'] || array() !== $decoded['malformed'] );
			},
		),
	array(
		'name'    => 'native-unavailable-corpus',
		'command' => array( PHP_BINARY, '-d', 'disable_functions=utf8_encode,utf8_decode', __DIR__ . '/corpus.php', '--external', 'none' ),
			'env'     => array( 'ENCODING_FUZZ_FORCE_PCRE_U' => '' ),
			'check'   => static function ( array $run ): array {
				$decoded = matrix_decode_ndjson( $run['stdout'] );
				$records = $decoded['records'];
				$done    = matrix_first_record( $records, 'done' );
				$ok      = 0 === $run['code'] &&
					array() === $decoded['malformed'] &&
					is_array( $done ) &&
					matrix_has_oracle_event( $records, 'native' ) &&
					0 === ( $done['stats']['failures'] ?? null );
				return array( $ok, is_array( $done ) ? json_encode( $done['stats'], JSON_UNESCAPED_SLASHES ) : trim( $run['stderr'] ), 2 === $run['code'] || array() !== $decoded['malformed'] );
			},
		),
	array(
		'name'    => 'mb-oracle-unavailable-fails-closed',
		'command' => array( PHP_BINARY, '-d', 'disable_functions=mb_check_encoding,mb_scrub', __DIR__ . '/corpus.php', '--external', 'none' ),
			'env'     => array( 'ENCODING_FUZZ_FORCE_PCRE_U' => '' ),
			'check'   => static function ( array $run ): array {
				$decoded = matrix_decode_ndjson( $run['stdout'] );
				$records = $decoded['records'];
				$fatal   = matrix_first_record( $records, 'fatal' );
				$ok      = 2 === $run['code'] &&
					array() === $decoded['malformed'] &&
					matrix_has_oracle_event( $records, 'mb' ) &&
					is_array( $fatal );
				return array( $ok, is_array( $fatal ) ? (string) $fatal['reason'] : trim( $run['stderr'] ), array() !== $decoded['malformed'] || ( 2 === $run['code'] && ! $ok ) );
			},
		),
);

$failed        = 0;
$harness_error = false;
foreach ( $cases as $case ) {
	$run = matrix_run_command( $case['command'], $case['env'] ?? array() );
	list( $ok, $detail, $case_harness_error ) = $case['check']( $run );
	if ( $ok ) {
		echo "PASS {$case['name']}\n";
	} else {
		++$failed;
		$harness_error = $harness_error || $case_harness_error;
		echo "FAIL {$case['name']}: exit {$run['code']}; {$detail}\n";
	}
}

echo $failed > 0 ? "\n{$failed} matrix check(s) FAILED\n" : "\nAll matrix checks passed\n";
exit( $failed > 0 ? ( $harness_error ? 2 : 1 ) : 0 );
