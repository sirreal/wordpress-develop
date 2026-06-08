#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

function html_api_fuzz_watcher_usage(): void {
	echo "Usage: php tools/html-api-fuzz/watcher.php --run-dir DIR [--state-dir DIR] [--once]\n";
}

function html_api_fuzz_watcher_load_state( string $path ): array {
	$state = \HtmlApiFuzz\read_json_file( $path );
	if ( is_array( $state ) ) {
		return $state;
	}

	return array(
		'schemaVersion' => 1,
		'kind'          => 'html-api-fuzz-triage-state',
		'createdAt'     => gmdate( 'c' ),
		'updatedAt'     => gmdate( 'c' ),
		'signatures'    => array(),
		'seenAttempts'  => array(),
		'summaryOffsets'=> array(),
	);
}

function html_api_fuzz_watcher_signature_dir( string $state_dir, string $hash ): string {
	return $state_dir . '/signatures/' . preg_replace( '/[^a-zA-Z0-9._-]+/', '_', $hash );
}

function html_api_fuzz_watcher_increment_count( array &$counts, ?string $key ): void {
	if ( null === $key || '' === $key ) {
		return;
	}

	$counts[ $key ] = (int) ( $counts[ $key ] ?? 0 ) + 1;
}

function html_api_fuzz_watcher_status_markdown( array $state ): string {
	$lines = array(
		'# HTML API Fuzz Triage',
		'',
		'- Updated: ' . ( $state['updatedAt'] ?? gmdate( 'c' ) ),
		'- Signatures: ' . count( $state['signatures'] ?? array() ),
		'',
	);

	foreach ( $state['signatures'] ?? array() as $hash => $record ) {
		$lines[] = '## ' . $hash;
		$lines[] = '';
		$lines[] = '- Class: ' . ( $record['failureClass'] ?? 'unknown' );
		$lines[] = '- Status: ' . ( $record['status'] ?? 'unknown' );
		$lines[] = '- First seen: ' . ( $record['firstSeenAt'] ?? 'unknown' );
		$lines[] = '- Last seen: ' . ( $record['lastSeenAt'] ?? 'unknown' );
		$lines[] = '- Seen count: ' . ( $record['seenCount'] ?? 0 );
		if ( ! empty( $record['replayPath'] ) ) {
			$lines[] = '- Replay: ' . $record['replayPath'];
		}
		if ( ! empty( $record['minimizeResult'] ) ) {
			$lines[] = '- Minimized: ' . $record['minimizeResult'];
		}
		$lines[] = '';
	}

	return implode( "\n", $lines ) . "\n";
}

function html_api_fuzz_watcher_record_failure( array $summary, string $state_dir, array &$state ): bool {
	$hash = $summary['signature']['hash'] ?? null;
	if ( null === $hash ) {
		return false;
	}

	$now = gmdate( 'c' );
	$new = ! isset( $state['signatures'][ $hash ] );
	if ( $new ) {
		$state['signatures'][ $hash ] = array(
			'hash'         => $hash,
			'status'       => 'new',
			'failureClass' => $summary['failureClass'] ?? 'unknown',
			'firstSeenAt'  => $now,
			'lastSeenAt'   => $now,
			'seenCount'    => 0,
			'replayPath'   => $summary['replayPath'] ?? null,
			'resultPath'   => $summary['resultPath'] ?? null,
			'profileCounts'       => array(),
			'payloadPolicyCounts' => array(),
			'featureCounts'       => array(),
			'examples'     => array(),
		);
	}

	$record = &$state['signatures'][ $hash ];
	++$record['seenCount'];
	$record['lastSeenAt'] = $now;
	html_api_fuzz_watcher_increment_count( $record['profileCounts'], $summary['profile'] ?? null );
	html_api_fuzz_watcher_increment_count( $record['payloadPolicyCounts'], $summary['payloadPolicy'] ?? null );
	foreach ( $summary['generator']['features'] ?? array() as $feature ) {
		html_api_fuzz_watcher_increment_count( $record['featureCounts'], is_string( $feature ) ? $feature : null );
	}
	if ( empty( $record['replayPath'] ) && ! empty( $summary['replayPath'] ) ) {
		$record['replayPath'] = $summary['replayPath'];
	}
	if ( count( $record['examples'] ) < 8 ) {
		$record['examples'][] = array(
			'seed'          => $summary['seed'] ?? null,
			'profile'       => $summary['profile'] ?? null,
			'mode'          => $summary['mode'] ?? null,
			'payloadPolicy' => $summary['payloadPolicy'] ?? null,
			'inputSource'   => $summary['inputSource'] ?? null,
			'features'      => $summary['generator']['features'] ?? array(),
			'inputSha1'     => $summary['inputSha1'] ?? null,
			'resultPath'    => $summary['resultPath'] ?? null,
			'replayPath'    => $summary['replayPath'] ?? null,
			'logPath'       => $summary['logPath'] ?? null,
			'seenAt'        => $now,
		);
	}
	unset( $record );

	$signature_dir = html_api_fuzz_watcher_signature_dir( $state_dir, $hash );
	\HtmlApiFuzz\ensure_dir( $signature_dir );
	\HtmlApiFuzz\write_json_file( $signature_dir . '/failure.json', $state['signatures'][ $hash ] );

	return $new;
}

