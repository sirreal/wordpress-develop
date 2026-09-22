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
			! in_array( $key, array( 'chromeSocket', 'browserPid', 'browserInstanceId', 'serverPid' ), true ),
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

function html_api_fuzz_chrome_smoke_profiles(): array {
	$profiles = array();
	foreach ( glob( sys_get_temp_dir() . '/html-api-fuzz-chrome-*', GLOB_ONLYDIR ) ?: array() as $profile ) {
		if ( 1 === preg_match( '/^html-api-fuzz-chrome-[A-Za-z0-9]{6}$/', basename( $profile ) ) ) {
			$profiles[] = $profile;
		}
	}
	foreach ( html_api_fuzz_chrome_smoke_lifecycle_roots() as $root ) {
		foreach ( glob( $root . '/profile-*', GLOB_ONLYDIR ) ?: array() as $profile ) {
			if ( 1 === preg_match( '/^profile-[A-Za-z0-9]{6}$/', basename( $profile ) ) ) {
				$profiles[] = $profile;
			}
		}
	}
	sort( $profiles, SORT_STRING );
	return $profiles;
}

function html_api_fuzz_chrome_smoke_lifecycle_roots(): array {
	$roots = array();
	foreach ( glob( sys_get_temp_dir() . '/html-api-fuzz-chrome-owner-*', GLOB_ONLYDIR ) ?: array() as $root ) {
		if ( 1 === preg_match( '/^html-api-fuzz-chrome-owner-[a-f0-9]{32}$/', basename( $root ) ) ) {
			$roots[] = $root;
		}
	}
	sort( $roots, SORT_STRING );
	return $roots;
}

function html_api_fuzz_chrome_smoke_new_lifecycle( array $baseline, string $message ): array {
	$new_roots = array_values( array_diff( html_api_fuzz_chrome_smoke_lifecycle_roots(), $baseline ) );
	html_api_fuzz_chrome_smoke_assert( 1 === count( $new_roots ), $message . ' did not create exactly one owned lifecycle root.' );
	$record = \HtmlApiFuzz\read_json_file( $new_roots[0] . '/lifecycle.json' );
	html_api_fuzz_chrome_smoke_assert( is_array( $record ), $message . ' did not publish a lifecycle record.' );
	return array( 'root' => $new_roots[0], 'record' => $record );
}

function html_api_fuzz_chrome_smoke_wait_for_process_absence( int $pid, bool $group, string $message ): void {
	$deadline = microtime( true ) + 10.0;
	$target = $group ? -$pid : $pid;
	do {
		if ( ! @posix_kill( $target, 0 ) ) {
			$error = posix_get_last_error();
			$esrch = defined( 'PCNTL_ESRCH' ) ? constant( 'PCNTL_ESRCH' ) : 3;
			if ( $esrch === $error ) {
				return;
			}
		}
		usleep( 25000 );
	} while ( microtime( true ) < $deadline );
	html_api_fuzz_chrome_smoke_fail( $message );
}

