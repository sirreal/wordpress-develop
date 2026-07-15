#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_preflight_smoke_fail( string $message ): void {
	throw new RuntimeException( $message );
}

function html_api_fuzz_preflight_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_preflight_smoke_fail( $message );
	}
}

function html_api_fuzz_preflight_smoke_run_command( array $command, string $cwd, int $timeout_ms = 10000 ): array {
	$spec = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$process = proc_open( $command, $spec, $pipes, $cwd );
	if ( ! is_resource( $process ) ) {
		html_api_fuzz_preflight_smoke_fail( 'Could not start subprocess.' );
	}
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	$stdout = '';
	$stderr = '';
	$start = microtime( true );
	$timed_out = false;
	while ( true ) {
		$stdout .= stream_get_contents( $pipes[1] );
		$stderr .= stream_get_contents( $pipes[2] );
		$status = proc_get_status( $process );
		if ( ! $status['running'] ) {
			break;
		}
		if ( ( microtime( true ) - $start ) * 1000 > $timeout_ms ) {
			$timed_out = true;
			proc_terminate( $process );
			usleep( 200000 );
			$status = proc_get_status( $process );
			if ( $status['running'] ) {
				proc_terminate( $process, 9 );
			}
			break;
		}
		usleep( 10000 );
	}
	$stdout .= stream_get_contents( $pipes[1] );
	$stderr .= stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $process );
	return array(
		'code'     => $timed_out ? null : $code,
		'timedOut' => $timed_out,
		'output'   => $stdout . $stderr,
	);
}

function html_api_fuzz_preflight_smoke_definitions(): array {
	$root = \HtmlApiFuzz\repo_root() . '/tools/html-api-fuzz/oracles';
	$chrome_version = trim( file_get_contents( $root . '/chrome/VERSION' ) );
	return array(
		'lexbor' => array(
			'kind'          => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
			'available'     => true,
			'lexborCommit'  => trim( file_get_contents( $root . '/lexbor/COMMIT' ) ),
			'lexborVersion' => 'test-version',
		),
		'html5ever' => array(
			'kind'                       => \HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE,
			'available'                  => true,
			'html5everVersion'           => '0.39.0',
			'html5everChecksum'          => '46a1761807faccc9a19e86944bbf40610014066306f96edcdedc2fb714bcb7b8',
			'markup5everRcdomVersion'    => '0.39.0+unofficial',
			'markup5everRcdomChecksum'   => '3ac010f19d6c4af81eeb4018a39d7a115de9d285af45c126a4ac02e6fc5716b7',
			'rustToolchain'               => '1.88.0',
		),
		'chrome' => array(
			'kind'                 => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
			'available'            => true,
			'pinnedChromeVersion'  => $chrome_version,
			'browserVersion'       => $chrome_version,
			'browserPid'           => 4242,
		),
	);
}

function html_api_fuzz_preflight_smoke_fixture( string $directory, string $mutation = 'valid' ): void {
	$definitions = html_api_fuzz_preflight_smoke_definitions();
	$start_seed = 10;
	$seed_stride = 3;
	$seeds = array( 10, 13, 16 );
	foreach ( $definitions as $label => $oracle ) {
		$state = array(
			'stopReason' => 'max-seeds',
			'startSeed'  => $start_seed,
			'seedStride' => $seed_stride,
			'nextSeed'   => 19,
			'oracle'     => $oracle,
		);
		$rows = array();
		foreach ( $seeds as $index => $seed ) {
			$rows[] = array(
				'seed'           => $seed,
				'ok'             => 2 !== $index,
				'status'         => 2 === $index ? 'failed' : 'ok',
				'failureClass'   => 2 === $index ? 'tree-mismatch' : null,
				'workerCode'     => 2 === $index ? 2 : 0,
				'workerTimedOut' => false,
				'inputSha1'      => sha1( 'shared-seed-' . $seed ),
				'oracle'         => $oracle,
			);
		}

		if ( 'duplicate' === $mutation && 'lexbor' === $label ) {
			$rows[] = $rows[0];
		} elseif ( 'missing' === $mutation && 'html5ever' === $label ) {
			array_pop( $rows );
		} elseif ( 'malformed-hash' === $mutation && 'lexbor' === $label ) {
			$rows[0]['inputSha1'] = 'not-a-sha1';
		} elseif ( 'hash-mismatch' === $mutation && 'html5ever' === $label ) {
			$rows[0]['inputSha1'] = sha1( 'different-input' );
		} elseif ( 'chrome-pid' === $mutation && 'chrome' === $label ) {
			$rows[2]['oracle']['browserPid'] = 4343;
		} elseif ( 'worker-failed' === $mutation && 'lexbor' === $label ) {
			$rows[0]['ok'] = false;
			$rows[0]['status'] = 'worker-failed';
			$rows[0]['failureClass'] = 'worker-failed';
		} elseif ( 'worker-code-one' === $mutation && 'lexbor' === $label ) {
			$rows[2]['workerCode'] = 1;
		} elseif ( 'ok-code-two' === $mutation && 'lexbor' === $label ) {
			$rows[0]['workerCode'] = 2;
		} elseif ( 'state-bounds' === $mutation && 'lexbor' === $label ) {
			$state['nextSeed'] = 16;
		}

		$lane = $directory . '/' . $label;
		\HtmlApiFuzz\ensure_dir( $lane );
		\HtmlApiFuzz\write_json_file( $lane . '/state.json', $state );
		$store = new \HtmlApiFuzz\ResultStore( $lane . '/' . \HtmlApiFuzz\ResultStore::FILENAME );
		foreach ( $rows as $row ) {
			$store->record_attempt( $row );
		}
		$store->close();
		if ( 'lexbor' === $label && in_array( $mutation, array( 'malformed-seed', 'malformed-timeout' ), true ) ) {
			$db = new SQLite3( $lane . '/' . \HtmlApiFuzz\ResultStore::FILENAME );
			if ( 'malformed-seed' === $mutation ) {
				$db->exec( "UPDATE attempts SET seed = '10junk' WHERE id = 1" );
			} else {
				$db->exec( "UPDATE attempts SET worker_timed_out = 'not-a-number' WHERE id = 1" );
			}
			$db->close();
		}
	}
}

