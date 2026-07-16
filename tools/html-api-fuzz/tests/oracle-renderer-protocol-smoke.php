#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

ini_set( 'memory_limit', '512M' );

function html_api_fuzz_protocol_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function html_api_fuzz_protocol_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_protocol_fail( $message );
	}
}

function html_api_fuzz_protocol_roots(): array {
	$roots = array_values(
		array_filter(
			glob( sys_get_temp_dir() . '/html-api-fuzz-oracle-*', GLOB_ONLYDIR ) ?: array(),
			static fn ( string $root ): bool => 1 === preg_match( '/^html-api-fuzz-oracle-[0-9a-f]{32}$/D', basename( $root ) )
		)
	);
	sort( $roots, SORT_STRING );
	return $roots;
}

function html_api_fuzz_protocol_wait_until( callable $condition, float $seconds, $message ): void {
	$deadline = microtime( true ) + $seconds;
	do {
		if ( $condition() ) {
			return;
		}
		usleep( 20000 );
	} while ( microtime( true ) < $deadline );
	html_api_fuzz_protocol_fail( is_callable( $message ) ? (string) $message() : (string) $message );
}

function html_api_fuzz_protocol_expect_strict_rejection( string $json, string $label ): void {
	try {
		\HtmlApiFuzz\StrictJsonParser::decode( $json );
	} catch ( RuntimeException $error ) {
		return;
	}
	html_api_fuzz_protocol_fail( "Expected strict JSON rejection for {$label}." );
}

function html_api_fuzz_protocol_expect_infrastructure( \HtmlApiFuzz\OracleRenderer $renderer, string $case, array $limits, string $label ): array {
	putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE=' . $case );
	$result = $renderer->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
	html_api_fuzz_protocol_assert( 'oracle-renderer-error' === ( $result['failureClass'] ?? null ), "Expected {$label} to be an oracle renderer failure." );
	html_api_fuzz_protocol_assert( true === ( $result['infrastructure'] ?? null ), "Expected {$label} to be marked as infrastructure." );
	html_api_fuzz_protocol_assert( true === ( $result['process']['cleanupVerified'] ?? null ), "Expected {$label} cleanup verification." );
	return $result;
}

function html_api_fuzz_protocol_kill_owner_at_phase( string $helper, string $binary, string $environment_name, string $work_dir ): void {
	$before = html_api_fuzz_protocol_roots();
	$marker = $work_dir . '/phase-' . strtolower( str_replace( 'HTML_API_FUZZ_TEST_PAUSE_AFTER_', '', $environment_name ) );
	@unlink( $marker );
	putenv( $environment_name . '=' . $marker );
	putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE=ok' );
	$spec = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'file', '/dev/null', 'a' ),
		2 => array( 'file', '/dev/null', 'a' ),
	);
	$process = proc_open( array( PHP_BINARY, $helper, $binary ), $spec, $pipes, \HtmlApiFuzz\repo_root(), null, array( 'bypass_shell' => true ) );
	html_api_fuzz_protocol_assert( is_resource( $process ), "Expected owner helper for {$environment_name}." );
	$status = proc_get_status( $process );
	$pid = (int) ( $status['pid'] ?? 0 );
	html_api_fuzz_protocol_assert( $pid > 1, "Expected owner PID for {$environment_name}." );
	html_api_fuzz_protocol_wait_until(
		static fn (): bool => is_file( $marker ) && count( array_diff( html_api_fuzz_protocol_roots(), $before ) ) > 0,
		5.0,
		"Expected {$environment_name} marker and ownership root."
	);
	html_api_fuzz_protocol_assert( posix_kill( $pid, SIGKILL ), "Expected to kill owner at {$environment_name}." );
	fclose( $pipes[0] );
	html_api_fuzz_protocol_wait_until(
		static fn (): bool => $before === html_api_fuzz_protocol_roots(),
		15.0,
		"Expected owner-EOF cleanup at {$environment_name}."
	);
	proc_close( $process );
	@unlink( $marker );
	putenv( $environment_name );
}

