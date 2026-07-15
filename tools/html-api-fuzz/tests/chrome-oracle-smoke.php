#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_chrome_smoke_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function html_api_fuzz_chrome_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_chrome_smoke_fail( $message );
	}
}

function html_api_fuzz_chrome_smoke_assert_replay_safe( array $value, string $path = 'replay' ): void {
	foreach ( $value as $key => $item ) {
		$current_path = $path . '.' . $key;
		html_api_fuzz_chrome_smoke_assert(
			! in_array( $key, array( 'chromeSocket', 'browserPid', 'browserInstanceId' ), true ),
			"Durable artifact contains ephemeral transport metadata at {$current_path}."
		);
		if ( is_array( $item ) ) {
			html_api_fuzz_chrome_smoke_assert_replay_safe( $item, $current_path );
		}
	}
}

function html_api_fuzz_chrome_smoke_sockets(): array {
	$sockets = glob( sys_get_temp_dir() . '/html-api-fuzz-chrome-*.sock' ) ?: array();
	sort( $sockets, SORT_STRING );
	return $sockets;
}

function html_api_fuzz_chrome_smoke_restore_env( string $name, $value ): void {
	putenv( false === $value ? $name : $name . '=' . $value );
}

$old_socket_env = getenv( 'HTML_API_FUZZ_CHROME_SOCKET' );
putenv( 'HTML_API_FUZZ_CHROME_SOCKET' );
$oracle_options = array(
	'dom-oracle' => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
);
$oracle = \HtmlApiFuzz\OracleRenderer::from_options( $oracle_options );
$metadata = $oracle->metadata();
if ( true !== ( $metadata['available'] ?? false ) ) {
	html_api_fuzz_chrome_smoke_restore_env( 'HTML_API_FUZZ_CHROME_SOCKET', $old_socket_env );
	echo 'SKIP chrome-oracle-smoke: ' . ( $metadata['error'] ?? $metadata['versionError'] ?? 'pinned Chrome unavailable' ) . "\n";
	exit( 0 );
}

$work_dir = sys_get_temp_dir() . '/html-api-fuzz-chrome-oracle-' . \HtmlApiFuzz\timestamp();
\HtmlApiFuzz\ensure_dir( $work_dir );
$stale_socket = sys_get_temp_dir() . '/html-api-fuzz-stale-replay.sock';
$stale_env_socket = sys_get_temp_dir() . '/html-api-fuzz-stale-env.sock';

