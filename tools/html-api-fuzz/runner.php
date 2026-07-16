#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

function html_api_fuzz_runner_usage(): void {
	echo "Usage: php tools/html-api-fuzz/runner.php [--output-dir DIR] [--start-seed N] [--seed-stride N] [--max-seeds N] [--duration-seconds N] [--payload-policy POLICY] [--max-input-bytes N] [--dom-oracle php-dom|lexbor-source|html5ever-source|chrome-cdp] [--lexbor-oracle-bin PATH|--html5ever-oracle-bin PATH|--chrome-oracle-script PATH] [--chrome-executable PATH] [--node-bin PATH] [--chrome-startup-timeout-ms N] [--max-keep-per-signature N] [--keep-all-artifacts] [--stop-file PATH]\n";
	echo "Use --duration-seconds 0 with --max-seeds 0 for an indefinite run.\n";
	echo "Create the stop file (default OUTPUT_DIR/STOP) to stop gracefully: the current batch finishes and no new batch starts.\n";
	echo "Oracle findings are recorded separately from failures; pass --triage-oracle-findings to watcher.php to process them.\n";
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

function html_api_fuzz_runner_validate_runtime_options( int $seed_stride, int $max_seeds, float $duration_seconds, int $timeout_ms, int $max_input_bytes, int $max_tokens, int $max_nodes, int $max_keep_per_signature ): void {
	if ( $max_keep_per_signature < 1 ) {
		// The first exemplar of every signature must stay on disk: the
		// watcher's minimizer works from a replay file, not the store.
		throw new InvalidArgumentException( 'Expected --max-keep-per-signature to be at least 1.' );
	}
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
if ( array_key_exists( 'timeout-ms', $options ) && true === $options['timeout-ms'] ) {
	throw new InvalidArgumentException( 'Expected --timeout-ms to have a value.' );
}

$repo_root        = \HtmlApiFuzz\repo_root();
$output_dir       = \HtmlApiFuzz\option_string( $options, 'output-dir', $repo_root . '/artifacts/html-api-fuzz/run-' . \HtmlApiFuzz\timestamp() );
$start_seed       = \HtmlApiFuzz\option_int( $options, 'start-seed', 1 );
$seed_stride      = \HtmlApiFuzz\option_int( $options, 'seed-stride', 1 );
$max_seeds        = \HtmlApiFuzz\option_int( $options, 'max-seeds', 0 );
$duration_seconds = \HtmlApiFuzz\option_float( $options, 'duration-seconds', 60.0 );
$timeout_explicit = array_key_exists( 'timeout-ms', $options );
$timeout_ms       = \HtmlApiFuzz\option_int( $options, 'timeout-ms', 2500 );
$stop_on_failure  = \HtmlApiFuzz\option_bool( $options, 'stop-on-failure', false );
$profile          = \HtmlApiFuzz\option_string( $options, 'profile', 'auto' );
$mode             = \HtmlApiFuzz\option_string( $options, 'mode', 'auto' );
$payload_policy   = \HtmlApiFuzz\option_string( $options, 'payload-policy', 'auto' );
$max_input_bytes  = \HtmlApiFuzz\option_int( $options, 'max-input-bytes', 0 );
$corpus_percent   = \HtmlApiFuzz\option_int( $options, 'corpus-mutate-percent', 20 );
$batch_size       = max( 1, \HtmlApiFuzz\option_int( $options, 'batch-size', 25 ) );
$max_tokens       = \HtmlApiFuzz\option_int( $options, 'max-tokens', 2000 );
$max_nodes        = \HtmlApiFuzz\option_int( $options, 'max-nodes', 3000 );
$fail_unsupported = \HtmlApiFuzz\option_bool( $options, 'fail-unsupported', false );
$max_keep_per_signature = \HtmlApiFuzz\option_int( $options, 'max-keep-per-signature', 5 );
$keep_all_artifacts     = \HtmlApiFuzz\option_bool( $options, 'keep-all-artifacts', false );
$stop_file              = \HtmlApiFuzz\option_string( $options, 'stop-file', $output_dir . '/STOP' );
if ( array_key_exists( 'stop-file', $options ) && ( true === $options['stop-file'] || null === $stop_file || '' === $stop_file ) ) {
	fwrite( STDERR, "Expected --stop-file to be a non-empty path.\n" );
	exit( 1 );
}
html_api_fuzz_runner_validate_generator_options( $profile, $mode, $payload_policy );
html_api_fuzz_runner_validate_runtime_options( $seed_stride, $max_seeds, $duration_seconds, $timeout_ms, $max_input_bytes, $max_tokens, $max_nodes, $max_keep_per_signature );

if ( is_file( $stop_file ) ) {
	// A leftover stop request must not silently turn this run into a 0-seed
	// success; starting again is an explicit operator decision.
	fwrite( STDERR, "Stop file already exists: {$stop_file}\nRemove it (or pass a different --stop-file) to start this run.\n" );
	exit( 1 );
}

\HtmlApiFuzz\ensure_dir( $output_dir );
$result_store = new \HtmlApiFuzz\ResultStore( $output_dir . '/' . \HtmlApiFuzz\ResultStore::FILENAME );
$events_path  = $output_dir . '/events.ndjson';
$state_path   = $output_dir . '/state.json';
$runner_log   = $output_dir . '/runner.log';
$git_metadata = null === \HtmlApiFuzz\option_string( $options, 'git-metadata-base64', null )
	? \HtmlApiFuzz\git_metadata()
	: \HtmlApiFuzz\git_metadata_from_base64( \HtmlApiFuzz\option_string( $options, 'git-metadata-base64' ) );
$git_metadata_base64 = \HtmlApiFuzz\git_metadata_base64( $git_metadata );
$oracle_renderer = \HtmlApiFuzz\OracleRenderer::from_options( $options );
$oracle_setup = \HtmlApiFuzz\OracleRenderer::with_explicit_close(
	$oracle_renderer,
	static function ( \HtmlApiFuzz\OracleRenderer $renderer ) use ( $timeout_explicit, $timeout_ms ): array {
		return array(
			'metadata'   => $renderer->metadata(),
			'workerArgs' => $renderer->worker_args(),
			'timeoutMs'  => $timeout_explicit ? $timeout_ms : $renderer->recommended_process_timeout_ms( 'full', 2500 ),
		);
	}
);
$oracle_metadata    = $oracle_setup['metadata'];
$oracle_worker_args = $oracle_setup['workerArgs'];
$timeout_ms         = $oracle_setup['timeoutMs'];

$state = array(
	'schemaVersion' => 1,
	'kind'          => 'html-api-fuzz-runner-state',
	'startedAt'     => gmdate( 'c' ),
	'updatedAt'     => gmdate( 'c' ),
	'outputDir'     => $output_dir,
	'cwd'           => getcwd() ?: null,
	'startSeed'     => $start_seed,
	'seedStride'    => $seed_stride,
	'nextSeed'      => $start_seed,
	'profile'       => $profile,
	'mode'          => $mode,
	'payloadPolicy' => $payload_policy,
	'maxInputBytes' => $max_input_bytes > 0 ? $max_input_bytes : null,
	'git'           => $git_metadata,
	'oracle'        => $oracle_metadata,
	'processTimeoutMs' => $timeout_ms,
	'maxKeepPerSignature' => $max_keep_per_signature,
	'keepAllArtifacts'    => $keep_all_artifacts,
	'stopFile'            => $stop_file,
	// Longest legitimate silence between state writes: one full batch worker
	// run. The watcher floors its dead-runner presumption on this.
	'batchBudgetMs'       => $timeout_ms * $batch_size,
	'successes'         => 0,
	'failures'          => 0,
	'unsupported'       => 0,
	'oracleParseErrors' => 0,
	'oracleUnsupported' => 0,
	'oracleTolerated'   => 0,
	'oracleFindings'    => 0,
	'stopReason'        => null,
);
\HtmlApiFuzz\write_json_file( $state_path, $state );
\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'runner-start', 'outputDir' => $output_dir, 'git' => $git_metadata, 'oracle' => $oracle_metadata ) );
file_put_contents( $runner_log, '[' . gmdate( 'c' ) . "] runner started outputDir={$output_dir}\n", FILE_APPEND );