$initial_roots = html_api_fuzz_protocol_roots();
$work_dir = sys_get_temp_dir() . '/html-api-fuzz-protocol-' . getmypid();
\HtmlApiFuzz\ensure_dir( $work_dir );
$binary = $work_dir . '/fake-lexbor-oracle.php';
$fake_source = <<<'PHP'
#!/usr/bin/env php
<?php
ini_set( 'memory_limit', '512M' );
$oracle = array(
	'kind'          => 'lexbor-source',
	'lexborCommit'  => '0000000000000000000000000000000000000000',
	'lexborVersion' => 'protocol-test',
);
if ( in_array( '--version', $argv, true ) ) {
	echo json_encode( array( 'status' => 'ok', 'oracle' => $oracle ), JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}
$case = getenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE' ) ?: 'ok';
$ok = static function ( string $tree = "\n", int $nodes = 0 ) use ( $oracle ): string {
	return json_encode(
		array( 'status' => 'ok', 'oracle' => $oracle, 'tree' => $tree, 'treeBase64' => base64_encode( $tree ), 'nodeCount' => $nodes ),
		JSON_UNESCAPED_SLASHES
	);
};
$error = static function ( string $failure_class ) use ( $oracle ): string {
	return json_encode( array( 'status' => 'error', 'oracle' => $oracle, 'nodeCount' => 0, 'failureClass' => $failure_class, 'error' => 'test error' ), JSON_UNESCAPED_SLASHES );
};
switch ( $case ) {
	case 'ok':
		echo $ok() . "\n";
		exit( 0 );
	case 'unsupported':
		echo json_encode( array( 'status' => 'unsupported', 'oracle' => $oracle, 'nodeCount' => 0, 'failureClass' => 'oracle-unsupported', 'unsupported' => array( 'message' => 'test unsupported' ) ), JSON_UNESCAPED_SLASHES ) . "\n";
		exit( 0 );
	case 'renderer-error':
		echo $error( 'oracle-renderer-error' ) . "\n";
		exit( 1 );
	case 'mutation-infrastructure':
		$input_index = array_search( '--input', $argv, true );
		$input = false === $input_index ? '' : ( file_get_contents( $argv[ $input_index + 1 ] ?? '' ) ?: '' );
		if ( false !== strpos( $input, 'data-fuzz' ) ) {
			echo $error( 'oracle-renderer-error' ) . "\n";
			exit( 1 );
		}
		$tree = base64_decode( getenv( 'HTML_API_FUZZ_TEST_BASELINE_TREE_BASE64' ) ?: '', true );
		echo $ok( false === $tree ? '' : $tree, 2 ) . "\n";
		exit( 0 );
	case 'manifest-drift-during-render':
		$manifest_path = getenv( 'HTML_API_FUZZ_TEST_MANIFEST_PATH' );
		$manifest = file_get_contents( $manifest_path );
		file_put_contents( $manifest_path, $manifest . " " );
		echo $ok() . "\n";
		exit( 0 );
	case 'node-limit':
		echo $error( 'node-limit-exceeded' ) . "\n";
		exit( 1 );
	case 'ok-wrong-exit':
		echo $ok() . "\n";
		exit( 1 );
	case 'error-wrong-exit':
		echo $error( 'oracle-parse-error' ) . "\n";
		exit( 0 );
	case 'bad-outcome':
		echo $error( 'oracle-cli-error' ) . "\n";
		exit( 1 );
	case 'base64-mismatch':
		$payload = json_decode( $ok( "tree\n", 1 ), true );
		$payload['treeBase64'] = base64_encode( "other\n" );
		echo json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n";
		exit( 0 );
	case 'extra-key':
		$payload = json_decode( $ok(), true );
		$payload['extra'] = true;
		echo json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n";
		exit( 0 );
	case 'duplicate-nested':
		$json = $ok();
		$json = preg_replace( '/"kind":"lexbor-source"/', '"kind":"lexbor-source","kind":"lexbor-source"', $json, 1 );
		echo $json . "\n";
		exit( 0 );
	case 'trailing':
		echo $ok() . " {}\n";
		exit( 0 );
	case 'invalid-utf8':
		echo "{\"status\":\"\xFF\"}\n";
		exit( 0 );
	case 'invalid-escape':
		echo '{"status":"bad\q"}' . "\n";
		exit( 0 );
	case 'invalid-number':
		echo str_replace( '"nodeCount":0', '"nodeCount":01', $ok() ) . "\n";
		exit( 0 );
	case 'deep-json':
		echo '{"x":' . str_repeat( '[', 70 ) . '0' . str_repeat( ']', 70 ) . '}';
		exit( 0 );
	case 'stdout-overflow':
		echo str_repeat( 'x', 67108865 );
		exit( 0 );
	case 'stderr-overflow':
		fwrite( STDERR, str_repeat( 'e', 1048577 ) );
		echo $ok() . "\n";
		exit( 0 );
	case 'escaped-tree':
		echo $ok( str_repeat( '"', 16777216 ), 1 ) . "\n";
		exit( 0 );
	case 'timeout':
		while ( true ) {
			usleep( 100000 );
		}
	case 'fork-heartbeat':
		$marker = getenv( 'HTML_API_FUZZ_TEST_HEARTBEAT' );
		$pid_file = getenv( 'HTML_API_FUZZ_TEST_HEARTBEAT_PID' );
		$pid = pcntl_fork();
		if ( 0 === $pid ) {
			file_put_contents( $pid_file, getmypid() . "\n" );
			while ( true ) {
				file_put_contents( $marker, microtime( true ) . "\n", FILE_APPEND );
				usleep( 20000 );
			}
		}
		echo $ok() . "\n";
		exit( 0 );
	case 'kill-supervisor':
		$marker = getenv( 'HTML_API_FUZZ_TEST_HEARTBEAT' );
		$pid_file = getenv( 'HTML_API_FUZZ_TEST_HEARTBEAT_PID' );
		file_put_contents( $pid_file, getmypid() . "\n" );
		posix_kill( posix_getppid(), SIGKILL );
		while ( true ) {
			file_put_contents( $marker, microtime( true ) . "\n", FILE_APPEND );
			usleep( 20000 );
		}
}
exit( 2 );
PHP;
html_api_fuzz_protocol_assert( strlen( $fake_source ) === file_put_contents( $binary, $fake_source ), 'Expected fake source oracle publication.' );
html_api_fuzz_protocol_assert( chmod( $binary, 0500 ), 'Expected executable fake source oracle.' );
\HtmlApiFuzz\write_json_file(
	$work_dir . '/build-manifest.json',
	array(
		'kind'           => 'html-api-fuzz-lexbor-build',
		'requestedRef'   => 'protocol-test',
		'resolvedCommit' => str_repeat( '0', 40 ),
		'upstream'       => 'https://github.com/lexbor/lexbor.git',
		'builtAt'        => gmdate( 'c' ),
		'binarySha256'   => hash_file( 'sha256', $binary ),
		'compiler'       => 'protocol-test',
		'cmake'          => 'protocol-test',
	)
);

html_api_fuzz_protocol_assert( array( 'a' => array( 'b' => 1 ) ) === \HtmlApiFuzz\StrictJsonParser::decode( '{"a":{"b":1}}' ), 'Expected valid strict JSON parsing.' );
html_api_fuzz_protocol_expect_strict_rejection( '{"a":{"x":1,"x":2}}', 'duplicate nested keys' );
html_api_fuzz_protocol_expect_strict_rejection( '{} {}', 'trailing values' );
html_api_fuzz_protocol_expect_strict_rejection( "{\"x\":\"\xFF\"}", 'invalid UTF-8' );
html_api_fuzz_protocol_expect_strict_rejection( '{"x":"bad\q"}', 'invalid escape' );
html_api_fuzz_protocol_expect_strict_rejection( '{"x":01}', 'invalid number' );
html_api_fuzz_protocol_expect_strict_rejection( str_repeat( '[', 70 ) . '0' . str_repeat( ']', 70 ), 'excessive nesting' );

$limits = array( 'maxNodes' => 20, 'maxDepth' => 20, 'maxTreeBytes' => 16777216 );
$renderer = \HtmlApiFuzz\OracleRenderer::from_options(
	array(
		'dom-oracle'        => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		'lexbor-oracle-bin' => $binary,
		'oracle-timeout-ms' => '10000',
	)
);
$metadata = $renderer->metadata();
html_api_fuzz_protocol_assert( array( 'schemaVersion', 'kind', 'available', 'identity', 'error' ) === array_keys( $metadata ), 'Expected normalized exact source metadata.' );
html_api_fuzz_protocol_assert( true === $metadata['available'] && null === $metadata['error'], 'Expected fake source oracle availability: ' . (string) ( $metadata['error'] ?? '' ) );
html_api_fuzz_protocol_assert( hash_file( 'sha256', $binary ) === $metadata['identity']['binarySha256'], 'Expected binary identity pinning.' );

$manifest_path = $work_dir . '/build-manifest.json';
$manifest_raw = file_get_contents( $manifest_path );
$changed_manifest = \HtmlApiFuzz\StrictJsonParser::decode( $manifest_raw );
$changed_manifest['builtAt'] .= '-changed-after-metadata';
\HtmlApiFuzz\write_json_file_atomic( $manifest_path, $changed_manifest );
$manifest_drift = $renderer->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_protocol_assert( 'oracle-renderer-error' === ( $manifest_drift['failureClass'] ?? null ) && true === ( $manifest_drift['infrastructure'] ?? null ), 'Expected every render to reject a valid manifest whose non-normalized builtAt field changed after metadata.' );
\HtmlApiFuzz\write_file_atomic( $manifest_path, $manifest_raw );

putenv( 'HTML_API_FUZZ_TEST_MANIFEST_PATH=' . $manifest_path );
putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE=manifest-drift-during-render' );
$manifest_drift_after_launch = $renderer->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_protocol_assert( 'oracle-renderer-error' === ( $manifest_drift_after_launch['failureClass'] ?? null ) && true === ( $manifest_drift_after_launch['infrastructure'] ?? null ), 'Expected post-invocation identity validation to reject manifest drift during rendering.' );
html_api_fuzz_protocol_assert( true === ( $manifest_drift_after_launch['process']['cleanupVerified'] ?? null ), 'Expected verified cleanup before reporting post-invocation manifest drift.' );
\HtmlApiFuzz\write_file_atomic( $manifest_path, $manifest_raw );
putenv( 'HTML_API_FUZZ_TEST_MANIFEST_PATH' );

putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE=ok' );
$ok = $renderer->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_protocol_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $ok['status'] ?? null ) && "\n" === ( $ok['tree'] ?? null ), 'Expected exact ok outcome.' );
putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE=unsupported' );
$unsupported = $renderer->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_protocol_assert( \HtmlApiFuzz\TreeRenderer::STATUS_UNSUPPORTED === ( $unsupported['status'] ?? null ) && 'oracle-unsupported' === ( $unsupported['failureClass'] ?? null ), 'Expected exact unsupported outcome.' );
putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE=renderer-error' );
$renderer_error = $renderer->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_protocol_assert( true === ( $renderer_error['infrastructure'] ?? null ) && 'oracle-renderer-error' === ( $renderer_error['failureClass'] ?? null ), 'Expected validated renderer errors to remain infrastructure.' );

