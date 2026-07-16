#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_batch_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function html_api_fuzz_batch_expect_failure( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Throwable $error ) {
		return;
	}
	html_api_fuzz_batch_assert( false, $message );
}

function html_api_fuzz_batch_replace_arg( array $arguments, string $option, string $value ): array {
	$index = array_search( $option, $arguments, true );
	html_api_fuzz_batch_assert( false !== $index && array_key_exists( $index + 1, $arguments ), "Expected {$option} in coordinator arguments." );
	$arguments[ $index + 1 ] = $value;
	return $arguments;
}

function html_api_fuzz_batch_create_workspace( string $workspace, string $batch ): array {
	\HtmlApiFuzz\ensure_dir( $workspace );
	$path = $workspace . '/documents.sqlite';
	$db = new SQLite3( $path );
	$db->exec( 'PRAGMA journal_mode = DELETE' );
	$db->exec( 'CREATE TABLE document_batches (
		name TEXT PRIMARY KEY, source_mode TEXT NOT NULL, crawl_selection TEXT NOT NULL,
		crawl_id TEXT NOT NULL, url_pattern TEXT NOT NULL, requested_limit INTEGER NOT NULL,
		state_path TEXT NOT NULL, cache_path TEXT NOT NULL, status TEXT NOT NULL,
		created_at TEXT NOT NULL, updated_at TEXT NOT NULL, completed_at TEXT
	)' );
	$db->exec( 'CREATE TABLE document_batch_documents (
		batch_name TEXT NOT NULL, sequence INTEGER NOT NULL, input_state_key TEXT NOT NULL,
		input_url TEXT NOT NULL, record_id TEXT NOT NULL, target_uri TEXT NOT NULL,
		response_code INTEGER NOT NULL, byte_length INTEGER NOT NULL, checksum TEXT NOT NULL,
		range_start INTEGER, range_length INTEGER, cached_at TEXT NOT NULL,
		PRIMARY KEY (batch_name, input_state_key, record_id), UNIQUE (batch_name, sequence)
	)' );
	$db->exec( 'CREATE TABLE cached_documents (
		input_state_key TEXT NOT NULL, input_url TEXT NOT NULL, record_id TEXT NOT NULL,
		target_uri TEXT NOT NULL, response_code INTEGER NOT NULL, content_type TEXT NOT NULL,
		transport_charset TEXT, response_headers_json TEXT NOT NULL, body BLOB NOT NULL,
		byte_length INTEGER NOT NULL, range_start INTEGER, range_length INTEGER,
		fetched_at TEXT NOT NULL, last_used_at TEXT NOT NULL,
		PRIMARY KEY (input_state_key, record_id)
	)' );
	$db->exec( 'CREATE TABLE document_batch_runs (
		run_id INTEGER PRIMARY KEY AUTOINCREMENT, batch_name TEXT NOT NULL,
		analysis_script TEXT NOT NULL, analysis_script_hash TEXT,
		documents_analyzed INTEGER NOT NULL, started_at TEXT NOT NULL, completed_at TEXT NOT NULL
	)' );
	$now = '2026-07-15T00:00:00+00:00';
	$documents = array(
		array(
			'input_state_key' => 'crawl-data/CC-MAIN-2026-30/segments/a.warc.gz#0:100',
			'input_url' => 'https://data.commoncrawl.org/crawl-data/a.warc.gz',
			'record_id' => 'urn:uuid:batch-one',
			'target_uri' => 'https://example.com/one',
			'response_code' => 200,
			'content_type' => 'text/html',
			'transport_charset' => 'UTF-8',
			'body' => '<!doctype html><title>one</title><p>alpha',
			'range_start' => 0,
			'range_length' => 100,
		),
		array(
			'input_state_key' => 'crawl-data/CC-MAIN-2026-30/segments/b.warc.gz#100:200',
			'input_url' => 'https://data.commoncrawl.org/crawl-data/b.warc.gz',
			'record_id' => 'urn:uuid:batch-two',
			'target_uri' => 'https://example.com/two',
			'response_code' => 200,
			'content_type' => 'text/html',
			'transport_charset' => null,
			'body' => '<table><tr><td>beta</table>',
			'range_start' => 100,
			'range_length' => 200,
		),
	);
	$batch_insert = $db->prepare( 'INSERT INTO document_batches VALUES (:name, :source, :selection, :crawl, :url, :limit, :state, :cache, :status, :created, :updated, :completed)' );
	foreach ( array(
		':name' => $batch, ':source' => 'url-index', ':selection' => 'CC-MAIN-2026-30',
		':crawl' => 'CC-MAIN-2026-30', ':url' => '', ':limit' => count( $documents ),
		':state' => $workspace . '/state.sqlite', ':cache' => $path, ':status' => 'ready',
		':created' => $now, ':updated' => $now, ':completed' => $now,
	) as $name => $value ) {
		$batch_insert->bindValue( $name, $value, is_int( $value ) ? SQLITE3_INTEGER : SQLITE3_TEXT );
	}
	$batch_insert->execute();
	foreach ( $documents as $index => $document ) {
		$manifest = $db->prepare( 'INSERT INTO document_batch_documents VALUES (:batch, :sequence, :state, :url, :record, :target, :response, :bytes, :checksum, :start, :length, :cached)' );
		$cache = $db->prepare( 'INSERT INTO cached_documents VALUES (:state, :url, :record, :target, :response, :type, :charset, :headers, :body, :bytes, :start, :length, :fetched, :used)' );
		$values = array(
			':batch' => $batch, ':sequence' => $index + 1, ':state' => $document['input_state_key'],
			':url' => $document['input_url'], ':record' => $document['record_id'], ':target' => $document['target_uri'],
			':response' => $document['response_code'], ':bytes' => strlen( $document['body'] ),
			':checksum' => hash( 'sha256', $document['body'] ), ':start' => $document['range_start'],
			':length' => $document['range_length'], ':cached' => $now, ':type' => $document['content_type'],
			':charset' => $document['transport_charset'], ':headers' => '[]', ':body' => $document['body'],
			':fetched' => $now, ':used' => $now,
		);
		foreach ( $values as $name => $value ) {
			$type = null === $value ? SQLITE3_NULL : ( is_int( $value ) ? SQLITE3_INTEGER : SQLITE3_TEXT );
			if ( in_array( $name, array( ':batch', ':sequence', ':state', ':url', ':record', ':target', ':response', ':bytes', ':checksum', ':start', ':length', ':cached' ), true ) ) {
				$manifest->bindValue( $name, $value, $type );
			}
			if ( in_array( $name, array( ':state', ':url', ':record', ':target', ':response', ':type', ':charset', ':headers', ':body', ':bytes', ':start', ':length', ':fetched', ':used' ), true ) ) {
				$cache->bindValue( $name, $value, ':body' === $name ? SQLITE3_BLOB : $type );
			}
		}
		$manifest->execute();
		$cache->execute();
	}
	$db->close();
	return array( 'path' => $path, 'documents' => $documents );
}

