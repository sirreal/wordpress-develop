#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

$options = \HtmlApiFuzz\parse_cli_options( $argv );
$replay_path = \HtmlApiFuzz\option_string( $options, 'replay', $options['_'][0] ?? null );
$store_path  = \HtmlApiFuzz\option_string( $options, 'store', null );
$stored_replay_value = null;
if ( ( null === $replay_path && null === $store_path ) || \HtmlApiFuzz\option_bool( $options, 'help', false ) ) {
	echo "Usage: php tools/html-api-fuzz/replay.php --replay path/to/replay.json [--output-dir DIR] [--payload-policy POLICY] [--memory-limit LIMIT] [--timeout-ms N] [--worker-script PATH] [--dom-oracle php-dom|lexbor-source] [--lexbor-oracle-bin PATH] [--allow-oracle-mismatch]\n";
	echo "       php tools/html-api-fuzz/replay.php --store path/to/results.sqlite (--id N|--seed N) [--output-dir DIR] [--payload-policy POLICY] [--dom-oracle php-dom|lexbor-source] [--lexbor-oracle-bin PATH] [--allow-oracle-mismatch]\n";
	echo "The --store form reproduces a failure whose seed directory was pruned, from the replay stored in the lane's results.sqlite.\n";
	exit( ( null === $replay_path && null === $store_path ) ? 1 : 0 );
}
foreach ( array( 'memory-limit', 'timeout-ms', 'worker-script', 'checks' ) as $value_option ) {
	if ( array_key_exists( $value_option, $options ) && true === $options[ $value_option ] ) {
		fwrite( STDERR, "Expected --{$value_option} to have a value.\n" );
		exit( 1 );
	}
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
	$replay_path = $replay_dir . '/source-replay.json';
	$stored_replay_value = $store_replay;
	$options['output-dir'] = $replay_dir;
}

$replay = is_array( $stored_replay_value ) ? $stored_replay_value : \HtmlApiFuzz\read_json_file( $replay_path );
if ( ! $replay || ! array_key_exists( 'inputBase64', $replay ) ) {
	fwrite( STDERR, "Invalid replay file: {$replay_path}\n" );
	exit( 1 );
}
if ( array_key_exists( 'options', $replay ) && ! is_array( $replay['options'] ) ) {
	fwrite( STDERR, "Invalid replay options: expected an object.\n" );
	exit( 1 );
}
$recorded_options = is_array( $replay['options'] ?? null ) ? $replay['options'] : array();