$worker_input = '<p>x</p>';
$worker_limits = array_merge( array( 'maxTokens' => 2000 ), $limits );
$wordpress_baseline = \HtmlApiFuzz\TreeRenderer::render_wordpress( $worker_input, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $worker_limits, 'body' );
html_api_fuzz_protocol_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $wordpress_baseline['status'] ?? null ), 'Expected a WordPress baseline tree for mutation infrastructure propagation.' );
putenv( 'HTML_API_FUZZ_TEST_BASELINE_TREE_BASE64=' . base64_encode( $wordpress_baseline['tree'] ) );
putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE=mutation-infrastructure' );
$mutation_infrastructure = \HtmlApiFuzz\Worker::evaluate_input( $worker_input, 1, 'replay', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, null, 'body', null, 'input-base64', $worker_limits, false, $renderer, 'full' );
html_api_fuzz_protocol_assert( 'mutation-oracle-render-error' === ( $mutation_infrastructure['failureClass'] ?? null ), 'Expected the mutation renderer failure class.' );
html_api_fuzz_protocol_assert( true === ( $mutation_infrastructure['mutation']['oracleInfrastructure'] ?? null ), 'Expected nested mutation infrastructure evidence.' );
html_api_fuzz_protocol_assert( true === ( $mutation_infrastructure['oracleInfrastructure'] ?? null ), 'Expected mutation infrastructure to propagate to the top-level worker result.' );
putenv( 'HTML_API_FUZZ_TEST_BASELINE_TREE_BASE64' );
putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE=node-limit' );
$node_limit = $renderer->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_protocol_assert( \HtmlApiFuzz\TreeRenderer::STATUS_ERROR === ( $node_limit['status'] ?? null ) && 'node-limit-exceeded' === ( $node_limit['failureClass'] ?? null ) && empty( $node_limit['infrastructure'] ), 'Expected a validated node-limit outcome.' );