function html_api_fuzz_batch_chrome_options(): array {
	$renderer = \HtmlApiFuzz\OracleRenderer::from_options( array( 'dom-oracle' => 'chrome-cdp', 'oracle-timeout-ms' => '10000' ) );
	return \HtmlApiFuzz\OracleRenderer::with_explicit_close(
		$renderer,
		static function ( \HtmlApiFuzz\OracleRenderer $renderer ): array {
			$metadata = $renderer->metadata();
			html_api_fuzz_batch_assert( true === ( $metadata['available'] ?? false ), 'Pinned Chrome must be installed for coordinator smoke.' );
			return $renderer->replay_options();
		}
	);
}

function html_api_fuzz_batch_failure_scenario( array $base_args, string $root, string $batch, string $name, array $control, array $overrides = array(), int $timeout_ms = 900000 ): array {
	$workspace = $root . '/' . $name . '-workspace';
	html_api_fuzz_batch_create_workspace( $workspace, $batch );
	file_put_contents( $workspace . '/control.json', json_encode( $control, JSON_THROW_ON_ERROR ) . "\n" );
	$phar = $root . '/' . $name . '-cc-analyzer.phar';
	html_api_fuzz_batch_assert( copy( __DIR__ . '/fixtures/fake-cc-analyzer.php', $phar ), "Expected {$name} fake cc-analyzer copy." );
	$output = $root . '/' . $name . '-output';
	$arguments = html_api_fuzz_batch_replace_arg( $base_args, '--cc-analyzer', $phar );
	$arguments = html_api_fuzz_batch_replace_arg( $arguments, '--workspace', $workspace );
	$arguments = html_api_fuzz_batch_replace_arg( $arguments, '--output-dir', $output );
	foreach ( $overrides as $option => $value ) {
		$arguments = html_api_fuzz_batch_replace_arg( $arguments, $option, $value );
	}
	$process = \HtmlApiFuzz\run_php_process( $arguments, \HtmlApiFuzz\repo_root(), $timeout_ms );
	html_api_fuzz_batch_assert( 0 !== ( $process['code'] ?? 0 ) || true === ( $process['timedOut'] ?? false ), "Expected {$name} coordinator failure." );
	html_api_fuzz_batch_assert( ! is_file( $output . '/.complete' ) && ! is_file( $output . '/shared-corpus.json' ), "Expected no shared success for {$name}." );
	foreach ( array( 'lexbor-source', 'html5ever-source', 'chrome-cdp' ) as $kind ) {
		html_api_fuzz_batch_assert( ! is_file( $output . '/' . $kind . '/.complete' ), "Expected no surviving {$kind} completion marker for {$name}." );
	}
	return array( 'process' => $process, 'workspace' => $workspace, 'phar' => $phar, 'output' => $output, 'arguments' => $arguments );
}