$output_dir = \HtmlApiFuzz\option_string( $options, 'output-dir', dirname( $replay_path ) . '/replay-' . \HtmlApiFuzz\timestamp() );
$input      = base64_decode( $replay['inputBase64'], true );
if ( false === $input ) {
	fwrite( STDERR, "Invalid base64 input in replay file: {$replay_path}\n" );
	exit( 1 );
}
$recorded_memory_limit = null;
if ( array_key_exists( 'memoryLimit', $recorded_options ) ) {
	$recorded_memory_limit = $recorded_options['memoryLimit'];
	if ( ! is_string( $recorded_memory_limit ) || ! preg_match( '/^(?:-1|[1-9][0-9]*[KMG]?)$/i', $recorded_memory_limit ) ) {
		fwrite( STDERR, "Invalid recorded memoryLimit: expected -1 or a positive PHP limit such as 256M.\n" );
		exit( 1 );
	}
}
$memory_limit = \HtmlApiFuzz\option_string( $options, 'memory-limit', $recorded_memory_limit );
if ( null === $memory_limit ) {
	$probe = \HtmlApiFuzz\run_php_process(
		array( '-r', 'echo ini_get("memory_limit");' ),
		\HtmlApiFuzz\repo_root(),
		5000
	);
	if ( 0 !== $probe['code'] || $probe['timedOut'] ) {
		fwrite( STDERR, "Could not resolve the default child PHP memory limit.\n" );
		exit( 1 );
	}
	$memory_limit = trim( $probe['stdout'] );
}
if ( ! preg_match( '/^(?:-1|[1-9][0-9]*[KMG]?)$/i', $memory_limit ) ) {
	fwrite( STDERR, "Expected --memory-limit or replay memoryLimit to be -1 or a positive PHP limit such as 256M.\n" );
	exit( 1 );
}
$recorded_timeout_ms = 2500;
if ( array_key_exists( 'processTimeoutMs', $recorded_options ) ) {
	$recorded_timeout_value = $recorded_options['processTimeoutMs'];
	$parsed_timeout = ( is_int( $recorded_timeout_value ) || is_string( $recorded_timeout_value ) )
		? filter_var( $recorded_timeout_value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) )
		: false;
	if ( false === $parsed_timeout ) {
		fwrite( STDERR, "Invalid recorded processTimeoutMs: expected a positive integer.\n" );
		exit( 1 );
	}
	$recorded_timeout_ms = (int) $parsed_timeout;
}
$timeout_ms = \HtmlApiFuzz\option_int( $options, 'timeout-ms', $recorded_timeout_ms );
if ( $timeout_ms < 1 ) {
	fwrite( STDERR, "Expected --timeout-ms or replay processTimeoutMs to be positive.\n" );
	exit( 1 );
}
$recorded_worker_script = __DIR__ . '/worker.php';
if ( array_key_exists( 'workerScript', $recorded_options ) ) {
	if ( ! is_string( $recorded_options['workerScript'] ) || '' === $recorded_options['workerScript'] ) {
		fwrite( STDERR, "Invalid recorded workerScript: expected a non-empty string.\n" );
		exit( 1 );
	}
	$recorded_worker_script = $recorded_options['workerScript'];
}
$worker_script = \HtmlApiFuzz\option_string( $options, 'worker-script', $recorded_worker_script );
if ( is_string( $worker_script ) && ! str_starts_with( $worker_script, DIRECTORY_SEPARATOR ) ) {
	$worker_script = \HtmlApiFuzz\repo_root() . DIRECTORY_SEPARATOR . $worker_script;
}
if ( ! is_string( $worker_script ) || ! is_file( $worker_script ) ) {
	fwrite( STDERR, "Recorded or selected Worker script does not exist.\n" );
	exit( 1 );
}
$worker_script = realpath( $worker_script ) ?: $worker_script;
$recorded_checks = 'full';
if ( array_key_exists( 'checks', $recorded_options ) ) {
	if ( ! is_string( $recorded_options['checks'] ) || ! in_array( $recorded_options['checks'], array( 'baseline', 'full' ), true ) ) {
		fwrite( STDERR, "Invalid recorded checks: expected baseline or full.\n" );
		exit( 1 );
	}
	$recorded_checks = $recorded_options['checks'];
}
$checks = \HtmlApiFuzz\option_string( $options, 'checks', $recorded_checks );
if ( ! in_array( $checks, array( 'baseline', 'full' ), true ) ) {
	fwrite( STDERR, "Expected --checks or replay checks to be baseline or full.\n" );
	exit( 1 );
}
$effective_policy = array(
	'workerScript'     => $worker_script,
	'memoryLimit'      => $memory_limit,
	'processTimeoutMs' => $timeout_ms,
	'checks'           => $checks,
);
$payload_policy = \HtmlApiFuzz\option_string( $options, 'payload-policy', null );
if ( null === $payload_policy ) {
	$payload_policy = \HtmlApiFuzz\normalize_payload_policy_label( $replay['payloadPolicy'] ?? null )
		?? \HtmlApiFuzz\normalize_payload_policy_label( $replay['generator']['payloadPolicy'] ?? null );
}
$original_generator = is_array( $replay['generator'] ?? null ) ? $replay['generator'] : ( $replay['originalGenerator'] ?? null );
$source_replay = \HtmlApiFuzz\replay_source_metadata( $replay_path, $replay );
$git_metadata_base64 = \HtmlApiFuzz\git_metadata_base64( \HtmlApiFuzz\git_metadata() );
$oracle_options = $options;
if ( null === \HtmlApiFuzz\option_string( $oracle_options, 'dom-oracle', null ) ) {
	$oracle_options['dom-oracle'] = $recorded_options['domOracle'] ?? $replay['oracle']['kind'] ?? \HtmlApiFuzz\OracleRenderer::KIND_PHP_DOM;
}
if ( null === \HtmlApiFuzz\option_string( $oracle_options, 'lexbor-oracle-bin', null ) && is_string( $recorded_options['lexborOracleBin'] ?? null ) ) {
	$oracle_options['lexbor-oracle-bin'] = $recorded_options['lexborOracleBin'];
}
$stored_oracle_timeout_ms = $recorded_options['oracleTimeoutMs'] ?? null;
if ( null === \HtmlApiFuzz\option_string( $oracle_options, 'oracle-timeout-ms', null ) && is_numeric( $stored_oracle_timeout_ms ) ) {
	$oracle_options['oracle-timeout-ms'] = (string) (int) $stored_oracle_timeout_ms;
}
$oracle_renderer    = \HtmlApiFuzz\OracleRenderer::from_options( $oracle_options );
$oracle_worker_args = $oracle_renderer->worker_args();
$current_oracle     = $oracle_renderer->metadata();
$oracle_mismatches  = \HtmlApiFuzz\OracleRenderer::identity_mismatches( $replay['oracle'] ?? null, $current_oracle );
if ( ! empty( $oracle_mismatches ) && ! \HtmlApiFuzz\option_bool( $options, 'allow-oracle-mismatch', false ) ) {
	fwrite( STDERR, 'Oracle identity mismatch: ' . implode( '; ', $oracle_mismatches ) . ".\n" );
	fwrite( STDERR, "Pass --allow-oracle-mismatch only for a deliberate diagnostic comparison.\n" );
	exit( 1 );
}

