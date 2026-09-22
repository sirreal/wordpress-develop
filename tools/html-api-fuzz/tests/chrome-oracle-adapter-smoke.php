#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

ini_set( 'memory_limit', '768M' );

function html_api_fuzz_chrome_adapter_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function html_api_fuzz_chrome_adapter_wait( callable $condition, float $seconds, string $message ): void {
	$deadline = microtime( true ) + $seconds;
	do {
		if ( $condition() ) {
			return;
		}
		usleep( 20000 );
	} while ( microtime( true ) < $deadline );
	html_api_fuzz_chrome_adapter_assert( false, $message );
}

function html_api_fuzz_chrome_adapter_assert_state_clean( string $path, string $label ): array {
	html_api_fuzz_chrome_adapter_wait( static fn (): bool => is_file( $path ), 5.0, "Expected {$label} service state." );
	$state = json_decode( (string) file_get_contents( $path ), true );
	html_api_fuzz_chrome_adapter_assert( is_array( $state ) && is_string( $state['runtimeRoot'] ?? null ), "Expected valid {$label} service state." );
	html_api_fuzz_chrome_adapter_wait(
		static fn (): bool => ! file_exists( $state['runtimeRoot'] ),
		10.0,
		"Expected explicit {$label} runtime cleanup."
	);
	return $state;
}

function html_api_fuzz_chrome_adapter_renderer( string $script, array $real_options, string $case = 'ok', int $render_timeout_ms = 10000, int $startup_timeout_ms = 5000 ): \HtmlApiFuzz\OracleRenderer {
	putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_CASE=' . $case );
	return \HtmlApiFuzz\OracleRenderer::from_options(
		array(
			'dom-oracle'               => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
			'chrome-oracle-script'     => $script,
			'chrome-executable'        => $real_options['chromeExecutable'],
			'node-bin'                 => $real_options['nodeBin'],
			'oracle-timeout-ms'        => (string) $render_timeout_ms,
			'chrome-startup-timeout-ms' => (string) $startup_timeout_ms,
		)
	);
}