function html_api_fuzz_preflight_smoke_verify( string $directory, bool $expected_ok, string $message ): void {
	$process = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/verify-preflight.php',
			'--run-dir',
			$directory,
			'--max-seeds',
			'3',
			'--start-seed',
			'10',
			'--seed-stride',
			'3',
		),
		\HtmlApiFuzz\repo_root(),
		10000
	);
	if ( $expected_ok ) {
		html_api_fuzz_preflight_smoke_assert( 0 === $process['code'], $message . ': ' . $process['output'] );
	} else {
		html_api_fuzz_preflight_smoke_assert( 0 !== $process['code'] && false !== strpos( $process['output'], $message ), "Expected verifier rejection containing '{$message}': {$process['output']}" );
	}
}

function html_api_fuzz_preflight_smoke_v2_schema( SQLite3 $db ): void {
	$db->exec(
		'CREATE TABLE attempts (
			id INTEGER PRIMARY KEY,
			created_at TEXT NOT NULL,
			seed INTEGER NOT NULL,
			ok INTEGER NOT NULL,
			status TEXT NOT NULL,
			failure_class TEXT,
			signature_hash TEXT,
			family_key TEXT,
			oracle_finding_class TEXT,
			oracle_finding_type TEXT,
			oracle_suspected_owner TEXT,
			oracle_signature_hash TEXT,
			oracle_family_key TEXT,
			oracle_kind TEXT,
			oracle_version TEXT,
			oracle_commit TEXT,
			oracle_binary TEXT,
			profile TEXT,
			mode TEXT,
			payload_policy TEXT,
			input_source TEXT,
			input_sha1 TEXT,
			input_length INTEGER,
			duration_ms INTEGER,
			worker_code INTEGER,
			worker_timed_out INTEGER NOT NULL DEFAULT 0,
			artifacts_retained INTEGER NOT NULL DEFAULT 0,
			failure_artifacts_retained INTEGER,
			oracle_artifacts_retained INTEGER,
			summary_json TEXT,
			result_json TEXT,
			replay_json TEXT
		)'
	);
	$db->exec( 'PRAGMA user_version = 2' );
}

$repo_root = \HtmlApiFuzz\repo_root();
$work_dir = sys_get_temp_dir() . '/html-api-fuzz-preflight-smoke-' . \HtmlApiFuzz\timestamp();
\HtmlApiFuzz\ensure_dir( $work_dir );
$failure = null;