function html_api_fuzz_watcher_summary_paths( string $run_dir ): array {
	$paths = array();
	$direct_summary = rtrim( $run_dir, DIRECTORY_SEPARATOR ) . '/summary.ndjson';
	if ( is_file( $direct_summary ) ) {
		$paths[] = $direct_summary;
	}

	$items = @scandir( $run_dir );
	if ( false === $items ) {
		return $paths;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item || in_array( $item, array( '.git', '.triage-watcher', 'triage' ), true ) ) {
			continue;
		}

		$summary_path = rtrim( $run_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $item . '/summary.ndjson';
		if ( is_file( $summary_path ) ) {
			$paths[] = $summary_path;
		}
	}

	sort( $paths );
	return array_values( array_unique( $paths ) );
}

function html_api_fuzz_watcher_read_summary_records( string $summary_path, int $offset ): array {
	$records = array();
	$line_no = 0;
	$handle = @fopen( $summary_path, 'rb' );
	if ( false === $handle ) {
		return array(
			'records' => $records,
			'offset'  => $offset,
		);
	}

	$size = filesize( $summary_path );
	if ( false === $size ) {
		$size = 0;
	}
	if ( $offset < 0 || $offset > $size ) {
		$offset = 0;
	}
	if ( $offset > 0 ) {
		fseek( $handle, $offset );
	}

	while ( false !== ( $line = fgets( $handle ) ) ) {
		++$line_no;
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}

		$record = json_decode( $line, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			$records[] = array(
				'line'   => $line_no,
				'record' => $record,
			);
		}
	}

	$offset = ftell( $handle );
	fclose( $handle );

	return array(
		'records' => $records,
		'offset'  => false === $offset ? 0 : $offset,
	);
}

function html_api_fuzz_watcher_attempt_key( string $summary_path, int $line_no, array $record ): string {
	if ( ! empty( $record['resultPath'] ) ) {
		return 'result:' . $record['resultPath'];
	}
	if ( ! empty( $record['replayPath'] ) ) {
		return 'replay:' . $record['replayPath'];
	}
	return 'summary:' . $summary_path . ':' . $line_no;
}

function html_api_fuzz_watcher_minimize( string $hash, string $state_dir, array &$state, array $options ): void {
	$record = $state['signatures'][ $hash ] ?? null;
	if ( ! is_array( $record ) || empty( $record['replayPath'] ) || ! is_file( $record['replayPath'] ) ) {
		$state['signatures'][ $hash ]['status'] = 'missing-replay';
		return;
	}

	$signature_dir = html_api_fuzz_watcher_signature_dir( $state_dir, $hash );
	$output_dir    = $signature_dir . '/minimize-' . \HtmlApiFuzz\timestamp();
	$state['signatures'][ $hash ]['status'] = 'minimizing';
	$state['signatures'][ $hash ]['minimizeStartedAt'] = gmdate( 'c' );

	$args = array(
		__DIR__ . '/minimize.php',
		'--replay',
		$record['replayPath'],
		'--output-dir',
		$output_dir,
		'--timeout-ms',
		(string) \HtmlApiFuzz\option_int( $options, 'timeout-ms', 2500 ),
		'--max-attempts',
		(string) \HtmlApiFuzz\option_int( $options, 'max-attempts', 250 ),
	);
	if ( \HtmlApiFuzz\option_bool( $options, 'any-failure', false ) ) {
		$args[] = '--any-failure';
	}

	$proc = \HtmlApiFuzz\run_php_process( $args, \HtmlApiFuzz\repo_root(), \HtmlApiFuzz\option_int( $options, 'minimize-timeout-ms', 300000 ), $signature_dir . '/minimize.log' );
	$result_path = $output_dir . '/minimize-result.json';
	$result = \HtmlApiFuzz\read_json_file( $result_path );

	$state['signatures'][ $hash ]['minimizeFinishedAt'] = gmdate( 'c' );
	$state['signatures'][ $hash ]['minimizeProcess'] = array(
		'code'       => $proc['code'],
		'timedOut'   => $proc['timedOut'],
		'durationMs' => $proc['durationMs'],
		'logPath'    => $proc['logPath'],
	);
	$state['signatures'][ $hash ]['minimizeResult'] = $result_path;
	$state['signatures'][ $hash ]['minimizeOutputDir'] = $output_dir;
	$state['signatures'][ $hash ]['status'] = ( is_array( $result ) && ( $result['ok'] ?? false ) ) ? 'minimized' : 'minimize-failed';
	\HtmlApiFuzz\write_json_file( $signature_dir . '/failure.json', $state['signatures'][ $hash ] );
}

