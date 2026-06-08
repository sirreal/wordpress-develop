#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

function html_api_fuzz_runner_usage(): void {
	echo "Usage: php tools/html-api-fuzz/runner.php [--output-dir DIR] [--start-seed N] [--seed-stride N] [--max-seeds N] [--duration-seconds N]\n";
	echo "Use --duration-seconds 0 with --max-seeds 0 for an indefinite run.\n";
}

$options = \HtmlApiFuzz\parse_cli_options( $argv );
if ( \HtmlApiFuzz\option_bool( $options, 'help', false ) || \HtmlApiFuzz\option_bool( $options, 'h', false ) ) {
	html_api_fuzz_runner_usage();
	exit( 0 );
}

$repo_root        = \HtmlApiFuzz\repo_root();
$output_dir       = \HtmlApiFuzz\option_string( $options, 'output-dir', $repo_root . '/artifacts/html-api-fuzz/run-' . \HtmlApiFuzz\timestamp() );
$start_seed       = \HtmlApiFuzz\option_int( $options, 'start-seed', 1 );
$seed_stride      = \HtmlApiFuzz\option_int( $options, 'seed-stride', 1 );
$max_seeds        = \HtmlApiFuzz\option_int( $options, 'max-seeds', 0 );
$duration_seconds = \HtmlApiFuzz\option_float( $options, 'duration-seconds', 60.0 );
$timeout_ms       = \HtmlApiFuzz\option_int( $options, 'timeout-ms', 2500 );
$stop_on_failure  = \HtmlApiFuzz\option_bool( $options, 'stop-on-failure', false );
$profile          = \HtmlApiFuzz\option_string( $options, 'profile', 'auto' );
$mode             = \HtmlApiFuzz\option_string( $options, 'mode', 'auto' );
$fail_unsupported = \HtmlApiFuzz\option_bool( $options, 'fail-unsupported', false );

\HtmlApiFuzz\ensure_dir( $output_dir );
$summary_path = $output_dir . '/summary.ndjson';
$events_path  = $output_dir . '/events.ndjson';
$state_path   = $output_dir . '/state.json';
$runner_log   = $output_dir . '/runner.log';

$state = array(
	'schemaVersion' => 1,
	'kind'          => 'html-api-fuzz-runner-state',
	'startedAt'     => gmdate( 'c' ),
	'updatedAt'     => gmdate( 'c' ),
	'outputDir'     => $output_dir,
	'startSeed'     => $start_seed,
	'seedStride'    => $seed_stride,
	'nextSeed'      => $start_seed,
	'successes'     => 0,
	'failures'      => 0,
	'unsupported'   => 0,
	'oracleErrors'  => 0,
	'stopReason'    => null,
);
\HtmlApiFuzz\write_json_file( $state_path, $state );
\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'runner-start', 'outputDir' => $output_dir ) );
file_put_contents( $runner_log, '[' . gmdate( 'c' ) . "] runner started outputDir={$output_dir}\n", FILE_APPEND );

$has_deadline = $duration_seconds > 0;
$deadline     = $has_deadline ? microtime( true ) + $duration_seconds : null;
$seed         = $start_seed;
$count        = 0;