try {
	$valid = $work_dir . '/valid';
	html_api_fuzz_preflight_smoke_fixture( $valid );
	html_api_fuzz_preflight_smoke_verify( $valid, true, 'Expected exact fixture to pass' );
	$chrome_db = new SQLite3( $valid . '/chrome/' . \HtmlApiFuzz\ResultStore::FILENAME, SQLITE3_OPEN_READONLY );
	html_api_fuzz_preflight_smoke_assert( 0 === (int) $chrome_db->querySingle( 'SELECT COUNT(*) FROM attempts WHERE oracle_browser_pid != 4242 OR oracle_browser_pid IS NULL' ), 'Expected every Chrome PID to persist.' );
	$chrome_db->close();

	$invalid_cases = array(
		'duplicate'       => 'duplicate seed',
		'missing'         => 'recorded 2 rows instead of 3',
		'malformed-hash'  => 'valid input SHA-1',
		'hash-mismatch'   => 'inputs differ from the shared-seed baseline',
		'chrome-pid'      => 'did not use the pinned live browser PID',
		'worker-failed'   => 'had infrastructure failure',
		'worker-code-one' => 'inconsistent worker status',
		'ok-code-two'     => 'inconsistent worker status',
		'state-bounds'    => 'recorded inconsistent seed bounds',
		'malformed-seed'  => 'invalid seed scalar',
		'malformed-timeout' => 'invalid worker timeout flag',
	);
	foreach ( $invalid_cases as $mutation => $diagnostic ) {
		$case_dir = $work_dir . '/' . $mutation;
		html_api_fuzz_preflight_smoke_fixture( $case_dir, $mutation );
		html_api_fuzz_preflight_smoke_verify( $case_dir, false, $diagnostic );
	}

	$migration_dir = $work_dir . '/migration';
	\HtmlApiFuzz\ensure_dir( $migration_dir );
	$migration_path = $migration_dir . '/results.sqlite';
	$migration_db = new SQLite3( $migration_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE );
	html_api_fuzz_preflight_smoke_v2_schema( $migration_db );
	$migration_db->close();
	$migrated_store = new \HtmlApiFuzz\ResultStore( $migration_path );
	$chrome_oracle = html_api_fuzz_preflight_smoke_definitions()['chrome'];
	$chrome_oracle['browserPid'] = 5151;
	$migrated_store->record_attempt(
		array(
			'seed'           => 1,
			'ok'             => true,
			'status'         => 'ok',
			'inputSha1'      => sha1( 'migration' ),
			'workerCode'     => 0,
			'workerTimedOut' => false,
			'oracle'         => $chrome_oracle,
		)
	);
	$migrated_store->close();
	$migration_db = new SQLite3( $migration_path, SQLITE3_OPEN_READONLY );
	html_api_fuzz_preflight_smoke_assert( 3 === (int) $migration_db->querySingle( 'PRAGMA user_version' ), 'Expected ResultStore v2 to migrate to v3.' );
	$columns = array();
	$column_rows = $migration_db->query( 'PRAGMA table_info(attempts)' );
	while ( false !== ( $column = $column_rows->fetchArray( SQLITE3_ASSOC ) ) ) {
		$columns[] = $column['name'];
	}
	html_api_fuzz_preflight_smoke_assert( in_array( 'oracle_browser_pid', $columns, true ), 'Expected migration to add oracle_browser_pid.' );
	html_api_fuzz_preflight_smoke_assert( 5151 === (int) $migration_db->querySingle( 'SELECT oracle_browser_pid FROM attempts' ), 'Expected migrated store to persist browser PID.' );
	$migration_db->close();

	$preflight = $repo_root . '/tools/html-api-fuzz/preflight.sh';
	$valid_environment = array(
		'MAX_SEEDS'      => '3',
		'START_SEED'     => '1',
		'SEED_STRIDE'    => '1',
		'BATCH_SIZE'     => '2',
		'MAX_INPUT_BYTES'=> '128',
	);
	$shell_cases = array(
		'max-zero' => array( 'MAX_SEEDS', '0', 'positive decimal integer' ),
		'max-text' => array( 'MAX_SEEDS', 'three', 'positive decimal integer' ),
		'stride-zero' => array( 'SEED_STRIDE', '0', 'positive decimal integer' ),
		'batch-zero' => array( 'BATCH_SIZE', '0', 'positive decimal integer' ),
		'input-zero' => array( 'MAX_INPUT_BYTES', '0', 'positive decimal integer' ),
		'start-text' => array( 'START_SEED', 'one', 'Expected START_SEED to be an integer' ),
		'max-range' => array( 'MAX_SEEDS', (string) PHP_INT_MAX . '0', 'fit in a PHP integer' ),
		'product-overflow' => array( 'MAX_SEEDS', (string) PHP_INT_MAX, 'MAX_SEEDS * SEED_STRIDE exceeds' ),
		'next-overflow' => array( 'START_SEED', (string) PHP_INT_MAX, 'final nextSeed exceeds' ),
	);
	foreach ( $shell_cases as $case => $definition ) {
		$environment = $valid_environment;
		$environment[ $definition[0] ] = $definition[1];
		if ( 'product-overflow' === $case ) {
			$environment['SEED_STRIDE'] = '2';
		}
		$output_dir = $work_dir . '/shell-' . $case;
		$command = array( '/usr/bin/env' );
		foreach ( $environment as $name => $value ) {
			$command[] = $name . '=' . $value;
		}
		$command[] = 'OUTPUT_DIR=' . $output_dir;
		$command[] = $preflight;
		$process = html_api_fuzz_preflight_smoke_run_command( $command, $repo_root );
		html_api_fuzz_preflight_smoke_assert( ! $process['timedOut'], "Expected {$case} validation to finish before install." );
		html_api_fuzz_preflight_smoke_assert( 0 !== $process['code'] && false !== strpos( $process['output'], $definition[2] ), "Expected {$case} diagnostic: {$process['output']}" );
		html_api_fuzz_preflight_smoke_assert( ! file_exists( $output_dir ), "Expected {$case} to fail before output creation." );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	\HtmlApiFuzz\remove_dir_recursive( $work_dir );
}

if ( null !== $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure->getMessage() . "\n" );
	exit( 1 );
}
if ( is_dir( $work_dir ) ) {
	fwrite( STDERR, "FAIL: Expected preflight smoke work directory cleanup.\n" );
	exit( 1 );
}
echo "OK preflight-verifier-smoke\n";
