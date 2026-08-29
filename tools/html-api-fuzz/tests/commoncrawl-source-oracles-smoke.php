#!/usr/bin/env php
<?php

namespace CcAnalyzer\Analysis {
	class HtmlAnalysisInput {
		public function __construct(
			public string $recordId,
			public string $targetUri,
			public int $responseCode,
			public string $contentType,
			public ?string $transportCharset,
			public string $body,
			public string $inputStateKey,
			public ?int $rangeStart = null,
			public ?int $rangeLength = null
		) {}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/lib/autoload.php';

	function html_api_fuzz_cc_sources_fail( string $message ): void {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	function html_api_fuzz_cc_sources_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			html_api_fuzz_cc_sources_fail( $message );
		}
	}

	function html_api_fuzz_cc_sources_process( array $arguments, int $timeout_ms = 60000 ): array {
		return \HtmlApiFuzz\run_php_process( $arguments, \HtmlApiFuzz\repo_root(), $timeout_ms );
	}

	$lexbor_binary = \HtmlApiFuzz\repo_root() . '/tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle';
	$html5ever_binary = \HtmlApiFuzz\repo_root() . '/tools/html-api-fuzz/oracles/html5ever/build/html5ever-tree-oracle';
	if ( ! is_executable( $lexbor_binary ) || ! is_executable( $html5ever_binary ) ) {
		echo "SKIP commoncrawl-source-oracles-smoke: build both source oracles first\n";
		exit( 0 );
	}

	$work_dir = sys_get_temp_dir() . '/html-api-fuzz-commoncrawl-sources-' . getmypid();
	\HtmlApiFuzz\ensure_dir( $work_dir );
	$body = '<!doctype html><html><head><title>T</title></head><body><p>Hello</p></body></html>';
	$source_cases = array(
		\HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE => array( 'binary' => $lexbor_binary, 'option' => 'lexborOracleBin' ),
		\HtmlApiFuzz\OracleRenderer::KIND_HTML5EVER_SOURCE => array( 'binary' => $html5ever_binary, 'option' => 'html5everOracleBin' ),
		\HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP => array( 'option' => 'chromeOracleScript' ),
	);
	$replays = array();
	$metadata_by_kind = array();

	putenv( 'HTML_API_FUZZ_LEXBOR_ORACLE=' . $lexbor_binary );
	putenv( 'HTML_API_FUZZ_HTML5EVER_ORACLE=' . $html5ever_binary );
	putenv( 'HTML_API_CC_RETAIN_ALL=1' );
	putenv( 'HTML_API_CC_REQUIRE_UTF8=1' );
	putenv( 'HTML_API_CC_CHECKS=baseline' );
	putenv( 'HTML_API_CC_PROCESS_TIMEOUT_MS=30000' );
	putenv( 'HTML_API_CC_ORACLE_TIMEOUT_MS=10000' );
	putenv( 'HTML_API_CC_MAX_INPUT_BYTES=4096' );
	putenv( 'HTML_API_CC_MAX_TOKENS=500' );
	putenv( 'HTML_API_CC_MAX_NODES=500' );
	putenv( 'HTML_API_CC_MAX_DEPTH=100' );
	putenv( 'HTML_API_CC_MAX_TREE_BYTES=1048576' );