while ( ( ! $has_deadline || microtime( true ) < $deadline ) && ( 0 === $max_seeds || $count < $max_seeds ) ) {
	$attempt_dir = $output_dir . '/seed-' . $seed . '/primary';
	\HtmlApiFuzz\ensure_dir( $attempt_dir );
	$log_path = $attempt_dir . '/worker.log';

	$args = array(
		__DIR__ . '/worker.php',
		'--seed',
		(string) $seed,
		'--profile',
		$profile,
		'--mode',
		$mode,
		'--output-dir',
		$attempt_dir,
		'--max-tokens',
		(string) \HtmlApiFuzz\option_int( $options, 'max-tokens', 2000 ),
		'--max-nodes',
		(string) \HtmlApiFuzz\option_int( $options, 'max-nodes', 3000 ),
	);
	if ( $fail_unsupported ) {
		$args[] = '--fail-unsupported';
	}

	\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'seed-start', 'seed' => $seed, 'attemptDir' => $attempt_dir ) );
	$proc   = \HtmlApiFuzz\run_php_process( $args, $repo_root, $timeout_ms, $log_path );
	$result = \HtmlApiFuzz\read_json_file( $attempt_dir . '/result.json' );

	if ( null === $result ) {
		$result = array(
			'ok'             => false,
			'status'         => $proc['timedOut'] ? 'timeout' : 'worker-failed',
			'failureClass'   => $proc['timedOut'] ? 'timeout' : 'worker-failed',
			'failureSnippet' => substr( $proc['output'], -2000 ),
			'seed'           => $seed,
			'profile'        => $profile,
			'mode'           => $mode,
			'paths'          => array(
				'outputDir'  => $attempt_dir,
				'resultPath' => $attempt_dir . '/result.json',
				'replayPath' => $attempt_dir . '/replay.json',
			),
		);
		$signature = \HtmlApiFuzz\Signature::from_result( $result );
		if ( null !== $signature ) {
			$result['signature'] = $signature;
		}
		\HtmlApiFuzz\write_json_file( $attempt_dir . '/result.json', $result );
	}

	$result['seed']    = $result['seed'] ?? $seed;
	$result['profile'] = $result['profile'] ?? $profile;
	$result['mode']    = $result['mode'] ?? $mode;
	$result['paths']   = $result['paths'] ?? array(
		'outputDir'  => $attempt_dir,
		'resultPath' => $attempt_dir . '/result.json',
		'replayPath' => $attempt_dir . '/replay.json',
	);
	if ( ! ( $result['ok'] ?? false ) && empty( $result['signature'] ) ) {
		$signature = \HtmlApiFuzz\Signature::from_result( $result );
		if ( null !== $signature ) {
			$result['signature'] = $signature;
		}
		\HtmlApiFuzz\write_json_file( $attempt_dir . '/result.json', $result );
	}

	if ( ! ( $result['ok'] ?? false ) ) {
		$replay_path = $attempt_dir . '/replay.json';
		$replay = \HtmlApiFuzz\read_json_file( $replay_path );
		if ( is_array( $replay ) ) {
			$replay['result'] = array(
				'ok'           => $result['ok'] ?? false,
				'status'       => $result['status'] ?? 'unknown',
				'failureClass' => $result['failureClass'] ?? null,
				'signature'    => $result['signature'] ?? null,
				'resultPath'   => $attempt_dir . '/result.json',
			);
			$replay['signature'] = $result['signature'] ?? null;
			\HtmlApiFuzz\write_json_file( $replay_path, $replay );
		}
	}

	$summary = array(
		'kind'          => ( $result['ok'] ?? false ) ? 'attempt' : 'failure',
		'ok'            => $result['ok'] ?? false,
		'status'        => $result['status'] ?? 'unknown',
		'failureClass'  => $result['failureClass'] ?? null,
		'seed'          => $seed,
		'profile'       => $result['profile'] ?? $profile,
		'mode'          => $result['mode'] ?? $mode,
		'inputSha1'     => $result['inputSha1'] ?? null,
		'inputLength'   => $result['inputLength'] ?? null,
		'signature'     => $result['signature'] ?? null,
		'resultPath'    => $attempt_dir . '/result.json',
		'replayPath'    => $attempt_dir . '/replay.json',
		'logPath'       => $log_path,
		'durationMs'    => $proc['durationMs'],
		'workerCode'    => $proc['code'],
		'workerTimedOut'=> $proc['timedOut'],
	);
	\HtmlApiFuzz\append_ndjson( $summary_path, $summary );
	\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'seed-complete', 'seed' => $seed, 'ok' => $summary['ok'], 'status' => $summary['status'], 'signature' => $summary['signature']['hash'] ?? null ) );

	if ( $summary['ok'] ) {
		if ( 'unsupported' === $summary['status'] ) {
			++$state['unsupported'];
		} elseif ( 'oracle-parse-error' === $summary['status'] ) {
			++$state['oracleErrors'];
		} else {
			++$state['successes'];
		}
	} else {
		++$state['failures'];
		if ( $stop_on_failure ) {
			$state['stopReason'] = 'stop-on-failure';
		}
	}

	$seed += $seed_stride;
	++$count;
	$state['nextSeed']  = $seed;
	$state['updatedAt'] = gmdate( 'c' );
	\HtmlApiFuzz\write_json_file( $state_path, $state );

	if ( 'stop-on-failure' === $state['stopReason'] ) {
		break;
	}
}

if ( null === $state['stopReason'] ) {
	$state['stopReason'] = ( 0 !== $max_seeds && $count >= $max_seeds ) ? 'max-seeds' : 'duration-elapsed';
}
$state['updatedAt'] = gmdate( 'c' );
\HtmlApiFuzz\write_json_file( $state_path, $state );
\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'runner-stop', 'stopReason' => $state['stopReason'], 'nextSeed' => $seed ) );
echo \HtmlApiFuzz\json_encode_safe( $state ) . "\n";
