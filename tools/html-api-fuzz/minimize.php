#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

function html_api_fuzz_min_accepts_result( ?array $result, array $base, bool $any_failure ): bool {
	if ( null === $result ) {
		return false;
	}

	return 'oracle-finding' === $base['targetKind']
		? ( ( $result['oracleFinding']['signature']['hash'] ?? null ) === $base['targetHash'] )
		: ( $any_failure ? ! ( $result['ok'] ?? true ) : ( ( $result['signature']['hash'] ?? null ) === $base['targetHash'] ) );
}

function html_api_fuzz_min_worker_options( string $candidate, array $base, string $output_dir ): array {
	$options = array(
		'input-base64' => base64_encode( $candidate ),
		'mode'         => $base['mode'],
		'profile'      => $base['profile'],
		'seed'         => (string) $base['seed'],
		'output-dir'   => $output_dir,
		'max-tokens'   => (string) $base['maxTokens'],
		'max-nodes'    => (string) $base['maxNodes'],
	);
	if ( null !== $base['gitMetadataBase64'] ) {
		$options['git-metadata-base64'] = $base['gitMetadataBase64'];
	}
	if ( $base['failUnsupported'] ) {
		$options['fail-unsupported'] = true;
	}
	if ( $base['forcePrimaryOracle'] ) {
		$options['force-primary-oracle'] = true;
	}
	if ( null !== $base['payloadPolicy'] ) {
		$options['payload-policy'] = $base['payloadPolicy'];
	}
	if ( 'body' !== $base['fragmentContext'] ) {
		$options['fragment-context'] = $base['fragmentContext'];
	}
	foreach ( $base['oracleOptions'] as $name => $value ) {
		if ( null !== $value ) {
			$options[ $name ] = $value;
		}
	}

	return $options;
}

function html_api_fuzz_min_fatal_result( array $base, \Throwable $e, int $duration_ms ): array {
	$result = array(
		'schemaVersion'  => 1,
		'kind'           => 'html-api-fuzz-worker-result',
		'createdAt'      => gmdate( 'c' ),
		'ok'             => false,
		'status'         => 'worker-fatal',
		'failureClass'   => 'fatal-error',
		'failureSnippet' => $e->getMessage(),
		'throwable'      => get_class( $e ),
		'seed'           => $base['seed'],
		'profile'        => $base['profile'],
		'mode'           => $base['mode'],
		'payloadPolicy'  => $base['payloadPolicy'],
		'fragmentContext' => $base['fragmentContext'],
		'inputSource'    => 'minimize-candidate',
		'oracle'         => $base['oracle'],
		'process'        => array(
			'code'       => null,
			'timedOut'   => false,
			'durationMs' => $duration_ms,
		),
	);
	$signature = \HtmlApiFuzz\Signature::from_result( $result );
	if ( null !== $signature ) {
		$result['signature'] = $signature;
	}

	return $result;
}

function html_api_fuzz_min_process_test( string $candidate, array $base, string $work_dir, int $attempt, int $timeout_ms, bool $any_failure ): array {
	$dir = $work_dir . '/candidates/candidate-' . str_pad( (string) $attempt, 4, '0', STR_PAD_LEFT );
	\HtmlApiFuzz\ensure_dir( $dir );
	$input_path = $dir . '/input.bin';
	file_put_contents( $input_path, $candidate );
	$args = array(
		__DIR__ . '/worker.php',
		'--input-file',
		$input_path,
		'--mode',
		$base['mode'],
		'--profile',
		$base['profile'],
		'--seed',
		(string) $base['seed'],
		'--output-dir',
		$dir,
		'--max-tokens',
		(string) $base['maxTokens'],
		'--max-nodes',
		(string) $base['maxNodes'],
	);
	if ( null !== $base['gitMetadataBase64'] ) {
		$args[] = '--git-metadata-base64';
		$args[] = $base['gitMetadataBase64'];
	}
	if ( $base['failUnsupported'] ) {
		$args[] = '--fail-unsupported';
	}
	if ( $base['forcePrimaryOracle'] ) {
		$args[] = '--force-primary-oracle';
	}
	if ( null !== $base['payloadPolicy'] ) {
		$args[] = '--payload-policy';
		$args[] = $base['payloadPolicy'];
	}
	if ( 'body' !== $base['fragmentContext'] ) {
		$args[] = '--fragment-context';
		$args[] = $base['fragmentContext'];
	}
	foreach ( $base['oracleWorkerArgs'] as $arg ) {
		$args[] = $arg;
	}
	$proc   = \HtmlApiFuzz\run_php_process( $args, \HtmlApiFuzz\repo_root(), $timeout_ms, $dir . '/worker.log' );
	$result = \HtmlApiFuzz\read_json_file( $dir . '/result.json' );
	if ( null === $result ) {
		return array( 'accepted' => false, 'result' => null, 'process' => $proc );
	}

	$accepted = html_api_fuzz_min_accepts_result( $result, $base, $any_failure );
	return array( 'accepted' => $accepted, 'result' => $result, 'process' => $proc );
}