$work_dir = sys_get_temp_dir() . '/html-api-fuzz-chrome-adapter-' . getmypid();
\HtmlApiFuzz\ensure_dir( $work_dir );
$fake_script = $work_dir . '/fake-chrome-client.js';
$fake_source = <<<'JS'
#!/usr/bin/env node
'use strict';
const crypto = require( 'crypto' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const readline = require( 'readline' );

const repo = process.env.HTML_API_FUZZ_TEST_REPO_ROOT;
const trust = path.join( repo, 'tools/html-api-fuzz/oracles/chrome' );
const versionRaw = fs.readFileSync( path.join( trust, 'VERSION' ) );
const version = versionRaw.toString( 'utf8' ).trim();
const contextsRaw = fs.readFileSync( path.join( repo, 'tools/html-api-fuzz/oracles/fragment-contexts.json' ) );
const contexts = JSON.parse( contextsRaw );
const platform = 'darwin' === process.platform ? ( 'arm64' === process.arch ? 'mac-arm64' : 'mac-x64' ) : 'linux64';
const digest = ( file ) => crypto.createHash( 'sha256' ).update( fs.readFileSync( file ) ).digest( 'hex' );
const manifestDigest = ( file, key ) => {
	const rows = fs.readFileSync( file, 'utf8' ).split( /\r?\n/ ).map( ( line ) => line.trim().split( /\s+/ ) ).filter( ( row ) => 2 === row.length && key === row[ 1 ] );
	if ( 1 !== rows.length ) throw new Error( 'missing manifest row ' + key );
	return rows[ 0 ][ 0 ];
};
const executableIndex = process.argv.indexOf( '--chrome-executable' );
const executable = fs.realpathSync( process.argv[ executableIndex + 1 ] );
const script = fs.realpathSync( __filename );
const node = fs.realpathSync( process.execPath );
const root = path.join( os.tmpdir(), 'html-api-fuzz-chrome-' + process.pid + '-' + crypto.randomBytes( 16 ).toString( 'hex' ) );
const profile = path.join( root, 'profile' );
fs.mkdirSync( profile, { recursive: true, mode: 0o700 } );
fs.chmodSync( root, 0o700 );
const lifecycle = ( event ) => {
	if ( process.env.HTML_API_FUZZ_TEST_CHROME_ADAPTER_LIFECYCLE ) {
		fs.appendFileSync( process.env.HTML_API_FUZZ_TEST_CHROME_ADAPTER_LIFECYCLE, JSON.stringify( { event, pid: process.pid, runtimeRoot: root } ) + '\n' );
	}
};
lifecycle( 'start' );
const executableSha = manifestDigest( path.join( trust, 'EXECUTABLE_SHA256SUMS' ), platform + '.executable' );
if ( digest( executable ) !== executableSha ) throw new Error( 'fake executable trust mismatch' );
const identity = {
	schemaVersion: 1,
	kind: 'chrome-cdp',
	platform,
	pinnedChromeVersion: version,
	chromeArchiveSha256: manifestDigest( path.join( trust, 'SHA256SUMS' ), 'chrome-' + version + '-' + platform + '.zip' ),
	expectedChromeExecutableSha256: executableSha,
	chromeExecutableSha256: executableSha,
	oracleScriptSha256: digest( script ),
	fragmentContextsSha256: crypto.createHash( 'sha256' ).update( contextsRaw ).digest( 'hex' ),
	fragmentContexts: contexts,
	nodeExecutableSha256: digest( node ),
	nodeVersion: process.version,
	chromeVersion: version,
	cdpProtocolVersion: '1.3',
};
const oracle = () => ( {
	kind: 'chrome-cdp',
	engine: 'chrome',
	available: true,
	identity,
	transport: {
		replayExcluded: true,
		ownerPid: process.pid,
		chromeExecutablePath: executable,
		oracleScriptPath: script,
		nodeExecutablePath: node,
		runtimeRoot: root,
		profilePath: profile,
		debugEndpoint: 'ws://127.0.0.1:9222/devtools/browser/fake',
		supervisorPid: process.pid,
		browserPid: process.pid,
		browserInstance: 1,
	},
} );
let cleaned = false;
const cleanup = () => {
	if ( cleaned ) return;
	cleaned = true;
	try { fs.rmSync( root, { recursive: true, force: true } ); } catch ( error ) {}
	lifecycle( 'cleanup' );
};
const startupCase = process.env.HTML_API_FUZZ_TEST_CHROME_ADAPTER_CASE || 'ok';
if ( 'cleanup-deadline' === startupCase ) {
	setInterval( () => {}, 1000 );
	for ( const signal of [ 'SIGTERM', 'SIGINT' ] ) process.on( signal, () => {} );
	process.stdin.on( 'end', () => {} );
} else {
	for ( const signal of [ 'SIGTERM', 'SIGINT' ] ) process.on( signal, () => { cleanup(); process.exit( 0 ); } );
	process.stdin.on( 'end', () => { cleanup(); process.exit( 0 ); } );
}
process.on( 'exit', cleanup );
if ( process.env.HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE ) {
	fs.writeFileSync( process.env.HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE, JSON.stringify( { pid: process.pid, runtimeRoot: root } ) + '\n' );
}
const send = ( value ) => process.stdout.write( JSON.stringify( value ) + '\n' );
const rl = readline.createInterface( { input: process.stdin, crlfDelay: Infinity } );
rl.on( 'line', ( line ) => {
	const request = JSON.parse( line );
	const testCase = process.env.HTML_API_FUZZ_TEST_CHROME_ADAPTER_CASE || 'ok';
	if ( 'version' === request.command ) {
		const respond = () => send( { id: request.id, status: 'ok', oracle: oracle() } );
		if ( 'startup-timeout' === testCase ) setTimeout( respond, 1000 );
		else if ( 'startup-slower-than-render' === testCase ) setTimeout( respond, 250 );
		else respond();
		return;
	}
	if ( 'shutdown' === request.command ) {
		if ( 'cleanup-deadline' === testCase ) return;
		const responseId = 'shutdown-wrong-id' === testCase ? request.id + 1 : request.id;
		const response = JSON.stringify( { id: responseId, status: 'ok', oracle: oracle(), shutdown: true } ) + '\n';
		lifecycle( 'shutdown-ack' );
		if ( 'immediate-shutdown' === testCase ) {
			process.stdout.write( response, () => { cleanup(); process.exit( 0 ); } );
		} else {
			process.stdout.write( response );
			setTimeout( () => { cleanup(); process.exit( 0 ); }, 50 );
		}
		return;
	}
	if ( 'render' !== request.command ) return;
	const once = process.env.HTML_API_FUZZ_TEST_CHROME_ADAPTER_ONCE;
	if ( ( 'render-timeout-once' === testCase || 'render-death-once' === testCase ) && once && ! fs.existsSync( once ) ) {
		fs.writeFileSync( once, String( process.pid ) );
		if ( 'render-death-once' === testCase ) {
			cleanup();
			process.exit( 7 );
		}
		return;
	}
	const input = Buffer.from( request.htmlBase64, 'base64' );
	if ( process.env.HTML_API_FUZZ_TEST_CHROME_ADAPTER_RENDER_LOG ) {
		fs.appendFileSync( process.env.HTML_API_FUZZ_TEST_CHROME_ADAPTER_RENDER_LOG, JSON.stringify( { pid: process.pid, bytes: input.length } ) + '\n' );
	}
	const tree = 'tree-16m' === testCase ? Buffer.alloc( 16 * 1024 * 1024, 0x78 ) : Buffer.from( '\n' );
	const result = {
		id: request.id,
		status: 'ok',
		oracle: oracle(),
		treeBase64: tree.toString( 'base64' ),
		treeBytes: tree.length,
		treeSha256: crypto.createHash( 'sha256' ).update( tree ).digest( 'hex' ),
		nodeCount: 1,
	};
	if ( 'extra-key' === testCase ) result.extra = true;
	if ( 'bad-tree-hash' === testCase ) result.treeSha256 = '0'.repeat( 64 );
	if ( 'wrong-id' === testCase ) result.id++;
	if ( 'wrong-identity' === testCase ) result.oracle.identity.nodeVersion += '-forged!';
	if ( 'missing-field' === testCase ) delete result.nodeCount;
	if ( 'malformed-json' === testCase ) {
		process.stdout.write( '{\n' );
		return;
	}
	if ( 'missing-response' === testCase ) return;
	if ( 'duplicate-key' === testCase ) {
		process.stdout.write( JSON.stringify( result ).replace( '"status":"ok"', '"status":"ok","status":"ok"' ) + '\n' );
		return;
	}
	if ( 'trailing-frame' === testCase ) {
		process.stdout.write( JSON.stringify( result ) + '\n{}\n' );
		return;
	}
	if ( 'stderr-overflow' === testCase ) process.stderr.write( 'e'.repeat( 1024 * 1024 + 1 ) );
	send( result );
} );
JS;
html_api_fuzz_chrome_adapter_assert( strlen( $fake_source ) === file_put_contents( $fake_script, $fake_source ), 'Expected fake Chrome client publication.' );
html_api_fuzz_chrome_adapter_assert( chmod( $fake_script, 0500 ), 'Expected executable fake Chrome client.' );
putenv( 'HTML_API_FUZZ_TEST_REPO_ROOT=' . \HtmlApiFuzz\repo_root() );

$real = \HtmlApiFuzz\OracleRenderer::from_options(
	array(
		'dom-oracle'        => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
		'oracle-timeout-ms' => '10000',
	)
);
$real_metadata = $real->metadata();
html_api_fuzz_chrome_adapter_assert( true === ( $real_metadata['available'] ?? false ), 'Expected pinned real Chrome availability: ' . (string) ( $real_metadata['error'] ?? '' ) );
html_api_fuzz_chrome_adapter_assert( array() === \HtmlApiFuzz\OracleRenderer::identity_mismatches( $real_metadata, $real_metadata ), 'Expected durable Chrome identity self-match.' );
$real_options = $real->replay_options();
html_api_fuzz_chrome_adapter_assert( 90000 === $real->recommended_process_timeout_ms( 'full', 2500 ), 'Expected Common Crawl-style 10-second render budget.' );
$default_policy = \HtmlApiFuzz\OracleRenderer::from_options( array( 'dom-oracle' => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP ) );
html_api_fuzz_chrome_adapter_assert( 60000 === $default_policy->recommended_process_timeout_ms( 'full', 2500 ), 'Expected default full Chrome Worker timeout.' );
html_api_fuzz_chrome_adapter_assert( 52500 === $default_policy->recommended_process_timeout_ms( 'baseline', 2500 ), 'Expected default baseline Chrome Worker timeout.' );
$default_policy->close();
$real_full = $real->render( '<!doctype html><p>adapter</p>', \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT, array( 'maxNodes' => 3000, 'maxDepth' => 512, 'maxTreeBytes' => 16777216 ), 'body' );
html_api_fuzz_chrome_adapter_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $real_full['status'] ?? null ), 'Expected real Chrome full-document render.' );
$fragment_limits = array( 'maxNodes' => 3000, 'maxDepth' => 512, 'maxTreeBytes' => 16777216 );
foreach ( \HtmlApiFuzz\Generator::fragment_contexts() as $context ) {
	$real_fragment = $real->render( '<b>x', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $fragment_limits, $context );
	html_api_fuzz_chrome_adapter_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $real_fragment['status'] ?? null ), "Expected real Chrome fragment render in {$context}." );
}
$real_invalid = $real->render( "\xFF", \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 3000, 'maxDepth' => 512, 'maxTreeBytes' => 16777216 ), 'body' );
html_api_fuzz_chrome_adapter_assert( \HtmlApiFuzz\TreeRenderer::STATUS_UNSUPPORTED === ( $real_invalid['status'] ?? null ) && 'invalid-utf8' === ( $real_invalid['failureClass'] ?? null ), 'Expected invalid UTF-8 to remain an explicit unsupported outcome.' );
$real->close();

