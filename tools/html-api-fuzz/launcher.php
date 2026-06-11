#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

function html_api_fuzz_launcher_usage(): void {
	echo "Usage: php tools/html-api-fuzz/launcher.php [--lanes N] [--output-dir DIR] [--duration-seconds N] [--max-seeds N] [--payload-policy POLICY] [--max-input-bytes N] [--dom-oracle php-dom|lexbor-source] [--lexbor-oracle-bin PATH] [--max-keep-per-signature N] [--keep-all-artifacts] [--watcher] [--triage-oracle-findings]\n";
	echo "Create OUTPUT_DIR/STOP (see stop.php) to stop all lanes gracefully: each finishes its current batch and exits.\n";
	echo "--max-keep-per-signature is applied per lane; a signature seen in every lane keeps up to N x lanes exemplar directories.\n";
	echo "--triage-oracle-findings passes oracle findings to the watcher/minimizer when --watcher is used.\n";
}

function html_api_fuzz_launcher_validate_generator_options( string $profile, string $mode, string $payload_policy ): void {
	if ( 'auto' !== $profile && ! in_array( $profile, \HtmlApiFuzz\Generator::profiles(), true ) ) {
		throw new InvalidArgumentException( 'Unknown generator profile: ' . $profile );
	}
	if ( 'auto' !== $mode && ! in_array( $mode, \HtmlApiFuzz\Generator::modes(), true ) ) {
		throw new InvalidArgumentException( 'Unknown generator mode: ' . $mode );
	}
	if ( 'auto' !== $payload_policy && ! in_array( $payload_policy, \HtmlApiFuzz\Generator::payload_policies(), true ) ) {
		throw new InvalidArgumentException( 'Unknown generator payload policy: ' . $payload_policy );
	}
}

function html_api_fuzz_launcher_validate_runtime_options( int $max_seeds, float $duration_seconds, int $timeout_ms, int $max_input_bytes, int $max_tokens, int $max_nodes ): void {
	if ( $max_seeds < 0 ) {
		throw new InvalidArgumentException( 'Expected --max-seeds to be at least 0.' );
	}
	if ( $duration_seconds < 0 ) {
		throw new InvalidArgumentException( 'Expected --duration-seconds to be at least 0.' );
	}
	if ( $timeout_ms < 1 ) {
		throw new InvalidArgumentException( 'Expected --timeout-ms to be at least 1.' );
	}
	if ( $max_input_bytes < 0 ) {
		throw new InvalidArgumentException( 'Expected --max-input-bytes to be at least 0.' );
	}
	if ( $max_tokens < 1 ) {
		throw new InvalidArgumentException( 'Expected --max-tokens to be at least 1.' );
	}
	if ( $max_nodes < 1 ) {
		throw new InvalidArgumentException( 'Expected --max-nodes to be at least 1.' );
	}
}

function html_api_fuzz_launcher_start_lane( array $command, string $cwd, string $log_path ) {
	$spec = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = proc_open( $command, $spec, $pipes, $cwd );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not start runner lane.' );
	}

	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	\HtmlApiFuzz\ensure_dir( dirname( $log_path ) );
	file_put_contents( $log_path, '' );

	return array(
		'process'   => $process,
		'pipes'     => $pipes,
		'logPath'   => $log_path,
		'startedAt' => microtime( true ),
		'stdout'    => '',
		'stderr'    => '',
	);
}

function html_api_fuzz_launcher_drain_lane( array &$lane ): void {
	$stdout = stream_get_contents( $lane['pipes'][1] );
	$stderr = stream_get_contents( $lane['pipes'][2] );
	if ( '' !== $stdout ) {
		$lane['stdout'] .= $stdout;
		file_put_contents( $lane['logPath'], $stdout, FILE_APPEND );
	}
	if ( '' !== $stderr ) {
		$lane['stderr'] .= $stderr;
		file_put_contents( $lane['logPath'], $stderr, FILE_APPEND );
	}
}

