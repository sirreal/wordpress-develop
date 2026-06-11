#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

function html_api_fuzz_min_test( string $candidate, array $base, string $work_dir, int $attempt, int $timeout_ms, bool $any_failure ): array {
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
	if ( null !== $base['payloadPolicy'] ) {
		$args[] = '--payload-policy';
		$args[] = $base['payloadPolicy'];
	}
	if ( 'body' !== $base['fragmentContext'] ) {
		$args[] = '--fragment-context';
		$args[] = $base['fragmentContext'];
	}
	$proc   = \HtmlApiFuzz\run_php_process( $args, \HtmlApiFuzz\repo_root(), $timeout_ms, $dir . '/worker.log' );
	$result = \HtmlApiFuzz\read_json_file( $dir . '/result.json' );
	if ( null === $result ) {
		return array( 'accepted' => false, 'result' => null, 'process' => $proc );
	}

	$accepted = 'oracle-finding' === $base['targetKind']
		? ( ( $result['oracleFinding']['signature']['hash'] ?? null ) === $base['targetHash'] )
		: ( $any_failure ? ! ( $result['ok'] ?? true ) : ( ( $result['signature']['hash'] ?? null ) === $base['targetHash'] ) );
	return array( 'accepted' => $accepted, 'result' => $result, 'process' => $proc );
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

$options = \HtmlApiFuzz\parse_cli_options( $argv );
$replay_path = \HtmlApiFuzz\option_string( $options, 'replay', $options['_'][0] ?? null );
if ( null === $replay_path || \HtmlApiFuzz\option_bool( $options, 'help', false ) ) {
	echo "Usage: php tools/html-api-fuzz/minimize.php --replay path/to/replay.json [--output-dir DIR] [--target-kind failure|oracle-finding --target-hash HASH]\n";
	exit( null === $replay_path ? 1 : 0 );
}

$replay = \HtmlApiFuzz\read_json_file( $replay_path );
if ( ! $replay || ! array_key_exists( 'inputBase64', $replay ) ) {
	fwrite( STDERR, "Invalid replay file: {$replay_path}\n" );
	exit( 1 );
}

$target      = html_api_fuzz_min_target( $replay, $options );
$target_hash = $target['hash'];
if ( null === $target_hash && ! \HtmlApiFuzz\option_bool( $options, 'any-failure', false ) ) {
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
	'gitMetadataBase64' => \HtmlApiFuzz\git_metadata_base64( \HtmlApiFuzz\git_metadata() ),
	'failUnsupported'   => (bool) ( $replay['options']['failUnsupported'] ?? ( 'unsupported' === ( $replay['result']['failureClass'] ?? null ) ) ),
	'maxTokens'         => (int) ( $replay['limits']['maxTokens'] ?? 2000 ),
	'maxNodes'          => (int) ( $replay['limits']['maxNodes'] ?? 3000 ),
);
$timeout_ms    = \HtmlApiFuzz\option_int( $options, 'timeout-ms', 2500 );
$max_attempts  = \HtmlApiFuzz\option_int( $options, 'max-attempts', 600 );
$any_failure   = \HtmlApiFuzz\option_bool( $options, 'any-failure', false );
$attempt_count = 0;

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
if ( null !== $base['payloadPolicy'] ) {
	$args[] = '--payload-policy';
	$args[] = $base['payloadPolicy'];
}
if ( 'body' !== $base['fragmentContext'] ) {
	$args[] = '--fragment-context';
	$args[] = $base['fragmentContext'];
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
	'finalFailureClass' => $final_result['failureClass'] ?? null,
	'finalStatus'       => $final_result['status'] ?? null,
	'originalLength'    => strlen( $input ),
	'minimizedLength'   => strlen( $current ),
	'attempts'          => $attempt_count,
	'minimizedReplay'   => $final_dir . '/replay.json',
	'minimizedResult'   => $final_dir . '/result.json',
	'inputBase64'       => base64_encode( $current ),
	'phpunitSnippet'    => '$html = base64_decode( ' . var_export( base64_encode( $current ), true ) . ' );',
);
\HtmlApiFuzz\write_json_file( $output_dir . '/minimize-result.json', $summary );
echo \HtmlApiFuzz\json_encode_safe( $summary ) . "\n";
exit( $summary['ok'] ? 0 : 1 );