$work_dir = sys_get_temp_dir() . '/html-api-commoncrawl-coordinator-' . getmypid();
\HtmlApiFuzz\ensure_dir( $work_dir );
$workspace = $work_dir . '/workspace';
$batch_name = 'coordinator-smoke';
$fixture = html_api_fuzz_batch_create_workspace( $workspace, $batch_name );
$fake_phar = $work_dir . '/cc-analyzer.phar';
html_api_fuzz_batch_assert( copy( __DIR__ . '/fixtures/fake-cc-analyzer.php', $fake_phar ), 'Expected fake cc-analyzer copy.' );
$chrome = html_api_fuzz_batch_chrome_options();
$output = $work_dir . '/shared-output';
$args = array(
	dirname( __DIR__ ) . '/commoncrawl-batch.php',
	'--cc-analyzer', $fake_phar,
	'--workspace', $workspace,
	'--batch', $batch_name,
	'--output-dir', $output,
	'--batch-timeout-ms', '180000',
	'--process-timeout-ms', '90000',
	'--oracle-timeout-ms', '10000',
	'--chrome-startup-timeout-ms', '35000',
	'--lexbor-oracle-bin', dirname( __DIR__ ) . '/oracles/lexbor/build/lexbor-tree-oracle',
	'--html5ever-oracle-bin', dirname( __DIR__ ) . '/oracles/html5ever/build/html5ever-tree-oracle',
	'--chrome-oracle-script', $chrome['chromeOracleScript'],
	'--chrome-executable', $chrome['chromeExecutable'],
	'--node-bin', $chrome['nodeBin'],
	'--retain-all',
);
$hostile = array(
	'NODE_OPTIONS' => '--definitely-invalid-coordinator-option',
	'PHPRC' => '/definitely/missing/php.ini',
	'PHP_INI_SCAN_DIR' => '/definitely/missing/conf.d',
	'LD_BIND_NOW' => '1',
	'DYLD_LIBRARY_PATH' => '/definitely/missing/dylibs',
	'HTML_API_CC_UNEXPECTED' => 'must-not-leak',
	'HTML_API_FUZZ_UNEXPECTED' => 'must-not-leak',
	'HTML_API_CC_CHECKS' => 'bogus-inherited-value',
);
$process = \HtmlApiFuzz\run_php_process( $args, \HtmlApiFuzz\repo_root(), 1800000, $work_dir . '/coordinator.log', 1048576, true, $hostile );
html_api_fuzz_batch_assert(
	false === ( $process['timedOut'] ?? true ) && 0 === ( $process['code'] ?? 1 ),
	'Expected successful shared-corpus coordinator: ' . json_encode( array(
		'code' => $process['code'] ?? null,
		'timedOut' => $process['timedOut'] ?? null,
		'cleanupFailed' => $process['processGroupCleanupFailed'] ?? null,
		'stderr' => $process['stderr'] ?? null,
	), JSON_UNESCAPED_SLASHES )
);
$shared = json_decode( (string) file_get_contents( $output . '/shared-corpus.json' ), true );
html_api_fuzz_batch_assert( is_array( $shared ) && 2 === ( $shared['documentCount'] ?? null ), 'Expected two-document shared-corpus manifest.' );
html_api_fuzz_batch_assert( array( 'lexbor-source', 'html5ever-source', 'chrome-cdp' ) === array_keys( $shared['runs'] ?? array() ), 'Expected all three required oracle runs.' );
$vectors = array();
foreach ( $shared['runs'] as $kind => $run ) {
	$run_dir = $output . '/' . $kind;
	html_api_fuzz_batch_assert( is_file( $run_dir . '/batch-run.stdout' ) && is_file( $run_dir . '/batch-run.stderr' ), "Expected separate {$kind} stdout/stderr evidence." );
	html_api_fuzz_batch_assert( is_file( $run_dir . '/run-seal.json' ) && is_file( $run_dir . '/.complete' ), "Expected sealed {$kind} run." );
	$observed = json_decode( (string) file_get_contents( $run_dir . '/observed-environment.json' ), true );
	$replacement = json_decode( (string) file_get_contents( $run_dir . '/replacement-environment.json' ), true );
	html_api_fuzz_batch_assert( ( $run['environment'] ?? null ) === $observed && $observed === ( $replacement['environment'] ?? null ), "Expected exact durable replacement environment evidence for {$kind}." );
	foreach ( array_keys( $hostile ) as $name ) {
		if ( 'HTML_API_CC_CHECKS' === $name ) {
			html_api_fuzz_batch_assert( 'baseline' === ( $observed[ $name ] ?? null ), "Expected explicit coordinator {$name} to replace the hostile parent value." );
			continue;
		}
		html_api_fuzz_batch_assert( ! array_key_exists( $name, $observed ), "Expected hostile {$name} to be absent from {$kind} batch tree." );
	}
	$vectors[] = $run['summary']['vector'];
}
html_api_fuzz_batch_assert( $vectors[0] === $vectors[1] && $vectors[1] === $vectors[2], 'Expected exact identical ordered vectors.' );
html_api_fuzz_batch_assert( is_file( $output . '/coordinator-seal.json' ) && is_file( $output . '/.complete' ), 'Expected completed sealed coordinator root.' );
$coordinator_state = json_decode( (string) file_get_contents( $output . '/coordinator-state.json' ), true );
html_api_fuzz_batch_assert( 'published' === ( $coordinator_state['status'] ?? null ) && realpath( $output . '/.complete' ) === realpath( (string) ( $coordinator_state['completionMarker'] ?? '' ) ), 'Expected published state to defer authoritative completion to the root marker.' );
$root_seal = json_decode( (string) file_get_contents( $output . '/coordinator-seal.json' ), true );
$root_seal_paths = array_column( is_array( $root_seal['files'] ?? null ) ? $root_seal['files'] : array(), 'path' );
foreach ( array( 'preflight/batch-info.stdout', 'lexbor-source/run-seal.json', 'html5ever-source/run-seal.json', 'chrome-cdp/run-seal.json', 'shared-corpus.payload.json' ) as $sealed_path ) {
	html_api_fuzz_batch_assert( in_array( $sealed_path, $root_seal_paths, true ), "Expected coordinator root seal to cover {$sealed_path}." );
}