foreach ( array( 'bad-outcome', 'base64-mismatch', 'extra-key', 'duplicate-nested', 'trailing', 'invalid-utf8', 'invalid-escape', 'invalid-number', 'deep-json', 'ok-wrong-exit', 'error-wrong-exit' ) as $case ) {
	html_api_fuzz_protocol_expect_infrastructure( $renderer, $case, $limits, $case );
}
putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE=escaped-tree' );
$escaped = $renderer->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_protocol_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $escaped['status'] ?? null ) && 16777216 === strlen( $escaped['tree'] ?? '' ), 'Expected exact 16 MiB high-escape tree transport at the decoded tree limit.' );
$stdout_overflow = html_api_fuzz_protocol_expect_infrastructure( $renderer, 'stdout-overflow', $limits, '64 MiB plus one stdout' );
html_api_fuzz_protocol_assert( true === ( $stdout_overflow['process']['stdoutOverflow'] ?? null ), 'Expected the exact stdout capture cap to trip.' );
$stderr_overflow = html_api_fuzz_protocol_expect_infrastructure( $renderer, 'stderr-overflow', $limits, '1 MiB plus one stderr' );
html_api_fuzz_protocol_assert( true === ( $stderr_overflow['process']['stderrOverflow'] ?? null ), 'Expected the exact stderr capture cap to trip.' );
$timeout_property = new ReflectionProperty( \HtmlApiFuzz\OracleRenderer::class, 'timeout_ms' );
$timeout_property->setValue( $renderer, 500 );
$timed_out = html_api_fuzz_protocol_expect_infrastructure( $renderer, 'timeout', $limits, 'oracle timeout' );
html_api_fuzz_protocol_assert( true === ( $timed_out['process']['timedOut'] ?? null ), 'Expected the oracle deadline to trip.' );
$timeout_property->setValue( $renderer, 10000 );

