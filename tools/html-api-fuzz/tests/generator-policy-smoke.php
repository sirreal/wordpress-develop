#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_smoke_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function html_api_fuzz_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_smoke_fail( $message );
	}
}

function html_api_fuzz_smoke_valid_utf8( string $bytes ): bool {
	return 1 === preg_match( '//u', $bytes );
}

function html_api_fuzz_smoke_expect_invalid_argument( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException $e ) {
		return;
	}

	html_api_fuzz_smoke_fail( $message );
}

function html_api_fuzz_smoke_rm_tree( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}
	if ( is_file( $path ) || is_link( $path ) ) {
		@unlink( $path );
		return;
	}
	foreach ( scandir( $path ) ?: array() as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		html_api_fuzz_smoke_rm_tree( $path . DIRECTORY_SEPARATOR . $item );
	}
	@rmdir( $path );
}

$valid = \HtmlApiFuzz\Generator::generate(
	123,
	'balanced',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	'valid-utf8',
	64
);
html_api_fuzz_smoke_assert( 'valid-utf8' === $valid['payloadPolicy'], 'valid-utf8 policy should be resolved.' );
html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $valid['input'] ), 'valid-utf8 policy should produce valid UTF-8 bytes.' );
html_api_fuzz_smoke_assert( strlen( $valid['input'] ) <= 64, 'max-input-bytes should cap generated input.' );
html_api_fuzz_smoke_assert( true === $valid['parameters']['truncated'], 'max-input-bytes smoke should exercise truncation.' );
html_api_fuzz_smoke_assert( in_array( 'generator:truncated', $valid['parameters']['features'], true ), 'truncation should be recorded as a feature.' );
html_api_fuzz_smoke_assert( 'valid-utf8' === $valid['parameters']['payloadPolicy'], 'parameters should include payload policy.' );
html_api_fuzz_smoke_expect_invalid_argument(
	static function (): void {
		\HtmlApiFuzz\Generator::generate( 1, 'balanced', 'bogus-mode', 'valid-utf8' );
	},
	'invalid generator mode should throw.'
);

$found_invalid = false;
for ( $seed = 1; $seed <= 200; ++$seed ) {
	$invalid = \HtmlApiFuzz\Generator::generate(
		$seed,
		'attributes-entities',
		\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'invalid-byte-heavy'
	);
	if ( ! html_api_fuzz_smoke_valid_utf8( $invalid['input'] ) ) {
		$found_invalid = true;
		html_api_fuzz_smoke_assert(
			in_array( 'payload:invalid-byte', $invalid['parameters']['features'], true ) || in_array( 'payload:nul', $invalid['parameters']['features'], true ),
			'invalid-byte-heavy invalid sample should record payload-level feature metadata.'
		);
		break;
	}
}
html_api_fuzz_smoke_assert( $found_invalid, 'invalid-byte-heavy policy should generate invalid UTF-8 across a small deterministic seed sample.' );

$found_resource_stress = false;
$found_resource_stress_long = false;
for ( $seed = 1; $seed <= 512; ++$seed ) {
	$generated = \HtmlApiFuzz\Generator::generate( $seed, 'auto', 'auto', 'auto', 4096 );
	if ( 'resource-stress' === $generated['profile'] ) {
		$found_resource_stress = true;
	}
	if ( 'stress-long' === $generated['payloadPolicy'] ) {
		html_api_fuzz_smoke_assert( 'resource-stress' === $generated['profile'], 'auto stress-long payloads should stay in the resource-stress profile.' );
		$found_resource_stress_long = true;
	}
}
html_api_fuzz_smoke_assert( $found_resource_stress, 'auto generation should retain the resource-stress bucket.' );
html_api_fuzz_smoke_assert( $found_resource_stress_long, 'resource-stress auto generation should retain stress-long payload coverage.' );
for ( $seed = 1; $seed <= 64; ++$seed ) {
	$generated = \HtmlApiFuzz\Generator::generate( $seed, 'balanced', 'auto', 'auto', 4096 );
	html_api_fuzz_smoke_assert( 'stress-long' !== $generated['payloadPolicy'], 'non-resource explicit profiles should not auto-resolve stress-long.' );
}

$tmp = tempnam( sys_get_temp_dir(), 'html-api-fuzz-policy-smoke-' );
if ( false === $tmp ) {
	html_api_fuzz_smoke_fail( 'Could not create temp path.' );
}
@unlink( $tmp );
\HtmlApiFuzz\ensure_dir( $tmp );
register_shutdown_function( 'html_api_fuzz_smoke_rm_tree', $tmp );
html_api_fuzz_smoke_expect_invalid_argument(
	static function () use ( $tmp ): void {
		\HtmlApiFuzz\Worker::run(
			array(
				'input-base64'   => base64_encode( '<p>x</p>' ),
				'payload-policy' => 'valid-ut8',
				'output-dir'     => $tmp . '/invalid-policy',
			)
		);
	},
	'invalid direct-input payload policy should throw.'
);

$worker_dir = $tmp . '/worker';
\HtmlApiFuzz\Worker::run(
	array(
		'seed'            => '17',
		'profile'         => 'balanced',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'valid-utf8',
		'max-input-bytes' => '2048',
		'output-dir'      => $worker_dir,
	)
);
$worker_replay = \HtmlApiFuzz\read_json_file( $worker_dir . '/replay.json' );
$worker_result = \HtmlApiFuzz\read_json_file( $worker_dir . '/result.json' );
html_api_fuzz_smoke_assert( 'valid-utf8' === ( $worker_replay['payloadPolicy'] ?? null ), 'replay should persist top-level payload policy.' );
html_api_fuzz_smoke_assert( 'valid-utf8' === ( $worker_replay['generator']['payloadPolicy'] ?? null ), 'replay generator parameters should persist payload policy.' );
html_api_fuzz_smoke_assert( 'generated' === ( $worker_replay['inputSource'] ?? null ), 'generated replay should record generated input source.' );
html_api_fuzz_smoke_assert( 'valid-utf8' === ( $worker_result['payloadPolicy'] ?? null ), 'result should persist payload policy.' );
html_api_fuzz_smoke_assert( ! empty( $worker_result['generator']['features'] ?? array() ), 'result should persist non-empty generator features.' );
html_api_fuzz_smoke_assert( ( $worker_replay['generator']['features'] ?? null ) === ( $worker_result['generator']['features'] ?? null ), 'result and replay should persist the same generator features.' );