$render_log = $work_dir . '/render.ndjson';
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_RENDER_LOG=' . $render_log );
$fake = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options );
$fake_metadata = $fake->metadata();
html_api_fuzz_chrome_adapter_assert( true === ( $fake_metadata['available'] ?? false ), 'Expected authenticated fake service metadata.' );
$two_mib = str_repeat( 'a', 2 * 1024 * 1024 );
$accepted = $fake->render( $two_mib, \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT, array( 'maxNodes' => 10, 'maxDepth' => 10, 'maxTreeBytes' => 16777216 ), 'body' );
html_api_fuzz_chrome_adapter_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $accepted['status'] ?? null ), 'Expected exact 2 MiB Chrome input acceptance.' );
$rejected = $fake->render( $two_mib . 'b', \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT, array( 'maxNodes' => 10, 'maxDepth' => 10, 'maxTreeBytes' => 16777216 ), 'body' );
html_api_fuzz_chrome_adapter_assert( 'input-byte-limit-exceeded' === ( $rejected['failureClass'] ?? null ), 'Expected 2 MiB plus one local resource result.' );
$second = $fake->render( '<p>reuse</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10, 'maxDepth' => 10, 'maxTreeBytes' => 16777216 ), 'body' );
html_api_fuzz_chrome_adapter_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $second['status'] ?? null ), 'Expected persistent fake service reuse.' );
$fake->close();
$render_rows = array_values( array_filter( explode( "\n", trim( (string) file_get_contents( $render_log ) ) ) ) );
html_api_fuzz_chrome_adapter_assert( 2 === count( $render_rows ), 'Expected oversized input rejection without a render frame.' );
$render_pids = array_map( static fn ( string $row ): int => (int) ( json_decode( $row, true )['pid'] ?? 0 ), $render_rows );
html_api_fuzz_chrome_adapter_assert( 1 === count( array_unique( $render_pids ) ), 'Expected baseline and later render to reuse one Node service.' );
$worker_resource = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'             => base64_encode( $two_mib . 'b' ),
		'profile'                  => 'replay',
		'mode'                     => \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
		'checks'                   => 'baseline',
		'output-dir'               => $work_dir . '/worker-input-limit',
		'dom-oracle'               => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
		'chrome-oracle-script'     => $fake_script,
		'chrome-executable'        => $real_options['chromeExecutable'],
		'node-bin'                 => $real_options['nodeBin'],
		'oracle-timeout-ms'        => '10000',
		'chrome-startup-timeout-ms' => '5000',
		'process-timeout-ms'       => '30000',
	)
);
html_api_fuzz_chrome_adapter_assert( 'resource-limit' === ( $worker_resource['failureClass'] ?? null ) && 'resource-limit' === ( $worker_resource['status'] ?? null ), 'Expected Worker to classify the Chrome input ceiling as a resource limit.' );
$resource_render_rows = array_values( array_filter( explode( "\n", trim( (string) file_get_contents( $render_log ) ) ) ) );
html_api_fuzz_chrome_adapter_assert( 2 === count( $resource_render_rows ), 'Expected Worker input-limit classification without a render frame.' );

