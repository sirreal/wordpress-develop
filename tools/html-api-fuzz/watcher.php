#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

function html_api_fuzz_watcher_usage(): void {
	echo "Usage: php tools/html-api-fuzz/watcher.php --run-dir DIR [--state-dir DIR] [--once] [--interval-seconds N] [--stop-stale-seconds N] [--triage-oracle-findings]\n";
	echo "When RUN_DIR/STOP exists, the watcher exits after a final scan once every runner reports a stop reason.\n";
	echo "--stop-stale-seconds (default 120) presumes a non-reporting runner dead; per lane it is floored at twice the lane's advertised batch budget (timeout-ms x batch-size).\n";
	echo "--triage-oracle-findings scans oracleFinding rows in addition to ok = 0 failures.\n";
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
		'sqliteOffsets' => array(),
		'oracleSummaryOffsets' => array(),
		'oracleSqliteOffsets'  => array(),
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
		if ( ! empty( $record['triageKind'] ) ) {
			$lines[] = '- Kind: ' . $record['triageKind'];
		}
		if ( ! empty( $record['oracleFindingType'] ) ) {
			$lines[] = '- Oracle finding: ' . $record['oracleFindingType'];
		}
		if ( ! empty( $record['suspectedOwner'] ) ) {
			$lines[] = '- Suspected owner: ' . $record['suspectedOwner'];
		}
		if ( ! empty( $record['upstreamIssueUrl'] ) ) {
			$lines[] = '- Upstream: ' . $record['upstreamIssueUrl'];
		}
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
			'triageKind'   => $summary['triageKind'] ?? ( ( $summary['ok'] ?? false ) ? 'oracle-finding' : 'failure' ),
			'failureClass' => $summary['failureClass'] ?? 'unknown',
			'oracleFindingType' => $summary['oracleFindingType'] ?? null,
			'classification'    => $summary['classification'] ?? null,
			'suspectedOwner'    => $summary['suspectedOwner'] ?? null,
			'upstreamIssueUrl'  => $summary['upstreamIssueUrl'] ?? null,
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
			'oracleFindingType' => $summary['oracleFindingType'] ?? null,
			'classification'    => $summary['classification'] ?? null,
			'suspectedOwner'    => $summary['suspectedOwner'] ?? null,
			'upstreamIssueUrl'  => $summary['upstreamIssueUrl'] ?? null,
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

	while ( false !== ( $line_offset = ftell( $handle ) ) && false !== ( $line = fgets( $handle ) ) ) {
		++$line_no;
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}

		$record = json_decode( $line, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			$records[] = array(
				'line'   => $line_no,
				'offset' => $line_offset,
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

function html_api_fuzz_watcher_attempt_key( array $record, string $fallback_key ): string {
	if ( ! empty( $record['resultPath'] ) ) {
		return 'result:' . $record['resultPath'];
	}
	if ( ! empty( $record['replayPath'] ) ) {
		return 'replay:' . $record['replayPath'];
	}
	return $fallback_key;
}

/**
 * Per-lane results.sqlite stores written by runner lanes: directly in the run
 * directory for a standalone runner, one level down for launcher lanes.
 */
function html_api_fuzz_watcher_sqlite_paths( string $run_dir ): array {
	$paths = array();
	$direct = rtrim( $run_dir, DIRECTORY_SEPARATOR ) . '/' . \HtmlApiFuzz\ResultStore::FILENAME;
	if ( is_file( $direct ) ) {
		$paths[] = $direct;
	}

	$items = @scandir( $run_dir );
	if ( false === $items ) {
		return $paths;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item || in_array( $item, array( '.git', '.triage-watcher', 'triage' ), true ) ) {
			continue;
		}

		$store_path = rtrim( $run_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $item . '/' . \HtmlApiFuzz\ResultStore::FILENAME;
		if ( is_file( $store_path ) ) {
			$paths[] = $store_path;
		}
	}

	sort( $paths );
	return array_values( array_unique( $paths ) );
}

/**
 * True when every runner under the run directory has recorded a stop reason;
 * gates the watcher's graceful exit after a stop request so the final scan
 * covers everything the runners wrote.
 *
 * A runner whose state has not been touched for $stale_seconds is presumed
 * dead (crashed lanes never record a stop reason and must not block the exit
 * forever). With no runner state at all the run has not started; the watcher
 * keeps scanning rather than racing a launcher that is still spawning lanes.
 */
function html_api_fuzz_watcher_runners_stopped( string $run_dir, float $stale_seconds ): bool {
	$state_paths = array();
	$direct = rtrim( $run_dir, DIRECTORY_SEPARATOR ) . '/state.json';
	if ( is_file( $direct ) ) {
		$state_paths[] = $direct;
	}
	$items = @scandir( $run_dir );
	if ( false !== $items ) {
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || in_array( $item, array( '.git', '.triage-watcher', 'triage' ), true ) ) {
				continue;
			}
			$state_path = rtrim( $run_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $item . '/state.json';
			if ( is_file( $state_path ) ) {
				$state_paths[] = $state_path;
			}
		}
	}

	$found_runner_state = false;
	foreach ( $state_paths as $state_path ) {
		try {
			$runner_state = \HtmlApiFuzz\read_json_file( $state_path );
		} catch ( \RuntimeException $e ) {
			// Mid-write; the runner is alive.
			return false;
		}
		if ( ! is_array( $runner_state ) || 'html-api-fuzz-runner-state' !== ( $runner_state['kind'] ?? null ) ) {
			continue;
		}
		$found_runner_state = true;
		if ( null !== ( $runner_state['stopReason'] ?? null ) ) {
			continue;
		}
		/*
		 * A live lane is legitimately silent for up to one batch worker run
		 * (its advertised batchBudgetMs), so the staleness threshold is
		 * floored at twice that budget — otherwise large --timeout-ms or
		 * --batch-size values would get a live lane presumed dead and the
		 * "final scan covers everything" contract silently broken.
		 */
		$lane_stale_seconds = max( $stale_seconds, 2.0 * ( (int) ( $runner_state['batchBudgetMs'] ?? 0 ) ) / 1000.0 );
		$updated_at = strtotime( (string) ( $runner_state['updatedAt'] ?? '' ) );
		if ( false !== $updated_at && ( time() - $updated_at ) > $lane_stale_seconds ) {
			fwrite( STDERR, '[' . gmdate( 'c' ) . "] watcher: presuming dead runner (state stale): {$state_path}\n" );
			continue;
		}
		return false;
	}

	return $found_runner_state;
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
	$state['signatures'][ $hash ]['minimizeAttempts'] = (int) ( $state['signatures'][ $hash ]['minimizeAttempts'] ?? 0 ) + 1;

	$args = array(
		__DIR__ . '/minimize.php',
		'--replay',
		$record['replayPath'],
		'--output-dir',
		$output_dir,
		'--timeout-ms',
		(string) \HtmlApiFuzz\option_int( $options, 'timeout-ms', 2500 ),
		'--max-attempts',
		(string) \HtmlApiFuzz\option_int( $options, 'max-attempts', 600 ),
	);
	if ( \HtmlApiFuzz\option_bool( $options, 'any-failure', false ) ) {
		$args[] = '--any-failure';
	}
	if ( 'oracle-finding' === ( $record['triageKind'] ?? null ) ) {
		$args[] = '--target-kind';
		$args[] = 'oracle-finding';
		$args[] = '--target-hash';
		$args[] = $hash;
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

function html_api_fuzz_watcher_process_failure_record( array $record, string $fallback_key, string $state_dir, array &$state, array &$new_hashes, int &$failures_seen ): void {
	if ( $record['ok'] ?? true ) {
		return;
	}
	if ( empty( $record['signature'] ) ) {
		$signature = \HtmlApiFuzz\Signature::from_result( $record );
		if ( null !== $signature ) {
			$record['signature'] = $signature;
		}
	}
	$attempt_key = html_api_fuzz_watcher_attempt_key( $record, $fallback_key );
	if ( isset( $state['seenAttempts'][ $attempt_key ] ) ) {
		return;
	}
	$state['seenAttempts'][ $attempt_key ] = gmdate( 'c' );
	++$failures_seen;
	if ( html_api_fuzz_watcher_record_failure( $record, $state_dir, $state ) ) {
		$new_hashes[] = $record['signature']['hash'];
	}
}

function html_api_fuzz_watcher_process_oracle_finding_record( array $record, string $fallback_key, string $state_dir, array &$state, array &$new_hashes, int &$oracle_findings_seen ): void {
	$finding = $record['oracleFinding'] ?? null;
	if ( ! is_array( $finding ) || empty( $finding['signature']['hash'] ) ) {
		return;
	}

	$summary = $record;
	$summary['triageKind']        = 'oracle-finding';
	$summary['signature']         = $finding['signature'];
	$summary['failureClass']      = $finding['classification'] ?? 'oracle-finding';
	$summary['classification']    = $finding['classification'] ?? null;
	$summary['oracleFindingType'] = $finding['type'] ?? null;
	$summary['suspectedOwner']    = $finding['suspectedOwner'] ?? null;
	$summary['upstreamIssueUrl']  = $finding['upstream']['issueUrl'] ?? null;

	$attempt_key = html_api_fuzz_watcher_attempt_key( $record, $fallback_key ) . ':oracle:' . $finding['signature']['hash'];
	if ( isset( $state['seenAttempts'][ $attempt_key ] ) ) {
		return;
	}
	$state['seenAttempts'][ $attempt_key ] = gmdate( 'c' );
	++$oracle_findings_seen;
	if ( html_api_fuzz_watcher_record_failure( $summary, $state_dir, $state ) ) {
		$new_hashes[] = $finding['signature']['hash'];
	}
}

function html_api_fuzz_watcher_scan_once( string $run_dir, string $state_dir, string $state_path, array &$state, array $options ): array {
	$summary_paths = html_api_fuzz_watcher_summary_paths( $run_dir );
	if ( ! is_array( $state['summaryOffsets'] ?? null ) ) {
		$state['summaryOffsets'] = array();
	}
	if ( ! is_array( $state['sqliteOffsets'] ?? null ) ) {
		$state['sqliteOffsets'] = array();
	}
	if ( ! is_array( $state['oracleSummaryOffsets'] ?? null ) ) {
		$state['oracleSummaryOffsets'] = array();
	}
	if ( ! is_array( $state['oracleSqliteOffsets'] ?? null ) ) {
		$state['oracleSqliteOffsets'] = array();
	}

	$new_hashes = array();
	$failures_seen = 0;
	$oracle_findings_seen = 0;
	$triage_oracle_findings = \HtmlApiFuzz\option_bool( $options, 'triage-oracle-findings', false );
	foreach ( $summary_paths as $summary_path ) {
		$read = html_api_fuzz_watcher_read_summary_records( $summary_path, (int) ( $state['summaryOffsets'][ $summary_path ] ?? 0 ) );
		$state['summaryOffsets'][ $summary_path ] = $read['offset'];
		foreach ( $read['records'] as $entry ) {
			html_api_fuzz_watcher_process_failure_record( $entry['record'], 'summary:' . $summary_path . ':offset:' . ( $entry['offset'] ?? $entry['line'] ), $state_dir, $state, $new_hashes, $failures_seen );
		}
	}

	if ( $triage_oracle_findings ) {
		foreach ( $summary_paths as $summary_path ) {
			$read = html_api_fuzz_watcher_read_summary_records( $summary_path, (int) ( $state['oracleSummaryOffsets'][ $summary_path ] ?? 0 ) );
			$state['oracleSummaryOffsets'][ $summary_path ] = $read['offset'];
			foreach ( $read['records'] as $entry ) {
				html_api_fuzz_watcher_process_oracle_finding_record( $entry['record'], 'summary:' . $summary_path . ':offset:' . ( $entry['offset'] ?? $entry['line'] ), $state_dir, $state, $new_hashes, $oracle_findings_seen );
			}
		}
	}

	$sqlite_paths = html_api_fuzz_watcher_sqlite_paths( $run_dir );
	foreach ( $sqlite_paths as $store_path ) {
		/*
		 * Lane stores fail transiently: a lane may not have committed its
		 * schema yet (SQLite opens lazily, so that surfaces on the first
		 * query, not in the constructor), and busy/corrupt stores throw on
		 * read. None of those may kill the long-lived watcher — log, skip,
		 * and retry on the next scan. Offsets only advance on success.
		 */
		$store = null;
		try {
			$store = new \HtmlApiFuzz\ResultStore( $store_path, true );
			$after  = (int) ( $state['sqliteOffsets'][ $store_path ] ?? 0 );
			$max_id = $store->max_id();
			if ( $after > $max_id ) {
				// The store shrank: it was recreated. Re-read from the start;
				// the seenAttempts keys dedupe anything genuinely re-seen.
				// (A recreated store that already grew past the stale offset
				// is not detected; recreating a lane store without wiping the
				// watcher state is unsupported.)
				$after = 0;
			}
			if ( $max_id > $after ) {
				foreach ( $store->failures_after( $after, $max_id ) as $row ) {
					html_api_fuzz_watcher_process_failure_record( $row['record'], 'sqlite:' . $store_path . ':' . $row['id'], $state_dir, $state, $new_hashes, $failures_seen );
				}
			}
			$state['sqliteOffsets'][ $store_path ] = $max_id;
			if ( $triage_oracle_findings ) {
				$oracle_after = (int) ( $state['oracleSqliteOffsets'][ $store_path ] ?? 0 );
				if ( $oracle_after > $max_id ) {
					$oracle_after = 0;
				}
				if ( $max_id > $oracle_after ) {
					foreach ( $store->oracle_findings_after( $oracle_after, $max_id ) as $row ) {
						html_api_fuzz_watcher_process_oracle_finding_record( $row['record'], 'sqlite:' . $store_path . ':' . $row['id'], $state_dir, $state, $new_hashes, $oracle_findings_seen );
					}
				}
				$state['oracleSqliteOffsets'][ $store_path ] = $max_id;
			}
		} catch ( \Throwable $e ) {
			fwrite( STDERR, '[' . gmdate( 'c' ) . "] watcher: could not read {$store_path}: {$e->getMessage()}\n" );
		} finally {
			if ( null !== $store ) {
				try {
					$store->close();
				} catch ( \Throwable $e ) {
					// Already unusable; nothing to release.
				}
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
		$max_minimize_retries = \HtmlApiFuzz\option_int( $options, 'max-minimize-retries', 3 );
		$queue = array();
		foreach ( $state['signatures'] as $hash => $record ) {
			$status = $record['status'] ?? 'new';
			if ( in_array( $status, array( 'new', 'queued' ), true ) ) {
				$queue[] = $hash;
				continue;
			}
			// Re-queue failed minimizations on later scans, up to the cap:
			// transient timeouts and load spikes should not strand a finding.
			if ( 'minimize-failed' === $status && (int) ( $record['minimizeAttempts'] ?? 0 ) < $max_minimize_retries ) {
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
		'sqliteStores'  => count( $sqlite_paths ),
		'failuresSeen'  => $failures_seen,
		'oracleFindingsSeen' => $oracle_findings_seen,
		'triageOracleFindings' => $triage_oracle_findings,
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

$interval      = max( 1.0, \HtmlApiFuzz\option_float( $options, 'interval-seconds', 10.0 ) );
$stop_file     = rtrim( $run_dir, DIRECTORY_SEPARATOR ) . '/STOP';
$stale_seconds = max( 10.0, \HtmlApiFuzz\option_float( $options, 'stop-stale-seconds', 120.0 ) );

do {
	/*
	 * Graceful stop: once a stop is requested and every runner has recorded a
	 * stop reason (or gone stale), this scan is the final one — nothing
	 * writes after it.
	 */
	$stop_requested = is_file( $stop_file );
	$final_scan     = $stop_requested && html_api_fuzz_watcher_runners_stopped( $run_dir, $stale_seconds );
	$scan = html_api_fuzz_watcher_scan_once( $run_dir, $state_dir, $state_path, $state, $options );
	echo \HtmlApiFuzz\json_encode_safe(
		array_merge(
			array(
				'ok'          => true,
				'at'          => gmdate( 'c' ),
				'finalScan'   => $final_scan,
				'stopPending' => $stop_requested && ! $final_scan,
			),
			$scan
		)
	) . "\n";
	if ( $final_scan || \HtmlApiFuzz\option_bool( $options, 'once', false ) ) {
		break;
	}
	usleep( (int) round( $interval * 1000000 ) );
} while ( true );