function html_api_fuzz_chrome_smoke_wait_for_pid_absence( int $pid, string $message ): void {
	$deadline = microtime( true ) + 5.0;
	$esrch = defined( 'PCNTL_ESRCH' ) ? constant( 'PCNTL_ESRCH' ) : 3;
	do {
		if ( ! @posix_kill( $pid, 0 ) ) {
			if ( $esrch === posix_get_last_error() ) {
				return;
			}
			html_api_fuzz_chrome_smoke_fail( $message );
		}
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	html_api_fuzz_chrome_smoke_fail( $message );
}

function html_api_fuzz_chrome_smoke_assert_one_active_profile( array $baseline, string $message ): void {
	$current = html_api_fuzz_chrome_smoke_profiles();
	html_api_fuzz_chrome_smoke_assert( array() === array_values( array_diff( $baseline, $current ) ), $message . ' removed a pre-existing profile.' );
	html_api_fuzz_chrome_smoke_assert( 1 === count( array_diff( $current, $baseline ) ), $message . ' did not leave exactly one active profile.' );
}

function html_api_fuzz_chrome_smoke_restore_env( string $name, $value ): void {
	putenv( false === $value ? $name : $name . '=' . $value );
}

function html_api_fuzz_chrome_smoke_socket_request( string $socket_path, array $request ): array {
	$socket = @stream_socket_client( 'unix://' . $socket_path, $errno, $error, 0.5 );
	html_api_fuzz_chrome_smoke_assert( is_resource( $socket ), "Could not connect Chrome smoke control socket: {$error} ({$errno})." );
	$json = json_encode( $request, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	html_api_fuzz_chrome_smoke_assert( is_string( $json ), 'Could not encode Chrome smoke control request.' );
	$encoded = $json . "\n";
	html_api_fuzz_chrome_smoke_assert( strlen( $encoded ) === @fwrite( $socket, $encoded ), 'Could not write Chrome smoke control request.' );
	@fflush( $socket );
	stream_set_timeout( $socket, 2 );
	$line = fgets( $socket );
	$metadata = stream_get_meta_data( $socket );
	fclose( $socket );
	html_api_fuzz_chrome_smoke_assert( false === ( $metadata['timed_out'] ?? false ), 'Chrome smoke control request timed out.' );
	$decoded = is_string( $line ) ? json_decode( trim( $line ), true ) : null;
	html_api_fuzz_chrome_smoke_assert( is_array( $decoded ), 'Chrome smoke control request returned invalid JSON.' );
	return $decoded;
}

$old_socket_env = getenv( 'HTML_API_FUZZ_CHROME_SOCKET' );
$old_invalidate_env = getenv( 'HTML_API_FUZZ_CHROME_TEST_ALLOW_INVALIDATE_SESSION' );
putenv( 'HTML_API_FUZZ_CHROME_SOCKET' );
$profiles_before = html_api_fuzz_chrome_smoke_profiles();
$lifecycle_before = html_api_fuzz_chrome_smoke_lifecycle_roots();
$oracle_options = array(
	'dom-oracle' => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
);
$oracle = \HtmlApiFuzz\OracleRenderer::from_options( $oracle_options );
$hostile_oracle = null;
$cleanup_failure_oracle = null;
$forced_run_oracle = null;
$forced_node_pids = array();
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
	putenv( 'HTML_API_FUZZ_CHROME_TEST_ALLOW_INVALIDATE_SESSION=1' );
	try {
		$oracle->start_run_service( $source_dir );
	} finally {
		html_api_fuzz_chrome_smoke_restore_env( 'HTML_API_FUZZ_CHROME_TEST_ALLOW_INVALIDATE_SESSION', $old_invalidate_env );
	}
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
	$socket_arg_index = array_search( '--chrome-socket', $source_oracle_args, true );
	html_api_fuzz_chrome_smoke_assert( false !== $socket_arg_index && is_string( $source_oracle_args[ $socket_arg_index + 1 ] ?? null ), 'Run service did not expose its worker socket argument.' );
	$source_socket = $source_oracle_args[ $socket_arg_index + 1 ];
	foreach ( $source_oracle_args as $arg ) {
		$worker_args[] = $arg;
	}
	$worker = \HtmlApiFuzz\run_php_process( $worker_args, \HtmlApiFuzz\repo_root(), 30000, $work_dir . '/source-worker.log' );
	html_api_fuzz_chrome_smoke_assert( 0 === $worker['code'], 'Initial Chrome worker failed: ' . trim( $worker['output'] ) );

	$source_result = \HtmlApiFuzz\read_json_file( $source_dir . '/result.json' );
	$source_replay = \HtmlApiFuzz\read_json_file( $source_dir . '/replay.json' );
	html_api_fuzz_chrome_smoke_assert( is_array( $source_result ) && is_array( $source_replay ), 'Initial Chrome worker did not write result and replay artifacts.' );
	html_api_fuzz_chrome_smoke_assert( false === strpos( \HtmlApiFuzz\json_encode_safe( array( $source_result, $source_replay ) ), '"serverPid"' ), 'Durable Chrome artifacts persisted the daemon PID.' );
	html_api_fuzz_chrome_smoke_assert( is_int( $source_result['oracle']['browserPid'] ?? null ), 'Operational Chrome result should expose its live browser PID.' );
	html_api_fuzz_chrome_smoke_assert( is_string( $source_result['oracle']['browserInstanceId'] ?? null ), 'Operational Chrome result should expose its browser instance ID.' );
	html_api_fuzz_chrome_smoke_assert_one_active_profile( $profiles_before, 'Initial Chrome service' );
	if ( ! function_exists( 'posix_kill' ) || ! defined( 'SIGTERM' ) || ! defined( 'SIGKILL' ) || ! defined( 'SIGSTOP' ) || ! defined( 'SIGCONT' ) ) {
		html_api_fuzz_chrome_smoke_fail( 'Chrome recovery smoke requires POSIX process signals.' );
	}
	$browser_pid = $source_result['oracle']['browserPid'];
	$browser_instance_id = $source_result['oracle']['browserInstanceId'];
	for ( $recovery = 1; $recovery <= 3; ++$recovery ) {
		html_api_fuzz_chrome_smoke_assert( posix_kill( $browser_pid, SIGTERM ), "Could not terminate Chrome recovery fixture {$recovery}." );
		html_api_fuzz_chrome_smoke_wait_for_pid_absence( $browser_pid, "Chrome recovery fixture {$recovery} did not exit after SIGTERM." );
		$recovered = $oracle->render( '<p>recovery-' . $recovery, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 200 ), 'body' );
		html_api_fuzz_chrome_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $recovered['status'] ?? null ), "Chrome recovery {$recovery} failed: " . ( $recovered['error'] ?? 'unknown error' ) );
		$new_browser_pid = $recovered['oracle']['browserPid'] ?? null;
		$new_browser_instance_id = $recovered['oracle']['browserInstanceId'] ?? null;
		html_api_fuzz_chrome_smoke_assert( is_int( $new_browser_pid ) && $new_browser_pid !== $browser_pid, "Chrome recovery {$recovery} did not replace the browser process." );
		html_api_fuzz_chrome_smoke_assert( is_string( $new_browser_instance_id ) && $new_browser_instance_id !== $browser_instance_id, "Chrome recovery {$recovery} did not replace the browser instance." );
		$browser_pid = $new_browser_pid;
		$browser_instance_id = $new_browser_instance_id;
		html_api_fuzz_chrome_smoke_assert_one_active_profile( $profiles_before, "Chrome recovery {$recovery}" );
	}
	for ( $session_recovery = 1; $session_recovery <= 3; ++$session_recovery ) {
		$invalidated = html_api_fuzz_chrome_smoke_socket_request(
			$source_socket,
			array( 'id' => 900 + $session_recovery, 'command' => 'test-invalidate-session' )
		);
		html_api_fuzz_chrome_smoke_assert( 'ok' === ( $invalidated['status'] ?? null ) && true === ( $invalidated['invalidatedSession'] ?? null ), "Live-session recovery fixture {$session_recovery} did not invalidate its CDP session." );
		html_api_fuzz_chrome_smoke_assert( $browser_pid === ( $invalidated['oracle']['browserPid'] ?? null ), "Live-session recovery fixture {$session_recovery} changed the browser before recovery." );
		html_api_fuzz_chrome_smoke_assert( posix_kill( $browser_pid, 0 ), "Live-session recovery fixture {$session_recovery} killed the browser process." );
		$recovered = $oracle->render( '<p>session-recovery-' . $session_recovery, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 200 ), 'body' );
		html_api_fuzz_chrome_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $recovered['status'] ?? null ), "Live-session recovery {$session_recovery} failed: " . ( $recovered['error'] ?? 'unknown error' ) );
		html_api_fuzz_chrome_smoke_assert( is_int( $recovered['process']['durationMs'] ?? null ) && $recovered['process']['durationMs'] < 2500, "Live-session recovery {$session_recovery} exceeded the default request deadline." );
		$new_browser_pid = $recovered['oracle']['browserPid'] ?? null;
		$new_browser_instance_id = $recovered['oracle']['browserInstanceId'] ?? null;
		html_api_fuzz_chrome_smoke_assert( is_int( $new_browser_pid ) && $new_browser_pid !== $browser_pid, "Live-session recovery {$session_recovery} did not replace the browser process." );
		html_api_fuzz_chrome_smoke_assert( is_string( $new_browser_instance_id ) && $new_browser_instance_id !== $browser_instance_id, "Live-session recovery {$session_recovery} did not replace the browser instance." );
		$browser_pid = $new_browser_pid;
		$browser_instance_id = $new_browser_instance_id;
		html_api_fuzz_chrome_smoke_assert_one_active_profile( $profiles_before, "Live-session recovery {$session_recovery}" );
	}
	html_api_fuzz_chrome_smoke_assert_replay_safe( $source_replay );
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
	html_api_fuzz_chrome_smoke_assert( $browser_pid === ( $explicit_result['oracle']['browserPid'] ?? null ), 'Explicit-socket replay did not reuse the live Chrome process.' );
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
	html_api_fuzz_chrome_smoke_assert( $profiles_before === html_api_fuzz_chrome_smoke_profiles(), 'Owning renderer left a Chrome profile behind.' );

	$hostile_oracle = \HtmlApiFuzz\OracleRenderer::from_options( $oracle_options );
	$hostile_oracle->start_run_service( $work_dir . '/hostile-close' );
	$hostile_args = $hostile_oracle->worker_args();
	$hostile_socket_index = array_search( '--chrome-socket', $hostile_args, true );
	$hostile_socket = $hostile_args[ $hostile_socket_index + 1 ] ?? null;
	html_api_fuzz_chrome_smoke_assert( is_string( $hostile_socket ) && file_exists( $hostile_socket ), 'Hostile-close fixture did not expose its socket.' );
	html_api_fuzz_chrome_smoke_assert_one_active_profile( $profiles_before, 'Hostile-close Chrome service' );
	$hostile_client = @stream_socket_client( 'unix://' . $hostile_socket, $hostile_errno, $hostile_error, 0.5 );
	html_api_fuzz_chrome_smoke_assert( is_resource( $hostile_client ), "Could not connect hostile-close fixture: {$hostile_error} ({$hostile_errno})." );
	$hostile_shutdown = "{\"id\":0,\"command\":\"shutdown\"}\n";
	html_api_fuzz_chrome_smoke_assert( strlen( $hostile_shutdown ) === @fwrite( $hostile_client, $hostile_shutdown ), 'Could not write hostile-close shutdown request.' );
	@fflush( $hostile_client );
	@fclose( $hostile_client );
	$hostile_deadline = microtime( true ) + 10.0;
	do {
		if ( ! file_exists( $hostile_socket ) && $profiles_before === html_api_fuzz_chrome_smoke_profiles() ) {
			break;
		}
		usleep( 10000 );
	} while ( microtime( true ) < $hostile_deadline );
	html_api_fuzz_chrome_smoke_assert( ! file_exists( $hostile_socket ), 'Early-disconnect shutdown crashed before removing its socket.' );
	html_api_fuzz_chrome_smoke_assert( $profiles_before === html_api_fuzz_chrome_smoke_profiles(), 'Early-disconnect shutdown crashed before removing its Chrome profile.' );
	$hostile_oracle->stop_run_service();
	$hostile_oracle = null;

	$cleanup_failure_script = $work_dir . '/cleanup-failure-oracle.js';
	$chrome_fixture_pin = $metadata['pinnedChromeVersion'] ?? null;
	$chrome_fixture_executable = $metadata['chromeExecutable'] ?? null;
	html_api_fuzz_chrome_smoke_assert( is_string( $chrome_fixture_pin ) && '' !== $chrome_fixture_pin, 'Chrome fixture is missing the pinned version.' );
	html_api_fuzz_chrome_smoke_assert( is_string( $chrome_fixture_executable ) && '' !== $chrome_fixture_executable, 'Chrome fixture is missing the configured executable.' );
	$cleanup_failure_source = <<<'JS'