foreach ( array(
	'unknown option' => array( '--unexpected-coordinator-option' ),
	'missing value' => array( '--max-depth' ),
	'invalid boolean' => array( '--retain-all=maybe' ),
) as $label => $invalid_arguments ) {
	$invalid_process = \HtmlApiFuzz\run_php_process( array_merge( $args, $invalid_arguments ), \HtmlApiFuzz\repo_root(), 30000 );
	html_api_fuzz_batch_assert( 0 !== ( $invalid_process['code'] ?? 0 ), "Expected {$label} rejection." );
}

foreach ( array( 'malformed-info', 'malformed-verify', 'nonzero-info', 'truncate-info', 'timeout-info' ) as $mode ) {
	file_put_contents( $workspace . '/control.json', json_encode( array( 'mode' => $mode ), JSON_THROW_ON_ERROR ) . "\n" );
	$failure_output = $work_dir . '/' . $mode;
	$failure_args = html_api_fuzz_batch_replace_arg( $args, '--output-dir', $failure_output );
	if ( 'timeout-info' === $mode ) {
		$failure_args = html_api_fuzz_batch_replace_arg( $failure_args, '--batch-timeout-ms', '100' );
	}
	$failure_process = \HtmlApiFuzz\run_php_process( $failure_args, \HtmlApiFuzz\repo_root(), 30000 );
	html_api_fuzz_batch_assert( 0 !== ( $failure_process['code'] ?? 0 ), "Expected {$mode} rejection." );
	html_api_fuzz_batch_assert( ! is_file( $failure_output . '/.complete' ) && ! is_file( $failure_output . '/shared-corpus.json' ), "Expected no shared success for {$mode}." );
}
file_put_contents( $workspace . '/control.json', "{}\n" );