function html_api_fuzz_watcher_scan_once( string $run_dir, string $state_dir, string $state_path, array &$state, array $options ): array {
	$summary_paths = html_api_fuzz_watcher_summary_paths( $run_dir );
	if ( ! is_array( $state['summaryOffsets'] ?? null ) ) {
		$state['summaryOffsets'] = array();
	}

	$new_hashes = array();
	$failures_seen = 0;
	foreach ( $summary_paths as $summary_path ) {
		$read = html_api_fuzz_watcher_read_summary_records( $summary_path, (int) ( $state['summaryOffsets'][ $summary_path ] ?? 0 ) );
		$state['summaryOffsets'][ $summary_path ] = $read['offset'];
		foreach ( $read['records'] as $entry ) {
			$record = $entry['record'];
			if ( $record['ok'] ?? true ) {
				continue;
			}
			if ( empty( $record['signature'] ) ) {
				$signature = \HtmlApiFuzz\Signature::from_result( $record );
				if ( null !== $signature ) {
					$record['signature'] = $signature;
				}
			}
			$attempt_key = html_api_fuzz_watcher_attempt_key( $summary_path, $entry['line'], $record );
			if ( isset( $state['seenAttempts'][ $attempt_key ] ) ) {
				continue;
			}
			$state['seenAttempts'][ $attempt_key ] = gmdate( 'c' );
			++$failures_seen;
			if ( html_api_fuzz_watcher_record_failure( $record, $state_dir, $state ) ) {
				$new_hashes[] = $record['signature']['hash'];
			}
		}
	}

	$state['updatedAt'] = gmdate( 'c' );
	\HtmlApiFuzz\write_json_file( $state_path, $state );

	if ( \HtmlApiFuzz\option_bool( $options, 'no-minimize', false ) ) {
		foreach ( array_unique( $new_hashes ) as $hash ) {
			$state['signatures'][ $hash ]['status'] = 'queued';
		}
	} else {
		$queue = array();
		foreach ( $state['signatures'] as $hash => $record ) {
			if ( in_array( $record['status'] ?? 'new', array( 'new', 'queued' ), true ) ) {
				$queue[] = $hash;
			}
		}
		$max_minimize = \HtmlApiFuzz\option_int( $options, 'max-minimize', count( $queue ) );
		$started = 0;
		foreach ( array_unique( $queue ) as $hash ) {
			if ( $started >= $max_minimize ) {
				$state['signatures'][ $hash ]['status'] = 'queued';
				continue;
			}
			html_api_fuzz_watcher_minimize( $hash, $state_dir, $state, $options );
			++$started;
			$state['updatedAt'] = gmdate( 'c' );
			\HtmlApiFuzz\write_json_file( $state_path, $state );
		}
	}

	$state['updatedAt'] = gmdate( 'c' );
	\HtmlApiFuzz\write_json_file( $state_path, $state );
	file_put_contents( $state_dir . '/STATUS.md', html_api_fuzz_watcher_status_markdown( $state ) );

	return array(
		'summaryFiles'  => count( $summary_paths ),
		'failuresSeen'  => $failures_seen,
		'newSignatures' => count( array_unique( $new_hashes ) ),
	);
}

$options = \HtmlApiFuzz\parse_cli_options( $argv );
$run_dir = \HtmlApiFuzz\option_string( $options, 'run-dir', $options['_'][0] ?? null );
if ( null === $run_dir || \HtmlApiFuzz\option_bool( $options, 'help', false ) ) {
	html_api_fuzz_watcher_usage();
	exit( null === $run_dir ? 1 : 0 );
}

$state_dir  = \HtmlApiFuzz\option_string( $options, 'state-dir', rtrim( $run_dir, DIRECTORY_SEPARATOR ) . '/.triage-watcher' );
$state_path = $state_dir . '/state.json';
\HtmlApiFuzz\ensure_dir( $state_dir );
$state = html_api_fuzz_watcher_load_state( $state_path );
$state['runDir'] = $run_dir;
$state['stateDir'] = $state_dir;

$interval = max( 1.0, \HtmlApiFuzz\option_float( $options, 'interval-seconds', 10.0 ) );

do {
	$scan = html_api_fuzz_watcher_scan_once( $run_dir, $state_dir, $state_path, $state, $options );
	echo \HtmlApiFuzz\json_encode_safe( array_merge( array( 'ok' => true, 'at' => gmdate( 'c' ) ), $scan ) ) . "\n";
	if ( \HtmlApiFuzz\option_bool( $options, 'once', false ) ) {
		break;
	}
	usleep( (int) round( $interval * 1000000 ) );
} while ( true );