$has_deadline = $duration_seconds > 0;
$deadline     = $has_deadline ? microtime( true ) + $duration_seconds : null;
$seed         = $start_seed;
$count        = 0;

function html_api_fuzz_runner_worker_args( int $seed, string $output_dir, string $profile, string $mode, string $payload_policy, int $max_tokens, int $max_nodes, string $git_metadata_base64, bool $fail_unsupported, int $max_input_bytes, int $corpus_percent, int $batch_count, int $seed_stride, int $process_timeout_ms, array $oracle_worker_args ): array {
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
		$output_dir,
		'--max-tokens',
		(string) $max_tokens,
		'--max-nodes',
		(string) $max_nodes,
		'--git-metadata-base64',
		$git_metadata_base64,
		'--process-timeout-ms',
		(string) $process_timeout_ms,
	);
	if ( $batch_count > 1 ) {
		$args[] = '--batch-count';
		$args[] = (string) $batch_count;
		$args[] = '--seed-stride';
		$args[] = (string) $seed_stride;
	}
	if ( $fail_unsupported ) {
		$args[] = '--fail-unsupported';
	}
	if ( $max_input_bytes > 0 ) {
		$args[] = '--max-input-bytes';
		$args[] = (string) $max_input_bytes;
	}
	$args[] = '--corpus-mutate-percent';
	$args[] = (string) $corpus_percent;
	foreach ( $oracle_worker_args as $arg ) {
		$args[] = $arg;
	}
	return $args;
}