	foreach ( $source_cases as $kind => $case ) {
		if ( \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP === $kind ) {
			putenv( 'HTML_API_CC_PROCESS_TIMEOUT_MS' );
			putenv( 'HTML_API_CC_CHECKS=sampled' );
			putenv( 'HTML_API_CC_FULL_SAMPLE_PERCENT=0' );
		} else {
			putenv( 'HTML_API_CC_PROCESS_TIMEOUT_MS=30000' );
			putenv( 'HTML_API_CC_CHECKS=baseline' );
			putenv( 'HTML_API_CC_FULL_SAMPLE_PERCENT' );
		}
		$oracle = \HtmlApiFuzz\OracleRenderer::from_options(
			array(
				'dom-oracle' => $kind,
				'lexbor-oracle-bin' => $lexbor_binary,
				'html5ever-oracle-bin' => $html5ever_binary,
				'oracle-timeout-ms' => '10000',
			)
		);
		$metadata = $oracle->metadata();
		html_api_fuzz_cc_sources_assert( true === ( $metadata['available'] ?? false ), "Expected {$kind} availability: " . (string) ( $metadata['error'] ?? '' ) );
		$oracle_replay_options = $oracle->replay_options();
		$oracle->close();
		$metadata_by_kind[ $kind ] = $metadata;
		$output_dir = $work_dir . '/' . $kind;
		putenv( 'CC_ANALYZER_OUTPUT_DIR=' . $output_dir );
		putenv( 'HTML_API_CC_ORACLE=' . $kind );
		putenv( 'HTML_API_CC_EXPECT_ORACLE_IDENTITY_SHA256=' . \HtmlApiFuzz\OracleRenderer::identity_sha256( $metadata ) );
		if ( \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE === $kind ) {
			putenv( 'HTML_API_CC_EXPECT_LEXBOR_COMMIT=' . $metadata['identity']['lexborCommit'] );
		} else {
			putenv( 'HTML_API_CC_EXPECT_LEXBOR_COMMIT' );
		}
		$runner = \HtmlApiFuzz\CommonCrawlRunner::from_environment();
		$summary = $runner->analyze_document(
			new \CcAnalyzer\Analysis\HtmlAnalysisInput(
				'urn:uuid:source-oracle-document',
				'https://example.com/source-oracle',
				200,
				'text/html; charset=UTF-8',
				'UTF-8',
				$body,
				'fixture:source-oracle'
			)
		);
		html_api_fuzz_cc_sources_assert( true === ( $summary['ok'] ?? null ), "Expected the exact retained document to pass {$kind}." );
		html_api_fuzz_cc_sources_assert( true === ( $summary['differentialCovered'] ?? null ), "Expected {$kind} differential coverage." );
		html_api_fuzz_cc_sources_assert( true === ( $summary['artifactsRetained'] ?? null ), "Expected retained {$kind} artifacts." );
		html_api_fuzz_cc_sources_assert( $metadata === ( $summary['oracle'] ?? null ), "Expected normalized {$kind} summary identity." );
		$artifact_dir = $summary['artifactDir'] ?? null;
		html_api_fuzz_cc_sources_assert( is_string( $artifact_dir ) && is_file( $artifact_dir . '/.complete' ), "Expected complete {$kind} artifact publication." );
		html_api_fuzz_cc_sources_assert( $body === file_get_contents( $artifact_dir . '/input.bin' ), "Expected byte-exact {$kind} input retention." );
		$replay = \HtmlApiFuzz\read_json_file( $artifact_dir . '/replay.json' );
		$configuration = \HtmlApiFuzz\read_json_file( $output_dir . '/configuration.json' );
		html_api_fuzz_cc_sources_assert( $metadata === ( $replay['oracle'] ?? null ), "Expected normalized {$kind} replay identity." );
		html_api_fuzz_cc_sources_assert( $body === base64_decode( $replay['inputBase64'] ?? '', true ), "Expected exact {$kind} replay bytes." );
		html_api_fuzz_cc_sources_assert( $kind === ( $replay['options']['domOracle'] ?? null ), "Expected {$kind} replay selection." );
		$expected_path = $case['binary'] ?? $oracle_replay_options[ $case['option'] ] ?? null;
		html_api_fuzz_cc_sources_assert( $expected_path === ( $replay['options'][ $case['option'] ] ?? null ), "Expected {$kind} replay executable/script path." );
		foreach ( array( 'lexborOracleBin', 'html5everOracleBin' ) as $source_option ) {
			if ( $source_option !== $case['option'] ) {
				html_api_fuzz_cc_sources_assert( ! array_key_exists( $source_option, $replay['options'] ), "Expected no stale {$source_option} for {$kind}." );
			}
		}
		if ( \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP === $kind ) {
			html_api_fuzz_cc_sources_assert( is_string( $replay['options']['chromeExecutable'] ?? null ), 'Expected durable Chrome executable replay path.' );
			html_api_fuzz_cc_sources_assert( is_string( $replay['options']['nodeBin'] ?? null ), 'Expected durable Node replay path.' );
			html_api_fuzz_cc_sources_assert( 35000 === ( $replay['options']['chromeStartupTimeoutMs'] ?? null ), 'Expected effective Chrome startup timeout.' );
			html_api_fuzz_cc_sources_assert( 90000 === ( $replay['options']['processTimeoutMs'] ?? null ), 'Expected sampled Common Crawl Chrome process budget.' );
		}
		html_api_fuzz_cc_sources_assert( \HtmlApiFuzz\OracleRenderer::identity_sha256( $metadata ) === ( $configuration['oracleIdentitySha256'] ?? null ), "Expected pinned {$kind} configuration identity." );
		html_api_fuzz_cc_sources_assert( $metadata === ( $configuration['oracle'] ?? null ), "Expected normalized {$kind} configuration metadata." );
		$replays[ $kind ] = $replay;
	}

