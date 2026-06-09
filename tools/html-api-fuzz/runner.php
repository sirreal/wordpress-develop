#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

function html_api_fuzz_runner_usage(): void {
	echo "Usage: php tools/html-api-fuzz/runner.php [--output-dir DIR] [--start-seed N] [--seed-stride N] [--max-seeds N] [--duration-seconds N] [--payload-policy POLICY] [--max-input-bytes N]\n";
	echo "Use --duration-seconds 0 with --max-seeds 0 for an indefinite run.\n";
}

function html_api_fuzz_runner_validate_generator_options( string $profile, string $mode, string $payload_policy ): void {
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

function html_api_fuzz_runner_validate_runtime_options( int $seed_stride, int $max_seeds, float $duration_seconds, int $timeout_ms, int $max_input_bytes, int $max_tokens, int $max_nodes ): void {
	if ( $seed_stride < 1 ) {
		throw new InvalidArgumentException( 'Expected --seed-stride to be at least 1.' );
	}
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
$payload_policy   = \HtmlApiFuzz\option_string( $options, 'payload-policy', 'auto' );
$max_input_bytes  = \HtmlApiFuzz\option_int( $options, 'max-input-bytes', 0 );
$max_tokens       = \HtmlApiFuzz\option_int( $options, 'max-tokens', 2000 );
$max_nodes        = \HtmlApiFuzz\option_int( $options, 'max-nodes', 3000 );
$fail_unsupported = \HtmlApiFuzz\option_bool( $options, 'fail-unsupported', false );
html_api_fuzz_runner_validate_generator_options( $profile, $mode, $payload_policy );
html_api_fuzz_runner_validate_runtime_options( $seed_stride, $max_seeds, $duration_seconds, $timeout_ms, $max_input_bytes, $max_tokens, $max_nodes );

\HtmlApiFuzz\ensure_dir( $output_dir );
$summary_path = $output_dir . '/summary.ndjson';
$events_path  = $output_dir . '/events.ndjson';
$state_path   = $output_dir . '/state.json';
$runner_log   = $output_dir . '/runner.log';
$git_metadata = null === \HtmlApiFuzz\option_string( $options, 'git-metadata-base64', null )
	? \HtmlApiFuzz\git_metadata()
	: \HtmlApiFuzz\git_metadata_from_base64( \HtmlApiFuzz\option_string( $options, 'git-metadata-base64' ) );
$git_metadata_base64 = \HtmlApiFuzz\git_metadata_base64( $git_metadata );

$state = array(
	'schemaVersion' => 1,
	'kind'          => 'html-api-fuzz-runner-state',
	'startedAt'     => gmdate( 'c' ),
	'updatedAt'     => gmdate( 'c' ),
	'outputDir'     => $output_dir,
	'startSeed'     => $start_seed,
	'seedStride'    => $seed_stride,
	'nextSeed'      => $start_seed,
	'profile'       => $profile,
	'mode'          => $mode,
	'payloadPolicy' => $payload_policy,
	'maxInputBytes' => $max_input_bytes > 0 ? $max_input_bytes : null,
	'git'           => $git_metadata,
	'successes'     => 0,
	'failures'      => 0,
	'unsupported'   => 0,
	'oracleErrors'  => 0,
	'stopReason'    => null,
);
\HtmlApiFuzz\write_json_file( $state_path, $state );
\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'runner-start', 'outputDir' => $output_dir, 'git' => $git_metadata ) );
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
		'--payload-policy',
		$payload_policy,
		'--output-dir',
		$attempt_dir,
		'--max-tokens',
		(string) $max_tokens,
		'--max-nodes',
		(string) $max_nodes,
		'--git-metadata-base64',
		$git_metadata_base64,
	);
	if ( $fail_unsupported ) {
		$args[] = '--fail-unsupported';
	}
	if ( $max_input_bytes > 0 ) {
		$args[] = '--max-input-bytes';
		$args[] = (string) $max_input_bytes;
	}

	\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'seed-start', 'seed' => $seed, 'attemptDir' => $attempt_dir ) );
	$proc   = \HtmlApiFuzz\run_php_process( $args, $repo_root, $timeout_ms, $log_path );
	$result = \HtmlApiFuzz\read_json_file( $attempt_dir . '/result.json' );

	if ( null === $result ) {
		$replay = \HtmlApiFuzz\read_json_file( $attempt_dir . '/replay.json' );
		$result = array(
			'ok'             => false,
			'status'         => $proc['timedOut'] ? 'timeout' : 'worker-failed',
			'failureClass'   => $proc['timedOut'] ? 'timeout' : 'worker-failed',
			'failureSnippet' => substr( $proc['output'], -2000 ),
			'seed'           => $seed,
			'profile'        => is_array( $replay ) ? ( $replay['profile'] ?? $profile ) : $profile,
			'mode'           => is_array( $replay ) ? ( $replay['mode'] ?? $mode ) : $mode,
			'payloadPolicy'  => is_array( $replay ) ? ( $replay['payloadPolicy'] ?? $payload_policy ) : $payload_policy,
			'generator'      => is_array( $replay ) ? ( $replay['generator'] ?? null ) : null,
			'inputSource'    => is_array( $replay ) ? ( $replay['inputSource'] ?? null ) : null,
			'inputSha1'      => is_array( $replay ) ? ( $replay['inputSha1'] ?? null ) : null,
			'inputLength'    => is_array( $replay ) ? ( $replay['inputLength'] ?? null ) : null,
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
	$result['payloadPolicy'] = $result['payloadPolicy'] ?? $payload_policy;
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
		'payloadPolicy' => $result['payloadPolicy'] ?? $payload_policy,
		'generator'     => $result['generator'] ?? null,
		'inputSource'   => $result['inputSource'] ?? null,
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
		} elseif ( in_array( $summary['status'], array( 'oracle-parse-error', 'oracle-unsupported', 'oracle-tolerated' ), true ) ) {
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