function html_api_fuzz_min_in_process_test( string $candidate, array $base, string $work_dir, int $attempt, bool $any_failure ): array {
	$started_at = microtime( true );

	try {
		if ( $base['keepCandidateArtifacts'] ) {
			$dir    = $work_dir . '/candidates/candidate-' . str_pad( (string) $attempt, 4, '0', STR_PAD_LEFT );
			$result = \HtmlApiFuzz\Worker::run( html_api_fuzz_min_worker_options( $candidate, $base, $dir ) );
		} else {
			$result = \HtmlApiFuzz\Worker::evaluate_input(
				$candidate,
				$base['seed'],
				$base['profile'],
				$base['mode'],
				$base['payloadPolicy'],
				$base['fragmentContext'],
				is_array( $base['originalGenerator'] ) ? $base['originalGenerator'] : null,
				'minimize-candidate',
				array(
					'maxTokens' => $base['maxTokens'],
					'maxNodes'  => $base['maxNodes'],
				),
				$base['failUnsupported'],
				$base['oracleRenderer'],
				$base['forcePrimaryOracle']
			);
		}
		$duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
	} catch ( \Throwable $e ) {
		$duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
		$result      = html_api_fuzz_min_fatal_result( $base, $e, $duration_ms );
	}

	return array(
		'accepted' => html_api_fuzz_min_accepts_result( $result, $base, $any_failure ),
		'result'   => $result,
		'process'  => array(
			'code'       => null,
			'timedOut'   => false,
			'durationMs' => $duration_ms,
			'mode'       => 'in-process',
		),
	);
}

function html_api_fuzz_min_test( string $candidate, array $base, string $work_dir, int $attempt, int $timeout_ms, bool $any_failure ): array {
	if ( 'process' === $base['probeMode'] ) {
		return html_api_fuzz_min_process_test( $candidate, $base, $work_dir, $attempt, $timeout_ms, $any_failure );
	}

	return html_api_fuzz_min_in_process_test( $candidate, $base, $work_dir, $attempt, $any_failure );
}

function html_api_fuzz_min_record_probe( array &$stats, array $test ): void {
	$duration_ms = $test['process']['durationMs'] ?? null;
	if ( ! is_numeric( $duration_ms ) ) {
		return;
	}

	$duration_ms = (int) $duration_ms;
	$stats['durationMs'] += $duration_ms;
	$stats['maxDurationMs'] = max( $stats['maxDurationMs'], $duration_ms );
	if ( $test['accepted'] ?? false ) {
		++$stats['accepted'];
	}
}

function html_api_fuzz_min_target( array $replay, array $options ): array {
	$target_hash = \HtmlApiFuzz\option_string( $options, 'target-hash', null );
	if ( null !== $target_hash ) {
		$target_kind = \HtmlApiFuzz\option_string( $options, 'target-kind', 'failure' );
		if ( ! in_array( $target_kind, array( 'failure', 'oracle-finding' ), true ) ) {
			throw new InvalidArgumentException( 'Expected --target-kind to be failure or oracle-finding.' );
		}
		return array(
			'kind' => $target_kind,
			'hash' => $target_hash,
		);
	}

	$failure_hash = $replay['signature']['hash'] ?? $replay['result']['signature']['hash'] ?? null;
	if ( is_string( $failure_hash ) && '' !== $failure_hash ) {
		return array(
			'kind' => 'failure',
			'hash' => $failure_hash,
		);
	}

	$oracle_hash = $replay['oracleFinding']['signature']['hash'] ?? $replay['result']['oracleFinding']['signature']['hash'] ?? null;
	if ( is_string( $oracle_hash ) && '' !== $oracle_hash ) {
		return array(
			'kind' => 'oracle-finding',
			'hash' => $oracle_hash,
		);
	}

	return array(
		'kind' => null,
		'hash' => null,
	);
}