	$source_replay = $replays[ \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE ];
	$chrome_replay = $replays[ \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP ];
	foreach ( array( 'chromeStartupTimeoutMs' => 1.5, 'chromeExecutable' => '' ) as $field => $invalid_value ) {
		$invalid_chrome = $chrome_replay;
		$invalid_chrome['options'][ $field ] = $invalid_value;
		$invalid_chrome_path = $work_dir . '/invalid-chrome-' . $field . '.json';
		$invalid_chrome_output = $work_dir . '/invalid-chrome-' . $field;
		\HtmlApiFuzz\write_json_file_atomic( $invalid_chrome_path, $invalid_chrome );
		$invalid_chrome_process = html_api_fuzz_cc_sources_process(
			array( dirname( __DIR__ ) . '/replay.php', '--replay', $invalid_chrome_path, '--output-dir', $invalid_chrome_output )
		);
		html_api_fuzz_cc_sources_assert( 1 === $invalid_chrome_process['code'] && ! is_dir( $invalid_chrome_output ), "Expected invalid recorded {$field} rejection before output creation." );
	}
	$tampered = $source_replay;
	$tampered['oracle']['identity']['binarySha256'] = str_repeat( '0', 64 );
	$tampered_path = $work_dir . '/tampered-replay.json';
	\HtmlApiFuzz\write_json_file_atomic( $tampered_path, $tampered );
	$rejected_replay_dir = $work_dir . '/tampered-replay-output';
	$rejected_replay = html_api_fuzz_cc_sources_process(
		array( dirname( __DIR__ ) . '/replay.php', '--replay', $tampered_path, '--output-dir', $rejected_replay_dir )
	);
	html_api_fuzz_cc_sources_assert( 1 === $rejected_replay['code'] && ! is_dir( $rejected_replay_dir ), 'Expected replay identity rejection before output creation or claim.' );
	$rejected_minimize_dir = $work_dir . '/tampered-minimize-output';
	$rejected_minimize = html_api_fuzz_cc_sources_process(
		array( dirname( __DIR__ ) . '/minimize.php', '--replay', $tampered_path, '--output-dir', $rejected_minimize_dir, '--any-failure', '--max-attempts', '1' )
	);
	html_api_fuzz_cc_sources_assert( 1 === $rejected_minimize['code'] && ! is_dir( $rejected_minimize_dir ), 'Expected minimizer identity rejection before output creation.' );

	$allowed_replay_dir = $work_dir . '/allowed-replay-output';
	$allowed_replay = html_api_fuzz_cc_sources_process(
		array( dirname( __DIR__ ) . '/replay.php', '--replay', $tampered_path, '--output-dir', $allowed_replay_dir, '--allow-oracle-mismatch' )
	);
	html_api_fuzz_cc_sources_assert( 0 === $allowed_replay['code'], 'Expected deliberately allowed diagnostic replay.' );
	$allowed_replay_manifest = \HtmlApiFuzz\read_json_file( $allowed_replay_dir . '/replay.json' );
	$allowed_replay_result = \HtmlApiFuzz\read_json_file( $allowed_replay_dir . '/result.json' );
	html_api_fuzz_cc_sources_assert( $tampered['oracle'] === ( $allowed_replay_manifest['sourceOracle'] ?? null ), 'Expected allowed replay source oracle provenance.' );
	html_api_fuzz_cc_sources_assert( $metadata_by_kind[ \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE ] === ( $allowed_replay_manifest['actualOracle'] ?? null ), 'Expected allowed replay actual oracle provenance.' );
	html_api_fuzz_cc_sources_assert( ! empty( $allowed_replay_manifest['oracleIdentityMismatches'] ?? array() ), 'Expected allowed replay mismatch reasons.' );
	html_api_fuzz_cc_sources_assert( $tampered['oracle'] === ( $allowed_replay_result['sourceOracle'] ?? null ), 'Expected result-level allowed replay source provenance.' );