function html_api_fuzz_launcher_close_lane( array &$lane ): array {
	html_api_fuzz_launcher_drain_lane( $lane );
	fclose( $lane['pipes'][1] );
	fclose( $lane['pipes'][2] );
	$code = proc_close( $lane['process'] );

	return array(
		'code'       => $code,
		'durationMs' => (int) round( ( microtime( true ) - $lane['startedAt'] ) * 1000 ),
		'stdoutTail' => substr( $lane['stdout'], -2000 ),
		'stderrTail' => substr( $lane['stderr'], -2000 ),
	);
}

$options = \HtmlApiFuzz\parse_cli_options( $argv );
if ( \HtmlApiFuzz\option_bool( $options, 'help', false ) || \HtmlApiFuzz\option_bool( $options, 'h', false ) ) {
	html_api_fuzz_launcher_usage();
	exit( 0 );
}

$repo_root        = \HtmlApiFuzz\repo_root();
$output_dir       = \HtmlApiFuzz\option_string( $options, 'output-dir', $repo_root . '/artifacts/html-api-fuzz/launch-' . \HtmlApiFuzz\timestamp() );
$lanes            = max( 1, \HtmlApiFuzz\option_int( $options, 'lanes', 2 ) );
$start_seed       = \HtmlApiFuzz\option_int( $options, 'start-seed', 1 );
$max_seeds        = \HtmlApiFuzz\option_int( $options, 'max-seeds', 0 );
$duration_seconds = \HtmlApiFuzz\option_float( $options, 'duration-seconds', 60.0 );
$timeout_ms       = \HtmlApiFuzz\option_int( $options, 'timeout-ms', 2500 );
$profile          = \HtmlApiFuzz\option_string( $options, 'profile', 'auto' );
$mode             = \HtmlApiFuzz\option_string( $options, 'mode', 'auto' );
$payload_policy   = \HtmlApiFuzz\option_string( $options, 'payload-policy', 'auto' );
$max_input_bytes  = \HtmlApiFuzz\option_int( $options, 'max-input-bytes', 0 );
$max_tokens       = \HtmlApiFuzz\option_int( $options, 'max-tokens', 2000 );
$max_nodes        = \HtmlApiFuzz\option_int( $options, 'max-nodes', 3000 );
$stop_on_failure  = \HtmlApiFuzz\option_bool( $options, 'stop-on-failure', false );
$fail_unsupported = \HtmlApiFuzz\option_bool( $options, 'fail-unsupported', false );
$run_watcher      = \HtmlApiFuzz\option_bool( $options, 'watcher', false );
$triage_oracle_findings = \HtmlApiFuzz\option_bool( $options, 'triage-oracle-findings', false );
$max_keep_per_signature = \HtmlApiFuzz\option_int( $options, 'max-keep-per-signature', 5 );
$keep_all_artifacts     = \HtmlApiFuzz\option_bool( $options, 'keep-all-artifacts', false );
if ( $max_keep_per_signature < 1 ) {
	throw new InvalidArgumentException( 'Expected --max-keep-per-signature to be at least 1.' );
}
html_api_fuzz_launcher_validate_generator_options( $profile, $mode, $payload_policy );
html_api_fuzz_launcher_validate_runtime_options( $max_seeds, $duration_seconds, $timeout_ms, $max_input_bytes, $max_tokens, $max_nodes );

if ( is_file( $output_dir . '/STOP' ) ) {
	// A leftover stop request must not silently turn this launch into a
	// 0-seed success; starting again is an explicit operator decision.
	fwrite( STDERR, "Stop file already exists: {$output_dir}/STOP\nRemove it to start a run in this directory.\n" );
	exit( 1 );
}

\HtmlApiFuzz\ensure_dir( $output_dir );
$events_path = $output_dir . '/events.ndjson';
$state_path  = $output_dir . '/launcher-state.json';
$git_metadata = null === \HtmlApiFuzz\option_string( $options, 'git-metadata-base64', null )
	? \HtmlApiFuzz\git_metadata()
	: \HtmlApiFuzz\git_metadata_from_base64( \HtmlApiFuzz\option_string( $options, 'git-metadata-base64' ) );
$git_metadata_base64 = \HtmlApiFuzz\git_metadata_base64( $git_metadata );
$oracle_renderer      = \HtmlApiFuzz\OracleRenderer::from_options( $options );
$oracle_metadata      = $oracle_renderer->metadata();
$oracle_worker_args   = $oracle_renderer->worker_args();

