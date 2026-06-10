<?php
/**
 * Self-test for the WP_HTML_Decoder fuzz harness.
 *
 * Exit codes: 0 pass, 1 fail.
 */

namespace HtmlDecoderFuzz;

require __DIR__ . '/../lib/autoload.php';

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );

Bootstrap::load_targets();

$failed = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $failed;
	if ( $ok ) {
		echo "PASS {$label}\n";
	} else {
		++$failed;
		echo "FAIL {$label}" . ( '' !== $detail ? ": {$detail}" : '' ) . "\n";
	}
}

/**
 * @return array{code: int, stdout: string, stderr: string}
 */
function run_process( array $command, array $env = array() ): array {
	$process = proc_open(
		$command,
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		Bootstrap::repo_root(),
		array_merge( getenv() ?: array(), $env )
	);

	if ( ! is_resource( $process ) ) {
		return array(
			'code'   => 127,
			'stdout' => '',
			'stderr' => 'proc_open failed',
		);
	}

	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array(
		'code'   => proc_close( $process ),
		'stdout' => (string) $stdout,
		'stderr' => (string) $stderr,
	);
}

function remove_tree( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$items = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
		\RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $path );
}

$oracles = Oracles::build();
$events  = $oracles->drain_events();

check( 'required oracles available', $oracles->has_required(), json_encode( $events ) );
check(
	'no oracle disabled by battery',
	array() === array_filter( $events, static fn( $e ) => 'oracle-disabled' === $e['type'] ),
	json_encode( $events )
);

$checks        = new Checks( $oracles );
$battery_fails = array();
foreach ( Oracles::battery() as $i => $vector ) {
	list( $context, $payload ) = $vector;
	foreach ( $checks->run( $context, $payload ) as $failure ) {
		$battery_fails[] = "vector {$i}: {$failure['signature']}";
	}
}
check( 'real targets clean on oracle battery', array() === $battery_fails, implode( '; ', $battery_fails ) );

$real_targets = Targets::real();

/**
 * @return string[] Distinct check names observed.
 */
function broken_run( Oracles $oracles, array $real_targets, array $overrides ): array {
	$checks = new Checks( $oracles, array_merge( $real_targets, $overrides ) );
	$seen   = array();

	$cases = array_merge(
		Oracles::battery(),
		array(
			array( 'text', 'a&amp;b' ),
			array( 'attribute', '&notx' ),
			array( 'attribute', 'jav' ),
			array( 'attribute', 'javascript&colon;alert(1)' ),
		)
	);

	foreach ( $cases as $case ) {
		foreach ( $checks->run( $case[0], $case[1] ) as $failure ) {
			$seen[ $failure['check'] ] = true;
		}
	}

	return array_keys( $seen );
}

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'decode_text'      => static fn( string $text ): string => str_replace( "\u{20AC}", "\u{0080}", \WP_HTML_Decoder::decode_text_node( $text ) ),
		'decode_attribute' => static fn( string $text ): string => str_replace( "\u{20AC}", "\u{0080}", \WP_HTML_Decoder::decode_attribute( $text ) ),
	)
);
check( 'catches decoder skipping C1 remap', in_array( 'decode-mismatch', $seen, true ), implode( ',', $seen ) );

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'decode_attribute' => static fn( string $text ): string => \WP_HTML_Decoder::decode_text_node( $text ),
		'read_character_reference' => static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
			return \WP_HTML_Decoder::read_character_reference( 'attribute' === $context ? 'data' : $context, $text, $at, $match_byte_length );
		},
	)
);
check( 'catches semicolonless refs decoded in attributes', in_array( 'decode-mismatch', $seen, true ), implode( ',', $seen ) );

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'read_character_reference' => static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
			$result = \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length );
			if ( null !== $result ) {
				++$match_byte_length;
			}
			return $result;
		},
	)
);
check(
	'catches off-by-one match length',
	in_array( 'reader-decode-mismatch', $seen, true ) || in_array( 'reader-overran-input', $seen, true ),
	implode( ',', $seen )
);

$seen = broken_run(
	$oracles,
	$real_targets,
	array(
		'attribute_starts_with' => static function ( string $haystack, string $search, string $case_sensitivity ): bool {
			unset( $case_sensitivity );
			return '' === $search || strlen( $haystack ) < strlen( $search ) || str_starts_with( \WP_HTML_Decoder::decode_attribute( $haystack ), $search );
		},
	)
);
check( 'catches partial-prefix attribute matcher', in_array( 'attribute-starts-with-mismatch', $seen, true ), implode( ',', $seen ) );

