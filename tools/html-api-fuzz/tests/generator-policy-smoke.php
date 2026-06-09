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

function html_api_fuzz_smoke_has_non_whitespace_c0_control( string $bytes ): bool {
	return 1 === preg_match( '/[\x01-\x08\x0b\x0e-\x1f]/', $bytes );
}

function html_api_fuzz_smoke_expect_invalid_argument( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException $e ) {
		return;
	}

	html_api_fuzz_smoke_fail( $message );
}

function html_api_fuzz_smoke_dom_drops_bare_xlink_local_name_after_xlink(): bool {
	if ( ! class_exists( 'Dom\\HTMLDocument' ) ) {
		return false;
	}

	$previous = libxml_use_internal_errors( true );
	try {
		$document = Dom\HTMLDocument::createFromString( '<svg xlink:href href></svg>', LIBXML_NOERROR );
		$svg      = $document->getElementsByTagName( 'svg' )->item( 0 );
		$drops    = null !== $svg && $svg->hasAttributeNS( 'http://www.w3.org/1999/xlink', 'href' ) && ! $svg->hasAttribute( 'href' );
	} catch ( Throwable $e ) {
		$drops = false;
	}
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	return $drops;
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
	1,
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
html_api_fuzz_smoke_assert( ! in_array( 'invalid-byte-heavy', \HtmlApiFuzz\Generator::payload_policies(), true ), 'invalid-byte-heavy should not be selectable for generated inputs.' );
html_api_fuzz_smoke_assert( in_array( 'invalid-byte-heavy', \HtmlApiFuzz\Generator::payload_policy_labels(), true ), 'invalid-byte-heavy should remain a recognized replay metadata label.' );
html_api_fuzz_smoke_expect_invalid_argument(
	static function (): void {
		\HtmlApiFuzz\Generator::generate( 1, 'attributes-entities', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'invalid-byte-heavy' );
	},
	'invalid-byte-heavy policy should be rejected for generated inputs.'
);

foreach ( \HtmlApiFuzz\Generator::payload_policies() as $payload_policy ) {
	foreach ( \HtmlApiFuzz\Generator::profiles() as $profile ) {
		foreach ( \HtmlApiFuzz\Generator::modes() as $mode ) {
			for ( $seed = 1; $seed <= 8; ++$seed ) {
				$generated = \HtmlApiFuzz\Generator::generate( $seed, $profile, $mode, $payload_policy, 4096 );
				html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $generated['input'] ), "{$payload_policy}/{$profile}/{$mode}/{$seed} should produce valid UTF-8 bytes." );
				html_api_fuzz_smoke_assert( ! in_array( 'payload:invalid-byte', $generated['parameters']['features'], true ), "{$payload_policy}/{$profile}/{$mode}/{$seed} should not record invalid-byte generation." );
			}
		}
	}
}
$found_non_whitespace_c0_control = false;
for ( $seed = 1; $seed <= 512; ++$seed ) {
	$generated = \HtmlApiFuzz\Generator::generate( $seed, 'balanced', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'mostly-valid', 4096 );
	if ( html_api_fuzz_smoke_has_non_whitespace_c0_control( $generated['input'] ) ) {
		$found_non_whitespace_c0_control = true;
		html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $generated['input'] ), 'non-whitespace C0 control sample should still be valid UTF-8.' );
		break;
	}
}
html_api_fuzz_smoke_assert( $found_non_whitespace_c0_control, 'generated valid UTF-8 payloads should retain non-whitespace C0 control coverage.' );

$required_generator_features = array(
	'charref:text',
	'charref:attr',
	'charref:rcdata',
	'charref:text:named-semicolon',
	'charref:text:named-missing-semicolon-legacy',
	'charref:text:named-missing-semicolon-invalid',
	'charref:text:numeric-valid',
	'charref:text:numeric-invalid',
	'charref:attr:named-semicolon',
	'charref:attr:named-missing-semicolon-legacy',
	'charref:attr:named-missing-semicolon-invalid',
	'charref:attr:numeric-valid',
	'charref:attr:numeric-invalid',
	'charref:rcdata:named-semicolon',
	'charref:rcdata:invalid',
	'charref:rcdata:numeric-valid',
	'charref:rcdata:numeric-invalid',
	'charref:leading-zero',
	'attr:weird-name',
	'attr:weird-spacing',
	'attr:malformed',
	'tag:unusual-name',
	'tag:invalid-name',
	'tag:alpha-invalid-name',
	'tag:alpha-weird-name',
	'tag:bogus-open-name',
	'tag:weird-spacing',
);
$found_generator_features = array_fill_keys( $required_generator_features, false );
$all_generator_features_found = false;
foreach ( array( 'attributes-entities', 'rawtext-rcdata', 'incomplete-malformed', 'balanced' ) as $feature_profile ) {
	for ( $seed = 1; $seed <= 128; ++$seed ) {
		$generated = \HtmlApiFuzz\Generator::generate( $seed, $feature_profile, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, 'mostly-valid', null );
		html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $generated['input'] ), "{$feature_profile}/{$seed} feature-coverage sample should produce valid UTF-8 bytes." );
		$features = $generated['parameters']['features'];
		if ( in_array( 'generator:truncated', $features, true ) || in_array( 'generator:hard-truncated', $features, true ) ) {
			continue;
		}
		foreach ( $features as $feature ) {
			if ( array_key_exists( $feature, $found_generator_features ) ) {
				$found_generator_features[ $feature ] = true;
			}
		}
		if ( ! in_array( false, $found_generator_features, true ) ) {
			$all_generator_features_found = true;
			break 2;
		}
	}
}
html_api_fuzz_smoke_assert( $all_generator_features_found, 'generated samples should cover all required generator features before exhausting the smoke seed budget.' );
foreach ( $found_generator_features as $feature => $found ) {
	html_api_fuzz_smoke_assert( $found, "generated samples should cover {$feature}." );
}

