#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_lexbor_smoke_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function html_api_fuzz_lexbor_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_lexbor_smoke_fail( $message );
	}
}

$binary = \HtmlApiFuzz\repo_root() . '/tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle';
if ( ! is_file( $binary ) || ! is_executable( $binary ) ) {
	echo "SKIP lexbor-oracle-smoke: build tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle first\n";
	exit( 0 );
}

$oracle = \HtmlApiFuzz\OracleRenderer::from_options(
	array(
		'dom-oracle'       => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		'lexbor-oracle-bin' => $binary,
	)
);
$metadata = $oracle->metadata();
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE === ( $metadata['kind'] ?? null ), 'Expected Lexbor source oracle metadata.' );
html_api_fuzz_lexbor_smoke_assert( is_string( $metadata['lexborCommit'] ?? null ) && 1 === preg_match( '/^[0-9a-f]{40}$/', $metadata['lexborCommit'] ), 'Expected the resolved Lexbor commit in oracle metadata.' );
html_api_fuzz_lexbor_smoke_assert( is_string( $metadata['binarySha256'] ?? null ) && 64 === strlen( $metadata['binarySha256'] ), 'Expected the oracle binary SHA-256.' );
html_api_fuzz_lexbor_smoke_assert( ( $metadata['lexborCommit'] ?? null ) === ( $metadata['buildManifest']['resolvedCommit'] ?? null ), 'Expected build manifest and binary commit agreement.' );
html_api_fuzz_lexbor_smoke_assert( ( $metadata['binarySha256'] ?? null ) === ( $metadata['buildManifest']['binarySha256'] ?? null ), 'Expected build manifest and binary hash agreement.' );

$limits = array(
	'maxTokens'    => 200,
	'maxNodes'     => 200,
	'maxDepth'     => 50,
	'maxTreeBytes' => 100000,
);

$work_dir = sys_get_temp_dir() . '/html-api-fuzz-lexbor-oracle-' . \HtmlApiFuzz\timestamp();
\HtmlApiFuzz\ensure_dir( $work_dir );

$empty_fragment = $oracle->render( '', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $empty_fragment['status'] ?? null ), 'Expected Lexbor to parse an empty fragment.' );
html_api_fuzz_lexbor_smoke_assert( "\n" === ( $empty_fragment['tree'] ?? null ), 'Expected Lexbor empty fragment rendering to match the fuzzer tree newline contract.' );

$deep_limits = $limits;
$deep_limits['maxDepth'] = 3;
$deep_html = str_repeat( '<div>', 12 );
$lexbor_deep = $oracle->render( $deep_html, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $deep_limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( 'depth-limit-exceeded' === ( $lexbor_deep['failureClass'] ?? null ), 'Expected Lexbor depth limiting.' );
$wordpress_deep = \HtmlApiFuzz\TreeRenderer::render_wordpress( $deep_html, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $deep_limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( 'depth-limit-exceeded' === ( $wordpress_deep['failureClass'] ?? null ), 'Expected WordPress tree depth limiting.' );

$byte_limits = $limits;
$byte_limits['maxTreeBytes'] = 20;
$lexbor_bytes = $oracle->render( '<p>tree output must exceed twenty bytes</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $byte_limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( 'tree-byte-limit-exceeded' === ( $lexbor_bytes['failureClass'] ?? null ), 'Expected Lexbor tree byte limiting.' );
$wordpress_bytes = \HtmlApiFuzz\TreeRenderer::render_wordpress( '<p>tree output must exceed twenty bytes</p>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $byte_limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( 'tree-byte-limit-exceeded' === ( $wordpress_bytes['failureClass'] ?? null ), 'Expected WordPress tree byte limiting.' );

$empty_worker_result = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'      => base64_encode( '' ),
		'mode'              => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'profile'           => 'replay',
		'seed'              => '100',
		'output-dir'        => $work_dir . '/empty-fragment',
		'max-tokens'        => '200',
		'max-nodes'         => '200',
		'dom-oracle'        => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		'lexbor-oracle-bin' => $binary,
	)
);
html_api_fuzz_lexbor_smoke_assert( true === ( $empty_worker_result['ok'] ?? null ), 'Expected empty fragment Worker run to pass against the Lexbor source oracle.' );

$processing_instruction = '<?wp data?><p>x';
$rendered_processing_instruction = $oracle->render( $processing_instruction, \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $rendered_processing_instruction['status'] ?? null ), 'Expected Lexbor to parse a processing instruction.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_processing_instruction['tree'] ?? '', "<?wp data?>\n" ), 'Expected Lexbor to render the processing-instruction target and data.' );
$processing_instruction_worker = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'      => base64_encode( $processing_instruction ),
		'mode'              => \HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
		'profile'           => 'replay',
		'seed'              => '101',
		'output-dir'        => $work_dir . '/processing-instruction',
		'max-tokens'        => '200',
		'max-nodes'         => '200',
		'dom-oracle'        => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		'lexbor-oracle-bin' => $binary,
	)
);
html_api_fuzz_lexbor_smoke_assert( true === ( $processing_instruction_worker['ok'] ?? null ), 'Expected a processing instruction to pass the WordPress/Lexbor differential.' );

