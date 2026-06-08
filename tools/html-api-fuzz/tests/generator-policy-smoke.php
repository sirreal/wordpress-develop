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

$valid = \HtmlApiFuzz\Generator::generate(
	123,
	'balanced',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	'valid-utf8',
	2048
);
html_api_fuzz_smoke_assert( 'valid-utf8' === $valid['payloadPolicy'], 'valid-utf8 policy should be resolved.' );
html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $valid['input'] ), 'valid-utf8 policy should produce valid UTF-8 bytes.' );
html_api_fuzz_smoke_assert( strlen( $valid['input'] ) <= 2048, 'max-input-bytes should cap generated input.' );
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

$resource_stress_count = 0;
for ( $seed = 1; $seed <= 256; ++$seed ) {
	$generated = \HtmlApiFuzz\Generator::generate( $seed, 'auto', 'auto', 'auto', 4096 );
	if ( 'resource-stress' === $generated['profile'] ) {
		++$resource_stress_count;
	}
	if ( 'stress-long' === $generated['payloadPolicy'] ) {
		html_api_fuzz_smoke_assert( 'resource-stress' === $generated['profile'], 'auto stress-long payloads should stay in the resource-stress profile.' );
	}
}
html_api_fuzz_smoke_assert( $resource_stress_count <= 8, 'resource-stress should remain a small auto-generation bucket.' );

$tmp = sys_get_temp_dir() . '/html-api-fuzz-policy-smoke-' . getmypid();
\HtmlApiFuzz\ensure_dir( $tmp );
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
html_api_fuzz_smoke_assert( is_array( $worker_result['generator']['features'] ?? null ), 'result should persist generator features.' );

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

echo "OK\n";