$found_resource_stress = false;
$found_resource_stress_long = false;
for ( $seed = 1; $seed <= 512; ++$seed ) {
	$generated = \HtmlApiFuzz\Generator::generate( $seed, 'auto', 'auto', 'auto', 4096 );
	html_api_fuzz_smoke_assert( html_api_fuzz_smoke_valid_utf8( $generated['input'] ), 'auto generation should produce valid UTF-8 bytes.' );
	html_api_fuzz_smoke_assert( ! in_array( 'payload:invalid-byte', $generated['parameters']['features'], true ), 'auto generation should not record invalid-byte payload features.' );
	if ( ! in_array( $generated['profile'], array( 'attributes-entities', 'incomplete-malformed' ), true ) ) {
		html_api_fuzz_smoke_assert( ! in_array( 'attr:malformed', $generated['parameters']['features'], true ), 'auto generation should keep malformed attributes in targeted profiles.' );
		html_api_fuzz_smoke_assert( ! in_array( 'tag:weird-syntax', $generated['parameters']['features'], true ), 'auto generation should keep weird tag syntax in targeted profiles.' );
	}
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
html_api_fuzz_smoke_expect_invalid_argument(
	static function () use ( $tmp ): void {
		\HtmlApiFuzz\Worker::run(
			array(
				'seed'           => '1',
				'profile'        => 'balanced',
				'mode'           => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
				'payload-policy' => 'invalid-byte-heavy',
				'output-dir'     => $tmp . '/generated-invalid-heavy',
			)
		);
	},
	'generated worker inputs should reject legacy invalid-byte-heavy policy.'
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

$legacy_replay = $worker_replay;
$legacy_replay['payloadPolicy'] = 'replay';
if ( isset( $legacy_replay['generator']['payloadPolicy'] ) ) {
	$legacy_replay['generator']['payloadPolicy'] = 'replay';
}
$legacy_replay_path = $tmp . '/legacy-payload-policy-replay.json';
\HtmlApiFuzz\write_json_file( $legacy_replay_path, $legacy_replay );
$legacy_replay_dir = $tmp . '/legacy-replay-cli';
$legacy_replay_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay',
		$legacy_replay_path,
		'--output-dir',
		$legacy_replay_dir,
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/legacy-replay-cli.log'
);
html_api_fuzz_smoke_assert( ! $legacy_replay_proc['timedOut'] && in_array( $legacy_replay_proc['code'], array( 0, 2 ), true ), 'legacy replay payload policy labels should not make replay fatal.' );
$legacy_replay_cli_replay = \HtmlApiFuzz\read_json_file( $legacy_replay_dir . '/replay.json' );
html_api_fuzz_smoke_assert( null === ( $legacy_replay_cli_replay['payloadPolicy'] ?? null ), 'legacy replay payload policy labels should be treated as unlabeled direct input.' );
html_api_fuzz_smoke_assert( 'replay' === ( $legacy_replay_cli_replay['originalGenerator']['payloadPolicy'] ?? null ), 'legacy replay should preserve original generator metadata.' );

$legacy_invalid_replay = $worker_replay;
$legacy_invalid_replay['payloadPolicy'] = 'invalid-byte-heavy';
if ( isset( $legacy_invalid_replay['generator']['payloadPolicy'] ) ) {
	$legacy_invalid_replay['generator']['payloadPolicy'] = 'invalid-byte-heavy';
}
$legacy_invalid_replay_path = $tmp . '/legacy-invalid-payload-policy-replay.json';
\HtmlApiFuzz\write_json_file( $legacy_invalid_replay_path, $legacy_invalid_replay );
$legacy_invalid_replay_dir = $tmp . '/legacy-invalid-replay-cli';
$legacy_invalid_replay_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay',
		$legacy_invalid_replay_path,
		'--output-dir',
		$legacy_invalid_replay_dir,
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/legacy-invalid-replay-cli.log'
);
html_api_fuzz_smoke_assert( ! $legacy_invalid_replay_proc['timedOut'] && in_array( $legacy_invalid_replay_proc['code'], array( 0, 2 ), true ), 'legacy invalid-byte-heavy replay payload policy label should not make replay fatal.' );
$legacy_invalid_replay_cli_replay = \HtmlApiFuzz\read_json_file( $legacy_invalid_replay_dir . '/replay.json' );
html_api_fuzz_smoke_assert( 'invalid-byte-heavy' === ( $legacy_invalid_replay_cli_replay['payloadPolicy'] ?? null ), 'legacy invalid-byte-heavy replay payload policy label should be preserved as direct-input metadata.' );
html_api_fuzz_smoke_assert( 'invalid-byte-heavy' === ( $legacy_invalid_replay_cli_replay['originalGenerator']['payloadPolicy'] ?? null ), 'legacy invalid-byte-heavy replay should preserve original generator metadata.' );

$invalid_byte_replay_source_dir = $tmp . '/invalid-byte-replay-source';
$invalid_byte_replay_source = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<p>' . str_repeat( 'a', 220 ) . "\xC0" . '</p>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'invalid-byte-heavy',
		'output-dir'      => $invalid_byte_replay_source_dir,
		'max-tokens'      => '2000',
		'max-nodes'       => '3000',
	)
);
html_api_fuzz_smoke_assert( 'encoding-mismatch' === ( $invalid_byte_replay_source['failureClass'] ?? null ), 'invalid-byte replay fixture should be a real invalid-byte failure.' );

$invalid_byte_replay_cli_dir = $tmp . '/invalid-byte-replay-cli';
$invalid_byte_replay_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay',
		$invalid_byte_replay_source_dir . '/replay.json',
		'--output-dir',
		$invalid_byte_replay_cli_dir,
		'--timeout-ms',
		'10000',
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/invalid-byte-replay-cli.log'
);
html_api_fuzz_smoke_assert( ! $invalid_byte_replay_proc['timedOut'] && 2 === $invalid_byte_replay_proc['code'], 'real invalid-byte replay should complete as a replayed failure.' );
$invalid_byte_replay_cli_replay = \HtmlApiFuzz\read_json_file( $invalid_byte_replay_cli_dir . '/replay.json' );
html_api_fuzz_smoke_assert( 'invalid-byte-heavy' === ( $invalid_byte_replay_cli_replay['payloadPolicy'] ?? null ), 'real invalid-byte replay should preserve legacy payload policy metadata.' );

