#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

$options = \HtmlApiFuzz\parse_cli_options( $argv );
$replay_path = \HtmlApiFuzz\option_string( $options, 'replay', $options['_'][0] ?? null );
$store_path  = \HtmlApiFuzz\option_string( $options, 'store', null );
if ( ( null === $replay_path && null === $store_path ) || \HtmlApiFuzz\option_bool( $options, 'help', false ) ) {
	echo "Usage: php tools/html-api-fuzz/replay.php --replay path/to/replay.json [--output-dir DIR] [--payload-policy POLICY] [--dom-oracle ORACLE] [oracle options]\n";
	echo "       php tools/html-api-fuzz/replay.php --store path/to/results.sqlite (--id N|--seed N) [--output-dir DIR] [--payload-policy POLICY] [--dom-oracle ORACLE] [oracle options]\n";
	echo "The --store form reproduces a failure whose seed directory was pruned, from the replay stored in the lane's results.sqlite.\n";
	exit( ( null === $replay_path && null === $store_path ) ? 1 : 0 );
}

if ( null !== $store_path ) {
	// Materialize the stored replay as a file and proceed exactly as if it
	// had been read from a retained seed directory.
	$store_id   = \HtmlApiFuzz\option_int( $options, 'id', -1 );
	$store_seed = \HtmlApiFuzz\option_int( $options, 'seed', -1 );
	if ( $store_id < 0 && $store_seed < 0 ) {
		fwrite( STDERR, "The --store form requires --id N or --seed N.\n" );
		exit( 1 );
	}
	try {
		$store        = new \HtmlApiFuzz\ResultStore( $store_path, true );
		$store_replay = $store_id >= 0 ? $store->replay_for_attempt_id( $store_id ) : $store->replay_for_seed( $store_seed );
		$store->close();
	} catch ( \Throwable $e ) {
		fwrite( STDERR, "Could not read store {$store_path}: {$e->getMessage()}\n" );
		exit( 1 );
	}
	if ( null === $store_replay ) {
		fwrite( STDERR, ( $store_id >= 0 ? "No stored replay for id {$store_id}" : "No stored replay for seed {$store_seed}" ) . " in {$store_path}.\n" );
		exit( 1 );
	}
	$store_label = $store_id >= 0 ? 'id-' . $store_id : 'seed-' . $store_seed;
	$replay_dir  = \HtmlApiFuzz\option_string( $options, 'output-dir', dirname( $store_path ) . '/replay-' . $store_label . '-' . \HtmlApiFuzz\timestamp() );
	\HtmlApiFuzz\ensure_dir( $replay_dir );
	$replay_path = $replay_dir . '/source-replay.json';
	$store_replay = \HtmlApiFuzz\OracleRenderer::replay_safe_document( $store_replay );
	\HtmlApiFuzz\write_json_file( $replay_path, $store_replay );
	$options['output-dir'] = $replay_dir;
}

$replay = \HtmlApiFuzz\read_json_file( $replay_path );
if ( ! $replay || ! array_key_exists( 'inputBase64', $replay ) ) {
	fwrite( STDERR, "Invalid replay file: {$replay_path}\n" );
	exit( 1 );
}