#!/usr/bin/env node
'use strict';
const fs = require( 'node:fs' );
const net = require( 'node:net' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const pinnedVersion = __PINNED_CHROME_VERSION__;
const executableIndex = process.argv.indexOf( '--chrome-executable' );
const chromeExecutable = path.resolve( process.argv[ executableIndex + 1 ] );
const oracle = ( live ) => {
	const metadata = {
		kind: 'chrome-cdp',
		engine: 'chrome',
		available: true,
		pinnedChromeVersion: pinnedVersion,
		chromeVersion: pinnedVersion,
		browserVersion: pinnedVersion,
		chromeExecutable,
		nodeVersion: process.version,
		script: __filename,
		cdpTransport: 'remote-debugging-websocket',
	};
	if ( live ) {
		metadata.cdpProtocolVersion = 'fixture-cdp';
		metadata.browserPid = process.pid;
		metadata.browserInstanceId = `cleanup-fixture-${ process.pid }`;
	}
	return metadata;
};
if ( process.argv.includes( '--version' ) ) {
	process.stdout.write( JSON.stringify( { status: 'ok', oracle: oracle( false ) } ) + '\n' );
	process.exit( 0 );
}
const socketIndex = process.argv.indexOf( '--socket' );
const socketPath = process.argv[ socketIndex + 1 ];
const unlinkSocket = () => {
	try {
		fs.unlinkSync( socketPath );
	} catch ( error ) {
		if ( 'ENOENT' !== error.code ) {
			throw error;
		}
	}
};
unlinkSocket();
const server = net.createServer( ( socket ) => {
	const lines = readline.createInterface( { input: socket } );
	lines.once( 'line', ( line ) => {
		const request = JSON.parse( line );
		if ( 'version' === request.command ) {
			socket.end( JSON.stringify( { id: request.id, serverPid: process.pid, status: 'ok', oracle: oracle( true ) } ) + '\n' );
			return;
		}
		if ( 'render' === request.command ) {
			const treeBase64 = process.env.HTML_API_FUZZ_CLEANUP_TREE_BASE64 || Buffer.from( '\n' ).toString( 'base64' );
			socket.end( JSON.stringify( { id: request.id, serverPid: process.pid, status: 'ok', oracle: oracle( true ), treeBase64, nodeCount: Number( process.env.HTML_API_FUZZ_CLEANUP_NODE_COUNT || 0 ) } ) + '\n' );
			return;
		}
		if ( 'shutdown' === request.command ) {
			socket.end( JSON.stringify( { id: request.id, serverPid: process.pid, status: 'ok', shutdown: true, oracle: oracle( true ) } ) + '\n' );
			server.close( () => {
				unlinkSocket();
				process.stderr.write( 'fixture-cleanup-failed\n' );
				process.exitCode = 1;
			} );
		}
	} );
} );
server.listen( socketPath, () => process.stdout.write( JSON.stringify( { status: 'ready', socket: socketPath } ) + '\n' ) );
JS;
	$cleanup_failure_source = str_replace( '__PINNED_CHROME_VERSION__', json_encode( $chrome_fixture_pin, JSON_THROW_ON_ERROR ), $cleanup_failure_source );
	file_put_contents(
		$cleanup_failure_script,
		$cleanup_failure_source
	);
	$cleanup_failure_oracle = \HtmlApiFuzz\OracleRenderer::from_options(
		array(
			'dom-oracle'           => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
			'chrome-oracle-script' => $cleanup_failure_script,
			'chrome-executable'    => $chrome_fixture_executable,
		)
	);
	$cleanup_failure_oracle->start_run_service( $work_dir . '/cleanup-failure' );
	$cleanup_failure_args = $cleanup_failure_oracle->worker_args();
	$cleanup_failure_socket_index = array_search( '--chrome-socket', $cleanup_failure_args, true );
	$cleanup_failure_socket = $cleanup_failure_args[ $cleanup_failure_socket_index + 1 ] ?? null;
	$cleanup_failure_message = null;
	try {
		$cleanup_failure_oracle->stop_run_service();
	} catch ( \RuntimeException $error ) {
		$cleanup_failure_message = $error->getMessage();
	}
	html_api_fuzz_chrome_smoke_assert( is_string( $cleanup_failure_message ), 'Owning renderer hid a daemon cleanup failure.' );
	html_api_fuzz_chrome_smoke_assert( false !== strpos( $cleanup_failure_message, 'daemon exited with code 1' ), 'Owning renderer omitted the cleanup daemon exit code.' );
	html_api_fuzz_chrome_smoke_assert( false !== strpos( $cleanup_failure_message, 'fixture-cleanup-failed' ), 'Owning renderer omitted cleanup daemon stderr.' );
	html_api_fuzz_chrome_smoke_assert( is_string( $cleanup_failure_socket ) && ! file_exists( $cleanup_failure_socket ), 'Cleanup-failure daemon left its socket behind.' );
	$cleanup_failure_oracle->stop_run_service();
	$cleanup_failure_oracle = null;

	$cleanup_replay = $source_replay;
	unset( $cleanup_replay['oracle']['pinnedChromeVersion'] );
	$cleanup_replay_input = '<p data-fuzz=1>x';
	$cleanup_replay['inputBase64'] = base64_encode( $cleanup_replay_input );
	$cleanup_replay['inputSha1'] = sha1( $cleanup_replay_input );
	$cleanup_replay['inputLength'] = strlen( $cleanup_replay_input );
	$cleanup_replay_path = $work_dir . '/cleanup-failure-replay.json';
	\HtmlApiFuzz\write_json_file( $cleanup_replay_path, $cleanup_replay );
	putenv( 'HTML_API_FUZZ_CLEANUP_TREE_BASE64=' . base64_encode( "<p>\n  data-fuzz=\"1\"\n  \"x\"\n\n" ) );
	putenv( 'HTML_API_FUZZ_CLEANUP_NODE_COUNT=2' );
	$sockets_before = html_api_fuzz_chrome_smoke_sockets();
	$cleanup_replay_dir = $work_dir . '/cleanup-failure-replay';
	$cleanup_replayed = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay',
			$cleanup_replay_path,
			'--output-dir',
			$cleanup_replay_dir,
			'--dom-oracle',
			\HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
			'--chrome-oracle-script',
			$cleanup_failure_script,
			'--chrome-executable',
			$chrome_fixture_executable,
		),
		\HtmlApiFuzz\repo_root(),
		30000,
		$work_dir . '/cleanup-failure-replay.log'
	);
	$cleanup_replay_result = \HtmlApiFuzz\read_json_file( $cleanup_replay_dir . '/result.json' );
	html_api_fuzz_chrome_smoke_assert( true === ( $cleanup_replay_result['ok'] ?? null ), 'Cleanup-failure replay worker did not finish successfully before shutdown.' );
	html_api_fuzz_chrome_smoke_assert( 0 !== $cleanup_replayed['code'] && false !== strpos( $cleanup_replayed['output'], 'daemon exited with code 1' ) && false !== strpos( $cleanup_replayed['output'], 'fixture-cleanup-failed' ), 'Successful replay hid its owned daemon cleanup failure.' );
	html_api_fuzz_chrome_smoke_assert( $sockets_before === html_api_fuzz_chrome_smoke_sockets(), 'Cleanup-failure replay left its run-service socket behind.' );
	putenv( 'HTML_API_FUZZ_CLEANUP_TREE_BASE64' );
	putenv( 'HTML_API_FUZZ_CLEANUP_NODE_COUNT' );

	$legacy_replay = $source_replay;
	$legacy_replay['serverPid'] = 12340;
	$legacy_replay['options']['serverPid'] = 12341;
	$legacy_replay['options']['chromeSocket'] = $stale_socket;
	$legacy_replay['oracle']['chromeSocket'] = $stale_socket;
	$legacy_replay['oracle']['browserPid'] = 12345;
	$legacy_replay['oracle']['browserInstanceId'] = 'stale-browser-instance';
	$legacy_replay['oracle']['serverPid'] = 12342;
	$legacy_replay['result']['oracle'] = $legacy_replay['oracle'];
	$legacy_replay['result']['serverPid'] = 12343;
	$legacy_safe_replay = \HtmlApiFuzz\OracleRenderer::replay_safe_document( $legacy_replay );
	html_api_fuzz_chrome_smoke_assert_replay_safe( $legacy_safe_replay, 'legacy-safe-replay' );
	html_api_fuzz_chrome_smoke_assert( false === strpos( \HtmlApiFuzz\json_encode_safe( $legacy_safe_replay ), '"serverPid"' ), 'Recursive replay scrubbing retained a daemon PID.' );
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

	$cleanup_minimize_replay = $minimize_replay;
	unset( $cleanup_minimize_replay['oracle']['pinnedChromeVersion'] );
	$cleanup_minimize_path = $work_dir . '/cleanup-failure-minimize-replay.json';
	\HtmlApiFuzz\write_json_file( $cleanup_minimize_path, $cleanup_minimize_replay );
	putenv( 'HTML_API_FUZZ_CLEANUP_TREE_BASE64=' . base64_encode( "\n" ) );
	putenv( 'HTML_API_FUZZ_CLEANUP_NODE_COUNT=0' );
	$sockets_before = html_api_fuzz_chrome_smoke_sockets();
	$cleanup_minimize_dir = $work_dir . '/cleanup-failure-minimize';
	$cleanup_minimized = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/minimize.php',
			'--replay',
			$cleanup_minimize_path,
			'--output-dir',
			$cleanup_minimize_dir,
			'--any-failure',
			'--max-attempts',
			'0',
			'--probe-mode',
			'process',
			'--dom-oracle',
			\HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
			'--chrome-oracle-script',
			$cleanup_failure_script,
			'--chrome-executable',
			$chrome_fixture_executable,
		),
		\HtmlApiFuzz\repo_root(),
		30000,
		$work_dir . '/cleanup-failure-minimize.log'
	);
	$cleanup_minimize_result = \HtmlApiFuzz\read_json_file( $cleanup_minimize_dir . '/minimize-result.json' );
	html_api_fuzz_chrome_smoke_assert( true === ( $cleanup_minimize_result['ok'] ?? null ), 'Cleanup-failure minimization did not finish successfully before shutdown.' );
	html_api_fuzz_chrome_smoke_assert( 0 !== $cleanup_minimized['code'] && false !== strpos( $cleanup_minimized['output'], 'daemon exited with code 1' ) && false !== strpos( $cleanup_minimized['output'], 'fixture-cleanup-failed' ), 'Successful minimization hid its owned daemon cleanup failure.' );
	html_api_fuzz_chrome_smoke_assert( $sockets_before === html_api_fuzz_chrome_smoke_sockets(), 'Cleanup-failure minimization left its run-service socket behind.' );
	putenv( 'HTML_API_FUZZ_CLEANUP_TREE_BASE64' );
	putenv( 'HTML_API_FUZZ_CLEANUP_NODE_COUNT' );

	putenv( 'HTML_API_FUZZ_CHROME_SOCKET' );
	$silent_exit_script = $work_dir . '/silent-exit-oracle.js';
	$silent_exit_marker = $work_dir . '/silent-exit-oracle.started';
	$silent_exit_source = <<<'JS'