$heartbeat = $work_dir . '/fork-heartbeat';
$heartbeat_pid = $work_dir . '/fork-heartbeat-pid';
putenv( 'HTML_API_FUZZ_TEST_HEARTBEAT=' . $heartbeat );
putenv( 'HTML_API_FUZZ_TEST_HEARTBEAT_PID=' . $heartbeat_pid );
putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE=fork-heartbeat' );
$forked = $renderer->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_protocol_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $forked['status'] ?? null ), 'Expected a leader exit with a forked descendant to retain its validated outcome.' );
html_api_fuzz_protocol_wait_until( static fn (): bool => is_file( $heartbeat_pid ), 2.0, 'Expected forked heartbeat PID evidence.' );
$forked_pid = (int) file_get_contents( $heartbeat_pid );
$heartbeat_before = is_file( $heartbeat ) ? file_get_contents( $heartbeat ) : null;
usleep( 100000 );
$heartbeat_after = is_file( $heartbeat ) ? file_get_contents( $heartbeat ) : null;
html_api_fuzz_protocol_assert( $forked_pid > 1 && ! posix_kill( $forked_pid, 0 ), 'Expected forked target descendant cleanup.' );
html_api_fuzz_protocol_assert( $heartbeat_before === $heartbeat_after, 'Expected forked target heartbeat to stop.' );

