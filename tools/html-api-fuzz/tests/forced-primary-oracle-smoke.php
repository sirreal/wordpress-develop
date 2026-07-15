#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_forced_oracle_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function html_api_fuzz_forced_oracle_count( string $path ): int {
	$contents = is_file( $path ) ? file_get_contents( $path ) : '';
	if ( false === $contents || '' === trim( $contents ) ) {
		return 0;
	}
	return count( preg_split( '/\R/', trim( $contents ) ) );
}

function html_api_fuzz_forced_oracle_reset_count( string $path ): void {
	file_put_contents( $path, '' );
}

function html_api_fuzz_forced_oracle_run( string $directory, string $binary, string $input, string $mode, bool $force = true, int $max_tokens = 2000 ): array {
	putenv( 'HTML_API_FUZZ_FORCE_ORACLE_MODE=' . $mode );
	$options = array(
		'input-base64'      => base64_encode( $input ),
		'profile'           => 'replay',
		'mode'              => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'    => 'ascii-structural',
		'fragment-context'  => 'body',
		'output-dir'        => $directory,
		'max-tokens'        => (string) $max_tokens,
		'max-nodes'         => '100',
		'dom-oracle'        => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		'lexbor-oracle-bin' => $binary,
	);
	if ( $force ) {
		$options['force-primary-oracle'] = true;
	}
	return \HtmlApiFuzz\Worker::run( $options );
}

$work_dir = sys_get_temp_dir() . '/html-api-fuzz-forced-oracle-' . \HtmlApiFuzz\timestamp();
\HtmlApiFuzz\ensure_dir( $work_dir );
$binary = $work_dir . '/fake-lexbor-oracle.php';
$count_path = $work_dir . '/render-count.log';
$commit = trim( file_get_contents( dirname( __DIR__ ) . '/oracles/lexbor/COMMIT' ) );
$script = <<<'PHP'
#!/usr/bin/env php
<?php
$oracle = array(
	'kind'          => 'lexbor-source',
	'available'     => true,
	'lexborCommit'  => getenv( 'HTML_API_FUZZ_FORCE_ORACLE_COMMIT' ),
	'lexborVersion' => 'forced-primary-oracle-smoke',
);
if ( in_array( '--version', $argv, true ) ) {
	echo json_encode( array( 'status' => 'ok', 'oracle' => $oracle ) ) . "\n";
	exit( 0 );
}
file_put_contents( getenv( 'HTML_API_FUZZ_FORCE_ORACLE_LOG' ), "render\n", FILE_APPEND );
$mode = getenv( 'HTML_API_FUZZ_FORCE_ORACLE_MODE' );
if ( 'transport' === $mode ) {
	echo "not-json\n";
	exit( 7 );
}
$result = array(
	'status'    => 'ok',
	'oracle'    => $oracle,
	'nodeCount' => 1,
	'treeBase64' => base64_encode( "\"fake\"\n\n" ),
);
if ( 'parse' === $mode ) {
	$result['status'] = 'error';
	$result['failureClass'] = 'oracle-parse-error';
	$result['error'] = 'synthetic parse failure';
} elseif ( 'resource' === $mode ) {
	$result['status'] = 'error';
	$result['failureClass'] = 'node-limit-exceeded';
	$result['error'] = 'synthetic node limit';
} elseif ( 'unsupported' === $mode ) {
	$result['status'] = 'unsupported';
	$result['failureClass'] = 'oracle-unsupported';
	$result['unsupported'] = array( 'message' => 'synthetic unsupported input' );
}
echo json_encode( $result ) . "\n";
PHP;