function html_api_fuzz_min_probe_mode( array $options ): string {
	$mode = \HtmlApiFuzz\option_string( $options, 'probe-mode', 'auto' );
	if ( ! in_array( $mode, array( 'auto', 'in-process', 'process' ), true ) ) {
		throw new InvalidArgumentException( 'Expected --probe-mode to be auto, in-process, or process.' );
	}

	if ( 'auto' !== $mode ) {
		return $mode;
	}

	return 'process';
}

$options = \HtmlApiFuzz\parse_cli_options( $argv );
$replay_path = \HtmlApiFuzz\option_string( $options, 'replay', $options['_'][0] ?? null );
if ( null === $replay_path || \HtmlApiFuzz\option_bool( $options, 'help', false ) ) {
	echo "Usage: php tools/html-api-fuzz/minimize.php --replay path/to/replay.json [--output-dir DIR] [--target-kind failure|oracle-finding --target-hash HASH] [--dom-oracle ORACLE] [oracle options] [--probe-mode auto|in-process|process] [--keep-candidate-artifacts]\n";
	exit( null === $replay_path ? 1 : 0 );
}

$replay = \HtmlApiFuzz\read_json_file( $replay_path );
if ( ! $replay || ! array_key_exists( 'inputBase64', $replay ) ) {
	fwrite( STDERR, "Invalid replay file: {$replay_path}\n" );
	exit( 1 );
}

$target      = html_api_fuzz_min_target( $replay, $options );
$target_hash = $target['hash'];
$any_failure = \HtmlApiFuzz\option_bool( $options, 'any-failure', false );
if ( null === $target_hash && ! $any_failure ) {
	fwrite( STDERR, "Replay does not contain a target failure or oracle-finding signature. Use --any-failure to minimize any failure.\n" );
	exit( 1 );
}

$output_dir = \HtmlApiFuzz\option_string( $options, 'output-dir', dirname( $replay_path ) . '/minimized-' . \HtmlApiFuzz\timestamp() );
\HtmlApiFuzz\ensure_dir( $output_dir );