$sentinel = proc_open( array( PHP_BINARY, '-r', 'while (true) { usleep(100000); }' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'a' ), 2 => array( 'file', '/dev/null', 'a' ) ), $sentinel_pipes, \HtmlApiFuzz\repo_root(), null, array( 'bypass_shell' => true ) );
html_api_fuzz_protocol_assert( is_resource( $sentinel ), 'Expected unrelated sentinel process.' );
$sentinel_pid = (int) ( proc_get_status( $sentinel )['pid'] ?? 0 );
putenv( 'HTML_API_FUZZ_TEST_HEARTBEAT=' . $work_dir . '/killed-supervisor-heartbeat' );
putenv( 'HTML_API_FUZZ_TEST_HEARTBEAT_PID=' . $work_dir . '/killed-supervisor-pid' );
$killed_supervisor = html_api_fuzz_protocol_expect_infrastructure( $renderer, 'kill-supervisor', $limits, 'target-killed supervisor' );
html_api_fuzz_protocol_assert( $sentinel_pid > 1 && posix_kill( $sentinel_pid, 0 ), 'Expected unrelated sentinel to survive authenticated fallback cleanup.' );
$killed_target_pid = (int) file_get_contents( $work_dir . '/killed-supervisor-pid' );
$killed_heartbeat_before = file_get_contents( $work_dir . '/killed-supervisor-heartbeat' );
usleep( 100000 );
$killed_heartbeat_after = file_get_contents( $work_dir . '/killed-supervisor-heartbeat' );
html_api_fuzz_protocol_assert( $killed_target_pid > 1 && ! posix_kill( $killed_target_pid, 0 ), 'Expected target cleanup after it killed the supervisor.' );
html_api_fuzz_protocol_assert( $killed_heartbeat_before === $killed_heartbeat_after, 'Expected target heartbeat to stop after supervisor fallback cleanup.' );
proc_terminate( $sentinel, SIGTERM );
fclose( $sentinel_pipes[0] );
proc_close( $sentinel );

$helper = $work_dir . '/renderer-owner-helper.php';
$helper_source = '<?php require_once ' . var_export( dirname( __DIR__ ) . '/lib/autoload.php', true ) . '; $renderer = \\HtmlApiFuzz\\OracleRenderer::from_options(array("dom-oracle"=>"lexbor-source","lexbor-oracle-bin"=>$argv[1],"oracle-timeout-ms"=>"10000")); $renderer->metadata();';
html_api_fuzz_protocol_assert( strlen( $helper_source ) === file_put_contents( $helper, $helper_source ), 'Expected renderer owner helper publication.' );
foreach ( array( 'HTML_API_FUZZ_TEST_PAUSE_AFTER_ANCHOR_READY', 'HTML_API_FUZZ_TEST_PAUSE_AFTER_GATED', 'HTML_API_FUZZ_TEST_PAUSE_AFTER_CLEANED' ) as $phase_environment ) {
	html_api_fuzz_protocol_kill_owner_at_phase( $helper, $binary, $phase_environment, $work_dir );
}

$shutdown_marker = $work_dir . '/shutdown-while-gated';
$short_renderer = \HtmlApiFuzz\OracleRenderer::from_options(
	array(
		'dom-oracle'        => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		'lexbor-oracle-bin' => $binary,
		'oracle-timeout-ms' => '10000',
	)
);
html_api_fuzz_protocol_assert( true === ( $short_renderer->metadata()['available'] ?? false ), 'Expected the shutdown fixture identity probe.' );
$timeout_property->setValue( $short_renderer, 1500 );
putenv( 'HTML_API_FUZZ_TEST_PAUSE_AFTER_GATED=' . $shutdown_marker );
$shutdown_result = $short_renderer->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_protocol_assert( true === ( $shutdown_result['process']['timedOut'] ?? null ), 'Expected authenticated shutdown while the target gate is paused.' );
html_api_fuzz_protocol_assert( is_file( $shutdown_marker ), 'Expected shutdown-while-paused marker evidence.' );
@unlink( $shutdown_marker );
putenv( 'HTML_API_FUZZ_TEST_PAUSE_AFTER_GATED' );

putenv( 'HTML_API_FUZZ_TEST_FAKE_ORACLE_CASE' );
putenv( 'HTML_API_FUZZ_TEST_HEARTBEAT' );
putenv( 'HTML_API_FUZZ_TEST_HEARTBEAT_PID' );
html_api_fuzz_protocol_assert( $initial_roots === html_api_fuzz_protocol_roots(), 'Expected no private oracle ownership roots to survive.' );
\HtmlApiFuzz\remove_dir_recursive( $work_dir );
html_api_fuzz_protocol_assert( ! is_dir( $work_dir ), 'Expected protocol smoke cleanup.' );

echo "OK oracle-renderer-protocol-smoke\n";
