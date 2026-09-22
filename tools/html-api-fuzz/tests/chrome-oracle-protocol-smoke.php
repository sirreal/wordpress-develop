#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_chrome_protocol_fail( string $message ): void {
	throw new RuntimeException( $message );
}

function html_api_fuzz_chrome_protocol_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_chrome_protocol_fail( $message );
	}
}

function html_api_fuzz_chrome_protocol_mutate( array $value, array $mutations ): array {
	foreach ( $mutations as $key => $replacement ) {
		if ( null === $replacement ) {
			unset( $value[ $key ] );
		} else {
			$value[ $key ] = $replacement;
		}
	}
	return $value;
}

function html_api_fuzz_chrome_protocol_relative_path( string $from, string $to ): string {
	$from_parts = explode( DIRECTORY_SEPARATOR, trim( $from, DIRECTORY_SEPARATOR ) );
	$to_parts = explode( DIRECTORY_SEPARATOR, trim( $to, DIRECTORY_SEPARATOR ) );
	while ( ! empty( $from_parts ) && ! empty( $to_parts ) && $from_parts[0] === $to_parts[0] ) {
		array_shift( $from_parts );
		array_shift( $to_parts );
	}
	return str_repeat( '..' . DIRECTORY_SEPARATOR, count( $from_parts ) ) . implode( DIRECTORY_SEPARATOR, $to_parts );
}

function html_api_fuzz_chrome_protocol_lifecycle_roots(): array {
	$roots = array();
	foreach ( glob( sys_get_temp_dir() . '/html-api-fuzz-chrome-owner-*', GLOB_ONLYDIR ) ?: array() as $root ) {
		if ( 1 === preg_match( '/^html-api-fuzz-chrome-owner-[a-f0-9]{32}$/', basename( $root ) ) ) {
			$roots[] = $root;
		}
	}
	sort( $roots, SORT_STRING );
	return $roots;
}