$large = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options, 'tree-16m' );
$large_tree = $large->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10, 'maxDepth' => 10, 'maxTreeBytes' => 16777216 ), 'body' );
html_api_fuzz_chrome_adapter_assert( 16777216 === strlen( $large_tree['tree'] ?? '' ), 'Expected exact 16 MiB decoded tree transport.' );
$large->close();

foreach ( array( 'extra-key', 'bad-tree-hash', 'duplicate-key', 'trailing-frame', 'wrong-id', 'wrong-identity', 'missing-field', 'malformed-json' ) as $case ) {
	$hostile = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options, $case );
	$result = $hostile->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10, 'maxDepth' => 10, 'maxTreeBytes' => 1024 ), 'body' );
	html_api_fuzz_chrome_adapter_assert( 'oracle-renderer-error' === ( $result['failureClass'] ?? null ) && true === ( $result['infrastructure'] ?? false ), "Expected {$case} to fail as infrastructure." );
	$hostile->close();
}
$missing = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options, 'missing-response', 100, 1000 );
$missing_result = $missing->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10, 'maxDepth' => 10, 'maxTreeBytes' => 1024 ), 'body' );
html_api_fuzz_chrome_adapter_assert( 'oracle-renderer-error' === ( $missing_result['failureClass'] ?? null ) && false !== strpos( (string) ( $missing_result['error'] ?? '' ), 'timed out' ), 'Expected a missing render response to time out as infrastructure.' );
$missing->close();

$original_fake_source = (string) file_get_contents( $fake_script );
$trust_drift = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options );
html_api_fuzz_chrome_adapter_assert( true === ( $trust_drift->metadata()['available'] ?? false ), 'Expected trust-drift service startup.' );
html_api_fuzz_chrome_adapter_assert( chmod( $fake_script, 0700 ), 'Expected temporary fake-script write permission.' );
html_api_fuzz_chrome_adapter_assert( false !== file_put_contents( $fake_script, "\n// local trust drift\n", FILE_APPEND ), 'Expected local trust drift mutation.' );
$trust_drift_result = $trust_drift->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10, 'maxDepth' => 10, 'maxTreeBytes' => 1024 ), 'body' );
html_api_fuzz_chrome_adapter_assert( strlen( $original_fake_source ) === file_put_contents( $fake_script, $original_fake_source ) && chmod( $fake_script, 0500 ), 'Expected fake-script trust restoration.' );
html_api_fuzz_chrome_adapter_assert( 'oracle-renderer-error' === ( $trust_drift_result['failureClass'] ?? null ) && true === ( $trust_drift_result['infrastructure'] ?? false ), 'Expected local script trust drift to fail closed.' );
$trust_drift->close();

foreach ( array( 'render-timeout-once', 'render-death-once' ) as $restart_case ) {
	$once = $work_dir . '/' . $restart_case . '.once';
	putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_ONCE=' . $once );
	$restart = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options, $restart_case, 100, 1000 );
	$first_restart = $restart->render( '<p>first</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10, 'maxDepth' => 10, 'maxTreeBytes' => 1024 ), 'body' );
	$second_restart = $restart->render( '<p>second</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10, 'maxDepth' => 10, 'maxTreeBytes' => 1024 ), 'body' );
	html_api_fuzz_chrome_adapter_assert( 'oracle-renderer-error' === ( $first_restart['failureClass'] ?? null ) && true === ( $first_restart['infrastructure'] ?? false ), "Expected {$restart_case} first render to fail as infrastructure." );
	html_api_fuzz_chrome_adapter_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $second_restart['status'] ?? null ), "Expected {$restart_case} later render to restart successfully." );
	$restart->close();
	@unlink( $once );
}
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_ONCE' );

$slow_start = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options, 'startup-slower-than-render', 50, 1000 );
html_api_fuzz_chrome_adapter_assert( true === ( $slow_start->metadata()['available'] ?? false ), 'Expected startup to use its independent longer budget.' );
$slow_start->close();

$immediate_shutdown = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options, 'immediate-shutdown' );
html_api_fuzz_chrome_adapter_assert( true === ( $immediate_shutdown->metadata()['available'] ?? false ), 'Expected immediate-shutdown service startup.' );
$immediate_shutdown->close();