$invalid_byte_minimize_dir = $tmp . '/invalid-byte-minimize';
$invalid_byte_minimize_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/minimize.php',
		'--replay',
		$invalid_byte_replay_source_dir . '/replay.json',
		'--output-dir',
		$invalid_byte_minimize_dir,
		'--any-failure',
		'--max-attempts',
		'1',
		'--timeout-ms',
		'10000',
	),
	\HtmlApiFuzz\repo_root(),
	20000,
	$tmp . '/invalid-byte-minimize.log'
);
html_api_fuzz_smoke_assert( ! $invalid_byte_minimize_proc['timedOut'] && 0 === $invalid_byte_minimize_proc['code'], 'real invalid-byte replay should remain minimizable.' );
$invalid_byte_minimize_result = \HtmlApiFuzz\read_json_file( $invalid_byte_minimize_dir . '/minimize-result.json' );
html_api_fuzz_smoke_assert( 'invalid-byte-heavy' === ( $invalid_byte_minimize_result['payloadPolicy'] ?? null ), 'invalid-byte minimization should preserve legacy payload policy metadata.' );

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

$legacy_invalid_runner_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/runner.php',
		'--payload-policy',
		'invalid-byte-heavy',
		'--max-seeds',
		'1',
		'--output-dir',
		$tmp . '/legacy-invalid-runner',
	),
	\HtmlApiFuzz\repo_root(),
	5000,
	$tmp . '/legacy-invalid-runner.log'
);
html_api_fuzz_smoke_assert( 0 !== $legacy_invalid_runner_proc['code'], 'runner CLI should reject legacy invalid-byte-heavy generation policy.' );

$legacy_invalid_launcher_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/launcher.php',
		'--payload-policy',
		'invalid-byte-heavy',
		'--max-seeds',
		'1',
		'--duration-seconds',
		'0',
		'--output-dir',
		$tmp . '/legacy-invalid-launcher',
	),
	\HtmlApiFuzz\repo_root(),
	5000,
	$tmp . '/legacy-invalid-launcher.log'
);
html_api_fuzz_smoke_assert( 0 !== $legacy_invalid_launcher_proc['code'], 'launcher CLI should reject legacy invalid-byte-heavy generation policy.' );

$bad_stride_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/runner.php',
		'--seed-stride',
		'0',
		'--max-seeds',
		'1',
		'--output-dir',
		$tmp . '/bad-stride-runner',
	),
	\HtmlApiFuzz\repo_root(),
	5000,
	$tmp . '/bad-stride-runner.log'
);
html_api_fuzz_smoke_assert( 0 !== $bad_stride_proc['code'], 'runner CLI should reject non-positive seed strides before starting workers.' );

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
html_api_fuzz_smoke_assert( 'idempotent' === ( $unlabeled_result['tagProcessor']['normalize']['status'] ?? null ), 'worker result should persist normalize() idempotence metadata.' );

$normalize_idempotent = \HtmlApiFuzz\TagInvariants::check( '<p a=1 a=2>One&nbsp</p>', array( 'maxTokens' => 2000 ) );
html_api_fuzz_smoke_assert( true === ( $normalize_idempotent['ok'] ?? null ), 'normalizable input should pass tag invariants.' );
html_api_fuzz_smoke_assert( 'idempotent' === ( $normalize_idempotent['normalize']['status'] ?? null ), 'normalizable input should record an idempotent normalize() status.' );
html_api_fuzz_smoke_assert( is_string( $normalize_idempotent['normalize']['normalizedSha1'] ?? null ), 'idempotent normalize() metadata should include the normalized hash.' );

$normalize_unsupported = \HtmlApiFuzz\TagInvariants::check( '<A><I><A>', array( 'maxTokens' => 2000 ) );
html_api_fuzz_smoke_assert( true === ( $normalize_unsupported['ok'] ?? null ), 'unsupported normalize() input should not fail unrelated tag invariants.' );
html_api_fuzz_smoke_assert( 'unsupported' === ( $normalize_unsupported['normalize']['status'] ?? null ), 'unsupported normalize() input should be recorded without an idempotence failure.' );

$normalize_not_idempotent = \HtmlApiFuzz\TagInvariants::check( '<svg xlink:href href></svg>', array( 'maxTokens' => 2000 ) );
html_api_fuzz_smoke_assert( true === ( $normalize_not_idempotent['ok'] ?? null ), 'normalize() metadata should not fail unrelated tag invariants directly.' );
if ( false === ( $normalize_not_idempotent['normalize']['ok'] ?? true ) ) {
	html_api_fuzz_smoke_assert( 'normalize-not-idempotent' === ( $normalize_not_idempotent['normalize']['failure']['name'] ?? null ), 'non-idempotent normalize() input should report the normalize-not-idempotent invariant.' );
	html_api_fuzz_smoke_assert( 'failed' === ( $normalize_not_idempotent['normalize']['status'] ?? null ), 'non-idempotent normalize() input should record failed normalize() metadata.' );
	html_api_fuzz_smoke_assert( is_int( $normalize_not_idempotent['normalize']['firstDifference']['firstByteOffset'] ?? null ), 'non-idempotent normalize() metadata should include a first byte difference.' );
}

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