	$allowed_minimize_dir = $work_dir . '/allowed-minimize-output';
	$allowed_minimize = html_api_fuzz_cc_sources_process(
		array( dirname( __DIR__ ) . '/minimize.php', '--replay', $tampered_path, '--output-dir', $allowed_minimize_dir, '--allow-oracle-mismatch', '--any-failure', '--max-attempts', '1' ),
		120000
	);
	html_api_fuzz_cc_sources_assert( 1 === $allowed_minimize['code'], 'Expected allowed minimization to finish without falsely finding a failure.' );
	$minimize_summary = \HtmlApiFuzz\read_json_file( $allowed_minimize_dir . '/minimize-result.json' );
	html_api_fuzz_cc_sources_assert( false === ( $minimize_summary['ok'] ?? null ), 'Expected passing final verification not to satisfy any-failure minimization.' );
	html_api_fuzz_cc_sources_assert( $tampered['oracle'] === ( $minimize_summary['sourceOracle'] ?? null ), 'Expected minimizer source oracle provenance.' );
	html_api_fuzz_cc_sources_assert( $metadata_by_kind[ \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE ] === ( $minimize_summary['actualOracle'] ?? null ), 'Expected minimizer actual oracle provenance.' );
	html_api_fuzz_cc_sources_assert( ! empty( $minimize_summary['oracleIdentityMismatches'] ?? array() ), 'Expected minimizer mismatch reasons.' );