$escaped_tag_name = $oracle->render( '<a"b></a"b>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $escaped_tag_name['status'] ?? null ), 'Expected Lexbor to parse a quoted tag-name fixture.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $escaped_tag_name['tree'] ?? '', "<a\\\"b>\n" ), 'Expected Lexbor to escape odd tag-name bytes in tree output.' );
$dom_escaped_tag_name = \HtmlApiFuzz\TreeRenderer::render_dom( '<a"b></a"b>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_escaped_tag_name['status'] ?? null ), 'Expected PHP DOM oracle to parse the quoted tag-name fixture.' );
html_api_fuzz_lexbor_smoke_assert( $dom_escaped_tag_name['tree'] === $escaped_tag_name['tree'], 'Expected Lexbor quoted tag-name tree to match PHP DOM escaping.' );

$adjusted_svg_names = '<svg><foreignobject><div></div></foreignobject><altglyph attributename=x attributetype=XML></altglyph><lineargradient gradientunits=userSpaceOnUse></lineargradient></svg>';
$rendered_adjusted_svg_names = $oracle->render( $adjusted_svg_names, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $rendered_adjusted_svg_names['status'] ?? null ), 'Expected Lexbor to parse adjusted SVG names fixture.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_adjusted_svg_names['tree'] ?? '', "<svg foreignObject>\n" ), 'Expected Lexbor to render adjusted SVG foreignObject casing.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_adjusted_svg_names['tree'] ?? '', "<svg altGlyph>\n" ), 'Expected Lexbor to render adjusted SVG altGlyph casing.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_adjusted_svg_names['tree'] ?? '', "<svg linearGradient>\n" ), 'Expected Lexbor to render adjusted SVG linearGradient casing.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_adjusted_svg_names['tree'] ?? '', "attributeName=\"x\"" ), 'Expected Lexbor to render adjusted SVG attributeName casing.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_adjusted_svg_names['tree'] ?? '', "attributeType=\"XML\"" ), 'Expected Lexbor to render adjusted SVG attributeType casing.' );
$dom_adjusted_svg_names = \HtmlApiFuzz\TreeRenderer::render_dom( $adjusted_svg_names, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $dom_adjusted_svg_names['status'] ?? null ), 'Expected PHP DOM oracle to parse adjusted SVG names fixture.' );
html_api_fuzz_lexbor_smoke_assert( $dom_adjusted_svg_names['tree'] === $rendered_adjusted_svg_names['tree'], 'Expected Lexbor adjusted SVG names tree to match PHP DOM.' );

$issue_372 = '<svg xlink:href=qual href=plain></svg>';
$rendered_372 = $oracle->render( $issue_372, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $rendered_372['status'] ?? null ), 'Expected Lexbor to parse issue 372 fixture.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_372['tree'] ?? '', "href=\"plain\"" ), 'Expected Lexbor to keep the bare href attribute from issue 372.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_372['tree'] ?? '', "xlink href=\"qual\"" ), 'Expected Lexbor to keep the namespaced xlink:href attribute from issue 372.' );