$normalize_resource_dir = $tmp . '/normalize-resource-limit';
$normalize_resource_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<svg xlink:href href></svg>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $normalize_resource_dir,
		'max-tokens'      => '1',
		'max-nodes'       => '100',
	)
);
html_api_fuzz_smoke_assert( 'resource-limit' === ( $normalize_resource_result['failureClass'] ?? null ), 'tag token ceilings should not be masked by normalize() failures.' );
html_api_fuzz_smoke_assert( 'resource-limit' === ( $normalize_resource_result['status'] ?? null ), 'tag token ceilings should retain resource-limit status when normalize() would otherwise fail.' );
html_api_fuzz_smoke_assert( 'skipped-resource-limit' === ( $normalize_resource_result['tagProcessor']['normalize']['status'] ?? null ), 'normalize() idempotence should be skipped after tag resource limits.' );
html_api_fuzz_smoke_assert( in_array( 'tag-token-limit-exceeded', $normalize_resource_result['signature']['facts']['limitFailures'] ?? array(), true ), 'resource-limit signature should still include tag token limit failures when normalize() is skipped.' );

$dom_resource_dir = $tmp . '/dom-resource-limit';
$dom_resource_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<p>x</p>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $dom_resource_dir,
		'max-tokens'      => '100',
		'max-nodes'       => '1',
	)
);
html_api_fuzz_smoke_assert( 'resource-limit' === ( $dom_resource_result['failureClass'] ?? null ), 'DOM node ceilings should be bucketed as resource-limit.' );
html_api_fuzz_smoke_assert( 'resource-limit' === ( $dom_resource_result['status'] ?? null ), 'DOM node ceilings should use resource-limit status.' );
html_api_fuzz_smoke_assert( 'node-limit-exceeded' === ( $dom_resource_result['dom']['failureClass'] ?? null ), 'DOM result should preserve the concrete node limit failure.' );
html_api_fuzz_smoke_assert( in_array( 'dom-node-limit-exceeded', $dom_resource_result['signature']['facts']['limitFailures'] ?? array(), true ), 'resource-limit signature should include DOM node limit failures.' );

$dom_template_context_dir = $tmp . '/dom-template-context-sensitive-col';
$dom_template_context_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<html><template><col>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $dom_template_context_dir,
		'max-tokens'      => '100',
		'max-nodes'       => '100',
	)
);
html_api_fuzz_smoke_assert( true === ( $dom_template_context_result['ok'] ?? null ), 'DOM template table-sensitive fallback should not be a failing tree mismatch.' );
html_api_fuzz_smoke_assert( 'oracle-unsupported' === ( $dom_template_context_result['status'] ?? null ), 'DOM template table-sensitive fallback should be quarantined as oracle-unsupported.' );
html_api_fuzz_smoke_assert( 'oracle-unsupported' === ( $dom_template_context_result['failureClass'] ?? null ), 'DOM template table-sensitive fallback should preserve the oracle-unsupported failure class.' );
html_api_fuzz_smoke_assert( 'unsupported' === ( $dom_template_context_result['dom']['status'] ?? null ), 'DOM template table-sensitive fallback should preserve the DOM unsupported status.' );
html_api_fuzz_smoke_assert( null === ( $dom_template_context_result['comparison'] ?? null ), 'DOM template table-sensitive fallback should not compare a lossy DOM tree.' );
html_api_fuzz_smoke_assert( null === ( $dom_template_context_result['signature'] ?? null ), 'DOM template table-sensitive fallback should not produce a fuzz signature.' );
$dom_template_context_wp_tree = file_get_contents( $dom_template_context_result['wordpress']['treePath'] ?? '' );
html_api_fuzz_smoke_assert( false !== $dom_template_context_wp_tree && false !== strpos( $dom_template_context_wp_tree, "        <col>\n" ), 'DOM template context regression should exercise WordPress <col> preservation.' );

$dom_nested_template_context_dir = $tmp . '/dom-nested-template-context-sensitive-col';
$dom_nested_template_context_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<body><template><col><template>x</template></template></body>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $dom_nested_template_context_dir,
		'max-tokens'      => '200',
		'max-nodes'       => '200',
	)
);
html_api_fuzz_smoke_assert( true === ( $dom_nested_template_context_result['ok'] ?? null ), 'Nested DOM template table-sensitive fallback should not be a failing tree mismatch.' );
html_api_fuzz_smoke_assert( 'oracle-unsupported' === ( $dom_nested_template_context_result['status'] ?? null ), 'Nested DOM template table-sensitive fallback should be quarantined as oracle-unsupported.' );
html_api_fuzz_smoke_assert( null === ( $dom_nested_template_context_result['comparison'] ?? null ), 'Nested DOM template table-sensitive fallback should not compare a lossy DOM tree.' );
html_api_fuzz_smoke_assert( null === ( $dom_nested_template_context_result['signature'] ?? null ), 'Nested DOM template table-sensitive fallback should not produce a fuzz signature.' );

$dom_template_context_resource_dir = $tmp . '/dom-template-context-resource-limit';
$dom_template_context_resource_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<template><col><x></x></template>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $dom_template_context_resource_dir,
		'max-tokens'      => '200',
		'max-nodes'       => '3',
	)
);
html_api_fuzz_smoke_assert( false === ( $dom_template_context_resource_result['ok'] ?? null ), 'DOM template oracle quarantine should not mask DOM node ceilings.' );
html_api_fuzz_smoke_assert( 'resource-limit' === ( $dom_template_context_resource_result['status'] ?? null ), 'DOM template oracle quarantine node ceilings should use resource-limit status.' );
html_api_fuzz_smoke_assert( 'resource-limit' === ( $dom_template_context_resource_result['failureClass'] ?? null ), 'DOM template oracle quarantine node ceilings should use resource-limit failure class.' );
html_api_fuzz_smoke_assert( 'node-limit-exceeded' === ( $dom_template_context_resource_result['dom']['failureClass'] ?? null ), 'DOM template oracle quarantine should preserve the concrete DOM node ceiling.' );