	$php_oracle = array(
		'schemaVersion' => 1,
		'kind'          => \HtmlApiFuzz\OracleRenderer::KIND_PHP_DOM,
		'available'     => true,
		'identity'      => array(
			'schemaVersion'   => 1,
			'kind'            => \HtmlApiFuzz\OracleRenderer::KIND_PHP_DOM,
			'phpVersion'      => PHP_VERSION,
			'phpVersionId'    => PHP_VERSION_ID,
			'phpSapi'         => PHP_SAPI,
			'zendVersion'     => zend_version(),
			'libxmlVersion'   => defined( 'LIBXML_DOTTED_VERSION' ) ? LIBXML_DOTTED_VERSION : null,
			'domHtmlDocument' => true,
		),
		'error'         => null,
	);
	$drift_worker = $work_dir . '/signed-drift-worker.php';
	$drift_worker_source = <<<'PHP'
#!/usr/bin/env php
<?php
$options = array();
for ( $i = 1; $i < count( $argv ); ++$i ) {
	if ( str_starts_with( $argv[ $i ], '--' ) && isset( $argv[ $i + 1 ] ) && ! str_starts_with( $argv[ $i + 1 ], '--' ) ) {
		$options[ substr( $argv[ $i ], 2 ) ] = $argv[ ++$i ];
	}
}
$output = $options['output-dir'];
$input = file_get_contents( $options['input-file'] );
$oracle = json_decode( base64_decode( getenv( 'HTML_API_FUZZ_TEST_DRIFT_ORACLE' ), true ), true );
$result = array(
	'schemaVersion' => 1,
	'kind' => 'html-api-fuzz-worker-result',
	'createdAt' => gmdate( 'c' ),
	'ok' => false,
	'status' => 'failed',
	'failureClass' => 'tree-mismatch',
	'failureSnippet' => 'pre-signed stale worker failure',
	'seed' => (int) ( $options['seed'] ?? 1 ),
	'profile' => $options['profile'] ?? 'commoncrawl',
	'mode' => $options['mode'] ?? 'full-document',
	'checks' => $options['checks'] ?? 'baseline',
	'inputSha1' => sha1( $input ),
	'inputLength' => strlen( $input ),
	'oracle' => $oracle,
	'signature' => array( 'hash' => 'stale-signed-hash', 'familyKey' => 'stale-signed-family' ),
);
file_put_contents( $output . '/result.json', json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n" );
file_put_contents( $output . '/replay.json', json_encode( array( 'kind' => 'html-api-fuzz-replay', 'inputBase64' => base64_encode( $input ), 'oracle' => $oracle, 'signature' => $result['signature'] ), JSON_UNESCAPED_SLASHES ) . "\n" );
exit( 2 );
PHP;
	html_api_fuzz_cc_sources_assert( strlen( $drift_worker_source ) === file_put_contents( $drift_worker, $drift_worker_source ), 'Expected signed drift worker fixture.' );
	chmod( $drift_worker, 0500 );
	putenv( 'HTML_API_FUZZ_TEST_DRIFT_ORACLE=' . base64_encode( json_encode( $php_oracle, JSON_UNESCAPED_SLASHES ) ) );
	putenv( 'CC_ANALYZER_OUTPUT_DIR=' . $work_dir . '/worker-drift' );
	putenv( 'HTML_API_CC_ORACLE=' . \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE );
	putenv( 'HTML_API_CC_EXPECT_LEXBOR_COMMIT=' . $metadata_by_kind[ \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE ]['identity']['lexborCommit'] );
	putenv( 'HTML_API_CC_EXPECT_ORACLE_IDENTITY_SHA256=' . \HtmlApiFuzz\OracleRenderer::identity_sha256( $metadata_by_kind[ \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE ] ) );
	putenv( 'HTML_API_CC_WORKER_SCRIPT=' . $drift_worker );
	$drift_runner = \HtmlApiFuzz\CommonCrawlRunner::from_environment();
	$drift_summary = $drift_runner->analyze_document(
		new \CcAnalyzer\Analysis\HtmlAnalysisInput( 'urn:uuid:signed-drift', 'https://example.com/drift', 200, 'text/html', 'UTF-8', $body, 'fixture:signed-drift' )
	);
	html_api_fuzz_cc_sources_assert( 'oracle-identity-drift' === ( $drift_summary['failureClass'] ?? null ), 'Expected Common Crawl worker identity drift.' );
	html_api_fuzz_cc_sources_assert( 'stale-signed-hash' !== ( $drift_summary['signature']['hash'] ?? null ), 'Expected stale worker signature invalidation and recomputation.' );
	html_api_fuzz_cc_sources_assert( $metadata_by_kind[ \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE ] === ( $drift_summary['sourceOracle'] ?? null ), 'Expected drift source oracle provenance.' );
	html_api_fuzz_cc_sources_assert( $php_oracle === ( $drift_summary['actualOracle'] ?? null ), 'Expected drift actual oracle provenance.' );
	html_api_fuzz_cc_sources_assert( ! empty( $drift_summary['oracleIdentityMismatches'] ?? array() ), 'Expected worker drift mismatch reasons.' );
	$drift_replay = \HtmlApiFuzz\read_json_file( $drift_summary['artifactDir'] . '/replay.json' );
	html_api_fuzz_cc_sources_assert( $metadata_by_kind[ \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE ] === ( $drift_replay['sourceOracle'] ?? null ), 'Expected drift replay source oracle.' );
	html_api_fuzz_cc_sources_assert( $php_oracle === ( $drift_replay['actualOracle'] ?? null ), 'Expected drift replay actual oracle.' );

	foreach (
		array(
			'CC_ANALYZER_OUTPUT_DIR', 'HTML_API_CC_ORACLE', 'HTML_API_CC_EXPECT_LEXBOR_COMMIT', 'HTML_API_CC_EXPECT_ORACLE_IDENTITY_SHA256',
			'HTML_API_CC_WORKER_SCRIPT', 'HTML_API_FUZZ_LEXBOR_ORACLE', 'HTML_API_FUZZ_HTML5EVER_ORACLE', 'HTML_API_CC_RETAIN_ALL',
			'HTML_API_CC_REQUIRE_UTF8', 'HTML_API_CC_CHECKS', 'HTML_API_CC_FULL_SAMPLE_PERCENT', 'HTML_API_CC_PROCESS_TIMEOUT_MS', 'HTML_API_CC_ORACLE_TIMEOUT_MS',
			'HTML_API_CC_MAX_INPUT_BYTES', 'HTML_API_CC_MAX_TOKENS', 'HTML_API_CC_MAX_NODES', 'HTML_API_CC_MAX_DEPTH',
			'HTML_API_CC_MAX_TREE_BYTES', 'HTML_API_FUZZ_TEST_DRIFT_ORACLE',
		) as $environment_name
	) {
		putenv( $environment_name );
	}
	\HtmlApiFuzz\remove_dir_recursive( $work_dir );
	html_api_fuzz_cc_sources_assert( ! is_dir( $work_dir ), 'Expected Common Crawl source smoke cleanup.' );

	echo "OK commoncrawl-source-oracles-smoke\n";
}