\HtmlApiFuzz\ensure_dir( $output_dir );
$claim_path = $output_dir . '/.replay-attempt';
$claim = @fopen( $claim_path, 'x' );
if ( false === $claim ) {
	fwrite( STDERR, "Replay output directory is already claimed: {$output_dir}\n" );
	exit( 1 );
}
fclose( $claim );
$conflicts = array( 'input.bin', 'result.json', 'replay.json', 'worker.log' );
if ( is_array( $stored_replay_value ) ) {
	$conflicts[] = 'source-replay.json';
}
foreach ( $conflicts as $conflict ) {
	if ( file_exists( $output_dir . '/' . $conflict ) ) {
		@unlink( $claim_path );
		fwrite( STDERR, "Replay output directory contains stale {$conflict}: {$output_dir}\n" );
		exit( 1 );
	}
}
if ( is_array( $stored_replay_value ) ) {
	\HtmlApiFuzz\write_json_file_atomic( $replay_path, $stored_replay_value );
}
$input_path = $output_dir . '/input.bin';
\HtmlApiFuzz\write_file_atomic( $input_path, $input );

$args = array(
	'-d',
	'memory_limit=' . $memory_limit,
	$worker_script,
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
	'--max-depth',
	(string) \HtmlApiFuzz\option_int( $options, 'max-depth', (int) ( $replay['limits']['maxDepth'] ?? 512 ) ),
	'--max-tree-bytes',
	(string) \HtmlApiFuzz\option_int( $options, 'max-tree-bytes', (int) ( $replay['limits']['maxTreeBytes'] ?? 16777216 ) ),
	'--checks',
	$checks,
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
if ( \HtmlApiFuzz\option_bool( $options, 'fail-unsupported', (bool) ( $recorded_options['failUnsupported'] ?? false ) ) ) {
	$args[] = '--fail-unsupported';
}
foreach ( $oracle_worker_args as $arg ) {
	$args[] = $arg;
}

$proc = \HtmlApiFuzz\run_php_process( $args, \HtmlApiFuzz\repo_root(), $timeout_ms, $output_dir . '/worker.log', 1048576, true );
$result = null;
$output_replay = null;
try {
	$result = \HtmlApiFuzz\read_json_file( $output_dir . '/result.json' );
} catch ( \Throwable $ignored ) {
	$result = null;
}
try {
	$output_replay = \HtmlApiFuzz\read_json_file( $output_dir . '/replay.json' );
} catch ( \Throwable $ignored ) {
	$output_replay = null;
}
if ( $proc['processGroupCleanupFailed'] ?? false ) {
	$result = null;
}
if ( ! is_array( $result ) ) {
	$result = \HtmlApiFuzz\synthesize_worker_process_failure(
		$proc,
		array(
			'seed'            => (int) ( $replay['seed'] ?? 1 ),
			'profile'         => (string) ( $replay['profile'] ?? 'replay' ),
			'mode'            => (string) ( $replay['mode'] ?? \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY ),
			'payloadPolicy'   => $replay['payloadPolicy'] ?? null,
			'fragmentContext' => (string) ( $replay['fragmentContext'] ?? 'body' ),
			'inputSource'     => 'replay',
			'inputSha1'       => sha1( $input ),
			'inputLength'     => strlen( $input ),
			'checks'          => $checks,
			'oracle'          => $current_oracle,
		)
	);
	$signature = \HtmlApiFuzz\Signature::from_result( $result );
	if ( null !== $signature ) {
		$result['signature'] = $signature;
	}
	$result['paths'] = array(
		'outputDir'  => $output_dir,
		'inputPath'  => $input_path,
		'replayPath' => $output_dir . '/replay.json',
		'resultPath' => $output_dir . '/result.json',
	);
	\HtmlApiFuzz\write_json_file_atomic( $output_dir . '/result.json', $result );
}
if ( ! is_array( $output_replay ) ) {
	$output_replay = $replay;
}
if ( is_array( $output_replay ) && is_array( $original_generator ) ) {
	$output_replay['originalGenerator'] = $original_generator;
}
if ( is_array( $output_replay ) ) {
	$output_replay['sourceReplay'] = $source_replay;
	$output_options = is_array( $output_replay['options'] ?? null ) ? $output_replay['options'] : array();
	unset( $output_options['domOracle'], $output_options['lexborOracleBin'], $output_options['oracleTimeoutMs'] );
	$output_replay['options'] = array_merge( $output_options, $effective_policy, $oracle_renderer->replay_options() );
	$output_replay['oracle'] = $current_oracle;
	$output_replay['result'] = array(
		'ok'            => $result['ok'] ?? false,
		'status'        => $result['status'] ?? 'missing-result',
		'failureClass'  => $result['failureClass'] ?? null,
		'signature'     => $result['signature'] ?? null,
		'oracleFinding' => $result['oracleFinding'] ?? null,
		'oracle'        => $result['oracle'] ?? null,
		'resultPath'    => $output_dir . '/result.json',
	);
	\HtmlApiFuzz\write_json_file_atomic( $output_dir . '/replay.json', $output_replay );
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
			'processGroupIsolated' => $proc['processGroupIsolated'],
			'processGroupCleanupFailed' => $proc['processGroupCleanupFailed'],
		),
		'signature'     => $result['signature'] ?? null,
		'oracleFinding' => $result['oracleFinding'] ?? null,
	)
) . "\n";
exit( ( $result['ok'] ?? false ) ? 0 : 2 );