$dom_template_table_context_dir = $tmp . '/dom-template-table-context';
$dom_template_table_context_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<template><table><tr><td>x</td></tr></table></template>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $dom_template_table_context_dir,
		'max-tokens'      => '200',
		'max-nodes'       => '200',
	)
);
html_api_fuzz_smoke_assert( true === ( $dom_template_table_context_result['comparison']['ok'] ?? null ), 'DOM template table-contained content should compare cleanly.' );
if ( false === ( $dom_template_table_context_result['tagProcessor']['normalize']['ok'] ?? true ) ) {
	html_api_fuzz_smoke_assert( false === ( $dom_template_table_context_result['ok'] ?? null ), 'DOM template table-contained content should surface normalize() failures after comparison.' );
	html_api_fuzz_smoke_assert( 'normalize-invariant-failed' === ( $dom_template_table_context_result['failureClass'] ?? null ), 'DOM template table-contained normalize() failures should be classified separately.' );
} else {
	html_api_fuzz_smoke_assert( true === ( $dom_template_table_context_result['ok'] ?? null ), 'DOM template fallback should compare table-contained content.' );
	html_api_fuzz_smoke_assert( 'passed' === ( $dom_template_table_context_result['status'] ?? null ), 'DOM template fallback should not quarantine table-contained content.' );
	html_api_fuzz_smoke_assert( null === ( $dom_template_table_context_result['signature'] ?? null ), 'DOM template table-contained content should not produce a fuzz signature.' );
}

$dom_template_foreign_context_dir = $tmp . '/dom-template-foreign-context';
$dom_template_foreign_context_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<template><svg><td></td></svg></template>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $dom_template_foreign_context_dir,
		'max-tokens'      => '200',
		'max-nodes'       => '200',
	)
);
html_api_fuzz_smoke_assert( true === ( $dom_template_foreign_context_result['comparison']['ok'] ?? null ), 'DOM template foreign-content overlap should compare cleanly.' );
if ( false === ( $dom_template_foreign_context_result['tagProcessor']['normalize']['ok'] ?? true ) ) {
	html_api_fuzz_smoke_assert( false === ( $dom_template_foreign_context_result['ok'] ?? null ), 'DOM template foreign-content overlap should surface normalize() failures after comparison.' );
	html_api_fuzz_smoke_assert( 'normalize-invariant-failed' === ( $dom_template_foreign_context_result['failureClass'] ?? null ), 'DOM template foreign-content normalize() failures should be classified separately.' );
} else {
	html_api_fuzz_smoke_assert( true === ( $dom_template_foreign_context_result['ok'] ?? null ), 'DOM template fallback should compare foreign-content tag names that overlap table names.' );
	html_api_fuzz_smoke_assert( 'passed' === ( $dom_template_foreign_context_result['status'] ?? null ), 'DOM template fallback should not quarantine foreign-content tag names that overlap table names.' );
	html_api_fuzz_smoke_assert( null === ( $dom_template_foreign_context_result['signature'] ?? null ), 'DOM template foreign-content overlap should not produce a fuzz signature.' );
}

$dom_oracle_needs_xlink_tolerance = html_api_fuzz_smoke_dom_drops_bare_xlink_local_name_after_xlink();
$dom_oracle_xlink_fragment_wp = \HtmlApiFuzz\TreeRenderer::render_wordpress(
	'<svg xlink:href href></svg>',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	array( 'maxTokens' => 100, 'maxNodes' => 100 )
);
$dom_oracle_xlink_fragment_dom = \HtmlApiFuzz\TreeRenderer::render_dom(
	'<svg xlink:href href></svg>',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	array( 'maxTokens' => 100, 'maxNodes' => 100 )
);
html_api_fuzz_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_oracle_xlink_fragment_wp['status'] ?? null ), 'DOM XLink fragment fixture should render with WordPress.' );
html_api_fuzz_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_oracle_xlink_fragment_dom['status'] ?? null ), 'DOM XLink fragment fixture should render with the DOM oracle.' );
$dom_oracle_xlink_fragment_comparison = \HtmlApiFuzz\TreeRenderer::compare_trees(
	$dom_oracle_xlink_fragment_wp['tree'],
	$dom_oracle_xlink_fragment_dom['tree'],
	$dom_oracle_xlink_fragment_wp['domOracleLineTolerances'] ?? array()
);
html_api_fuzz_smoke_assert( true === ( $dom_oracle_xlink_fragment_comparison['ok'] ?? null ), 'DOM XLink oracle limitation should compare after fragment tolerance.' );
if ( $dom_oracle_needs_xlink_tolerance ) {
	html_api_fuzz_smoke_assert( array( 1 ) === ( $dom_oracle_xlink_fragment_wp['domOracleLineTolerances'] ?? null ), 'DOM XLink fragment tolerance should identify only the dropped WordPress attribute line.' );
} else {
	html_api_fuzz_smoke_assert( array() === ( $dom_oracle_xlink_fragment_wp['domOracleLineTolerances'] ?? null ), 'Fixed DOM runtimes should not record XLink oracle tolerance lines.' );
}

$dom_oracle_xlink_worker_dir = $tmp . '/dom-oracle-xlink-worker';
$dom_oracle_xlink_worker_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<svg xlink:href href></svg>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $dom_oracle_xlink_worker_dir,
		'max-tokens'      => '100',
		'max-nodes'       => '100',
	)
);
html_api_fuzz_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_oracle_xlink_worker_result['dom']['status'] ?? null ), 'Worker should still render the DOM oracle when normalize() fails independently.' );
html_api_fuzz_smoke_assert( true === ( $dom_oracle_xlink_worker_result['comparison']['ok'] ?? null ), 'Worker should still compare DOM trees when normalize() fails independently.' );
if ( false === ( $normalize_not_idempotent['normalize']['ok'] ?? true ) ) {
	html_api_fuzz_smoke_assert( false === ( $dom_oracle_xlink_worker_result['ok'] ?? null ), 'Worker should fail when normalize() is non-idempotent.' );
	html_api_fuzz_smoke_assert( 'normalize-invariant-failed' === ( $dom_oracle_xlink_worker_result['failureClass'] ?? null ), 'Worker should classify normalize() idempotence failures separately.' );
	html_api_fuzz_smoke_assert( 'normalize-not-idempotent' === ( $dom_oracle_xlink_worker_result['signature']['facts']['invariant'] ?? null ), 'Normalize failure signature should record the concrete invariant.' );
	html_api_fuzz_smoke_assert( ( $normalize_not_idempotent['normalize']['normalizedSha1'] ?? null ) === ( $dom_oracle_xlink_worker_result['signature']['facts']['normalizedSha1'] ?? null ), 'Normalize failure signature should include the normalized hash.' );
	html_api_fuzz_smoke_assert( is_int( $dom_oracle_xlink_worker_result['signature']['facts']['firstByteOffset'] ?? null ), 'Normalize failure signature should include first-difference metadata.' );
} else {
	html_api_fuzz_smoke_assert( true === ( $dom_oracle_xlink_worker_result['ok'] ?? null ), 'Worker should pass the XLink fixture once normalize() is idempotent.' );
	html_api_fuzz_smoke_assert( ( $dom_oracle_needs_xlink_tolerance ? 'oracle-tolerated' : 'passed' ) === ( $dom_oracle_xlink_worker_result['status'] ?? null ), 'Worker should keep normal DOM oracle status once normalize() is idempotent.' );
}

