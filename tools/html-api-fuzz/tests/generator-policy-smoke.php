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
html_api_fuzz_smoke_assert( '/p/@a"b' === ( $quoted_attribute_diff['path'] ?? null ), 'quoted attribute-name diff should preserve the attribute path.' );
html_api_fuzz_smoke_assert( 'a"b="<value>"' === ( $quoted_attribute_diff['wordpressNorm'] ?? null ), 'quoted attribute-name normalization should preserve the WordPress attribute name.' );
html_api_fuzz_smoke_assert( 'a"b="<value>"' === ( $quoted_attribute_diff['domNorm'] ?? null ), 'quoted attribute-name normalization should preserve the DOM attribute name.' );

echo "OK\n";