$input = base64_decode( $replay['inputBase64'], true );
if ( false === $input ) {
	fwrite( STDERR, "Invalid base64 input in replay file: {$replay_path}\n" );
	exit( 1 );
}
$original_generator = is_array( $replay['generator'] ?? null ) ? $replay['generator'] : ( $replay['originalGenerator'] ?? null );
$source_replay = \HtmlApiFuzz\replay_source_metadata( $replay_path, $replay );
$explicit_chrome_socket = array_key_exists( 'chrome-socket', $options );
if ( ! $explicit_chrome_socket ) {
	putenv( 'HTML_API_FUZZ_CHROME_SOCKET' );
}
$oracle_options = $options;
if ( null === \HtmlApiFuzz\option_string( $oracle_options, 'dom-oracle', null ) ) {
	$oracle_options['dom-oracle'] = $replay['options']['domOracle'] ?? $replay['oracle']['kind'] ?? \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE;
}
if ( null === \HtmlApiFuzz\option_string( $oracle_options, 'lexbor-oracle-bin', null ) && is_string( $replay['options']['lexborOracleBin'] ?? null ) ) {
	$oracle_options['lexbor-oracle-bin'] = $replay['options']['lexborOracleBin'];
}
$stored_oracle_options = array(
	'html5ever-oracle-bin' => 'html5everOracleBin',
	'chrome-oracle-script' => 'chromeOracleScript',
	'chrome-executable'    => 'chromeExecutable',
	'node-bin'             => 'nodeBin',
);
foreach ( $stored_oracle_options as $option_name => $replay_key ) {
	if ( null === \HtmlApiFuzz\option_string( $oracle_options, $option_name, null ) && is_string( $replay['options'][ $replay_key ] ?? null ) ) {
		$oracle_options[ $option_name ] = $replay['options'][ $replay_key ];
	}
}
$stored_oracle_timeout_ms = $replay['options']['oracleTimeoutMs'] ?? null;
if ( null === \HtmlApiFuzz\option_string( $oracle_options, 'oracle-timeout-ms', null ) && is_numeric( $stored_oracle_timeout_ms ) ) {
	$oracle_options['oracle-timeout-ms'] = (string) (int) $stored_oracle_timeout_ms;
}
$oracle_renderer = \HtmlApiFuzz\OracleRenderer::from_options( $oracle_options );
$oracle_renderer->start_run_service( $output_dir );
$oracle_renderer->assert_replay_compatible( is_array( $replay['oracle'] ?? null ) ? $replay['oracle'] : array() );
$probe_mode      = html_api_fuzz_min_probe_mode( $options );
$base = array(
	'mode'              => $replay['mode'] ?? \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	'profile'           => $replay['profile'] ?? 'replay',
	'payloadPolicy'     => \HtmlApiFuzz\normalize_payload_policy_label( $replay['payloadPolicy'] ?? null )
		?? \HtmlApiFuzz\normalize_payload_policy_label( $replay['generator']['payloadPolicy'] ?? null ),
	'fragmentContext'   => is_string( $replay['fragmentContext'] ?? null ) ? $replay['fragmentContext'] : 'body',
	'originalGenerator' => $original_generator,
	'seed'              => (int) ( $replay['seed'] ?? 1 ),
	'targetHash'        => $target_hash,
	'targetKind'        => $target['kind'] ?? 'failure',
	'sourceReplay'      => $source_replay,
	'oracle'            => $oracle_renderer->metadata(),
	'oracleRenderer'    => $oracle_renderer,
	'oracleOptions'     => array(
		'dom-oracle'            => \HtmlApiFuzz\option_string( $oracle_options, 'dom-oracle', \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE ),
		'lexbor-oracle-bin'     => \HtmlApiFuzz\option_string( $oracle_options, 'lexbor-oracle-bin', null ),
		'html5ever-oracle-bin'  => \HtmlApiFuzz\option_string( $oracle_options, 'html5ever-oracle-bin', null ),
		'chrome-oracle-script'  => \HtmlApiFuzz\option_string( $oracle_options, 'chrome-oracle-script', null ),
		'chrome-executable'     => \HtmlApiFuzz\option_string( $oracle_options, 'chrome-executable', null ),
		'chrome-socket'         => \HtmlApiFuzz\option_string( $oracle_options, 'chrome-socket', null ),
		'node-bin'              => \HtmlApiFuzz\option_string( $oracle_options, 'node-bin', null ),
		'oracle-timeout-ms'     => \HtmlApiFuzz\option_string( $oracle_options, 'oracle-timeout-ms', null ),
	),
	'oracleWorkerArgs'  => $oracle_renderer->worker_args(),
	'gitMetadataBase64' => \HtmlApiFuzz\git_metadata_base64( \HtmlApiFuzz\git_metadata() ),
	'failUnsupported'   => (bool) ( $replay['options']['failUnsupported'] ?? ( 'unsupported' === ( $replay['result']['failureClass'] ?? null ) ) ),
	'forcePrimaryOracle'=> (bool) ( $replay['options']['forcePrimaryOracle'] ?? false ),
	'maxTokens'         => (int) ( $replay['limits']['maxTokens'] ?? 2000 ),
	'maxNodes'          => (int) ( $replay['limits']['maxNodes'] ?? 3000 ),
	'probeMode'         => $probe_mode,
	'keepCandidateArtifacts' => \HtmlApiFuzz\option_bool( $options, 'keep-candidate-artifacts', false ),
);
$timeout_ms    = \HtmlApiFuzz\option_int( $options, 'timeout-ms', 2500 );
$max_attempts  = \HtmlApiFuzz\option_int( $options, 'max-attempts', 600 );
$attempt_count = 0;
$probe_stats   = array(
	'durationMs'    => 0,
	'maxDurationMs' => 0,
	'accepted'      => 0,
);