$dead_before_close_state = $work_dir . '/dead-before-close-state.json';
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE=' . $dead_before_close_state );
$dead_before_close = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options );
html_api_fuzz_chrome_adapter_assert( true === ( $dead_before_close->metadata()['available'] ?? false ), 'Expected dead-before-close service startup.' );
html_api_fuzz_chrome_adapter_wait( static fn (): bool => is_file( $dead_before_close_state ), 5.0, 'Expected dead-before-close service state.' );
$dead_before_close_owner = json_decode( (string) file_get_contents( $dead_before_close_state ), true );
html_api_fuzz_chrome_adapter_assert( is_array( $dead_before_close_owner ) && posix_kill( (int) $dead_before_close_owner['pid'], SIGTERM ), 'Expected explicit service termination before close.' );
html_api_fuzz_chrome_adapter_wait(
	static fn (): bool => ! file_exists( (string) $dead_before_close_owner['runtimeRoot'] ),
	5.0,
	'Expected dead service to finish its own signal cleanup.'
);
$dead_close_failed = false;
try {
	$dead_before_close->close();
} catch ( RuntimeException $error ) {
	$dead_close_failed = false !== strpos( $error->getMessage(), 'shutdown acknowledgement' );
}
html_api_fuzz_chrome_adapter_assert( $dead_close_failed, 'Expected normal close to reject a service that died before acknowledgement.' );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE' );

$cleanup_deadline_state = $work_dir . '/cleanup-deadline-state.json';
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE=' . $cleanup_deadline_state );
$cleanup_deadline = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options, 'cleanup-deadline', 1000, 1000 );
$cleanup_deadline_metadata = $cleanup_deadline->metadata();
html_api_fuzz_chrome_adapter_assert( true === ( $cleanup_deadline_metadata['available'] ?? false ), 'Expected cleanup-deadline service startup.' );
html_api_fuzz_chrome_adapter_wait( static fn (): bool => is_file( $cleanup_deadline_state ), 5.0, 'Expected cleanup-deadline service state.' );
$cleanup_deadline_owner = json_decode( (string) file_get_contents( $cleanup_deadline_state ), true );
$cleanup_started = microtime( true );
$cleanup_deadline_failed = false;
try {
	$cleanup_deadline->close();
} catch ( RuntimeException $error ) {
	$cleanup_deadline_failed = false !== strpos( $error->getMessage(), 'runtime root survived cleanup' );
}
$cleanup_elapsed = microtime( true ) - $cleanup_started;
html_api_fuzz_chrome_adapter_assert( $cleanup_deadline_failed, 'Expected the uncooperative service to fail verified cleanup.' );
html_api_fuzz_chrome_adapter_assert( $cleanup_elapsed <= 10.75, 'Expected the complete cleanup path to honor its absolute 10-second budget.' );
if ( is_array( $cleanup_deadline_owner ) && is_string( $cleanup_deadline_owner['runtimeRoot'] ?? null ) ) {
	\HtmlApiFuzz\remove_dir_recursive( $cleanup_deadline_owner['runtimeRoot'] );
}
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE' );

$stderr_hostile = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options, 'stderr-overflow' );
$stderr_result = $stderr_hostile->render( '<p>x</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 10, 'maxDepth' => 10, 'maxTreeBytes' => 1024 ), 'body' );
$stderr_close_failed = false;
try {
	$stderr_hostile->close();
} catch ( RuntimeException $error ) {
	$stderr_close_failed = false !== strpos( $error->getMessage(), 'stderr exceeded 1 MiB' );
}
html_api_fuzz_chrome_adapter_assert(
	( 'oracle-renderer-error' === ( $stderr_result['failureClass'] ?? null ) && true === ( $stderr_result['infrastructure'] ?? false ) ) || $stderr_close_failed,
	'Expected stderr overflow to surface during render or mandatory close.'
);
$worker_stderr = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'             => base64_encode( '<p>x</p>' ),
		'profile'                  => 'replay',
		'mode'                     => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'checks'                   => 'baseline',
		'output-dir'               => $work_dir . '/worker-stderr-overflow',
		'dom-oracle'               => \HtmlApiFuzz\OracleRenderer::KIND_CHROME_CDP,
		'chrome-oracle-script'     => $fake_script,
		'chrome-executable'        => $real_options['chromeExecutable'],
		'node-bin'                 => $real_options['nodeBin'],
		'oracle-timeout-ms'        => '10000',
		'chrome-startup-timeout-ms' => '5000',
		'process-timeout-ms'       => '30000',
	)
);
html_api_fuzz_chrome_adapter_assert( 'oracle-renderer-error' === ( $worker_stderr['failureClass'] ?? null ) && true === ( $worker_stderr['oracleInfrastructure'] ?? false ), 'Expected Worker to surface mandatory Chrome cleanup failure.' );
html_api_fuzz_chrome_adapter_assert( false === ( $worker_stderr['oracleCleanup']['ok'] ?? true ) && is_array( $worker_stderr['signature'] ?? null ), 'Expected replayable cleanup evidence and a recomputed signature.' );

$startup = html_api_fuzz_chrome_adapter_renderer( $fake_script, $real_options, 'startup-timeout', 10000, 100 );
$startup_metadata = $startup->metadata();
html_api_fuzz_chrome_adapter_assert( false === ( $startup_metadata['available'] ?? true ) && false !== strpos( (string) ( $startup_metadata['error'] ?? '' ), 'timed out' ), 'Expected independent startup deadline enforcement.' );
$startup->close();

putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_CASE=ok' );
$overflow = (string) PHP_INT_MAX;
$owner_cli_options = array(
	'--dom-oracle', 'chrome-cdp',
	'--chrome-oracle-script', $fake_script,
	'--chrome-executable', $real_options['chromeExecutable'],
	'--node-bin', $real_options['nodeBin'],
	'--oracle-timeout-ms', '1000',
	'--chrome-startup-timeout-ms', $overflow,
);
foreach ( array( 'runner.php' => 'runner', 'launcher.php' => 'launcher' ) as $owner_script => $owner_label ) {
	$state_path = $work_dir . '/' . $owner_label . '-owner-state.json';
	putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE=' . $state_path );
	$args = array_merge(
		array( dirname( __DIR__ ) . '/' . $owner_script, '--output-dir', $work_dir . '/' . $owner_label . '-owner-output', '--max-seeds', '1' ),
		$owner_cli_options
	);
	$owner_proc = \HtmlApiFuzz\run_php_process( $args, \HtmlApiFuzz\repo_root(), 10000, $work_dir . '/' . $owner_label . '-owner.log', 1048576, true );
	html_api_fuzz_chrome_adapter_assert( false === ( $owner_proc['timedOut'] ?? true ) && 0 !== ( $owner_proc['code'] ?? 0 ), "Expected {$owner_label} post-start timeout overflow." );
	html_api_fuzz_chrome_adapter_assert_state_clean( $state_path, $owner_label );
}

$commoncrawl_state = $work_dir . '/commoncrawl-owner-state.json';
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE=' . $commoncrawl_state );
putenv( 'CC_ANALYZER_OUTPUT_DIR=' . $work_dir . '/commoncrawl-owner-output' );
putenv( 'HTML_API_CC_ORACLE=chrome-cdp' );
putenv( 'HTML_API_CC_CHECKS=invalid-after-start' );
putenv( 'HTML_API_FUZZ_CHROME_ORACLE=' . $fake_script );
putenv( 'HTML_API_FUZZ_CHROME_EXECUTABLE=' . $real_options['chromeExecutable'] );
putenv( 'HTML_API_FUZZ_NODE_BIN=' . $real_options['nodeBin'] );
putenv( 'HTML_API_CC_CHROME_STARTUP_TIMEOUT_MS=1000' );
$commoncrawl_failed = false;
try {
	\HtmlApiFuzz\CommonCrawlRunner::from_environment();
} catch ( Throwable $error ) {
	$commoncrawl_failed = false !== strpos( $error->getMessage(), 'HTML_API_CC_CHECKS' );
}
html_api_fuzz_chrome_adapter_assert( $commoncrawl_failed, 'Expected Common Crawl validation failure after Chrome startup.' );
html_api_fuzz_chrome_adapter_assert_state_clean( $commoncrawl_state, 'Common Crawl owner' );
foreach ( array( 'CC_ANALYZER_OUTPUT_DIR', 'HTML_API_CC_ORACLE', 'HTML_API_CC_CHECKS', 'HTML_API_FUZZ_CHROME_ORACLE', 'HTML_API_FUZZ_CHROME_EXECUTABLE', 'HTML_API_FUZZ_NODE_BIN', 'HTML_API_CC_CHROME_STARTUP_TIMEOUT_MS' ) as $name ) {
	putenv( $name );
}

$worker_owner_state = $work_dir . '/worker-owner-state.json';
$worker_owner_dir = $work_dir . '/worker-owner-output';
\HtmlApiFuzz\ensure_dir( $worker_owner_dir . '/input.bin' );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE=' . $worker_owner_state );
$worker_publication_failed = false;
set_error_handler( static fn (): bool => true );
try {
	try {
		\HtmlApiFuzz\Worker::run(
			array(
				'input-base64'              => base64_encode( '<p>x</p>' ),
				'profile'                   => 'replay',
				'mode'                      => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
				'checks'                    => 'baseline',
				'output-dir'                => $worker_owner_dir,
				'dom-oracle'                => 'chrome-cdp',
				'chrome-oracle-script'      => $fake_script,
				'chrome-executable'         => $real_options['chromeExecutable'],
				'node-bin'                  => $real_options['nodeBin'],
				'oracle-timeout-ms'         => '1000',
				'chrome-startup-timeout-ms' => '1000',
			)
		);
	} catch ( Throwable $error ) {
		$worker_publication_failed = false !== strpos( $error->getMessage(), 'atomically publish' );
	}
} finally {
	restore_error_handler();
}
html_api_fuzz_chrome_adapter_assert( $worker_publication_failed, 'Expected Worker publication failure after Chrome startup.' );
html_api_fuzz_chrome_adapter_assert_state_clean( $worker_owner_state, 'Worker owner' );