$dom_oracle_xlink_full_document_wp = \HtmlApiFuzz\TreeRenderer::render_wordpress(
	'<svg xlink:href href></svg>',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
	array( 'maxTokens' => 100, 'maxNodes' => 100 )
);
$dom_oracle_xlink_full_document_dom = \HtmlApiFuzz\TreeRenderer::render_dom(
	'<svg xlink:href href></svg>',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
	array( 'maxTokens' => 100, 'maxNodes' => 100 )
);
html_api_fuzz_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_oracle_xlink_full_document_wp['status'] ?? null ), 'DOM XLink full-document fixture should render with WordPress.' );
html_api_fuzz_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_oracle_xlink_full_document_dom['status'] ?? null ), 'DOM XLink full-document fixture should render with the DOM oracle.' );
$dom_oracle_xlink_full_document_comparison = \HtmlApiFuzz\TreeRenderer::compare_trees(
	$dom_oracle_xlink_full_document_wp['tree'],
	$dom_oracle_xlink_full_document_dom['tree'],
	$dom_oracle_xlink_full_document_wp['domOracleLineTolerances'] ?? array()
);
html_api_fuzz_smoke_assert( true === ( $dom_oracle_xlink_full_document_comparison['ok'] ?? null ), 'DOM XLink oracle limitation should compare after full-document tolerance.' );
html_api_fuzz_smoke_assert( $dom_oracle_needs_xlink_tolerance ? array( 4 ) === ( $dom_oracle_xlink_full_document_wp['domOracleLineTolerances'] ?? null ) : array() === ( $dom_oracle_xlink_full_document_wp['domOracleLineTolerances'] ?? null ), 'DOM XLink full-document fixture should record tolerance lines only while the runtime needs them.' );

$dom_oracle_xlink_minimized_input = base64_decode( 'Pjx3cC14PjxiIHNyYz0i16oiPjxzdHJvbmcgZDw8PDw8PCI+YWFhYWFhYc6yPHN2ZyBwWDgxRzY4QndxPSJudWtyUSBhbXA7IiB4bGluazpocmVmIGhyZWYgdml0bGU+Ri1qOA==', true );
html_api_fuzz_smoke_assert( false !== $dom_oracle_xlink_minimized_input, 'DOM XLink minimized fixture should decode.' );
$dom_oracle_xlink_minimized_wp = \HtmlApiFuzz\TreeRenderer::render_wordpress(
	$dom_oracle_xlink_minimized_input,
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
	array( 'maxTokens' => 2000, 'maxNodes' => 3000 )
);
$dom_oracle_xlink_minimized_dom = \HtmlApiFuzz\TreeRenderer::render_dom(
	$dom_oracle_xlink_minimized_input,
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
	array( 'maxTokens' => 2000, 'maxNodes' => 3000 )
);
html_api_fuzz_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_oracle_xlink_minimized_wp['status'] ?? null ), 'DOM XLink minimized fixture should render with WordPress.' );
html_api_fuzz_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_oracle_xlink_minimized_dom['status'] ?? null ), 'DOM XLink minimized fixture should render with the DOM oracle.' );
$dom_oracle_xlink_minimized_comparison = \HtmlApiFuzz\TreeRenderer::compare_trees(
	$dom_oracle_xlink_minimized_wp['tree'],
	$dom_oracle_xlink_minimized_dom['tree'],
	$dom_oracle_xlink_minimized_wp['domOracleLineTolerances'] ?? array()
);
html_api_fuzz_smoke_assert( true === ( $dom_oracle_xlink_minimized_comparison['ok'] ?? null ), 'DOM XLink minimized fixture comparison should pass.' );
html_api_fuzz_smoke_assert( $dom_oracle_needs_xlink_tolerance ? 1 === count( $dom_oracle_xlink_minimized_wp['domOracleLineTolerances'] ?? array() ) : array() === ( $dom_oracle_xlink_minimized_wp['domOracleLineTolerances'] ?? null ), 'DOM XLink minimized fixture should record tolerance lines only while the runtime needs them.' );

$dom_oracle_xlink_inverse_wp = \HtmlApiFuzz\TreeRenderer::render_wordpress(
	'<svg href xlink:href></svg>',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	array( 'maxTokens' => 100, 'maxNodes' => 100 )
);
$dom_oracle_xlink_inverse_dom = \HtmlApiFuzz\TreeRenderer::render_dom(
	'<svg href xlink:href></svg>',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	array( 'maxTokens' => 100, 'maxNodes' => 100 )
);
html_api_fuzz_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_oracle_xlink_inverse_wp['status'] ?? null ), 'Bare attribute before XLink should render with WordPress.' );
html_api_fuzz_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_oracle_xlink_inverse_dom['status'] ?? null ), 'Bare attribute before XLink should render with the DOM oracle.' );
$dom_oracle_xlink_inverse_comparison = \HtmlApiFuzz\TreeRenderer::compare_trees(
	$dom_oracle_xlink_inverse_wp['tree'],
	$dom_oracle_xlink_inverse_dom['tree'],
	$dom_oracle_xlink_inverse_wp['domOracleLineTolerances'] ?? array()
);
html_api_fuzz_smoke_assert( true === ( $dom_oracle_xlink_inverse_comparison['ok'] ?? null ), 'Bare attribute before XLink should remain comparable.' );
html_api_fuzz_smoke_assert( array() === ( $dom_oracle_xlink_inverse_wp['domOracleLineTolerances'] ?? null ), 'Bare attribute before XLink should not record DOM oracle tolerance lines.' );