$current = $input;

/*
 * Phase 1: markup-aligned segment deletion. Splitting on tag boundaries is
 * token-naive (rawtext contents split incorrectly), but unsound candidates
 * simply fail the signature check; aligned deletions converge far faster on
 * HTML than blind byte chunks.
 */
$progress = true;
while ( $progress && $attempt_count < $max_attempts ) {
	$progress = false;
	preg_match_all( '/<[^>]*>?|[^<]+/s', $current, $matches );
	$segments = $matches[0];
	if ( count( $segments ) < 2 ) {
		break;
	}
	for ( $i = count( $segments ) - 1; $i >= 0 && $attempt_count < $max_attempts; $i-- ) {
		$candidate_segments = $segments;
		unset( $candidate_segments[ $i ] );
		$candidate = implode( '', $candidate_segments );
		if ( $candidate === $current || '' === $candidate ) {
			continue;
		}
		++$attempt_count;
		$test = html_api_fuzz_min_test( $candidate, $base, $output_dir, $attempt_count, $timeout_ms, $any_failure );
		html_api_fuzz_min_record_probe( $probe_stats, $test );
		if ( $test['accepted'] ) {
			$current  = $candidate;
			$progress = true;
			break;
		}
	}
}

// Phase 2: byte-chunk deletion for reductions that cross tag boundaries.
$chunks = 2;
while ( strlen( $current ) > 0 && $attempt_count < $max_attempts ) {
	$length     = strlen( $current );
	$chunk_size = (int) ceil( $length / $chunks );
	$changed    = false;

	for ( $offset = 0; $offset < $length && $attempt_count < $max_attempts; $offset += $chunk_size ) {
		$candidate = substr( $current, 0, $offset ) . substr( $current, min( $length, $offset + $chunk_size ) );
		if ( $candidate === $current ) {
			continue;
		}
		++$attempt_count;
		$test = html_api_fuzz_min_test( $candidate, $base, $output_dir, $attempt_count, $timeout_ms, $any_failure );
		html_api_fuzz_min_record_probe( $probe_stats, $test );
		if ( $test['accepted'] ) {
			$current = $candidate;
			$chunks  = max( 2, $chunks - 1 );
			$changed = true;
			break;
		}
	}

	if ( ! $changed ) {
		if ( $chunks >= $length ) {
			break;
		}
		$chunks = min( $length, $chunks * 2 );
	}
}

/*
 * Phase 3: per-byte canonicalization. Deletion is tried first; replacements
 * never grow the input. After a deletion the same index holds the next byte,
 * so stay in place; after a substitution move on.
 */
$simple_replacements = array( '', 'a', ' ', "\n" );
for ( $i = 0; $i < strlen( $current ) && $attempt_count < $max_attempts; ++$i ) {
	foreach ( $simple_replacements as $replacement ) {
		$candidate = substr( $current, 0, $i ) . $replacement . substr( $current, $i + 1 );
		if ( $candidate === $current ) {
			continue;
		}
		++$attempt_count;
		$test = html_api_fuzz_min_test( $candidate, $base, $output_dir, $attempt_count, $timeout_ms, $any_failure );
		html_api_fuzz_min_record_probe( $probe_stats, $test );
		if ( $test['accepted'] ) {
			$current = $candidate;
			if ( '' === $replacement ) {
				--$i;
			}
			break;
		}
	}
}