$worker_cli_lifecycle = $work_dir . '/worker-cli-fatal-lifecycle.ndjson';
$worker_cli_dir = $work_dir . '/worker-cli-fatal-output';
\HtmlApiFuzz\ensure_dir( $worker_cli_dir . '/input.bin' );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE' );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_CASE=shutdown-wrong-id' );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_LIFECYCLE=' . $worker_cli_lifecycle );
$worker_cli_fatal = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/worker.php',
		'--input-base64', base64_encode( '<p>x</p>' ),
		'--profile', 'replay',
		'--mode', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'--checks', 'baseline',
		'--output-dir', $worker_cli_dir,
		'--dom-oracle', 'chrome-cdp',
		'--chrome-oracle-script', $fake_script,
		'--chrome-executable', $real_options['chromeExecutable'],
		'--node-bin', $real_options['nodeBin'],
		'--oracle-timeout-ms', '1000',
		'--chrome-startup-timeout-ms', '1000',
	),
	\HtmlApiFuzz\repo_root(),
	15000,
	$work_dir . '/worker-cli-fatal.log',
	1048576,
	true
);
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_LIFECYCLE' );
html_api_fuzz_chrome_adapter_assert( false === ( $worker_cli_fatal['timedOut'] ?? true ) && 1 === ( $worker_cli_fatal['code'] ?? 0 ), 'Expected bounded Worker CLI fatal fallback.' );
$worker_cli_fatal_result = json_decode( (string) file_get_contents( $worker_cli_dir . '/result.json' ), true );
html_api_fuzz_chrome_adapter_assert( false === ( $worker_cli_fatal_result['oracleCleanup']['ok'] ?? true ), 'Expected the Worker CLI fatal result to surface fallback cleanup failure.' );
$worker_cli_rows = array_values( array_filter( array_map( static fn ( string $line ) => json_decode( $line, true ), explode( "\n", trim( (string) @file_get_contents( $worker_cli_lifecycle ) ) ) ), 'is_array' ) );
$worker_cli_starts = array_values( array_filter( $worker_cli_rows, static fn ( array $row ): bool => 'start' === ( $row['event'] ?? null ) ) );
html_api_fuzz_chrome_adapter_assert( 2 === count( $worker_cli_starts ), 'Expected one primary and one fatal-fallback Chrome service.' );
foreach ( $worker_cli_starts as $row ) {
	html_api_fuzz_chrome_adapter_assert( ! file_exists( (string) ( $row['runtimeRoot'] ?? '' ) ), 'Expected explicit cleanup of every Worker CLI fatal-path Chrome service.' );
}
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_CASE=ok' );

$owner_replay = array(
	'schemaVersion'   => 1,
	'kind'            => 'html-api-fuzz-replay',
	'seed'            => 1,
	'profile'         => 'replay',
	'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	'fragmentContext' => 'body',
	'inputBase64'     => base64_encode( '<p>x</p>' ),
	'limits'          => array( 'maxTokens' => 100, 'maxNodes' => 100, 'maxDepth' => 100, 'maxTreeBytes' => 1024 ),
	'options'         => array(
		'domOracle'             => 'chrome-cdp',
		'chromeOracleScript'    => $fake_script,
		'chromeExecutable'      => $real_options['chromeExecutable'],
		'nodeBin'               => $real_options['nodeBin'],
		'oracleTimeoutMs'       => 1000,
		'chromeStartupTimeoutMs'=> 1000,
	),
	'oracle'          => $fake_metadata,
);
$replay_fixture = $work_dir . '/owner-replay.json';
\HtmlApiFuzz\write_json_file( $replay_fixture, $owner_replay );
$replay_owner_state = $work_dir . '/replay-owner-state.json';
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE=' . $replay_owner_state );
$replay_proc = \HtmlApiFuzz\run_php_process(
	array_merge(
		array( dirname( __DIR__ ) . '/replay.php', '--replay', $replay_fixture, '--output-dir', $work_dir . '/replay-owner-output' ),
		$owner_cli_options
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$work_dir . '/replay-owner.log',
	1048576,
	true
);
html_api_fuzz_chrome_adapter_assert( false === ( $replay_proc['timedOut'] ?? true ) && 0 !== ( $replay_proc['code'] ?? 0 ), 'Expected replay post-start timeout overflow.' );
html_api_fuzz_chrome_adapter_assert_state_clean( $replay_owner_state, 'replay owner' );

$minimize_lifecycle = $work_dir . '/minimize-lifecycle.ndjson';
$minimize_override_replay = $owner_replay;
$minimize_override_replay['options']['chromeStartupTimeoutMs'] = PHP_INT_MAX;
$minimize_override_path = $work_dir . '/minimize-explicit-timeout-replay.json';
\HtmlApiFuzz\write_json_file( $minimize_override_path, $minimize_override_replay );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE' );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_LIFECYCLE=' . $minimize_lifecycle );
$minimize_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/minimize.php',
		'--replay', $minimize_override_path,
		'--output-dir', $work_dir . '/minimize-owner-output',
		'--probe-mode', 'in-process',
		'--max-attempts', '0',
		'--any-failure',
		'--timeout-ms', '10000',
	),
	\HtmlApiFuzz\repo_root(),
	30000,
	$work_dir . '/minimize-owner.log',
	1048576,
	true
);
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_LIFECYCLE' );
html_api_fuzz_chrome_adapter_assert( false === ( $minimize_proc['timedOut'] ?? true ) && 0 === ( $minimize_proc['code'] ?? 1 ), 'Expected the explicit minimizer timeout to bypass recommendation overflow.' );
$lifecycle_rows = array_values( array_filter( array_map( static fn ( string $line ) => json_decode( $line, true ), explode( "\n", trim( (string) @file_get_contents( $minimize_lifecycle ) ) ) ), 'is_array' ) );
$start_indexes = array_keys( array_filter( $lifecycle_rows, static fn ( array $row ): bool => 'start' === ( $row['event'] ?? null ) ) );
$ack_indexes = array_keys( array_filter( $lifecycle_rows, static fn ( array $row ): bool => 'shutdown-ack' === ( $row['event'] ?? null ) ) );
html_api_fuzz_chrome_adapter_assert( 2 === count( $start_indexes ) && 2 === count( $ack_indexes ), 'Expected exactly one parent and one final-Worker Chrome service.' );
html_api_fuzz_chrome_adapter_assert( $ack_indexes[0] < $start_indexes[1], 'Expected parent Chrome shutdown acknowledgement before final Worker startup.' );
html_api_fuzz_chrome_adapter_assert( $lifecycle_rows[ $start_indexes[0] ]['pid'] !== $lifecycle_rows[ $start_indexes[1] ]['pid'], 'Expected distinct bounded parent and final Worker services.' );
foreach ( $lifecycle_rows as $row ) {
	if ( 'start' === ( $row['event'] ?? null ) ) {
		html_api_fuzz_chrome_adapter_assert( ! file_exists( (string) ( $row['runtimeRoot'] ?? '' ) ), 'Expected every minimizer Chrome runtime root removed.' );
	}
}