$dom_oracle_xlink_resource_dom = \HtmlApiFuzz\TreeRenderer::render_dom(
	'<svg xlink:href href><pass>x</pass></svg>',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	array( 'maxTokens' => 100, 'maxNodes' => 1 )
);
html_api_fuzz_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_ERROR === ( $dom_oracle_xlink_resource_dom['status'] ?? null ), 'DOM XLink node ceiling should fail the DOM renderer.' );
html_api_fuzz_smoke_assert( 'node-limit-exceeded' === ( $dom_oracle_xlink_resource_dom['failureClass'] ?? null ), 'DOM XLink oracle tolerance should preserve the concrete DOM node limit failure.' );

$wp_resource_dir = $tmp . '/wordpress-resource-limit';
$wp_resource_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<p>x</p>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
		'payload-policy'  => 'ascii-structural',
		'output-dir'      => $wp_resource_dir,
		'max-tokens'      => '3',
		'max-nodes'       => '100',
	)
);
html_api_fuzz_smoke_assert( 'resource-limit' === ( $wp_resource_result['failureClass'] ?? null ), 'WordPress tree token ceilings should be bucketed as resource-limit.' );
html_api_fuzz_smoke_assert( 'resource-limit' === ( $wp_resource_result['status'] ?? null ), 'WordPress tree token ceilings should use resource-limit status.' );
html_api_fuzz_smoke_assert( 'token-limit-exceeded' === ( $wp_resource_result['wordpress']['failureClass'] ?? null ), 'WordPress result should preserve the concrete token limit failure.' );
html_api_fuzz_smoke_assert( in_array( 'wordpress-token-limit-exceeded', $wp_resource_result['signature']['facts']['limitFailures'] ?? array(), true ), 'resource-limit signature should include WordPress token limit failures.' );

$resource_watcher_run_dir = $tmp . '/resource-watcher-run';
\HtmlApiFuzz\ensure_dir( $resource_watcher_run_dir );
\HtmlApiFuzz\append_ndjson(
	$resource_watcher_run_dir . '/summary.ndjson',
	array(
		'ok'            => false,
		'status'        => $resource_result['status'] ?? null,
		'failureClass'  => $resource_result['failureClass'] ?? null,
		'profile'       => $resource_result['profile'] ?? null,
		'mode'          => $resource_result['mode'] ?? null,
		'payloadPolicy' => $resource_result['payloadPolicy'] ?? null,
		'generator'     => $resource_result['generator'] ?? null,
		'inputSource'   => $resource_result['inputSource'] ?? null,
		'inputSha1'     => $resource_result['inputSha1'] ?? null,
		'inputLength'   => $resource_result['inputLength'] ?? null,
		'signature'     => $resource_result['signature'] ?? null,
		'resultPath'    => $resource_dir . '/result.json',
		'replayPath'    => $resource_dir . '/replay.json',
	)
);
$resource_watcher_state_dir = $tmp . '/resource-watcher-state';
$resource_watcher_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/watcher.php',
		'--run-dir',
		$resource_watcher_run_dir,
		'--state-dir',
		$resource_watcher_state_dir,
		'--once',
		'--no-minimize',
		'--max-minimize',
		'1',
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/resource-watcher.log'
);
html_api_fuzz_smoke_assert( ! $resource_watcher_proc['timedOut'] && 0 === $resource_watcher_proc['code'], 'watcher should process resource-limit summaries.' );
$resource_watcher_state = \HtmlApiFuzz\read_json_file( $resource_watcher_state_dir . '/state.json' );
$resource_watcher_hash = $resource_result['signature']['hash'] ?? null;
$resource_watcher_record = is_string( $resource_watcher_hash ) ? ( $resource_watcher_state['signatures'][ $resource_watcher_hash ] ?? array() ) : array();
html_api_fuzz_smoke_assert( 'queued' === ( $resource_watcher_record['status'] ?? null ), 'watcher should queue resource-limit signatures for minimization.' );
html_api_fuzz_smoke_assert( ! isset( $resource_watcher_record['minimizeResult'] ), 'watcher --no-minimize should not start resource-limit minimization.' );
$resource_watcher_second_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/watcher.php',
		'--run-dir',
		$resource_watcher_run_dir,
		'--state-dir',
		$resource_watcher_state_dir,
		'--once',
		'--no-minimize',
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$tmp . '/resource-watcher-second.log'
);
$resource_watcher_second = json_decode( $resource_watcher_second_proc['stdout'], true );
html_api_fuzz_smoke_assert( ! $resource_watcher_second_proc['timedOut'] && 0 === $resource_watcher_second_proc['code'], 'watcher should process a second scan.' );
html_api_fuzz_smoke_assert( 0 === ( $resource_watcher_second['failuresSeen'] ?? null ), 'watcher should not reread already-scanned summary records.' );

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
$encoding_diff = $encoding_result['comparison']['firstDifference'] ?? array();
html_api_fuzz_smoke_assert( ( $encoding_diff['firstByteOffset'] ?? 0 ) > 160, 'long encoding mismatch should exercise an offset beyond the leading hex preview.' );
html_api_fuzz_smoke_assert( ( $encoding_diff['wordpressHex'] ?? null ) === ( $encoding_diff['domHex'] ?? null ), 'long encoding mismatch should show why leading hex previews alone are insufficient.' );
html_api_fuzz_smoke_assert( false !== strpos( $encoding_diff['wordpressDiffHex'] ?? '', 'c0' ), 'long encoding mismatch should include the differing WordPress byte in the diff window.' );
html_api_fuzz_smoke_assert( false !== strpos( $encoding_diff['domDiffHex'] ?? '', 'efbfbd' ), 'long encoding mismatch should include the differing DOM replacement bytes in the diff window.' );