$final_dir = $output_dir . '/minimized';
\HtmlApiFuzz\ensure_dir( $final_dir );
$final_input_path = $final_dir . '/input.bin';
file_put_contents( $final_input_path, $current );
$args = array(
	__DIR__ . '/worker.php',
	'--input-file',
	$final_input_path,
	'--mode',
	$base['mode'],
	'--profile',
	$base['profile'],
	'--seed',
	(string) $base['seed'],
	'--output-dir',
	$final_dir,
	'--max-tokens',
	(string) $base['maxTokens'],
	'--max-nodes',
	(string) $base['maxNodes'],
);
if ( null !== $base['gitMetadataBase64'] ) {
	$args[] = '--git-metadata-base64';
	$args[] = $base['gitMetadataBase64'];
}
if ( $base['failUnsupported'] ) {
	$args[] = '--fail-unsupported';
}
if ( $base['forcePrimaryOracle'] ) {
	$args[] = '--force-primary-oracle';
}
if ( null !== $base['payloadPolicy'] ) {
	$args[] = '--payload-policy';
	$args[] = $base['payloadPolicy'];
}
if ( 'body' !== $base['fragmentContext'] ) {
	$args[] = '--fragment-context';
	$args[] = $base['fragmentContext'];
}
foreach ( $base['oracleWorkerArgs'] as $arg ) {
	$args[] = $arg;
}
\HtmlApiFuzz\run_php_process( $args, \HtmlApiFuzz\repo_root(), $timeout_ms, $final_dir . '/worker.log' );
$final_result = \HtmlApiFuzz\read_json_file( $final_dir . '/result.json' );
$final_replay = \HtmlApiFuzz\read_json_file( $final_dir . '/replay.json' );
if ( is_array( $final_replay ) && is_array( $base['originalGenerator'] ) ) {
	$final_replay['originalGenerator'] = $base['originalGenerator'];
}
if ( is_array( $final_replay ) ) {
	$final_replay['sourceReplay'] = $base['sourceReplay'];
	\HtmlApiFuzz\write_json_file( $final_dir . '/replay.json', $final_replay );
}

$summary = array(
	'schemaVersion'     => 1,
	'kind'              => 'html-api-fuzz-minimize-result',
	'createdAt'         => gmdate( 'c' ),
	'ok'                => null !== $final_result && ( 'oracle-finding' === $base['targetKind'] ? ( ( $final_result['oracleFinding']['signature']['hash'] ?? null ) === $target_hash ) : ( $any_failure ? ! ( $final_result['ok'] ?? true ) : ( ( $final_result['signature']['hash'] ?? null ) === $target_hash ) ) ),
	'targetHash'        => $target_hash,
	'targetKind'        => $base['targetKind'],
	'finalHash'         => $final_result['signature']['hash'] ?? null,
	'finalOracleHash'   => $final_result['oracleFinding']['signature']['hash'] ?? null,
	'profile'           => $base['profile'],
	'mode'              => $base['mode'],
	'payloadPolicy'     => $base['payloadPolicy'],
	'originalGenerator' => $base['originalGenerator'],
	'sourceReplay'      => $base['sourceReplay'],
	'oracle'            => \HtmlApiFuzz\OracleRenderer::replay_safe_metadata( is_array( $final_result['oracle'] ?? null ) ? $final_result['oracle'] : $base['oracle'] ),
	'finalFailureClass' => $final_result['failureClass'] ?? null,
	'finalStatus'       => $final_result['status'] ?? null,
	'originalLength'    => strlen( $input ),
	'minimizedLength'   => strlen( $current ),
	'attempts'          => $attempt_count,
	'probeMode'         => $base['probeMode'],
	'candidateArtifactsRetained' => 'process' === $base['probeMode'] || $base['keepCandidateArtifacts'],
	'probeTiming'       => array(
		'totalDurationMs' => $probe_stats['durationMs'],
		'maxDurationMs'   => $probe_stats['maxDurationMs'],
		'acceptedProbes'  => $probe_stats['accepted'],
		'averageDurationMs' => $attempt_count > 0 ? round( $probe_stats['durationMs'] / $attempt_count, 2 ) : null,
	),
	'minimizedReplay'   => $final_dir . '/replay.json',
	'minimizedResult'   => $final_dir . '/result.json',
	'inputBase64'       => base64_encode( $current ),
	'phpunitSnippet'    => '$html = base64_decode( ' . var_export( base64_encode( $current ), true ) . ' );',
);
\HtmlApiFuzz\write_json_file( $output_dir . '/minimize-result.json', $summary );
echo \HtmlApiFuzz\json_encode_safe( $summary ) . "\n";
exit( $summary['ok'] ? 0 : 1 );