$state = array(
	'schemaVersion' => 1,
	'kind'          => 'html-api-fuzz-launcher-state',
	'startedAt'     => gmdate( 'c' ),
	'updatedAt'     => gmdate( 'c' ),
	'outputDir'     => $output_dir,
	'lanes'         => $lanes,
	'startSeed'     => $start_seed,
	'seedStride'    => $lanes,
	'profile'       => $profile,
	'mode'          => $mode,
	'payloadPolicy' => $payload_policy,
	'maxInputBytes' => $max_input_bytes > 0 ? $max_input_bytes : null,
	'git'           => $git_metadata,
	'oracle'        => $oracle_metadata,
	'finished'      => false,
	'laneResults'   => array(),
);
\HtmlApiFuzz\write_json_file( $state_path, $state );
\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'launcher-start', 'outputDir' => $output_dir, 'lanes' => $lanes, 'git' => $git_metadata, 'oracle' => $oracle_metadata ) );

$running = array();
for ( $i = 0; $i < $lanes; ++$i ) {
	$lane_max_seeds = 0;
	if ( 0 !== $max_seeds ) {
		$lane_max_seeds = intdiv( $max_seeds, $lanes ) + ( $i < ( $max_seeds % $lanes ) ? 1 : 0 );
		if ( 0 === $lane_max_seeds ) {
			$lane_dir = $output_dir . '/lane-' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
			$state['laneResults'][ $i ] = array(
				'lane'      => $i,
				'status'    => 'skipped',
				'outputDir' => $lane_dir,
				'reason'    => 'no seeds assigned',
			);
			continue;
		}
	}

	$lane_dir = $output_dir . '/lane-' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
	$command  = array(
		PHP_BINARY,
		__DIR__ . '/runner.php',
		'--output-dir',
		$lane_dir,
		'--start-seed',
		(string) ( $start_seed + $i ),
		'--seed-stride',
		(string) $lanes,
		'--duration-seconds',
		(string) $duration_seconds,
		'--timeout-ms',
		(string) $timeout_ms,
		'--profile',
		$profile,
		'--mode',
		$mode,
		'--payload-policy',
		$payload_policy,
		'--max-tokens',
		(string) $max_tokens,
		'--max-nodes',
		(string) $max_nodes,
		'--git-metadata-base64',
		$git_metadata_base64,
		'--max-keep-per-signature',
		(string) $max_keep_per_signature,
		'--stop-file',
		$output_dir . '/STOP',
	);
	foreach ( $oracle_worker_args as $arg ) {
		$command[] = $arg;
	}

	if ( 0 !== $max_seeds ) {
		$command[] = '--max-seeds';
		$command[] = (string) $lane_max_seeds;
	}
	if ( $keep_all_artifacts ) {
		$command[] = '--keep-all-artifacts';
	}
	if ( $stop_on_failure ) {
		$command[] = '--stop-on-failure';
	}
	if ( $fail_unsupported ) {
		$command[] = '--fail-unsupported';
	}
	if ( $max_input_bytes > 0 ) {
		$command[] = '--max-input-bytes';
		$command[] = (string) $max_input_bytes;
	}

	$running[ $i ] = html_api_fuzz_launcher_start_lane( $command, $repo_root, $lane_dir . '/runner.stdout.log' );
	$state['laneResults'][ $i ] = array(
		'lane'      => $i,
		'status'    => 'running',
		'command'   => \HtmlApiFuzz\command_string( $command ),
		'outputDir' => $lane_dir,
		'logPath'   => $lane_dir . '/runner.stdout.log',
	);
	\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'lane-start', 'lane' => $i, 'outputDir' => $lane_dir, 'oracle' => $oracle_metadata ) );
}
\HtmlApiFuzz\write_json_file( $state_path, $state );