$issue_373 = '<h2><math><mi>x</h2>k';
$rendered_373 = $oracle->render( $issue_373, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $rendered_373['status'] ?? null ), 'Expected Lexbor to parse issue 373 fixture.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_373['tree'] ?? '', "<math mi>\n      \"xk\"" ), 'Expected Lexbor to keep post-heading text in the MathML mi element for issue 373.' );

$worker_result_372 = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'      => base64_encode( $issue_372 ),
		'mode'              => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'profile'           => 'replay',
		'seed'              => '372',
		'output-dir'        => $work_dir . '/issue-372',
		'max-tokens'        => '200',
		'max-nodes'         => '200',
		'dom-oracle'        => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		'lexbor-oracle-bin' => $binary,
	)
);
html_api_fuzz_lexbor_smoke_assert( true === ( $worker_result_372['ok'] ?? null ), 'Expected issue 372 to pass Worker against the Lexbor source oracle.' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE === ( $worker_result_372['oracle']['kind'] ?? null ), 'Expected Worker result to record Lexbor source oracle kind.' );

$worker_replay_372 = \HtmlApiFuzz\read_json_file( $work_dir . '/issue-372/replay.json' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE === ( $worker_replay_372['options']['domOracle'] ?? null ), 'Expected replay options to preserve the Lexbor source oracle kind.' );
html_api_fuzz_lexbor_smoke_assert( $binary === ( $worker_replay_372['options']['lexborOracleBin'] ?? null ), 'Expected replay options to preserve the Lexbor source oracle binary.' );
html_api_fuzz_lexbor_smoke_assert( ( $metadata['lexborCommit'] ?? null ) === ( $worker_replay_372['oracle']['lexborCommit'] ?? null ), 'Expected replay metadata to preserve the Lexbor source commit.' );

$replay_dir = $work_dir . '/issue-372-replay';
$proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay',
		$work_dir . '/issue-372/replay.json',
		'--output-dir',
		$replay_dir,
	),
	\HtmlApiFuzz\repo_root(),
	10000,
	$work_dir . '/replay.log'
);
html_api_fuzz_lexbor_smoke_assert( 0 === $proc['code'], 'Expected replay to pass while preserving the Lexbor source oracle.' );
$replayed = \HtmlApiFuzz\read_json_file( $replay_dir . '/result.json' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE === ( $replayed['oracle']['kind'] ?? null ), 'Expected replayed result to use the Lexbor source oracle.' );

$identity_mismatch_replays = array();
$hash_mismatch_replay = $worker_replay_372;
$hash_mismatch_replay['oracle']['binarySha256'] = str_repeat( '0', 64 );
$identity_mismatch_replays['binary-hash'] = $hash_mismatch_replay;
$commit_mismatch_replay = $worker_replay_372;
$commit_mismatch_replay['oracle']['lexborCommit'] = str_repeat( '0', 40 );
$identity_mismatch_replays['lexbor-commit'] = $commit_mismatch_replay;
$kind_mismatch_replay = $worker_replay_372;
$kind_mismatch_replay['oracle']['kind'] = \HtmlApiFuzz\OracleRenderer::KIND_PHP_DOM;
$identity_mismatch_replays['oracle-kind'] = $kind_mismatch_replay;
foreach ( $identity_mismatch_replays as $mismatch_name => $mismatch_replay ) {
	$mismatch_path = $work_dir . '/mismatch-' . $mismatch_name . '.json';
	$mismatch_output = $work_dir . '/mismatch-' . $mismatch_name;
	\HtmlApiFuzz\write_json_file_atomic( $mismatch_path, $mismatch_replay );
	$mismatch_proc = \HtmlApiFuzz\run_php_process(
		array(
			dirname( __DIR__ ) . '/replay.php',
			'--replay', $mismatch_path,
			'--output-dir', $mismatch_output,
		),
		\HtmlApiFuzz\repo_root(),
		10000
	);
	html_api_fuzz_lexbor_smoke_assert( 1 === $mismatch_proc['code'], "Expected {$mismatch_name} mismatch to reject replay." );
	html_api_fuzz_lexbor_smoke_assert( ! is_dir( $mismatch_output ), "Expected {$mismatch_name} rejection before output creation." );
}