$cache_drift = html_api_fuzz_batch_failure_scenario( $args, $work_dir, $batch_name, 'live-cache-drift', array( 'mode' => 'mutate-cache', 'triggerRun' => 1 ) );
$cache_drift_error = (string) ( $cache_drift['process']['stderr'] ?? '' );
html_api_fuzz_batch_assert( false !== strpos( $cache_drift_error, 'Batch cache body identity mismatch' ) || false !== strpos( $cache_drift_error, 'Cached batch corpus changed' ), 'Expected live cache mutation to fail a post-run cache read.' );

$raw_lexbor_root = $work_dir . '/raw-lexbor';
$raw_lexbor_build = $raw_lexbor_root . '/build';
\HtmlApiFuzz\ensure_dir( $raw_lexbor_build );
$raw_lexbor_binary = $raw_lexbor_build . '/lexbor-tree-oracle';
$raw_lexbor_manifest = $raw_lexbor_build . '/build-manifest.json';
html_api_fuzz_batch_assert( copy( dirname( __DIR__ ) . '/oracles/lexbor/build/lexbor-tree-oracle', $raw_lexbor_binary ) && chmod( $raw_lexbor_binary, 0500 ), 'Expected private Lexbor binary copy.' );
html_api_fuzz_batch_assert( copy( dirname( __DIR__ ) . '/oracles/lexbor/build/build-manifest.json', $raw_lexbor_manifest ), 'Expected private Lexbor manifest copy.' );
$raw_oracle_drift = html_api_fuzz_batch_failure_scenario(
	$args,
	$work_dir,
	$batch_name,
	'raw-oracle-drift',
	array( 'mode' => 'mutate-trust-file', 'triggerRun' => 1, 'mutationPath' => $raw_lexbor_manifest ),
	array( '--lexbor-oracle-bin' => $raw_lexbor_binary )
);
html_api_fuzz_batch_assert( false !== strpos( (string) ( $raw_oracle_drift['process']['stderr'] ?? '' ), 'Global trust bundle changed after lexbor-source.' ), 'Expected raw build-manifest byte drift to fail despite normalized Lexbor identity.' );

$mode_oracle_drift = html_api_fuzz_batch_failure_scenario(
	$args,
	$work_dir,
	$batch_name,
	'raw-oracle-mode-drift',
	array( 'mode' => 'chmod-trust-file', 'triggerRun' => 1, 'mutationPath' => $raw_lexbor_manifest ),
	array( '--lexbor-oracle-bin' => $raw_lexbor_binary )
);
html_api_fuzz_batch_assert( false !== strpos( (string) ( $mode_oracle_drift['process']['stderr'] ?? '' ), 'Global trust bundle changed after lexbor-source.' ), 'Expected raw trust-file permission drift to fail with unchanged bytes.' );

$final_drift = html_api_fuzz_batch_failure_scenario( $args, $work_dir, $batch_name, 'final-run-phar-drift', array( 'mode' => 'self-change', 'triggerRun' => 3 ) );
html_api_fuzz_batch_assert( false !== strpos( (string) ( $final_drift['process']['stderr'] ?? '' ), 'Global trust bundle changed after chrome-cdp.' ), 'Expected final-run PHAR mutation to fail the Chrome post-run trust comparison.' );