$replay_cli_dir = $tmp . '/replay-cli';
$replay_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay',
		$worker_dir . '/replay.json',
		'--output-dir',
		$replay_cli_dir,
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/replay-cli.log'
);
html_api_fuzz_smoke_assert( ! $replay_proc['timedOut'] && in_array( $replay_proc['code'], array( 0, 2 ), true ), 'replay CLI should complete.' );
$replay_cli_replay = \HtmlApiFuzz\read_json_file( $replay_cli_dir . '/replay.json' );
html_api_fuzz_smoke_assert( null === ( $replay_cli_replay['generator'] ?? null ), 'replay CLI output should not invent immediate generator metadata.' );
html_api_fuzz_smoke_assert( 'input-file' === ( $replay_cli_replay['inputSource'] ?? null ), 'replay CLI output should record immediate input source.' );
html_api_fuzz_smoke_assert( ( $worker_replay['generator'] ?? null ) === ( $replay_cli_replay['originalGenerator'] ?? null ), 'replay CLI output should preserve original generator metadata.' );

$bad_runner_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/runner.php',
		'--payload-policy',
		'valid-ut8',
		'--max-seeds',
		'1',
		'--output-dir',
		$tmp . '/bad-runner',
	),
	\HtmlApiFuzz\repo_root(),
	5000,
	$tmp . '/bad-runner.log'
);
html_api_fuzz_smoke_assert( 0 !== $bad_runner_proc['code'], 'runner CLI should reject invalid payload policy before starting workers.' );

$unlabeled_dir = $tmp . '/unlabeled-direct';
$unlabeled_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64' => base64_encode( '<p>x</p>' ),
		'profile'      => 'replay',
		'mode'         => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'output-dir'   => $unlabeled_dir,
	)
);
$unlabeled_replay = \HtmlApiFuzz\read_json_file( $unlabeled_dir . '/replay.json' );
html_api_fuzz_smoke_assert( null === ( $unlabeled_result['payloadPolicy'] ?? null ), 'unlabeled direct input result should leave payloadPolicy null.' );
html_api_fuzz_smoke_assert( null === ( $unlabeled_replay['payloadPolicy'] ?? null ), 'unlabeled direct input replay should leave payloadPolicy null.' );
html_api_fuzz_smoke_assert( null === ( $unlabeled_result['generator'] ?? null ), 'unlabeled direct input should not invent generator metadata.' );
html_api_fuzz_smoke_assert( 'input-base64' === ( $unlabeled_replay['inputSource'] ?? null ), 'unlabeled direct input replay should record inputSource.' );

$resource_dir = $tmp . '/resource-limit';
$resource_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( str_repeat( '<span>', 12 ) ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $resource_dir,
		'max-tokens'      => '1',
		'max-nodes'       => '100',
	)
);
html_api_fuzz_smoke_assert( 'resource-limit' === ( $resource_result['failureClass'] ?? null ), 'token ceilings should be bucketed as resource-limit.' );
html_api_fuzz_smoke_assert( 'resource-limit' === ( $resource_result['status'] ?? null ), 'token ceilings should use resource-limit status.' );
html_api_fuzz_smoke_assert( in_array( 'tag-token-limit-exceeded', $resource_result['signature']['facts']['limitFailures'] ?? array(), true ), 'resource-limit signature should include concrete limit failure names.' );
html_api_fuzz_smoke_assert( 'input-base64' === ( $resource_result['inputSource'] ?? null ), 'provided base64 input should record inputSource.' );
html_api_fuzz_smoke_assert( null === ( $resource_result['generator'] ?? null ), 'provided input should not invent generator metadata.' );

$encoding_dir = $tmp . '/encoding-mismatch';
$encoding_input = '<p>' . str_repeat( 'a', 220 ) . "\xC0" . '</p>';
$encoding_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( $encoding_input ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'invalid-byte-heavy',
		'output-dir'      => $encoding_dir,
		'max-tokens'      => '2000',
		'max-nodes'       => '3000',
	)
);
html_api_fuzz_smoke_assert( 'encoding-mismatch' === ( $encoding_result['failureClass'] ?? null ), 'invalid byte beyond hex preview should be classified as encoding-mismatch.' );
html_api_fuzz_smoke_assert( null === ( $encoding_result['generator'] ?? null ), 'replayed invalid input should not invent generator metadata.' );

$structural_with_invalid_dir = $tmp . '/structural-with-invalid';
$structural = \HtmlApiFuzz\Generator::generate( 61, 'balanced', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'valid-utf8', 4096 );
$structural_with_invalid_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( $structural['input'] . "\xC0" ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'invalid-byte-heavy',
		'output-dir'      => $structural_with_invalid_dir,
		'max-tokens'      => '2000',
		'max-nodes'       => '3000',
	)
);
html_api_fuzz_smoke_assert( 'tree-mismatch' === ( $structural_with_invalid_result['failureClass'] ?? null ), 'invalid bytes elsewhere should not relabel structural tree mismatches as encoding-mismatch.' );

echo "OK\n";