$pause_marker = $work_dir . '/real-handshake-pause.json';
$pause_helper = $work_dir . '/pause-helper.php';
$pause_source = '<?php require_once ' . var_export( dirname( __DIR__ ) . '/lib/autoload.php', true ) . '; $r=\\HtmlApiFuzz\\OracleRenderer::from_options(["dom-oracle"=>"chrome-cdp","chrome-oracle-script"=>$argv[1],"chrome-executable"=>$argv[2],"node-bin"=>$argv[3],"chrome-startup-timeout-ms"=>"35000"]); $r->metadata();';
html_api_fuzz_chrome_adapter_assert( strlen( $pause_source ) === file_put_contents( $pause_helper, $pause_source ), 'Expected paused metadata helper publication.' );
putenv( 'HTML_API_FUZZ_CHROME_TEST_PAUSE_AFTER_CDP_HANDSHAKE=' . $pause_marker );
$paused_process = \HtmlApiFuzz\run_php_process(
	array( $pause_helper, $real_options['chromeOracleScript'], $real_options['chromeExecutable'], $real_options['nodeBin'] ),
	\HtmlApiFuzz\repo_root(),
	5000,
	$work_dir . '/paused-metadata.log',
	1048576,
	true
);
putenv( 'HTML_API_FUZZ_CHROME_TEST_PAUSE_AFTER_CDP_HANDSHAKE' );
html_api_fuzz_chrome_adapter_assert( true === ( $paused_process['timedOut'] ?? false ), 'Expected an explicit outer timeout during the real CDP handshake pause.' );
html_api_fuzz_chrome_adapter_assert( is_file( $pause_marker ), 'Expected authenticated paused Chrome process evidence.' );
$paused_state = json_decode( (string) file_get_contents( $pause_marker ), true );
@unlink( $pause_marker );
html_api_fuzz_chrome_adapter_wait(
	static fn (): bool => is_array( $paused_state ) && ! file_exists( (string) ( $paused_state['runtimeRoot'] ?? '' ) ) && ! file_exists( (string) ( $paused_state['profilePath'] ?? '' ) ),
	15.0,
	'Expected outer-timeout cleanup of the paused real Chrome runtime and profile.'
);

$owner_state = $work_dir . '/owner-state.json';
$owner_helper = $work_dir . '/owner-helper.php';
$owner_source = '<?php require_once ' . var_export( dirname( __DIR__ ) . '/lib/autoload.php', true ) . '; $r=\\HtmlApiFuzz\\OracleRenderer::from_options(["dom-oracle"=>"chrome-cdp","chrome-oracle-script"=>$argv[1],"chrome-executable"=>$argv[2],"node-bin"=>$argv[3],"chrome-startup-timeout-ms"=>"5000"]); $m=$r->metadata(); if (!($m["available"]??false)) { exit(2); } while (true) { usleep(100000); }';
html_api_fuzz_chrome_adapter_assert( strlen( $owner_source ) === file_put_contents( $owner_helper, $owner_source ), 'Expected owner helper publication.' );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_CASE=ok' );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE=' . $owner_state );
$owner = proc_open( array( PHP_BINARY, $owner_helper, $fake_script, $real_options['chromeExecutable'], $real_options['nodeBin'] ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'a' ), 2 => array( 'file', '/dev/null', 'a' ) ), $owner_pipes, \HtmlApiFuzz\repo_root(), null, array( 'bypass_shell' => true ) );
html_api_fuzz_chrome_adapter_assert( is_resource( $owner ), 'Expected owner helper process.' );
$owner_pid = (int) ( proc_get_status( $owner )['pid'] ?? 0 );
html_api_fuzz_chrome_adapter_wait( static fn (): bool => is_file( $owner_state ), 5.0, 'Expected fake service owner state.' );
$owned = json_decode( (string) file_get_contents( $owner_state ), true );
html_api_fuzz_chrome_adapter_assert( posix_kill( $owner_pid, SIGKILL ), 'Expected owner termination.' );
fclose( $owner_pipes[0] );
html_api_fuzz_chrome_adapter_wait(
	static fn (): bool => ! posix_kill( (int) $owned['pid'], 0 ) && ! file_exists( (string) $owned['runtimeRoot'] ),
	10.0,
	'Expected Node EOF cleanup after abrupt PHP owner death.'
);
proc_close( $owner );

putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_STATE' );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_RENDER_LOG' );
putenv( 'HTML_API_FUZZ_TEST_CHROME_ADAPTER_CASE' );
putenv( 'HTML_API_FUZZ_TEST_REPO_ROOT' );
\HtmlApiFuzz\remove_dir_recursive( $work_dir );
echo "PASS: Chrome oracle adapter smoke checks\n";