$structural_with_invalid_dir = $tmp . '/structural-with-invalid';
$structural_with_invalid_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<select><track e><!-->' . "\xC0" ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'invalid-byte-heavy',
		'output-dir'      => $structural_with_invalid_dir,
		'max-tokens'      => '2000',
		'max-nodes'       => '3000',
	)
);
html_api_fuzz_smoke_assert( 'tree-mismatch' === ( $structural_with_invalid_result['failureClass'] ?? null ), 'invalid bytes elsewhere should not relabel structural tree mismatches as encoding-mismatch.' );

$newline_scalar_cases = array(
	'text'    => array(
		'input' => '<p>a' . "\n" . 'b' . "\xC0" . '</p>',
		'path'  => '/p/#text',
	),
	'attr'    => array(
		'input' => '<p title="a' . "\n" . 'b' . "\xC0" . '"></p>',
		'path'  => '/p/@title',
	),
	'comment' => array(
		'input' => '<p><!-- a' . "\n" . 'b' . "\xC0" . ' --></p>',
		'path'  => '/p/#text',
	),
);
foreach ( $newline_scalar_cases as $name => $case ) {
	$newline_scalar_dir = $tmp . '/newline-scalar-' . $name;
	$newline_scalar_result = \HtmlApiFuzz\Worker::run(
		array(
			'input-base64'    => base64_encode( $case['input'] ),
			'profile'         => 'replay',
			'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
			'payload-policy'  => 'invalid-byte-heavy',
			'output-dir'      => $newline_scalar_dir,
			'max-tokens'      => '2000',
			'max-nodes'       => '3000',
		)
	);
	$newline_scalar_diff = $newline_scalar_result['comparison']['firstDifference'] ?? array();
	html_api_fuzz_smoke_assert( 'encoding-mismatch' === ( $newline_scalar_result['failureClass'] ?? null ), $name . ' newline scalar invalid byte should be classified as encoding-mismatch.' );
	html_api_fuzz_smoke_assert( $case['path'] === ( $newline_scalar_diff['path'] ?? null ), $name . ' newline scalar diff should preserve the logical tree path.' );
	html_api_fuzz_smoke_assert( ! array_key_exists( 'wordpressLine', $newline_scalar_diff ), $name . ' newline scalar diff should not persist the full WordPress line.' );
	html_api_fuzz_smoke_assert( ! array_key_exists( 'domLine', $newline_scalar_diff ), $name . ' newline scalar diff should not persist the full DOM line.' );
	html_api_fuzz_smoke_assert( isset( $newline_scalar_diff['wordpressLinePreview'], $newline_scalar_diff['domLinePreview'] ), $name . ' newline scalar diff should persist bounded previews.' );
	html_api_fuzz_smoke_assert( isset( $newline_scalar_diff['wordpressLineBytes'], $newline_scalar_diff['domLineBytes'] ), $name . ' newline scalar diff should persist line byte lengths.' );
	html_api_fuzz_smoke_assert( isset( $newline_scalar_diff['wordpressLineSha1'], $newline_scalar_diff['domLineSha1'] ), $name . ' newline scalar diff should persist line hashes.' );
	html_api_fuzz_smoke_assert( isset( $newline_scalar_diff['firstByteOffset'] ), $name . ' newline scalar diff should persist first differing byte offset.' );
}

$template_content_dir = $tmp . '/template-content-path';
$template_content_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<template><p>a' . "\xC0" . '</p></template>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'invalid-byte-heavy',
		'output-dir'      => $template_content_dir,
		'max-tokens'      => '2000',
		'max-nodes'       => '3000',
	)
);
$template_content_diff = $template_content_result['comparison']['firstDifference'] ?? array();
html_api_fuzz_smoke_assert( 'encoding-mismatch' === ( $template_content_result['failureClass'] ?? null ), 'template content invalid byte should be classified as encoding-mismatch.' );
html_api_fuzz_smoke_assert( '/template/content/p/#text' === ( $template_content_diff['path'] ?? null ), 'template content descendants should include the content pseudo-node in diff paths.' );

$quoted_attribute_dir = $tmp . '/quoted-attribute-name';
$quoted_attribute_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'    => base64_encode( '<p a"b="x' . "\xC0" . '"></p>' ),
		'profile'         => 'replay',
		'mode'            => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'payload-policy'  => 'invalid-byte-heavy',
		'output-dir'      => $quoted_attribute_dir,
		'max-tokens'      => '2000',
		'max-nodes'       => '3000',
	)
);
$quoted_attribute_diff = $quoted_attribute_result['comparison']['firstDifference'] ?? array();
html_api_fuzz_smoke_assert( 'encoding-mismatch' === ( $quoted_attribute_result['failureClass'] ?? null ), 'quoted attribute-name invalid byte should be classified as encoding-mismatch.' );
html_api_fuzz_smoke_assert( '/p/@a\\"b' === ( $quoted_attribute_diff['path'] ?? null ), 'quoted attribute-name diff should preserve the attribute path.' );
html_api_fuzz_smoke_assert( 'a\\"b="<value>"' === ( $quoted_attribute_diff['wordpressNorm'] ?? null ), 'quoted attribute-name normalization should preserve the WordPress attribute name.' );
html_api_fuzz_smoke_assert( 'a\\"b="<value>"' === ( $quoted_attribute_diff['domNorm'] ?? null ), 'quoted attribute-name normalization should preserve the DOM attribute name.' );

echo "OK\n";