function html_api_fuzz_chrome_protocol_wait_for_pid_absence( int $pid, string $message ): void {
	if ( ! function_exists( 'posix_kill' ) ) {
		html_api_fuzz_chrome_protocol_fail( 'Chrome protocol PID cleanup checks require posix_kill().' );
	}
	$deadline = microtime( true ) + 5.0;
	$esrch = defined( 'PCNTL_ESRCH' ) ? constant( 'PCNTL_ESRCH' ) : 3;
	do {
		if ( ! @posix_kill( $pid, 0 ) && $esrch === posix_get_last_error() ) {
			return;
		}
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	html_api_fuzz_chrome_protocol_fail( $message );
}

function html_api_fuzz_chrome_protocol_oracle( string $script, string $executable, string $pin, bool $live ): array {
	$oracle = array(
		'kind'                 => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
		'engine'               => 'chrome',
		'available'            => true,
		'pinnedChromeVersion' => $pin,
		'chromeVersion'       => $pin,
		'browserVersion'      => $pin,
		'chromeExecutable'    => $executable,
		'nodeVersion'         => 'v-fixture',
		'script'              => $script,
		'cdpTransport'        => 'remote-debugging-websocket',
	);
	if ( $live ) {
		$oracle['cdpProtocolVersion'] = 'fixture-cdp';
		$oracle['browserPid'] = 4242;
		$oracle['browserInstanceId'] = 'fixture-browser-instance';
	}
	return $oracle;
}

function html_api_fuzz_chrome_protocol_fake(
	string $directory,
	string $reported_executable,
	string $pin,
	array $configuration = array()
): string {
	static $counter = 0;
	++$counter;
	$script = $directory . '/fake-chrome-oracle-' . $counter . '.js';
	$static_oracle = html_api_fuzz_chrome_protocol_mutate(
		html_api_fuzz_chrome_protocol_oracle( $script, $reported_executable, $pin, false ),
		$configuration['versionOracle'] ?? array()
	);
	$live_oracle = html_api_fuzz_chrome_protocol_mutate(
		html_api_fuzz_chrome_protocol_oracle( $script, $reported_executable, $pin, true ),
		$configuration['renderOracle'] ?? array()
	);
	$socket_oracle = html_api_fuzz_chrome_protocol_mutate(
		html_api_fuzz_chrome_protocol_oracle( $script, $reported_executable, $pin, true ),
		$configuration['socketOracle'] ?? array()
	);
	$version = html_api_fuzz_chrome_protocol_mutate(
		array( 'status' => 'ok', 'oracle' => $static_oracle ),
		$configuration['versionFields'] ?? array()
	);
	$socket_version = html_api_fuzz_chrome_protocol_mutate(
		array( 'status' => 'ok', 'oracle' => $socket_oracle ),
		$configuration['socketFields'] ?? array()
	);
	$render = html_api_fuzz_chrome_protocol_mutate(
		array(
			'status'     => 'ok',
			'oracle'     => $live_oracle,
			'nodeCount'  => 0,
			'tree'       => "\n",
			'treeBase64' => base64_encode( "\n" ),
		),
		$configuration['renderFields'] ?? array()
	);
	$fixtures = base64_encode(
		json_encode(
			array(
				'version'       => $version,
				'versionExit'   => $configuration['versionExit'] ?? 0,
				'socketVersion' => $socket_version,
				'render'        => $render,
			),
			JSON_THROW_ON_ERROR
		)
	);
	$source = <<<'JS'
#!/usr/bin/env node
'use strict';
const fs = require( 'node:fs' );
const net = require( 'node:net' );
const readline = require( 'node:readline' );
const fixtures = JSON.parse( Buffer.from( '__FIXTURES__', 'base64' ).toString( 'utf8' ) );
const write = ( stream, value ) => stream.write( JSON.stringify( value ) + '\n' );
const response = ( request ) => {
	let result;
	if ( 'version' === request.command ) {
		result = fixtures.socketVersion;
	} else if ( 'render' === request.command || ! request.command ) {
		result = fixtures.render;
	} else if ( 'shutdown' === request.command ) {
		result = { status: 'ok', oracle: fixtures.socketVersion.oracle, shutdown: true };
	} else {
		result = { status: 'error', failureClass: 'oracle-renderer-error', error: 'unknown command', oracle: fixtures.version.oracle, nodeCount: 0 };
	}
	result = JSON.parse( JSON.stringify( result ) );
	if ( 4242 === result.oracle?.browserPid ) {
		result.oracle.browserPid = process.pid;
	}
	return { ...result, id: request.id, serverPid: process.pid };
};
if ( process.argv.includes( '--version' ) ) {
	write( process.stdout, fixtures.version );
	process.exit( fixtures.versionExit );
}
const socketIndex = process.argv.indexOf( '--socket' );
if ( -1 !== socketIndex ) {
	const socketPath = process.argv[ socketIndex + 1 ];
	try { fs.unlinkSync( socketPath ); } catch ( error ) { if ( 'ENOENT' !== error.code ) throw error; }
	const server = net.createServer( ( socket ) => {
		const lines = readline.createInterface( { input: socket } );
		lines.on( 'line', ( line ) => {
			const request = JSON.parse( line );
			write( socket, response( request ) );
			if ( 'shutdown' === request.command ) {
				lines.close();
				server.close( () => {
					try { fs.unlinkSync( socketPath ); } catch ( error ) { if ( 'ENOENT' !== error.code ) throw error; }
				} );
			}
		} );
	} );
	server.listen( socketPath, () => write( process.stdout, { status: 'ready', socket: socketPath } ) );
} else {
	const lines = readline.createInterface( { input: process.stdin } );
	lines.on( 'line', ( line ) => {
		const request = JSON.parse( line );
		write( process.stdout, response( request ) );
		if ( 'shutdown' === request.command ) {
			lines.close();
		}
	} );
}
JS;
	$source = str_replace( '__FIXTURES__', $fixtures, $source );
	if ( false === file_put_contents( $script, $source ) ) {
		html_api_fuzz_chrome_protocol_fail( 'Could not create a fake Chrome oracle.' );
	}
	return $script;
}

function html_api_fuzz_chrome_protocol_renderer( string $script, string $executable ): \HtmlApiFuzz\OracleRenderer {
	return \HtmlApiFuzz\OracleRenderer::from_options(
		array(
			'dom-oracle'           => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
			'chrome-oracle-script' => $script,
			'chrome-executable'    => $executable,
			'node-bin'             => 'node',
			'oracle-timeout-ms'    => 1000,
		)
	);
}

function html_api_fuzz_chrome_protocol_render_case(
	string $directory,
	string $configured_executable,
	string $reported_executable,
	string $pin,
	array $configuration = array()
): array {
	$script = html_api_fuzz_chrome_protocol_fake( $directory, $reported_executable, $pin, $configuration );
	$renderer = html_api_fuzz_chrome_protocol_renderer( $script, $configured_executable );
	$result = null;
	try {
		$result = $renderer->render( 'x', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 20 ), 'body' );
	} finally {
		\HtmlApiFuzz\OracleRenderer::shutdown_chrome_processes();
	}
	if ( is_int( $result['oracle']['browserPid'] ?? null ) ) {
		html_api_fuzz_chrome_protocol_wait_for_pid_absence( $result['oracle']['browserPid'], 'Fake stdio Chrome daemon survived renderer cleanup.' );
	}
	return $result;
}