while ( $running ) {
	foreach ( array_keys( $running ) as $lane_id ) {
		html_api_fuzz_launcher_drain_lane( $running[ $lane_id ] );
		$status = proc_get_status( $running[ $lane_id ]['process'] );
		if ( $status['running'] ) {
			continue;
		}

		$closed = html_api_fuzz_launcher_close_lane( $running[ $lane_id ] );
		unset( $running[ $lane_id ] );
		$lane_dir = $state['laneResults'][ $lane_id ]['outputDir'];
		$runner_state = \HtmlApiFuzz\read_json_file( $lane_dir . '/state.json' );
		$state['laneResults'][ $lane_id ] = array_merge(
			$state['laneResults'][ $lane_id ],
			array(
				'status'      => 0 === $closed['code'] ? 'completed' : 'failed',
				'code'        => $closed['code'],
				'durationMs'  => $closed['durationMs'],
				'runnerState' => $runner_state,
				'stdoutTail'  => $closed['stdoutTail'],
				'stderrTail'  => $closed['stderrTail'],
			)
		);
		$state['updatedAt'] = gmdate( 'c' );
		\HtmlApiFuzz\write_json_file( $state_path, $state );
		\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'lane-stop', 'lane' => $lane_id, 'code' => $closed['code'] ) );
	}
	usleep( 100000 );
}

$aggregate = array(
	'successes'         => 0,
	'failures'          => 0,
	'unsupported'       => 0,
	'oracleParseErrors' => 0,
	'oracleUnsupported' => 0,
	'oracleTolerated'   => 0,
	'oracleFindings'    => 0,
);
foreach ( $state['laneResults'] as $lane ) {
	$runner_state = $lane['runnerState'] ?? array();
	foreach ( $aggregate as $name => $count ) {
		$aggregate[ $name ] += (int) ( $runner_state[ $name ] ?? 0 );
	}
}

$state['finished']  = true;
$state['updatedAt'] = gmdate( 'c' );
$state['aggregate'] = $aggregate;
\HtmlApiFuzz\write_json_file( $state_path, $state );
\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'launcher-stop', 'aggregate' => $aggregate ) );

$watcher_result = null;
if ( $run_watcher ) {
	$triage_dir = $output_dir . '/triage';
	$minimize_timeout_ms = \HtmlApiFuzz\option_int( $options, 'minimize-timeout-ms', 300000 );
	$watcher_timeout_ms  = \HtmlApiFuzz\option_int( $options, 'watcher-timeout-ms', max( 600000, $minimize_timeout_ms + 60000 ) );
	$proc = \HtmlApiFuzz\run_php_process(
		array_values(
			array_filter(
				array(
			__DIR__ . '/watcher.php',
			'--run-dir',
			$output_dir,
			'--state-dir',
			$triage_dir,
			'--once',
			'--minimize-timeout-ms',
			(string) $minimize_timeout_ms,
			'--timeout-ms',
			(string) $timeout_ms,
			array_key_exists( 'max-attempts', $options ) ? '--max-attempts' : null,
			array_key_exists( 'max-attempts', $options ) ? (string) \HtmlApiFuzz\option_int( $options, 'max-attempts', 250 ) : null,
			array_key_exists( 'max-minimize', $options ) ? '--max-minimize' : null,
			array_key_exists( 'max-minimize', $options ) ? (string) \HtmlApiFuzz\option_int( $options, 'max-minimize', 0 ) : null,
			\HtmlApiFuzz\option_bool( $options, 'no-minimize', false ) ? '--no-minimize' : null,
			\HtmlApiFuzz\option_bool( $options, 'any-failure', false ) ? '--any-failure' : null,
			$triage_oracle_findings ? '--triage-oracle-findings' : null,
				),
				static function ( $value ) {
					return null !== $value;
				}
			)
		),
		$repo_root,
		$watcher_timeout_ms,
		$triage_dir . '/watcher.log'
	);
	$watcher_result = array(
		'code'       => $proc['code'],
		'timedOut'   => $proc['timedOut'],
		'durationMs' => $proc['durationMs'],
		'logPath'    => $proc['logPath'],
	);
}

echo \HtmlApiFuzz\json_encode_safe(
	array(
		'ok'            => true,
		'outputDir'     => $output_dir,
		'statePath'     => $state_path,
		'aggregate'     => $aggregate,
		'watcherResult' => $watcher_result,
	)
) . "\n";