$kind_change_source = $worker_replay_372;
$kind_change_source['options']['oracleTimeoutMs'] = 1234;
$kind_change_path = $work_dir . '/kind-change-source.json';
$kind_change_dir  = $work_dir . '/kind-change';
\HtmlApiFuzz\write_json_file_atomic( $kind_change_path, $kind_change_source );
$kind_change_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay', $kind_change_path,
		'--output-dir', $kind_change_dir,
		'--dom-oracle', \HtmlApiFuzz\OracleRenderer::KIND_PHP_DOM,
		'--oracle-timeout-ms', '2500',
		'--allow-oracle-mismatch',
	),
	\HtmlApiFuzz\repo_root(),
	10000
);
html_api_fuzz_lexbor_smoke_assert( 0 === $kind_change_proc['code'], 'Expected an explicitly allowed Lexbor-to-PHP-DOM diagnostic replay.' );
$kind_change_replay = \HtmlApiFuzz\read_json_file( $kind_change_dir . '/replay.json' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\OracleRenderer::KIND_PHP_DOM === ( $kind_change_replay['oracle']['kind'] ?? null ), 'Expected allowed mismatch output to record current oracle metadata.' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\OracleRenderer::KIND_PHP_DOM === ( $kind_change_replay['options']['domOracle'] ?? null ), 'Expected allowed mismatch output to record current oracle selection.' );
html_api_fuzz_lexbor_smoke_assert( ! array_key_exists( 'lexborOracleBin', $kind_change_replay['options'] ), 'Expected allowed kind change to remove stale Lexbor binary option.' );
html_api_fuzz_lexbor_smoke_assert( ! array_key_exists( 'oracleTimeoutMs', $kind_change_replay['options'] ), 'Expected allowed kind change to remove stale oracle timeout option.' );
html_api_fuzz_lexbor_smoke_assert( ( $metadata['lexborCommit'] ?? null ) === ( $kind_change_replay['sourceReplay']['oracle']['lexborCommit'] ?? null ), 'Expected allowed mismatch to preserve source oracle identity.' );

$kind_change_again_dir = $work_dir . '/kind-change-again';
$kind_change_again_proc = \HtmlApiFuzz\run_php_process(
	array(
		dirname( __DIR__ ) . '/replay.php',
		'--replay', $kind_change_dir . '/replay.json',
		'--output-dir', $kind_change_again_dir,
	),
	\HtmlApiFuzz\repo_root(),
	10000
);
html_api_fuzz_lexbor_smoke_assert( 0 === $kind_change_again_proc['code'], 'Expected replay-of-replay to use the newly recorded PHP DOM oracle without an override.' );
$kind_change_again = \HtmlApiFuzz\read_json_file( $kind_change_again_dir . '/result.json' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\OracleRenderer::KIND_PHP_DOM === ( $kind_change_again['oracle']['kind'] ?? null ), 'Expected replay-of-replay result to use PHP DOM.' );

$worker_result_373 = \HtmlApiFuzz\Worker::run(
	array(
		'input-base64'      => base64_encode( $issue_373 ),
		'mode'              => \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
		'profile'           => 'replay',
		'seed'              => '373',
		'output-dir'        => $work_dir . '/issue-373',
		'max-tokens'        => '200',
		'max-nodes'         => '200',
		'dom-oracle'        => \HtmlApiFuzz\OracleRenderer::KIND_LEXBOR_SOURCE,
		'lexbor-oracle-bin' => $binary,
	)
);
html_api_fuzz_lexbor_smoke_assert( true === ( $worker_result_373['ok'] ?? null ), 'Expected issue 373 to pass Worker against the Lexbor source oracle.' );

\HtmlApiFuzz\remove_dir_recursive( $work_dir );
html_api_fuzz_lexbor_smoke_assert( ! is_dir( $work_dir ), 'Expected smoke work directory cleanup.' );

echo "OK lexbor-oracle-smoke\n";