$failure = null;
try {
	if ( false === file_put_contents( $binary, $script ) || ! chmod( $binary, 0700 ) ) {
		throw new RuntimeException( 'Could not create the fake source oracle.' );
	}
	putenv( 'HTML_API_FUZZ_FORCE_ORACLE_COMMIT=' . $commit );
	putenv( 'HTML_API_FUZZ_FORCE_ORACLE_LOG=' . $count_path );

	foreach ( array( 'ok', 'parse', 'resource', 'unsupported' ) as $mode ) {
		html_api_fuzz_forced_oracle_reset_count( $count_path );
		$result = html_api_fuzz_forced_oracle_run( $work_dir . '/unsupported-' . $mode, $binary, '<', $mode );
		html_api_fuzz_forced_oracle_assert( true === ( $result['oracleExecuted'] ?? false ), "Forced {$mode} case did not record oracle execution." );
		html_api_fuzz_forced_oracle_assert( 'unsupported' === ( $result['status'] ?? null ), "Forced {$mode} case replaced the WordPress unsupported status." );
		html_api_fuzz_forced_oracle_assert( 'unsupported' === ( $result['failureClass'] ?? null ), "Forced {$mode} case replaced the WordPress unsupported class." );
		html_api_fuzz_forced_oracle_assert( 1 === html_api_fuzz_forced_oracle_count( $count_path ), "Forced {$mode} case did not invoke the oracle exactly once." );
	}

	html_api_fuzz_forced_oracle_reset_count( $count_path );
	$unforced = html_api_fuzz_forced_oracle_run( $work_dir . '/unforced-unsupported', $binary, '<', 'ok', false );
	html_api_fuzz_forced_oracle_assert( false === ( $unforced['oracleExecuted'] ?? true ), 'Unforced unsupported input unexpectedly invoked the oracle.' );
	html_api_fuzz_forced_oracle_assert( 0 === html_api_fuzz_forced_oracle_count( $count_path ), 'Unforced unsupported input reached the fake oracle.' );

	html_api_fuzz_forced_oracle_reset_count( $count_path );
	$comparable = html_api_fuzz_forced_oracle_run( $work_dir . '/comparable', $binary, '<p>x</p>', 'ok' );
	html_api_fuzz_forced_oracle_assert( true === ( $comparable['oracleExecuted'] ?? false ), 'Comparable forced input did not record oracle execution.' );
	html_api_fuzz_forced_oracle_assert( 1 === html_api_fuzz_forced_oracle_count( $count_path ), 'Comparable forced input invoked the oracle more than once.' );

	html_api_fuzz_forced_oracle_reset_count( $count_path );
	$unsupported_infrastructure = html_api_fuzz_forced_oracle_run( $work_dir . '/unsupported-infrastructure', $binary, '<', 'transport' );
	html_api_fuzz_forced_oracle_assert( false === ( $unsupported_infrastructure['ok'] ?? true ), 'Oracle infrastructure failure did not fail an unsupported input.' );
	html_api_fuzz_forced_oracle_assert( 'failed' === ( $unsupported_infrastructure['status'] ?? null ), 'Oracle infrastructure failure did not override unsupported status.' );
	html_api_fuzz_forced_oracle_assert( 'oracle-renderer-error' === ( $unsupported_infrastructure['failureClass'] ?? null ), 'Oracle infrastructure failure did not override unsupported class.' );
	html_api_fuzz_forced_oracle_assert( 1 === html_api_fuzz_forced_oracle_count( $count_path ), 'Unsupported infrastructure case did not invoke the oracle exactly once.' );

	html_api_fuzz_forced_oracle_reset_count( $count_path );
	$tag_infrastructure = html_api_fuzz_forced_oracle_run( $work_dir . '/tag-infrastructure', $binary, str_repeat( '<span>', 12 ), 'transport', true, 1 );
	html_api_fuzz_forced_oracle_assert( 'oracle-renderer-error' === ( $tag_infrastructure['failureClass'] ?? null ), 'Oracle infrastructure failure did not override tag/resource classification.' );
	html_api_fuzz_forced_oracle_assert( 1 === html_api_fuzz_forced_oracle_count( $count_path ), 'Tag infrastructure case did not invoke the oracle exactly once.' );

	$source_replay = $work_dir . '/unsupported-infrastructure/replay.json';
	$replay = \HtmlApiFuzz\read_json_file( $source_replay );
	html_api_fuzz_forced_oracle_assert( true === ( $replay['options']['forcePrimaryOracle'] ?? false ), 'Source replay did not persist forcePrimaryOracle.' );
	html_api_fuzz_forced_oracle_reset_count( $count_path );
	putenv( 'HTML_API_FUZZ_FORCE_ORACLE_MODE=ok' );
	$replay_dir = $work_dir . '/replayed';
	$replay_process = \HtmlApiFuzz\run_php_process(
		array( dirname( __DIR__ ) . '/replay.php', '--replay', $source_replay, '--output-dir', $replay_dir ),
		\HtmlApiFuzz\repo_root(),
		10000
	);
	html_api_fuzz_forced_oracle_assert( 0 === $replay_process['code'], 'Replay failed: ' . $replay_process['output'] );
	$replayed_result = \HtmlApiFuzz\read_json_file( $replay_dir . '/result.json' );
	$replayed_replay = \HtmlApiFuzz\read_json_file( $replay_dir . '/replay.json' );
	html_api_fuzz_forced_oracle_assert( true === ( $replayed_result['oracleExecuted'] ?? false ), 'Replay did not restore forced oracle execution.' );
	html_api_fuzz_forced_oracle_assert( true === ( $replayed_replay['options']['forcePrimaryOracle'] ?? false ), 'Replay output did not preserve forcePrimaryOracle.' );
	html_api_fuzz_forced_oracle_assert( 1 === html_api_fuzz_forced_oracle_count( $count_path ), 'Replay did not invoke the forced oracle exactly once.' );

	putenv( 'HTML_API_FUZZ_FORCE_ORACLE_MODE=transport' );
	foreach ( array( 'in-process', 'process' ) as $probe_mode ) {
		html_api_fuzz_forced_oracle_reset_count( $count_path );
		$minimize_dir = $work_dir . '/minimize-' . $probe_mode;
		$minimize_process = \HtmlApiFuzz\run_php_process(
			array( dirname( __DIR__ ) . '/minimize.php', '--replay', $source_replay, '--output-dir', $minimize_dir, '--max-attempts', '1', '--probe-mode', $probe_mode, '--any-failure' ),
			\HtmlApiFuzz\repo_root(),
			10000
		);
		html_api_fuzz_forced_oracle_assert( 0 === $minimize_process['code'], "{$probe_mode} minimization failed: " . $minimize_process['output'] );
		$minimized_replay = \HtmlApiFuzz\read_json_file( $minimize_dir . '/minimized/replay.json' );
		$minimized_result = \HtmlApiFuzz\read_json_file( $minimize_dir . '/minimized/result.json' );
		html_api_fuzz_forced_oracle_assert( true === ( $minimized_replay['options']['forcePrimaryOracle'] ?? false ), "{$probe_mode} minimization did not preserve forcePrimaryOracle." );
		html_api_fuzz_forced_oracle_assert( true === ( $minimized_result['oracleExecuted'] ?? false ), "{$probe_mode} minimization final run did not execute the forced oracle." );
		html_api_fuzz_forced_oracle_assert( html_api_fuzz_forced_oracle_count( $count_path ) >= 2, "{$probe_mode} minimization did not invoke the oracle in both a probe and its final run." );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	putenv( 'HTML_API_FUZZ_FORCE_ORACLE_COMMIT' );
	putenv( 'HTML_API_FUZZ_FORCE_ORACLE_LOG' );
	putenv( 'HTML_API_FUZZ_FORCE_ORACLE_MODE' );
	\HtmlApiFuzz\remove_dir_recursive( $work_dir );
}

if ( null !== $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure->getMessage() . "\n" );
	exit( 1 );
}
echo "PASS: forced primary oracle semantics, replay, and minimization\n";