$names = Bootstrap::named_reference_names();
check( 'uses generated named-reference map', count( $names ) > 2000, (string) count( $names ) );

$a = ( new Generator( new Prng( '7:3' ), 4096, $names ) )->generate();
$b = ( new Generator( new Prng( '7:3' ), 4096, $names ) )->generate();
check( 'generator deterministic for (seed, case)', $a === $b );

$strategies = array();
$contexts   = array();
$unsafe     = 0;
$total      = 1200;
for ( $i = 0; $i < $total; $i++ ) {
	$generated = ( new Generator( new Prng( "smoke:{$i}" ), 4096, $names ) )->generate();
	$strategies[ $generated['strategy'] ] = true;
	$contexts[ $generated['context'] ]    = true;
	if ( ! Generator::is_oracle_safe_payload( $generated['payload'] ) ) {
		++$unsafe;
	}
}
check( 'all 10 strategies appear', 10 === count( $strategies ), implode( ',', array_keys( $strategies ) ) );
check( 'both contexts appear', isset( $contexts['text'], $contexts['attribute'] ), implode( ',', array_keys( $contexts ) ) );
check( 'generated payloads are oracle-safe', 0 === $unsafe, (string) $unsafe );

$fuzz_failures = 0;
for ( $i = 0; $i < 300; $i++ ) {
	$generated = ( new Generator( new Prng( "smoke-run:{$i}" ), 4096, $names ) )->generate();
	$failures  = $checks->run( $generated['context'], $generated['payload'] );
	foreach ( $failures as $failure ) {
		++$fuzz_failures;
		echo "  finding: {$failure['signature']} on " . bin2hex( substr( $generated['payload'], 0, 48 ) ) . "\n";
	}
}
check( '300-case fuzz run clean', 0 === $fuzz_failures );

$zero_cases = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--cases', '0' ) );
check( 'worker rejects zero cases', 2 === $zero_cases['code'], $zero_cases['stdout'] . $zero_cases['stderr'] );

$zero_batch = run_process( array( PHP_BINARY, __DIR__ . '/../runner.php', '--cases-per-batch', '0', '--duration-seconds', '1', '--output-dir', sys_get_temp_dir() . '/html-decoder-fuzz-smoke-bad-runner-' . getmypid() ) );
check( 'runner rejects zero cases per batch', 2 === $zero_batch['code'], $zero_batch['stdout'] . $zero_batch['stderr'] );

$unwritable_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-unwritable-' . getmypid();
remove_tree( $unwritable_dir );
mkdir( $unwritable_dir, 0555, true );
chmod( $unwritable_dir, 0555 );
clearstatcache( true, $unwritable_dir );
$unwritable_runner = run_process( array( PHP_BINARY, __DIR__ . '/../runner.php', '--duration-seconds', '1', '--output-dir', $unwritable_dir ) );
chmod( $unwritable_dir, 0755 );
clearstatcache( true, $unwritable_dir );
remove_tree( $unwritable_dir );
check( 'runner rejects unwritable output dir', 2 === $unwritable_runner['code'], $unwritable_runner['stdout'] . $unwritable_runner['stderr'] );

$bad_state_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-bad-state-' . getmypid();
remove_tree( $bad_state_dir );
mkdir( $bad_state_dir, 0777, true );
mkdir( $bad_state_dir . '/state.json' );
$bad_state_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--cases-per-batch',
		'1',
		'--output-dir',
		$bad_state_dir,
	)
);
remove_tree( $bad_state_dir );
check( 'runner reports state write failures', 2 === $bad_state_runner['code'], $bad_state_runner['stdout'] . $bad_state_runner['stderr'] );

$bad_summary_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-bad-summary-' . getmypid();
remove_tree( $bad_summary_dir );
mkdir( $bad_summary_dir, 0777, true );
mkdir( $bad_summary_dir . '/summary.ndjson' );
$bad_summary_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'1',
		'--cases-per-batch',
		'1',
		'--output-dir',
		$bad_summary_dir,
	)
);
remove_tree( $bad_summary_dir );
check( 'runner reports summary open failures', 2 === $bad_summary_runner['code'], $bad_summary_runner['stdout'] . $bad_summary_runner['stderr'] );

$bad_integer = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--cases', 'abc' ) );
check( 'worker rejects non-numeric integer options', 2 === $bad_integer['code'], $bad_integer['stdout'] . $bad_integer['stderr'] );