#!/usr/bin/env node
'use strict';
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const readline = require( 'node:readline' );
const pinnedVersion = __PINNED_CHROME_VERSION__;
const executableIndex = process.argv.indexOf( '--chrome-executable' );
const chromeExecutable = path.resolve( process.argv[ executableIndex + 1 ] );
const oracle = ( live ) => {
	const metadata = {
		kind: 'chrome-cdp',
		engine: 'chrome',
		available: true,
		pinnedChromeVersion: pinnedVersion,
		chromeVersion: pinnedVersion,
		browserVersion: pinnedVersion,
		chromeExecutable,
		nodeVersion: process.version,
		script: __filename,
		cdpTransport: 'remote-debugging-websocket',
	};
	if ( live ) {
		metadata.cdpProtocolVersion = 'fixture-cdp';
		metadata.browserPid = process.pid;
		metadata.browserInstanceId = `silent-exit-fixture-${ process.pid }`;
	}
	return metadata;
};
if ( process.argv.includes( '--version' ) ) {
	process.stdout.write( JSON.stringify( { status: 'ok', oracle: oracle( false ) } ) + '\n' );
	process.exit( 0 );
}
const marker = process.env.HTML_API_FUZZ_SILENT_EXIT_MARKER;
const retry = fs.existsSync( marker );
if ( retry ) {
	fs.appendFileSync( marker, 'retry\n' );
} else {
	fs.writeFileSync( marker, 'first\n' );
}
const lines = readline.createInterface( { input: process.stdin } );
lines.on( 'line', ( line ) => {
	const request = JSON.parse( line );
	if ( ! retry ) {
		process.exit( 1 );
	}
	if ( 'shutdown' === request.command ) {
		process.exit( 0 );
	}
	process.stdout.write( JSON.stringify( {
		id: request.id,
		serverPid: process.pid,
		status: 'ok',
		oracle: oracle( true ),
		treeBase64: Buffer.from( '\n' ).toString( 'base64' ),
		nodeCount: 0,
	} ) + '\n' );
} );
JS;
	$silent_exit_source = str_replace( '__PINNED_CHROME_VERSION__', json_encode( $chrome_fixture_pin, JSON_THROW_ON_ERROR ), $silent_exit_source );
	file_put_contents(
		$silent_exit_script,
		$silent_exit_source
	);
	putenv( 'HTML_API_FUZZ_SILENT_EXIT_MARKER=' . $silent_exit_marker );
	$silent_exit_oracle = \HtmlApiFuzz\OracleRenderer::from_options(
		array(
			'dom-oracle'           => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
			'chrome-oracle-script' => $silent_exit_script,
			'chrome-executable'    => $chrome_fixture_executable,
		)
	);
	$silent_exit_result = $silent_exit_oracle->render( '', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10 ), 'body' );
	html_api_fuzz_chrome_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_ERROR === ( $silent_exit_result['status'] ?? null ), 'Silent exit-1 oracle was retried and accepted.' );
	html_api_fuzz_chrome_smoke_assert( 1 === ( $silent_exit_result['process']['code'] ?? null ), 'Silent exit-1 oracle lost its first observed process status.' );
	html_api_fuzz_chrome_smoke_assert( "first\n" === file_get_contents( $silent_exit_marker ), 'Silent exit-1 oracle started a retry process.' );
	putenv( 'HTML_API_FUZZ_SILENT_EXIT_MARKER' );

	$stdio_lifecycle_before = html_api_fuzz_chrome_smoke_lifecycle_roots();
	$forced_stdio_oracle = \HtmlApiFuzz\OracleRenderer::from_options(
		array(
			'dom-oracle'           => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
			'chrome-oracle-script' => 'tools/html-api-fuzz/oracles/chrome/chrome-tree-oracle.js',
			'oracle-timeout-ms'    => '250',
		)
	);
	$forced_stdio_primed = $forced_stdio_oracle->render( '', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10 ), 'body' );
	html_api_fuzz_chrome_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $forced_stdio_primed['status'] ?? null ), 'Forced stdio Chrome fixture did not prime its persistent server.' );
	$stdio_lifecycle = html_api_fuzz_chrome_smoke_new_lifecycle( $stdio_lifecycle_before, 'Forced stdio Chrome fixture' );
	$stdio_record = $stdio_lifecycle['record'];
	$stdio_node_pid = $stdio_record['serverPid'] ?? null;
	$stdio_group_pid = $stdio_record['browserGroupPid'] ?? null;
	html_api_fuzz_chrome_smoke_assert( is_int( $stdio_node_pid ) && is_int( $stdio_group_pid ) && 'gated' === ( $stdio_record['phase'] ?? null ), 'Forced stdio Chrome fixture published an invalid lifecycle record.' );
	html_api_fuzz_chrome_smoke_assert( is_dir( $stdio_record['profilePath'] ?? '' ), 'Forced stdio Chrome fixture did not create its owned profile.' );
	$forced_node_pids[] = $stdio_node_pid;
	html_api_fuzz_chrome_smoke_assert( posix_kill( $stdio_node_pid, SIGSTOP ), 'Could not stop the forced stdio Chrome daemon.' );
	$stdio_forced_started = microtime( true );
	$stdio_forced = $forced_stdio_oracle->render( '<p>force-stdio-cleanup', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10 ), 'body' );
	$stdio_forced_duration = microtime( true ) - $stdio_forced_started;
	html_api_fuzz_chrome_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_ERROR === ( $stdio_forced['status'] ?? null ), 'Stopped stdio Chrome daemon did not fail its request.' );
	html_api_fuzz_chrome_smoke_assert( false !== strpos( \HtmlApiFuzz\json_encode_safe( $stdio_forced ), 'required SIGKILL' ), 'Forced stdio Chrome cleanup hid its authoritative SIGKILL outcome.' );
	html_api_fuzz_chrome_smoke_assert( $stdio_forced_duration >= 44.0 && $stdio_forced_duration < 60.0, "Forced stdio Chrome cleanup exceeded its bounded escalation window ({$stdio_forced_duration}s)." );
	html_api_fuzz_chrome_smoke_wait_for_process_absence( $stdio_node_pid, false, 'Forced stdio Chrome daemon survived cleanup.' );
	$forced_node_index = array_search( $stdio_node_pid, $forced_node_pids, true );
	html_api_fuzz_chrome_smoke_assert( false !== $forced_node_index, 'Forced stdio Chrome daemon was missing from the fallback PID set.' );
	unset( $forced_node_pids[ $forced_node_index ] );
	html_api_fuzz_chrome_smoke_wait_for_process_absence( $stdio_group_pid, true, 'Forced stdio Chrome process group survived cleanup.' );
	html_api_fuzz_chrome_smoke_assert( ! file_exists( $stdio_record['profilePath'] ), 'Forced stdio Chrome cleanup retained its profile.' );
	html_api_fuzz_chrome_smoke_assert( $stdio_lifecycle_before === html_api_fuzz_chrome_smoke_lifecycle_roots(), 'Forced stdio Chrome cleanup retained its ownership root.' );

	$run_lifecycle_before = html_api_fuzz_chrome_smoke_lifecycle_roots();
	$forced_run_oracle = \HtmlApiFuzz\OracleRenderer::from_options( $oracle_options );
	$forced_run_oracle->start_run_service( $work_dir . '/forced-run-service' );
	$forced_run_args = $forced_run_oracle->worker_args();
	$forced_run_socket_index = array_search( '--chrome-socket', $forced_run_args, true );
	$forced_run_socket = $forced_run_args[ $forced_run_socket_index + 1 ] ?? null;
	$run_lifecycle = html_api_fuzz_chrome_smoke_new_lifecycle( $run_lifecycle_before, 'Forced run-service Chrome fixture' );
	$run_record = $run_lifecycle['record'];
	$run_node_pid = $run_record['serverPid'] ?? null;
	$run_group_pid = $run_record['browserGroupPid'] ?? null;
	html_api_fuzz_chrome_smoke_assert( is_int( $run_node_pid ) && is_int( $run_group_pid ) && 'gated' === ( $run_record['phase'] ?? null ), 'Forced run-service Chrome fixture published an invalid lifecycle record.' );
	$forced_node_pids[] = $run_node_pid;
	html_api_fuzz_chrome_smoke_assert( posix_kill( $run_node_pid, SIGSTOP ), 'Could not stop the forced run-service Chrome daemon.' );
	$run_forced_started = microtime( true );
	$run_forced_message = null;
	try {
		$forced_run_oracle->stop_run_service();
	} catch ( \RuntimeException $error ) {
		$run_forced_message = $error->getMessage();
	}
	$run_forced_duration = microtime( true ) - $run_forced_started;
	html_api_fuzz_chrome_smoke_assert( is_string( $run_forced_message ) && false !== strpos( $run_forced_message, 'required SIGKILL' ), 'Forced run-service Chrome cleanup hid its authoritative SIGKILL outcome.' );
	html_api_fuzz_chrome_smoke_assert( $run_forced_duration >= 44.0 && $run_forced_duration < 60.0, "Forced run-service Chrome cleanup exceeded its bounded escalation window ({$run_forced_duration}s)." );
	html_api_fuzz_chrome_smoke_wait_for_process_absence( $run_node_pid, false, 'Forced run-service Chrome daemon survived cleanup.' );
	$forced_node_index = array_search( $run_node_pid, $forced_node_pids, true );
	html_api_fuzz_chrome_smoke_assert( false !== $forced_node_index, 'Forced run-service Chrome daemon was missing from the fallback PID set.' );
	unset( $forced_node_pids[ $forced_node_index ] );
	html_api_fuzz_chrome_smoke_wait_for_process_absence( $run_group_pid, true, 'Forced run-service Chrome process group survived cleanup.' );
	html_api_fuzz_chrome_smoke_assert( is_string( $forced_run_socket ) && ! file_exists( $forced_run_socket ), 'Forced run-service Chrome cleanup retained its socket.' );
	html_api_fuzz_chrome_smoke_assert( ! file_exists( $run_record['profilePath'] ?? '' ), 'Forced run-service Chrome cleanup retained its profile.' );
	html_api_fuzz_chrome_smoke_assert( $run_lifecycle_before === html_api_fuzz_chrome_smoke_lifecycle_roots(), 'Forced run-service Chrome cleanup retained its ownership root.' );
	$forced_run_oracle = null;

	putenv( 'HTML_API_FUZZ_CLEANUP_TREE_BASE64' );
	putenv( 'HTML_API_FUZZ_CLEANUP_NODE_COUNT' );
} finally {
	$oracle->stop_run_service();
	if ( $hostile_oracle instanceof \HtmlApiFuzz\OracleRenderer ) {
		$hostile_oracle->stop_run_service();
	}
	if ( $cleanup_failure_oracle instanceof \HtmlApiFuzz\OracleRenderer ) {
		try {
			$cleanup_failure_oracle->stop_run_service();
		} catch ( \RuntimeException $error ) {
			// The assertion path above owns the cleanup diagnostic.
		}
	}
	foreach ( $forced_node_pids as $forced_node_pid ) {
		@posix_kill( $forced_node_pid, SIGCONT );
	}
	if ( $forced_run_oracle instanceof \HtmlApiFuzz\OracleRenderer ) {
		try {
			$forced_run_oracle->stop_run_service();
		} catch ( \RuntimeException $error ) {
			// The assertion path above owns the cleanup diagnostic.
		}
	}
	\HtmlApiFuzz\OracleRenderer::shutdown_chrome_processes();
	putenv( 'HTML_API_FUZZ_SILENT_EXIT_MARKER' );
	html_api_fuzz_chrome_smoke_restore_env( 'HTML_API_FUZZ_CHROME_TEST_ALLOW_INVALIDATE_SESSION', $old_invalidate_env );
	html_api_fuzz_chrome_smoke_restore_env( 'HTML_API_FUZZ_CHROME_SOCKET', $old_socket_env );
	\HtmlApiFuzz\remove_dir_recursive( $work_dir );
}

html_api_fuzz_chrome_smoke_assert( ! is_dir( $work_dir ), 'Expected Chrome smoke work directory cleanup.' );
html_api_fuzz_chrome_smoke_assert( $profiles_before === html_api_fuzz_chrome_smoke_profiles(), 'Chrome smoke left new profile directories behind.' );
html_api_fuzz_chrome_smoke_assert( $lifecycle_before === html_api_fuzz_chrome_smoke_lifecycle_roots(), 'Chrome smoke left new lifecycle ownership roots behind.' );
echo "OK chrome-oracle-smoke\n";