function html_api_fuzz_chrome_protocol_assert_rejected( array $result, string $message ): void {
	html_api_fuzz_chrome_protocol_assert( \HtmlApiFuzz\TreeRenderer::STATUS_ERROR === ( $result['status'] ?? null ), $message . ' status' );
	html_api_fuzz_chrome_protocol_assert( 'oracle-renderer-error' === ( $result['failureClass'] ?? null ), $message . ' failure class' );
}

$root = \HtmlApiFuzz\repo_root();
$work_dir = sys_get_temp_dir() . '/html-api-fuzz-chrome-protocol-' . \HtmlApiFuzz\timestamp();
\HtmlApiFuzz\ensure_dir( $work_dir );
$lifecycle_before = html_api_fuzz_chrome_protocol_lifecycle_roots();
$failure = null;

try {
	$pin = trim( file_get_contents( $root . '/tools/html-api-fuzz/oracles/chrome/VERSION' ) );
	$executable = $work_dir . '/fake-chrome';
	if ( false === file_put_contents( $executable, "#!/bin/sh\nexit 0\n" ) || ! chmod( $executable, 0700 ) ) {
		html_api_fuzz_chrome_protocol_fail( 'Could not create a fake Chrome executable.' );
	}
	$executable_alias = $work_dir . '/fake-chrome-link';
	if ( ! symlink( $executable, $executable_alias ) ) {
		html_api_fuzz_chrome_protocol_fail( 'Could not create a fake Chrome executable symlink.' );
	}
	$relative_alias = html_api_fuzz_chrome_protocol_relative_path( $root, $executable_alias );
	$valid_script = html_api_fuzz_chrome_protocol_fake(
		$work_dir,
		$executable_alias,
		$pin,
		array(
			'versionOracle' => array(
				'nodeBinary'  => 'lying-node',
				'chromeSocket'=> '/lying/socket',
				'serverPid'   => 99999,
				'arbitrary'   => 'discard-me',
			),
		)
	);
	$valid_metadata = html_api_fuzz_chrome_protocol_renderer( $valid_script, $relative_alias )->metadata();
	html_api_fuzz_chrome_protocol_assert( true === ( $valid_metadata['available'] ?? null ), 'Expected valid static Chrome metadata.' );
	html_api_fuzz_chrome_protocol_assert( realpath( $executable ) === ( $valid_metadata['chromeExecutable'] ?? null ), 'Expected relative symlink executable to use canonical identity.' );
	html_api_fuzz_chrome_protocol_assert( realpath( $valid_script ) === ( $valid_metadata['script'] ?? null ), 'Expected trusted local script identity.' );
	html_api_fuzz_chrome_protocol_assert( 'node' === ( $valid_metadata['nodeBinary'] ?? null ), 'Reported metadata overrode the local Node binary.' );
	html_api_fuzz_chrome_protocol_assert( null === ( $valid_metadata['chromeSocket'] ?? null ), 'Reported metadata overrode the local socket.' );
	foreach ( array( 'arbitrary', 'serverPid', 'browserPid', 'browserInstanceId' ) as $key ) {
		html_api_fuzz_chrome_protocol_assert( ! array_key_exists( $key, $valid_metadata ), "Static metadata retained untrusted {$key}." );
	}

	$wrong_path = $work_dir . '/wrong-executable';
	file_put_contents( $wrong_path, "#!/bin/sh\nexit 0\n" );
	chmod( $wrong_path, 0700 );
	$version_mutations = array(
		'kind'                => array( 'kind' => 'wrong' ),
		'missing kind'        => array( 'kind' => null ),
		'engine'              => array( 'engine' => 'wrong' ),
		'available'           => array( 'available' => false ),
		'pin'                 => array( 'pinnedChromeVersion' => 'wrong' ),
		'installed version'   => array( 'chromeVersion' => 'wrong' ),
		'live version'        => array( 'browserVersion' => 'wrong' ),
		'transport'           => array( 'cdpTransport' => 'wrong' ),
		'node version'        => array( 'nodeVersion' => 20 ),
		'script path'         => array( 'script' => $wrong_path ),
		'executable path'     => array( 'chromeExecutable' => $wrong_path ),
	);
	foreach ( $version_mutations as $label => $mutation ) {
		$script = html_api_fuzz_chrome_protocol_fake( $work_dir, $executable, $pin, array( 'versionOracle' => $mutation ) );
		$metadata = html_api_fuzz_chrome_protocol_renderer( $script, $executable )->metadata();
		html_api_fuzz_chrome_protocol_assert( false === ( $metadata['available'] ?? true ), "Expected static version metadata to reject {$label}." );
		html_api_fuzz_chrome_protocol_assert( ! array_key_exists( 'arbitrary', $metadata ), "Rejected {$label} metadata leaked an arbitrary key." );
	}
	$script = html_api_fuzz_chrome_protocol_fake( $work_dir, $executable, $pin, array( 'versionFields' => array( 'status' => 'error', 'error' => str_repeat( 'x', 2000 ) ) ) );
	$metadata = html_api_fuzz_chrome_protocol_renderer( $script, $executable )->metadata();
	html_api_fuzz_chrome_protocol_assert( false === ( $metadata['available'] ?? true ) && strlen( $metadata['versionError'] ?? '' ) < 600, 'Expected bounded rejection of non-ok version status.' );
	$script = html_api_fuzz_chrome_protocol_fake( $work_dir, $executable, $pin, array( 'versionExit' => 3 ) );
	$metadata = html_api_fuzz_chrome_protocol_renderer( $script, $executable )->metadata();
	html_api_fuzz_chrome_protocol_assert( false === ( $metadata['available'] ?? true ) && false !== strpos( $metadata['versionError'] ?? '', 'code 3' ), 'Expected nonzero version exit rejection.' );

	$valid = html_api_fuzz_chrome_protocol_render_case(
		$work_dir,
		$executable,
		$executable,
		$pin,
		array( 'renderOracle' => array( 'arbitrary' => 'discard-me', 'nodeBinary' => 'lying-node' ) )
	);
	html_api_fuzz_chrome_protocol_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $valid['status'] ?? null ) && "\n" === ( $valid['tree'] ?? null ), 'Expected a valid Chrome render.' );
	html_api_fuzz_chrome_protocol_assert( is_int( $valid['oracle']['browserPid'] ?? null ), 'Expected validated live browser PID.' );
	html_api_fuzz_chrome_protocol_assert( ! array_key_exists( 'arbitrary', $valid['oracle'] ) && 'node' === ( $valid['oracle']['nodeBinary'] ?? null ), 'Valid render retained untrusted metadata.' );

	$unsupported = html_api_fuzz_chrome_protocol_render_case(
		$work_dir,
		$executable,
		$executable,
		$pin,
		array(
			'renderFields' => array(
				'status'       => 'unsupported',
				'failureClass' => 'oracle-unsupported',
				'unsupported'  => array( 'message' => 'fixture unsupported', 'extra' => 'discard' ),
			)
		)
	);
	html_api_fuzz_chrome_protocol_assert( \HtmlApiFuzz\TreeRenderer::STATUS_UNSUPPORTED === ( $unsupported['status'] ?? null ), 'Expected valid unsupported Chrome response.' );
	html_api_fuzz_chrome_protocol_assert( array( 'message' => 'fixture unsupported' ) === ( $unsupported['unsupported'] ?? null ), 'Unsupported response retained untrusted details.' );

	foreach ( array( 'oracle-unavailable', 'oracle-renderer-error', 'node-limit-exceeded' ) as $failure_class ) {
		$error = html_api_fuzz_chrome_protocol_render_case(
			$work_dir,
			$executable,
			$executable,
			$pin,
			array(
				'renderOracle' => array(
					'cdpProtocolVersion' => null,
					'browserPid' => null,
					'browserInstanceId' => null,
				),
				'renderFields' => array( 'status' => 'error', 'failureClass' => $failure_class, 'error' => 'fixture ' . $failure_class )
			)
		);
		html_api_fuzz_chrome_protocol_assert( \HtmlApiFuzz\TreeRenderer::STATUS_ERROR === ( $error['status'] ?? null ), "Expected accepted {$failure_class} response." );
		html_api_fuzz_chrome_protocol_assert( $failure_class === ( $error['failureClass'] ?? null ), "Expected accepted {$failure_class} classification." );
		html_api_fuzz_chrome_protocol_assert( ! array_key_exists( 'browserPid', $error['oracle'] ), "Pre-start {$failure_class} invented a browser PID." );
	}
	$live_error = html_api_fuzz_chrome_protocol_render_case(
		$work_dir,
		$executable,
		$executable,
		$pin,
		array( 'renderFields' => array( 'status' => 'error', 'failureClass' => 'node-limit-exceeded', 'error' => 'limit' ) )
	);
	html_api_fuzz_chrome_protocol_assert( is_int( $live_error['oracle']['browserPid'] ?? null ), 'Expected a coherent live error identity.' );

	$invalid_identity_cases = array(
		'wrong kind'        => array( 'kind' => 'wrong', 'arbitrary' => 'discard-me' ),
		'missing pin'       => array( 'pinnedChromeVersion' => null, 'arbitrary' => 'discard-me' ),
		'wrong live version'=> array( 'browserVersion' => 'wrong', 'arbitrary' => 'discard-me' ),
		'missing protocol'  => array( 'cdpProtocolVersion' => null, 'arbitrary' => 'discard-me' ),
		'empty protocol'    => array( 'cdpProtocolVersion' => '', 'arbitrary' => 'discard-me' ),
		'missing PID'       => array( 'browserPid' => null, 'arbitrary' => 'discard-me' ),
		'non-integer PID'   => array( 'browserPid' => '4242', 'arbitrary' => 'discard-me' ),
		'zero PID'          => array( 'browserPid' => 0, 'arbitrary' => 'discard-me' ),
		'missing instance'  => array( 'browserInstanceId' => null, 'arbitrary' => 'discard-me' ),
		'wrong script'      => array( 'script' => $wrong_path, 'arbitrary' => 'discard-me' ),
		'wrong executable'  => array( 'chromeExecutable' => $wrong_path, 'arbitrary' => 'discard-me' ),
	);
	foreach ( $invalid_identity_cases as $label => $mutation ) {
		$result = html_api_fuzz_chrome_protocol_render_case( $work_dir, $executable, $executable, $pin, array( 'renderOracle' => $mutation ) );
		html_api_fuzz_chrome_protocol_assert_rejected( $result, "Expected render identity to reject {$label}." );
		html_api_fuzz_chrome_protocol_assert( \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP === ( $result['oracle']['kind'] ?? null ), "Rejected {$label} overrode trusted kind." );
		html_api_fuzz_chrome_protocol_assert( $pin === ( $result['oracle']['pinnedChromeVersion'] ?? null ), "Rejected {$label} overrode trusted pin." );
		html_api_fuzz_chrome_protocol_assert( realpath( $executable ) === ( $result['oracle']['chromeExecutable'] ?? null ), "Rejected {$label} overrode trusted executable." );
		html_api_fuzz_chrome_protocol_assert( ! array_key_exists( 'browserPid', $result['oracle'] ) && ! array_key_exists( 'arbitrary', $result['oracle'] ), "Rejected {$label} leaked untrusted PID or extra metadata." );
	}

	$invalid_payload_cases = array(
		'unknown status' => array( 'renderFields' => array( 'status' => 'unknown' ) ),
		'missing nodeCount' => array( 'renderFields' => array( 'nodeCount' => null ) ),
		'string nodeCount' => array( 'renderFields' => array( 'nodeCount' => '0' ) ),
		'negative nodeCount' => array( 'renderFields' => array( 'nodeCount' => -1 ) ),
		'positive error nodeCount' => array( 'renderFields' => array( 'status' => 'error', 'failureClass' => 'oracle-renderer-error', 'error' => 'x', 'nodeCount' => 1 ) ),
		'missing treeBase64' => array( 'renderFields' => array( 'treeBase64' => null ) ),
		'invalid treeBase64' => array( 'renderFields' => array( 'treeBase64' => '***' ) ),
		'disagreeing trees' => array( 'renderFields' => array( 'tree' => 'wrong' ) ),
		'malformed unsupported class' => array( 'renderFields' => array( 'status' => 'unsupported', 'failureClass' => 'wrong', 'unsupported' => array( 'message' => 'x' ) ) ),
		'malformed unsupported message' => array( 'renderFields' => array( 'status' => 'unsupported', 'failureClass' => 'oracle-unsupported', 'unsupported' => array() ) ),
		'unknown error class' => array( 'renderFields' => array( 'status' => 'error', 'failureClass' => 'arbitrary', 'error' => 'x' ) ),
		'missing error message' => array( 'renderFields' => array( 'status' => 'error', 'failureClass' => 'oracle-renderer-error', 'error' => null ) ),
		'partial live error' => array(
			'renderOracle' => array( 'cdpProtocolVersion' => null, 'browserInstanceId' => null ),
			'renderFields' => array( 'status' => 'error', 'failureClass' => 'oracle-renderer-error', 'error' => 'x' ),
		),
	);
	foreach ( $invalid_payload_cases as $label => $configuration ) {
		$result = html_api_fuzz_chrome_protocol_render_case( $work_dir, $executable, $executable, $pin, $configuration );
		html_api_fuzz_chrome_protocol_assert_rejected( $result, "Expected Chrome protocol to reject {$label}." );
	}

	$socket_script = html_api_fuzz_chrome_protocol_fake( $work_dir, $executable, $pin );
	$owner = html_api_fuzz_chrome_protocol_renderer( $socket_script, $executable );
	$owner->start_run_service( $work_dir . '/valid-socket' );
	$args = $owner->worker_args();
	$socket_index = array_search( '--chrome-socket', $args, true );
	$socket = $args[ $socket_index + 1 ] ?? null;
	$socket_renderer = \HtmlApiFuzz\OracleRenderer::from_options(
		array(
			'dom-oracle'           => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
			'chrome-oracle-script' => $socket_script,
			'chrome-executable'    => $executable,
			'chrome-socket'        => $socket,
		)
	);
	$socket_metadata = $socket_renderer->metadata();
	$socket_daemon_pid = $socket_metadata['browserPid'] ?? null;
	html_api_fuzz_chrome_protocol_assert( true === ( $socket_metadata['available'] ?? null ) && is_int( $socket_daemon_pid ), 'Expected valid live socket version identity.' );
	$owner->stop_run_service();
	html_api_fuzz_chrome_protocol_wait_for_pid_absence( $socket_daemon_pid, 'Fake socket Chrome daemon survived service cleanup.' );

	$invalid_socket_script = html_api_fuzz_chrome_protocol_fake( $work_dir, $executable, $pin, array( 'socketOracle' => array( 'browserPid' => null ) ) );
	$invalid_owner = html_api_fuzz_chrome_protocol_renderer( $invalid_socket_script, $executable );
	$readiness_error = null;
	try {
		$invalid_owner->start_run_service( $work_dir . '/invalid-socket' );
	} catch ( RuntimeException $error ) {
		$readiness_error = $error->getMessage();
	}
	html_api_fuzz_chrome_protocol_assert( is_string( $readiness_error ) && false !== strpos( $readiness_error, 'browserPid' ), 'Expected readiness to reject missing live socket PID.' );
	$invalid_owner->stop_run_service();
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	\HtmlApiFuzz\OracleRenderer::shutdown_chrome_processes();
	if ( $lifecycle_before !== html_api_fuzz_chrome_protocol_lifecycle_roots() && null === $failure ) {
		$failure = new RuntimeException( 'Chrome protocol smoke retained a lifecycle ownership root.' );
	}
	\HtmlApiFuzz\remove_dir_recursive( $work_dir );
}

if ( null !== $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure->getMessage() . "\n" );
	exit( 1 );
}
if ( is_dir( $work_dir ) ) {
	fwrite( STDERR, "FAIL: Expected Chrome protocol work directory cleanup.\n" );
	exit( 1 );
}
echo "OK chrome-oracle-protocol-smoke\n";