try {
	$source_dir = $work_dir . '/source';
	$oracle->start_run_service( $source_dir );
	$worker_args = array(
		dirname( __DIR__ ) . '/worker.php',
		'--input-base64',
		base64_encode( '<p>x' ),
		'--mode',
		\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'--profile',
		'replay',
		'--seed',
		'901',
		'--output-dir',
		$source_dir,
		'--max-tokens',
		'200',
		'--max-nodes',
		'200',
	);
	$source_oracle_args = $oracle->worker_args();
	foreach ( $source_oracle_args as $arg ) {
		$worker_args[] = $arg;
	}
	$worker = \HtmlApiFuzz\run_php_process( $worker_args, \HtmlApiFuzz\repo_root(), 30000, $work_dir . '/source-worker.log' );
	html_api_fuzz_chrome_smoke_assert( 0 === $worker['code'], 'Initial Chrome worker failed: ' . trim( $worker['output'] ) );

	$source_result = \HtmlApiFuzz\read_json_file( $source_dir . '/result.json' );
	$source_replay = \HtmlApiFuzz\read_json_file( $source_dir . '/replay.json' );
	html_api_fuzz_chrome_smoke_assert( is_array( $source_result ) && is_array( $source_replay ), 'Initial Chrome worker did not write result and replay artifacts.' );
	html_api_fuzz_chrome_smoke_assert( is_int( $source_result['oracle']['browserPid'] ?? null ), 'Operational Chrome result should expose its live browser PID.' );
	html_api_fuzz_chrome_smoke_assert_replay_safe( $source_replay );
	$socket_arg_index = array_search( '--chrome-socket', $source_oracle_args, true );
	html_api_fuzz_chrome_smoke_assert( false !== $socket_arg_index && is_string( $source_oracle_args[ $socket_arg_index + 1 ] ?? null ), 'Run service did not expose its worker socket argument.' );
	$source_socket = $source_oracle_args[ $socket_arg_index + 1 ];

	$sockets_before = html_api_fuzz_chrome_smoke_sockets();
	$explicit_dir = $work_dir . '/explicit-socket';
	$explicit = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay',
			$source_dir . '/replay.json',
			'--output-dir',
			$explicit_dir,
			'--chrome-socket',
			$source_socket,
		),
		\HtmlApiFuzz\repo_root(),
		30000,
		$work_dir . '/explicit-socket.log'
	);
	html_api_fuzz_chrome_smoke_assert( 0 === $explicit['code'], 'Replay rejected a valid explicit Chrome socket: ' . trim( $explicit['output'] ) );
	html_api_fuzz_chrome_smoke_assert( $sockets_before === html_api_fuzz_chrome_smoke_sockets(), 'Explicit-socket replay spawned or removed a run-service socket.' );
	$explicit_result = \HtmlApiFuzz\read_json_file( $explicit_dir . '/result.json' );
	$explicit_replay = \HtmlApiFuzz\read_json_file( $explicit_dir . '/replay.json' );
	html_api_fuzz_chrome_smoke_assert( is_array( $explicit_result ) && is_array( $explicit_replay ), 'Explicit-socket replay did not write output artifacts.' );
	html_api_fuzz_chrome_smoke_assert( ( $source_result['oracle']['browserPid'] ?? null ) === ( $explicit_result['oracle']['browserPid'] ?? null ), 'Explicit-socket replay did not reuse the live Chrome process.' );
	html_api_fuzz_chrome_smoke_assert( 'chrome-cdp socket ' . $source_socket === ( $explicit_result['dom']['process']['command'] ?? null ), 'Explicit-socket replay used the wrong transport.' );
	html_api_fuzz_chrome_smoke_assert( file_exists( $source_socket ), 'Explicit-socket replay shut down a service it did not own.' );
	html_api_fuzz_chrome_smoke_assert_replay_safe( $explicit_replay, 'explicit-replay' );

	putenv( 'HTML_API_FUZZ_CHROME_SOCKET=' . $source_socket );
	$bare_socket = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay',
			$source_dir . '/replay.json',
			'--output-dir',
			$work_dir . '/bare-socket',
			'--chrome-socket',
		),
		\HtmlApiFuzz\repo_root(),
		5000,
		$work_dir . '/bare-socket.log'
	);
	html_api_fuzz_chrome_smoke_assert( 0 !== $bare_socket['code'] && false !== strpos( $bare_socket['output'], 'non-empty path' ), 'Bare --chrome-socket reused the environment instead of failing.' );
	putenv( 'HTML_API_FUZZ_CHROME_SOCKET' );
	$oracle->stop_run_service();
	html_api_fuzz_chrome_smoke_assert( ! file_exists( $source_socket ), 'Owning renderer did not remove its explicit run-service socket.' );

	$legacy_replay = $source_replay;
	$legacy_replay['options']['chromeSocket'] = $stale_socket;
	$legacy_replay['oracle']['chromeSocket'] = $stale_socket;
	$legacy_replay['oracle']['browserPid'] = 12345;
	$legacy_replay['oracle']['browserInstanceId'] = 'stale-browser-instance';
	$legacy_replay['result']['oracle'] = $legacy_replay['oracle'];
	$legacy_path = $work_dir . '/legacy-replay.json';
	\HtmlApiFuzz\write_json_file( $legacy_path, $legacy_replay );
	$store_path = $work_dir . '/legacy-results.sqlite';
	$store = new \HtmlApiFuzz\ResultStore( $store_path );
	$store_id = $store->record_attempt(
		array(
			'ok'           => false,
			'status'       => 'failed',
			'failureClass' => 'tree-mismatch',
			'seed'         => 902,
			'profile'      => 'replay',
			'mode'         => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
			'signature'    => array( 'hash' => 'legacy-socket', 'familyKey' => 'legacy-socket' ),
			'oracle'       => $legacy_replay['oracle'],
		),
		array( 'ok' => false, 'status' => 'failed', 'failureClass' => 'tree-mismatch' ),
		$legacy_replay
	);
	$store->close();

	putenv( 'HTML_API_FUZZ_CHROME_SOCKET=' . $stale_env_socket );
	$sockets_before = html_api_fuzz_chrome_smoke_sockets();
	$replay_dir = $work_dir . '/replayed';
	$replayed = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--store',
			$store_path,
			'--id',
			(string) $store_id,
			'--output-dir',
			$replay_dir,
		),
		\HtmlApiFuzz\repo_root(),
		40000,
		$work_dir . '/replay.log'
	);
	html_api_fuzz_chrome_smoke_assert( 0 === $replayed['code'], 'Chrome replay failed instead of replacing stale transport state: ' . trim( $replayed['output'] ) );
	html_api_fuzz_chrome_smoke_assert( $sockets_before === html_api_fuzz_chrome_smoke_sockets(), 'Chrome replay left its fresh service socket behind.' );
	$replayed_replay = \HtmlApiFuzz\read_json_file( $replay_dir . '/replay.json' );
	$replayed_result = \HtmlApiFuzz\read_json_file( $replay_dir . '/result.json' );
	$materialized_replay = \HtmlApiFuzz\read_json_file( $replay_dir . '/source-replay.json' );
	html_api_fuzz_chrome_smoke_assert( is_array( $replayed_replay ) && is_array( $replayed_result ) && is_array( $materialized_replay ), 'Stored Chrome replay did not write source and output artifacts.' );
	html_api_fuzz_chrome_smoke_assert_replay_safe( $materialized_replay, 'materialized-replay' );
	html_api_fuzz_chrome_smoke_assert_replay_safe( $replayed_replay );
	$replayed_json = \HtmlApiFuzz\json_encode_safe( $replayed_replay );
	html_api_fuzz_chrome_smoke_assert( false === strpos( $replayed_json, $stale_socket ) && false === strpos( $replayed_json, $stale_env_socket ), 'Chrome replay copied a stale socket into its durable replay.' );
	$replay_command = (string) ( $replayed_result['dom']['process']['command'] ?? '' );
	html_api_fuzz_chrome_smoke_assert( false !== strpos( $replay_command, 'chrome-cdp socket ' ), 'Chrome replay worker did not use its fresh run service.' );
	html_api_fuzz_chrome_smoke_assert( false === strpos( $replay_command, $stale_socket ) && false === strpos( $replay_command, $stale_env_socket ), 'Chrome replay connected to stale transport state.' );

	$minimize_replay = $legacy_replay;
	$minimize_input = '<div><span>x</span></div>';
	$minimize_replay['inputBase64'] = base64_encode( $minimize_input );
	$minimize_replay['inputSha1'] = sha1( $minimize_input );
	$minimize_replay['inputLength'] = strlen( $minimize_input );
	$minimize_replay['limits']['maxNodes'] = 1;
	unset( $minimize_replay['signature'], $minimize_replay['oracleFinding'] );
	$minimize_replay['result'] = array( 'oracle' => $legacy_replay['oracle'] );
	$minimize_path = $work_dir . '/legacy-minimize-replay.json';
	\HtmlApiFuzz\write_json_file( $minimize_path, $minimize_replay );

	$sockets_before = html_api_fuzz_chrome_smoke_sockets();
	$minimize_dir = $work_dir . '/minimize';
	$minimized = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/minimize.php',
			'--replay',
			$minimize_path,
			'--output-dir',
			$minimize_dir,
			'--any-failure',
			'--max-attempts',
			'0',
			'--probe-mode',
			'process',
		),
		\HtmlApiFuzz\repo_root(),
		40000,
		$work_dir . '/minimize.log'
	);
	html_api_fuzz_chrome_smoke_assert( 0 === $minimized['code'], 'Chrome minimization failed instead of replacing stale transport state: ' . trim( $minimized['output'] ) );
	html_api_fuzz_chrome_smoke_assert( $sockets_before === html_api_fuzz_chrome_smoke_sockets(), 'Chrome minimization left its fresh service socket behind.' );
	$minimize_result = \HtmlApiFuzz\read_json_file( $minimize_dir . '/minimize-result.json' );
	$minimize_final_replay = \HtmlApiFuzz\read_json_file( $minimize_dir . '/minimized/replay.json' );
	html_api_fuzz_chrome_smoke_assert( true === ( $minimize_result['ok'] ?? null ), 'Chrome minimization did not preserve a bounded failure.' );
	html_api_fuzz_chrome_smoke_assert( is_array( $minimize_final_replay ), 'Chrome minimization did not write its final replay.' );
	html_api_fuzz_chrome_smoke_assert_replay_safe( $minimize_result, 'minimize-result' );
	html_api_fuzz_chrome_smoke_assert_replay_safe( $minimize_final_replay, 'minimized-replay' );
	$minimize_json = \HtmlApiFuzz\json_encode_safe( array( $minimize_result, $minimize_final_replay ) );
	html_api_fuzz_chrome_smoke_assert( false === strpos( $minimize_json, $stale_socket ) && false === strpos( $minimize_json, $stale_env_socket ), 'Chrome minimization persisted stale transport state.' );

	putenv( 'HTML_API_FUZZ_CHROME_SOCKET' );
	$blocked_script = $work_dir . '/blocked-oracle.js';
	$blocked_marker = $work_dir . '/blocked-oracle.started';
	file_put_contents(
		$blocked_script,
		<<<'JS'
#!/usr/bin/env node
'use strict';
const fs = require( 'node:fs' );
if ( process.argv.includes( '--version' ) ) {
	process.stdout.write( JSON.stringify( { status: 'ok', oracle: { kind: 'chrome-cdp', available: true } } ) + '\n' );
	process.exit( 0 );
}
const marker = process.env.HTML_API_FUZZ_BLOCKED_ORACLE_MARKER;
if ( fs.existsSync( marker ) ) {
	process.exit( 0 );
}
fs.writeFileSync( marker, 'started\n' );
const readline = require( 'node:readline' );
const lines = readline.createInterface( { input: process.stdin } );
lines.once( 'line', ( line ) => {
	const request = JSON.parse( line );
	process.stdout.write( JSON.stringify( {
		id: request.id,
		status: 'ok',
		oracle: { kind: 'chrome-cdp', available: true },
		treeBase64: Buffer.from( '\n' ).toString( 'base64' ),
		nodeCount: 0,
	} ) + '\n' );
	lines.close();
	process.stdin.pause();
} );
setInterval( () => {}, 1000 );
JS
	);
	putenv( 'HTML_API_FUZZ_BLOCKED_ORACLE_MARKER=' . $blocked_marker );
	$blocked_oracle = \HtmlApiFuzz\OracleRenderer::from_options(
		array(
			'dom-oracle'          => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
			'chrome-oracle-script' => $blocked_script,
			'oracle-timeout-ms'    => '250',
		)
	);
	$primed = $blocked_oracle->render( '', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10 ), 'body' );
	html_api_fuzz_chrome_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $primed['status'] ?? null ), 'Blocked-write fixture did not prime its persistent server.' );
	$blocked_started = microtime( true );
	$blocked = $blocked_oracle->render( str_repeat( 'x', 4 * 1024 * 1024 ), \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10 ), 'body' );
	$blocked_duration = microtime( true ) - $blocked_started;
	html_api_fuzz_chrome_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_ERROR === ( $blocked['status'] ?? null ), 'A non-reading oracle server did not fail its blocked protocol write.' );
	html_api_fuzz_chrome_smoke_assert( $blocked_duration < 10.0, "Blocked oracle write exceeded its bounded cleanup window ({$blocked_duration}s)." );
	putenv( 'HTML_API_FUZZ_BLOCKED_ORACLE_MARKER' );
} finally {
	$oracle->stop_run_service();
	putenv( 'HTML_API_FUZZ_BLOCKED_ORACLE_MARKER' );
	html_api_fuzz_chrome_smoke_restore_env( 'HTML_API_FUZZ_CHROME_SOCKET', $old_socket_env );
	\HtmlApiFuzz\remove_dir_recursive( $work_dir );
}

html_api_fuzz_chrome_smoke_assert( ! is_dir( $work_dir ), 'Expected Chrome smoke work directory cleanup.' );
echo "OK chrome-oracle-smoke\n";