$prior_evidence_tamper = html_api_fuzz_batch_failure_scenario( $args, $work_dir, $batch_name, 'prior-evidence-tamper', array( 'mode' => 'tamper-previous-evidence', 'triggerRun' => 2 ) );
html_api_fuzz_batch_assert( false !== strpos( (string) ( $prior_evidence_tamper['process']['stderr'] ?? '' ), 'lexbor-source summary is partial or contains extra documents.' ), 'Expected final disk revalidation to reject evidence changed by a later analyzer run.' );

$publication_failure = html_api_fuzz_batch_failure_scenario( $args, $work_dir, $batch_name, 'completion-publication-failure', array( 'mode' => 'block-root-complete', 'triggerRun' => 3 ) );
$failed_state = json_decode( (string) file_get_contents( $publication_failure['output'] . '/coordinator-state.json' ), true );
html_api_fuzz_batch_assert( 'failed' === ( $failed_state['status'] ?? null ), 'Expected a failed state when the authoritative root completion marker cannot be published.' );

$manifest = json_decode( (string) file_get_contents( $output . '/batch-manifest.json' ), true );
$lexbor = $shared['runs']['lexbor-source'];
$identity = $shared['trust']['oracles']['lexbor-source']['identitySha256'];
$validate_batch_result = Closure::bind(
	static fn ( $value, $expected_batch, $callback_path, $callback_sha256 ) => \HtmlApiFuzz\CommonCrawlBatchCoordinator::validate_batch_run_result( $value, $expected_batch, $callback_path, $callback_sha256 ),
	null,
	\HtmlApiFuzz\CommonCrawlBatchCoordinator::class
);
$valid_batch_result = json_decode( (string) file_get_contents( $output . '/lexbor-source/batch-run.stdout' ), true );
$callback_path = realpath( dirname( __DIR__ ) . '/commoncrawl-analysis.php' );
$callback_sha256 = $shared['trust']['callback']['sha256'];
foreach ( array( 'outer-schema', 'batch-metadata', 'callback-hash', 'document-count', 'run-schema' ) as $variant ) {
	$invalid = $valid_batch_result;
	if ( 'outer-schema' === $variant ) {
		$invalid['unexpected'] = true;
	} elseif ( 'batch-metadata' === $variant ) {
		$invalid['batch']['crawlId'] = 'CC-MAIN-WRONG';
	} elseif ( 'callback-hash' === $variant ) {
		$invalid['run']['analysisScriptHash'] = str_repeat( '0', 64 );
	} elseif ( 'document-count' === $variant ) {
		++$invalid['documentsAnalyzed'];
		++$invalid['run']['documentsAnalyzed'];
	} elseif ( 'run-schema' === $variant ) {
		$invalid['run']['unexpected'] = true;
	}
	html_api_fuzz_batch_expect_failure(
		static fn () => $validate_batch_result( $invalid, $manifest['batch'], $callback_path, $callback_sha256 ),
		"Expected {$variant} batch-result rejection."
	);
}

$assert_trust = Closure::bind(
	static fn ( $expected, $actual, $when ) => \HtmlApiFuzz\CommonCrawlBatchCoordinator::assert_same_trust( $expected, $actual, $when ),
	null,
	\HtmlApiFuzz\CommonCrawlBatchCoordinator::class
);
$trust_mutations = array(
	'runtime-before-lexbor' => array( 'runtime', 'runtimeSha256' ),
	'repository-after-html5ever' => array( 'repository', 'codeSha256' ),
	'raw-oracle-before-chrome' => array( 'oracleTrustFiles', 'lexborBuildManifest', 'sha256' ),
	'raw-oracle-mode-after-chrome' => array( 'oracleTrustFiles', 'lexborBuildManifest', 'mode' ),
	'oracle-final' => array( 'oracles', 'chrome-cdp', 'identitySha256' ),
);
foreach ( $trust_mutations as $label => $path ) {
	$changed = $shared['trust'];
	$cursor =& $changed;
	foreach ( array_slice( $path, 0, -1 ) as $key ) {
		$cursor =& $cursor[ $key ];
	}
	$leaf = $path[ count( $path ) - 1 ];
	$cursor[ $leaf ] = 'mode' === $leaf ? '100600' : str_repeat( '0', 64 );
	unset( $cursor );
	html_api_fuzz_batch_expect_failure( static fn () => $assert_trust( $shared['trust'], $changed, $label ), "Expected {$label} trust rejection." );
}