$huge_integer = run_process( array( PHP_BINARY, __DIR__ . '/../worker.php', '--cases', '999999999999999999999999999999999999999' ) );
check( 'worker rejects out-of-range integer options', 2 === $huge_integer['code'], $huge_integer['stdout'] . $huge_integer['stderr'] );

$pipeline_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-' . getmypid();
remove_tree( $pipeline_dir );
$faulted_worker = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../worker.php',
		'--seed',
		'1',
		'--cases',
		'100',
		'--output-dir',
		$pipeline_dir,
		'--progress-every',
		'100',
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
check( 'faulted worker reports findings', 1 === $faulted_worker['code'], $faulted_worker['stdout'] . $faulted_worker['stderr'] );

$failure_files = glob( $pipeline_dir . '/failure-*/failure.json' );
check( 'faulted worker writes failure artifact', is_array( $failure_files ) && array() !== $failure_files );

$failure_file = is_array( $failure_files ) && array() !== $failure_files ? $failure_files[0] : null;
if ( null !== $failure_file ) {
	$manifest = json_decode( (string) file_get_contents( $failure_file ), true );
	$detail   = $manifest['failures'][0]['detail'] ?? array();
	check( 'failure artifact includes full expected/got', isset( $detail['expected_base64'], $detail['got_base64'] ) );

	$replay = run_process(
		array( PHP_BINARY, __DIR__ . '/../replay.php', '--failure', $failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
	);
	check( 'faulted replay reproduces finding', 1 === $replay['code'], $replay['stdout'] . $replay['stderr'] );

	$minimize = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $failure_file ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
	);
	check( 'faulted minimizer preserves signature', 0 === $minimize['code'], $minimize['stdout'] . $minimize['stderr'] );

	$minimize_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-minimize-' . getmypid();
	remove_tree( $minimize_dir );
	$minimize_output_dir = run_process(
		array( PHP_BINARY, __DIR__ . '/../minimize.php', '--failure', $failure_file, '--output-dir', $minimize_dir ),
		array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
	);
	check(
		'minimizer creates requested output directory',
		0 === $minimize_output_dir['code'] && is_file( $minimize_dir . '/minimized.json' ),
		$minimize_output_dir['stdout'] . $minimize_output_dir['stderr']
	);
	remove_tree( $minimize_dir );
}

remove_tree( $pipeline_dir );

$runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-runner-' . getmypid();
remove_tree( $runner_dir );
$faulted_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'100',
		'--seed-base',
		'1',
		'--cases-per-batch',
		'100',
		'--output-dir',
		$runner_dir,
	),
	array( 'HTML_DECODER_FUZZ_FAULT' => 'skip-c1-remap' )
);
$runner_state = is_file( $runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $runner_dir . '/state.json' ), true )
	: array();
check(
	'faulted runner reports findings',
	1 === $faulted_runner['code'] && ( $runner_state['failures'] ?? 0 ) > 0,
	$faulted_runner['stdout'] . $faulted_runner['stderr']
);
remove_tree( $runner_dir );

$corrupt_runner_dir = sys_get_temp_dir() . '/html-decoder-fuzz-smoke-corrupt-runner-' . getmypid();
remove_tree( $corrupt_runner_dir );
$corrupt_runner = run_process(
	array(
		PHP_BINARY,
		__DIR__ . '/../runner.php',
		'--lanes',
		'1',
		'--duration-seconds',
		'0',
		'--max-cases',
		'100',
		'--seed-base',
		'1',
		'--cases-per-batch',
		'100',
		'--output-dir',
		$corrupt_runner_dir,
	),
	array(
		'HTML_DECODER_FUZZ_FAULT'                 => 'skip-c1-remap',
		'HTML_DECODER_FUZZ_CORRUPT_FAILURE_EVENT' => '1',
	)
);
$corrupt_runner_state = is_file( $corrupt_runner_dir . '/state.json' )
	? json_decode( (string) file_get_contents( $corrupt_runner_dir . '/state.json' ), true )
	: array();
check(
	'runner treats malformed finding events as harness errors',
	2 === $corrupt_runner['code'] && ( $corrupt_runner_state['harness_errors'] ?? 0 ) > 0,
	$corrupt_runner['stdout'] . $corrupt_runner['stderr']
);
remove_tree( $corrupt_runner_dir );

echo $failed > 0 ? "\n{$failed} smoke check(s) FAILED\n" : "\nAll smoke checks passed\n";
exit( $failed > 0 ? 1 : 0 );