$output_dir = \HtmlApiFuzz\option_string( $options, 'output-dir', dirname( $replay_path ) . '/replay-' . \HtmlApiFuzz\timestamp() );
$input      = base64_decode( $replay['inputBase64'], true );
if ( false === $input ) {
	fwrite( STDERR, "Invalid base64 input in replay file: {$replay_path}\n" );
	exit( 1 );
}
\HtmlApiFuzz\ensure_dir( $output_dir );
$input_path = $output_dir . '/input.bin';
file_put_contents( $input_path, $input );
$payload_policy = \HtmlApiFuzz\option_string( $options, 'payload-policy', null );
if ( null === $payload_policy ) {
	$payload_policy = \HtmlApiFuzz\normalize_payload_policy_label( $replay['payloadPolicy'] ?? null )
		?? \HtmlApiFuzz\normalize_payload_policy_label( $replay['generator']['payloadPolicy'] ?? null );
}
$original_generator = is_array( $replay['generator'] ?? null ) ? $replay['generator'] : ( $replay['originalGenerator'] ?? null );
$source_replay = \HtmlApiFuzz\replay_source_metadata( $replay_path, $replay );
$git_metadata_base64 = \HtmlApiFuzz\git_metadata_base64( \HtmlApiFuzz\git_metadata() );
$explicit_chrome_socket = array_key_exists( 'chrome-socket', $options );
if ( ! $explicit_chrome_socket ) {
	putenv( 'HTML_API_FUZZ_CHROME_SOCKET' );
}
$oracle_options = $options;
if ( null === \HtmlApiFuzz\option_string( $oracle_options, 'dom-oracle', null ) ) {
	$oracle_options['dom-oracle'] = $replay['options']['domOracle'] ?? $replay['oracle']['kind'] ?? \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE;
}
if ( null === \HtmlApiFuzz\option_string( $oracle_options, 'lexbor-oracle-bin', null ) && is_string( $replay['options']['lexborOracleBin'] ?? null ) ) {
	$oracle_options['lexbor-oracle-bin'] = $replay['options']['lexborOracleBin'];
}
$stored_oracle_options = array(
	'html5ever-oracle-bin' => 'html5everOracleBin',
	'chrome-oracle-script' => 'chromeOracleScript',
	'chrome-executable'    => 'chromeExecutable',
	'node-bin'             => 'nodeBin',
);
foreach ( $stored_oracle_options as $option_name => $replay_key ) {
	if ( null === \HtmlApiFuzz\option_string( $oracle_options, $option_name, null ) && is_string( $replay['options'][ $replay_key ] ?? null ) ) {
		$oracle_options[ $option_name ] = $replay['options'][ $replay_key ];
	}
}
$stored_oracle_timeout_ms = $replay['options']['oracleTimeoutMs'] ?? null;
if ( null === \HtmlApiFuzz\option_string( $oracle_options, 'oracle-timeout-ms', null ) && is_numeric( $stored_oracle_timeout_ms ) ) {
	$oracle_options['oracle-timeout-ms'] = (string) (int) $stored_oracle_timeout_ms;
}
$oracle_renderer    = \HtmlApiFuzz\OracleRenderer::from_options( $oracle_options );
$oracle_renderer->start_run_service( $output_dir );
$oracle_renderer->assert_replay_compatible( is_array( $replay['oracle'] ?? null ) ? $replay['oracle'] : array() );
$oracle_worker_args = $oracle_renderer->worker_args();

$args = array(
	__DIR__ . '/worker.php',
	'--input-file',
	$input_path,
	'--mode',
	$replay['mode'] ?? \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	'--profile',
	$replay['profile'] ?? 'replay',
	'--seed',
	(string) ( $replay['seed'] ?? 1 ),
	'--output-dir',
	$output_dir,
	'--max-tokens',
	(string) \HtmlApiFuzz\option_int( $options, 'max-tokens', (int) ( $replay['limits']['maxTokens'] ?? 2000 ) ),
	'--max-nodes',
	(string) \HtmlApiFuzz\option_int( $options, 'max-nodes', (int) ( $replay['limits']['maxNodes'] ?? 3000 ) ),
	'--git-metadata-base64',
	$git_metadata_base64,
);
if ( null !== $payload_policy ) {
	$args[] = '--payload-policy';
	$args[] = $payload_policy;
}
$fragment_context = $replay['fragmentContext'] ?? null;
if ( is_string( $fragment_context ) && 'body' !== $fragment_context ) {
	$args[] = '--fragment-context';
	$args[] = $fragment_context;
}
if ( \HtmlApiFuzz\option_bool( $options, 'fail-unsupported', (bool) ( $replay['options']['failUnsupported'] ?? false ) ) ) {
	$args[] = '--fail-unsupported';
}
foreach ( $oracle_worker_args as $arg ) {
	$args[] = $arg;
}

$proc = \HtmlApiFuzz\run_php_process( $args, \HtmlApiFuzz\repo_root(), \HtmlApiFuzz\option_int( $options, 'timeout-ms', 2500 ), $output_dir . '/worker.log' );
$result = \HtmlApiFuzz\read_json_file( $output_dir . '/result.json' );
$output_replay = \HtmlApiFuzz\read_json_file( $output_dir . '/replay.json' );
if ( is_array( $output_replay ) && is_array( $original_generator ) ) {
	$output_replay['originalGenerator'] = $original_generator;
}
if ( is_array( $output_replay ) ) {
	$output_replay['sourceReplay'] = $source_replay;
	\HtmlApiFuzz\write_json_file( $output_dir . '/replay.json', $output_replay );
}
echo \HtmlApiFuzz\json_encode_safe(
	array(
		'ok'       => $result['ok'] ?? false,
		'status'   => $result['status'] ?? 'missing-result',
		'result'   => $output_dir . '/result.json',
		'replay'   => $output_dir . '/replay.json',
		'worker'   => array(
			'code'       => $proc['code'],
			'timedOut'   => $proc['timedOut'],
			'durationMs' => $proc['durationMs'],
			'logPath'    => $proc['logPath'],
		),
		'signature'     => $result['signature'] ?? null,
		'oracleFinding' => $result['oracleFinding'] ?? null,
	)
) . "\n";
exit( ( $result['ok'] ?? false ) ? 0 : 2 );