$assert_process = Closure::bind(
	static fn ( $process, $label ) => \HtmlApiFuzz\CommonCrawlBatchCoordinator::assert_process_success( $process, $label ),
	null,
	\HtmlApiFuzz\CommonCrawlBatchCoordinator::class
);
html_api_fuzz_batch_expect_failure(
	static fn () => $assert_process( array( 'code' => 0, 'timedOut' => false, 'stdoutTruncated' => false, 'stderrTruncated' => false, 'processGroupCleanupFailed' => true ), 'cleanup fixture' ),
	'Expected process-group cleanup failure rejection.'
);

$canonical_json = Closure::bind(
	static fn ( $value ) => \HtmlApiFuzz\CommonCrawlBatchCoordinator::canonical_json( $value ),
	null,
	\HtmlApiFuzz\CommonCrawlBatchCoordinator::class
);
$variant_root = $work_dir . '/summary-variants';
\HtmlApiFuzz\ensure_dir( $variant_root );
foreach ( array( 'partial', 'duplicate', 'reorder', 'wrong-hash', 'wrong-config', 'wrong-setting', 'wrong-setting-rehashed', 'wrong-environment' ) as $variant ) {
	$variant_dir = $variant_root . '/' . $variant;
	\HtmlApiFuzz\ensure_dir( $variant_dir );
	$summary_lines = file( $output . '/lexbor-source/commoncrawl-summary.ndjson', FILE_IGNORE_NEW_LINES );
	$configuration = json_decode( (string) file_get_contents( $output . '/lexbor-source/configuration.json' ), true );
	if ( 'partial' === $variant ) {
		array_pop( $summary_lines );
	} elseif ( 'duplicate' === $variant ) {
		$summary_lines[] = $summary_lines[0];
	} elseif ( 'reorder' === $variant ) {
		$summary_lines = array_reverse( $summary_lines );
	} elseif ( 'wrong-hash' === $variant ) {
		$row = json_decode( $summary_lines[0], true );
		$row['inputSha256'] = str_repeat( '0', 64 );
		$summary_lines[0] = json_encode( $row, JSON_UNESCAPED_SLASHES );
	} elseif ( 'wrong-config' === $variant ) {
		$configuration['ccAnalyzer']['coordinator']['runtimeSha256'] = str_repeat( '0', 64 );
	} elseif ( 'wrong-setting' === $variant ) {
		$configuration['checks'] = 'full';
	} elseif ( 'wrong-setting-rehashed' === $variant ) {
		$configuration['checks'] = 'full';
		$hash_material = $configuration;
		unset( $hash_material['configHash'], $hash_material['createdAt'] );
		$configuration['configHash'] = hash( 'sha256', $canonical_json( $hash_material ) );
		foreach ( $summary_lines as &$summary_line ) {
			$summary_record = json_decode( $summary_line, true );
			$summary_record['configHash'] = $configuration['configHash'];
			$summary_line = json_encode( $summary_record, JSON_UNESCAPED_SLASHES );
		}
		unset( $summary_line );
	}
	file_put_contents( $variant_dir . '/commoncrawl-summary.ndjson', implode( "\n", $summary_lines ) . "\n" );
	file_put_contents( $variant_dir . '/configuration.json', json_encode( $configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	$environment_evidence = json_decode( (string) file_get_contents( $output . '/lexbor-source/replacement-environment.json' ), true );
	if ( 'wrong-environment' === $variant ) {
		$environment_evidence['environment']['HTML_API_CC_CHECKS'] = 'full';
	}
	file_put_contents( $variant_dir . '/replacement-environment.json', json_encode( $environment_evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	html_api_fuzz_batch_expect_failure(
		static fn () => \HtmlApiFuzz\CommonCrawlBatchCoordinator::verify_run_output( $variant_dir, $manifest, $lexbor['provenance']['id'] . '-lexbor-source', 'lexbor-source', $identity, $lexbor['provenance'], $lexbor['environment'] ),
		"Expected {$variant} shared-summary rejection."
	);
}

$database_info = array_merge( $manifest['batch'], array( 'runCount' => 3 ) );
$db = new SQLite3( $fixture['path'] );
$original_body = $fixture['documents'][0]['body'];
$db->exec( "UPDATE cached_documents SET body = body || 'x' WHERE record_id = 'urn:uuid:batch-one'" );
$db->close();
html_api_fuzz_batch_expect_failure(
	static fn () => \HtmlApiFuzz\CommonCrawlBatchCoordinator::load_batch_manifest( $fixture['path'], $database_info ),
	'Expected cache body checksum rejection.'
);
$db = new SQLite3( $fixture['path'] );
$restore = $db->prepare( 'UPDATE cached_documents SET body = :body WHERE record_id = :record' );
$restore->bindValue( ':body', $original_body, SQLITE3_BLOB );
$restore->bindValue( ':record', 'urn:uuid:batch-one', SQLITE3_TEXT );
$restore->execute();
$db->close();
foreach ( array(
	'HTTP response range' => array(
		"UPDATE document_batch_documents SET response_code = 99 WHERE record_id = 'urn:uuid:batch-one'; UPDATE cached_documents SET response_code = 99 WHERE record_id = 'urn:uuid:batch-one'",
		"UPDATE document_batch_documents SET response_code = 200 WHERE record_id = 'urn:uuid:batch-one'; UPDATE cached_documents SET response_code = 200 WHERE record_id = 'urn:uuid:batch-one'",
	),
	'HTTP response type' => array(
		"UPDATE document_batch_documents SET response_code = 'oops' WHERE record_id = 'urn:uuid:batch-one'; UPDATE cached_documents SET response_code = 'oops' WHERE record_id = 'urn:uuid:batch-one'",
		"UPDATE document_batch_documents SET response_code = 200 WHERE record_id = 'urn:uuid:batch-one'; UPDATE cached_documents SET response_code = 200 WHERE record_id = 'urn:uuid:batch-one'",
	),
	'content type' => array(
		"UPDATE cached_documents SET content_type = '' WHERE record_id = 'urn:uuid:batch-one'",
		"UPDATE cached_documents SET content_type = 'text/html' WHERE record_id = 'urn:uuid:batch-one'",
	),
	'transport charset' => array(
		"UPDATE cached_documents SET transport_charset = '' WHERE record_id = 'urn:uuid:batch-one'",
		"UPDATE cached_documents SET transport_charset = 'UTF-8' WHERE record_id = 'urn:uuid:batch-one'",
	),
	'batch requested-limit type' => array(
		"UPDATE document_batches SET requested_limit = 'oops' WHERE name = 'coordinator-smoke'",
		"UPDATE document_batches SET requested_limit = 2 WHERE name = 'coordinator-smoke'",
	),
) as $label => $queries ) {
	$db = new SQLite3( $fixture['path'] );
	html_api_fuzz_batch_assert( $db->exec( $queries[0] ), "Expected {$label} fixture mutation." );
	$db->close();
	html_api_fuzz_batch_expect_failure(
		static fn () => \HtmlApiFuzz\CommonCrawlBatchCoordinator::load_batch_manifest( $fixture['path'], $database_info ),
		"Expected invalid {$label} metadata rejection."
	);
	$db = new SQLite3( $fixture['path'] );
	html_api_fuzz_batch_assert( $db->exec( $queries[1] ), "Expected {$label} fixture restoration." );
	$db->close();
}
$db = new SQLite3( $fixture['path'] );
$db->exec( "UPDATE document_batch_documents SET sequence = 3 WHERE record_id = 'urn:uuid:batch-two'" );
$db->close();
html_api_fuzz_batch_expect_failure(
	static fn () => \HtmlApiFuzz\CommonCrawlBatchCoordinator::load_batch_manifest( $fixture['path'], $database_info ),
	'Expected gapped sequence rejection.'
);

$preexisting = $work_dir . '/preexisting';
\HtmlApiFuzz\ensure_dir( $preexisting );
$preexisting_args = html_api_fuzz_batch_replace_arg( $args, '--output-dir', $preexisting );
$preexisting_process = \HtmlApiFuzz\run_php_process( $preexisting_args, \HtmlApiFuzz\repo_root(), 30000 );
html_api_fuzz_batch_assert( 0 !== ( $preexisting_process['code'] ?? 0 ), 'Expected pre-existing output rejection.' );

\HtmlApiFuzz\remove_dir_recursive( $work_dir );
echo "OK commoncrawl-batch-coordinator-smoke\n";