/**
 * A batch worker killed mid-write can leave truncated JSON behind; such a
 * file must behave like a missing one so the seed takes the isolation
 * fallback instead of fataling the lane.
 */
function html_api_fuzz_runner_read_json_or_null( string $path ) {
	try {
		return \HtmlApiFuzz\read_json_file( $path );
	} catch ( \RuntimeException $e ) {
		return null;
	}
}

$pending_batch  = array();
$batch_log      = null;
$batch_keep_log = false;

// Already-computed batch results are always processed; the deadline and seed
// budget gate only the formation of new batches.
while ( array() !== $pending_batch || ( ( ! $has_deadline || microtime( true ) < $deadline ) && ( 0 === $max_seeds || $count < $max_seeds ) ) ) {
	if ( array() === $pending_batch ) {
		/*
		 * Graceful stop: the already-computed batch above has fully drained
		 * and is recorded; honor a stop request (or a stop-on-failure from
		 * inside the batch) before committing to a new batch.
		 */
		if ( null !== $state['stopReason'] ) {
			break;
		}
		if ( is_file( $stop_file ) ) {
			$state['stopReason'] = 'stop-requested';
			\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'stop-requested', 'stopFile' => $stop_file ) );
			break;
		}

		/*
		 * Run a batch of seeds in one worker process: per-seed process spawns
		 * dominate wall-clock otherwise. Seeds missing a result.json after
		 * the batch (the batch process died or timed out mid-way) are re-run
		 * individually below.
		 */
		$batch_count = $batch_size;
		if ( 0 !== $max_seeds ) {
			$batch_count = min( $batch_count, $max_seeds - $count );
		}
		$batch_count = max( 1, $batch_count );
		$batch_seeds = array();
		for ( $i = 0; $i < $batch_count; $i++ ) {
			$batch_seeds[] = $seed + ( $i * $seed_stride );
		}

		// Batch logs live outside the prunable seed directories; clean
		// batches drop theirs once fully recorded (see end of loop).
		$batch_log      = $output_dir . '/logs/batch-' . $batch_seeds[0] . '.log';
		$batch_keep_log = false;
		\HtmlApiFuzz\ensure_dir( dirname( $batch_log ) );
		\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'batch-start', 'seeds' => $batch_seeds, 'logPath' => $batch_log ) );
		$batch_args = html_api_fuzz_runner_worker_args( $batch_seeds[0], $output_dir, $profile, $mode, $payload_policy, $max_tokens, $max_nodes, $git_metadata_base64, $fail_unsupported, $max_input_bytes, $corpus_percent, $batch_count, $seed_stride, $timeout_ms, $oracle_worker_args );
		$batch_proc = \HtmlApiFuzz\run_php_process( $batch_args, $repo_root, $timeout_ms * $batch_count, $batch_log );
		$pending_batch = $batch_seeds;
	}

	$current_seed = array_shift( $pending_batch );
	$attempt_dir  = $output_dir . '/seed-' . $current_seed . '/primary';
	\HtmlApiFuzz\ensure_dir( $attempt_dir );
	$log_path = $attempt_dir . '/worker.log';

	$result = html_api_fuzz_runner_read_json_or_null( $attempt_dir . '/result.json' );
	$proc   = $batch_proc;
	if ( null === $result ) {
		// Isolation fallback: re-run this seed in its own process.
		$batch_keep_log = true;
		$args = html_api_fuzz_runner_worker_args( $current_seed, $attempt_dir, $profile, $mode, $payload_policy, $max_tokens, $max_nodes, $git_metadata_base64, $fail_unsupported, $max_input_bytes, $corpus_percent, 1, $seed_stride, $timeout_ms, $oracle_worker_args );
		$proc   = \HtmlApiFuzz\run_php_process( $args, $repo_root, $timeout_ms, $log_path );
		$result = html_api_fuzz_runner_read_json_or_null( $attempt_dir . '/result.json' );
	}

	if ( null === $result ) {
		$replay = html_api_fuzz_runner_read_json_or_null( $attempt_dir . '/replay.json' );
		$result = array(
			'ok'             => false,
			'status'         => $proc['timedOut'] ? 'timeout' : 'worker-failed',
			'failureClass'   => $proc['timedOut'] ? 'timeout' : 'worker-failed',
			'failureSnippet' => substr( $proc['output'], -2000 ),
			'seed'           => $current_seed,
			'profile'        => is_array( $replay ) ? ( $replay['profile'] ?? $profile ) : $profile,
			'mode'           => is_array( $replay ) ? ( $replay['mode'] ?? $mode ) : $mode,
			'payloadPolicy'  => is_array( $replay ) ? ( $replay['payloadPolicy'] ?? $payload_policy ) : $payload_policy,
			'generator'      => is_array( $replay ) ? ( $replay['generator'] ?? null ) : null,
			'inputSource'    => is_array( $replay ) ? ( $replay['inputSource'] ?? null ) : null,
			'inputSha1'      => is_array( $replay ) ? ( $replay['inputSha1'] ?? null ) : null,
			'inputLength'    => is_array( $replay ) ? ( $replay['inputLength'] ?? null ) : null,
			'oracle'         => is_array( $replay ) ? ( $replay['oracle'] ?? $oracle_metadata ) : $oracle_metadata,
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

	$result['seed']    = $result['seed'] ?? $current_seed;
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

	$attempt_ok = (bool) ( $result['ok'] ?? false );
	$has_oracle_finding = is_array( $result['oracleFinding'] ?? null );

	/*
	 * Artifact retention: every attempt is regenerable from its seed, so seed
	 * directories are kept only for failures and oracle findings, and only
	 * until their signature has enough exemplars on disk. Everything else
	 * lives in the SQLite store (failures and oracle findings include their
	 * result and replay JSON there, so a pruned finding remains reproducible).
	 */
	$retain_artifacts = $keep_all_artifacts;
	$retain_failure_artifacts = $keep_all_artifacts && ! $attempt_ok;
	$retain_oracle_artifacts  = $keep_all_artifacts && $has_oracle_finding;
	$replay           = null;
	if ( ! $attempt_ok || $has_oracle_finding ) {
		$replay_path = $attempt_dir . '/replay.json';
		$replay      = html_api_fuzz_runner_read_json_or_null( $replay_path );

		if ( ! $keep_all_artifacts ) {
			$retention_targets = array();
			if ( ! $attempt_ok ) {
				$retention_targets[] = array(
					'hash' => $result['signature']['hash'] ?? null,
					'kind' => 'failure',
				);
			}
			if ( $has_oracle_finding ) {
				$retention_targets[] = array(
					'hash' => $result['oracleFinding']['signature']['hash'] ?? null,
					'kind' => 'oracle',
				);
			}

			if ( ! is_array( $replay ) ) {
				// Without a replay document the files are the only reproduction.
				$retain_failure_artifacts = ! $attempt_ok;
				$retain_oracle_artifacts  = $has_oracle_finding;
			} else {
				/*
				 * Count exemplar directories still on disk rather than rows
				 * ever written: a restarted runner re-records seeds, and row
				 * counting would saturate the cap without keeping anything.
				 */
				foreach ( $retention_targets as $target ) {
					$signature_hash = $target['hash'];
					if ( null === $signature_hash ) {
						if ( 'oracle' === $target['kind'] ) {
							$retain_oracle_artifacts = true;
						} else {
							$retain_failure_artifacts = true;
						}
						continue;
					}
					$retained_seeds = 'oracle' === $target['kind']
						? $result_store->oracle_retained_seeds( $signature_hash )
						: $result_store->retained_seeds( $signature_hash );
					$retained_on_disk = 0;
					foreach ( $retained_seeds as $retained_seed ) {
						if ( is_dir( $output_dir . '/seed-' . $retained_seed ) ) {
							++$retained_on_disk;
						}
					}
					if ( $retained_on_disk < $max_keep_per_signature ) {
						if ( 'oracle' === $target['kind'] ) {
							$retain_oracle_artifacts = true;
						} else {
							$retain_failure_artifacts = true;
						}
					}
				}
			}
			$retain_artifacts = $retain_failure_artifacts || $retain_oracle_artifacts;
		}

		if ( $retain_artifacts ) {
			// Over-cap repeats of a known signature do not justify keeping
			// their batch log; retained exemplars and re-runs do.
			$batch_keep_log = true;
		}

		if ( is_array( $replay ) ) {
			$replay['result'] = array(
				'ok'           => $result['ok'] ?? false,
				'status'       => $result['status'] ?? 'unknown',
				'failureClass' => $result['failureClass'] ?? null,
				'signature'    => $result['signature'] ?? null,
				'oracleFinding' => $result['oracleFinding'] ?? null,
				'oracle'       => $result['oracle'] ?? $replay['oracle'] ?? $oracle_metadata,
				'resultPath'   => $retain_artifacts ? $attempt_dir . '/result.json' : null,
			);
			$replay['oracle'] = $replay['oracle'] ?? $result['oracle'] ?? $oracle_metadata;
			$replay['signature'] = $result['signature'] ?? null;
			$replay['oracleFinding'] = $result['oracleFinding'] ?? null;
			if ( $retain_artifacts ) {
				\HtmlApiFuzz\write_json_file( $replay_path, $replay );
			}
		}
	}

	$summary = array(
		'kind'          => $attempt_ok ? ( $has_oracle_finding ? 'oracle-finding' : 'attempt' ) : 'failure',
		'ok'            => $attempt_ok,
		'status'        => $result['status'] ?? 'unknown',
		'failureClass'  => $result['failureClass'] ?? null,
		'seed'          => $current_seed,
		'profile'       => $result['profile'] ?? $profile,
		'mode'          => $result['mode'] ?? $mode,
		'payloadPolicy' => $result['payloadPolicy'] ?? $payload_policy,
		'generator'     => $result['generator'] ?? null,
		'inputSource'   => $result['inputSource'] ?? null,
		'inputSha1'     => $result['inputSha1'] ?? null,
		'inputLength'   => $result['inputLength'] ?? null,
		'signature'     => $result['signature'] ?? null,
		'oracleFinding' => $result['oracleFinding'] ?? null,
		'oracle'        => $result['oracle'] ?? $oracle_metadata,
		'artifactsRetained' => $retain_artifacts,
		'failureArtifactsRetained' => $retain_failure_artifacts,
		'oracleArtifactsRetained'  => $retain_oracle_artifacts,
		'resultPath'    => $retain_artifacts ? $attempt_dir . '/result.json' : null,
		'replayPath'    => $retain_artifacts ? $attempt_dir . '/replay.json' : null,
		// Batch-executed seeds have no per-seed worker.log; point at a log
		// that exists (isolation re-run log, else the shared batch log).
		'logPath'       => $retain_artifacts ? ( is_file( $log_path ) ? $log_path : $batch_log ) : null,
		'durationMs'    => $proc['durationMs'],
		'workerCode'    => $proc['code'],
		'workerTimedOut'=> $proc['timedOut'],
	);
	$attempt_id = $result_store->record_attempt(
		$summary,
		( $attempt_ok && ! $has_oracle_finding ) ? null : $result,
		( ( $attempt_ok && ! $has_oracle_finding ) || ! is_array( $replay ) ) ? null : $replay
	);
	if ( ! $retain_artifacts && is_array( $replay ) && ( ! $attempt_ok || $has_oracle_finding ) ) {
		// The stored copy outlives the pruned files; its replay command must
		// point at this exact row, not just at a seed that a later restart may
		// record again with different input.
		$replay['command'] = array(
			'program' => PHP_BINARY,
			'args'    => array(
				'tools/html-api-fuzz/replay.php',
				'--store',
				$output_dir . '/' . \HtmlApiFuzz\ResultStore::FILENAME,
				'--id',
				(string) $attempt_id,
			),
			'cwd'     => $repo_root,
		);
		$result_store->update_replay_for_attempt( $attempt_id, $replay );
	}
	if ( ! $retain_artifacts && ! $result_store->seed_artifacts_retained( $current_seed ) ) {
		// The retained check covers earlier rows: a re-run of a previously
		// retained seed must not delete the exemplar directory they cite.
		\HtmlApiFuzz\remove_dir_recursive( $output_dir . '/seed-' . $current_seed );
	}

	if ( $summary['ok'] ) {
		if ( $has_oracle_finding ) {
			++$state['oracleFindings'];
		}
		if ( 'unsupported' === $summary['status'] ) {
			++$state['unsupported'];
		} elseif ( 'oracle-parse-error' === $summary['status'] ) {
			// Inputs the selected oracle cannot parse receive no differential
			// coverage; track the loss per class so long runs surface how
			// much of the input space the oracle gives up on.
			++$state['oracleParseErrors'];
		} elseif ( 'oracle-unsupported' === $summary['status'] ) {
			++$state['oracleUnsupported'];
		} elseif ( 'oracle-tolerated' === $summary['status'] ) {
			++$state['oracleTolerated'];
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

	// A stop-on-failure stop reason is honored at the top of the loop, after
	// the rest of the batch has been recorded and pruned. Under
	// --keep-all-artifacts every recorded logPath must keep existing.
	if ( array() === $pending_batch && ! $batch_keep_log && ! $keep_all_artifacts && null !== $batch_log ) {
		@unlink( $batch_log );
	}
}

if ( null === $state['stopReason'] ) {
	$state['stopReason'] = ( 0 !== $max_seeds && $count >= $max_seeds ) ? 'max-seeds' : 'duration-elapsed';
}
$state['updatedAt'] = gmdate( 'c' );
\HtmlApiFuzz\write_json_file( $state_path, $state );
\HtmlApiFuzz\append_ndjson( $events_path, array( 'at' => gmdate( 'c' ), 'kind' => 'runner-stop', 'stopReason' => $state['stopReason'], 'nextSeed' => $seed ) );
$result_store->close();
echo \HtmlApiFuzz\json_encode_safe( $state ) . "\n";
