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
html_api_fuzz_lexbor_smoke_assert( true === ( $metadata['available'] ?? null ), 'Expected the Lexbor source oracle to report available=true.' );
html_api_fuzz_lexbor_smoke_assert( is_string( $metadata['lexborCommit'] ?? null ) && 1 === preg_match( '/^[0-9a-f]{40}$/', $metadata['lexborCommit'] ), 'Expected the resolved Lexbor commit in oracle metadata.' );
$pinned_commit = trim( file_get_contents( \HtmlApiFuzz\repo_root() . '/tools/html-api-fuzz/oracles/lexbor/COMMIT' ) );
html_api_fuzz_lexbor_smoke_assert( $pinned_commit === ( $metadata['lexborCommit'] ?? null ), 'Expected the built Lexbor commit to match the tracked pin.' );

$limits = array(
	'maxTokens' => 200,
	'maxNodes'  => 200,
);

$node_limited = $oracle->render( '<b>x</b>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, array( 'maxNodes' => 1 ), 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_ERROR === ( $node_limited['status'] ?? null ), 'Expected a Lexbor node limit to be a semantic error.' );
html_api_fuzz_lexbor_smoke_assert( 'node-limit-exceeded' === ( $node_limited['failureClass'] ?? null ), 'Expected the Lexbor node limit failure class.' );
html_api_fuzz_lexbor_smoke_assert( 0 === ( $node_limited['process']['code'] ?? null ), 'Expected the Lexbor semantic error process to exit successfully.' );

$invalid_utf8 = $oracle->render( "<p>\xC0</p>", \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $invalid_utf8['status'] ?? null ), 'Expected Lexbor to return an invalid-UTF-8 tree through treeBase64.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $invalid_utf8['tree'] ?? '', "\xC0" ), 'Expected Lexbor treeBase64 to preserve the invalid byte.' );

$work_dir = sys_get_temp_dir() . '/html-api-fuzz-lexbor-oracle-' . \HtmlApiFuzz\timestamp();
\HtmlApiFuzz\ensure_dir( $work_dir );

$empty_fragment = $oracle->render( '', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $empty_fragment['status'] ?? null ), 'Expected Lexbor to parse an empty fragment.' );
html_api_fuzz_lexbor_smoke_assert( "\n" === ( $empty_fragment['tree'] ?? null ), 'Expected Lexbor empty fragment rendering to match the fuzzer tree newline contract.' );

$noscript_document = $oracle->render(
	'<!doctype html><html><head><noscript><meta name=x></noscript></head><body><noscript><b>y</b></noscript></body></html>',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT,
	$limits,
	'body'
);
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $noscript_document['status'] ?? null ), 'Expected Lexbor to parse a noscript document.' );
html_api_fuzz_lexbor_smoke_assert(
	false !== strpos( $noscript_document['tree'] ?? '', "    <noscript>\n      <meta>\n        name=\"x\"\n  <body>\n    <noscript>\n      <b>\n        \"y\"\n" ),
	'Expected Lexbor document parsing to use scripting-disabled tree construction.'
);

$noscript_fragment = $oracle->render( '<noscript><b>x</b></noscript>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( "<noscript>\n  <b>\n    \"x\"\n\n" === ( $noscript_fragment['tree'] ?? null ), 'Expected Lexbor fragment parsing to use scripting-disabled tree construction.' );

foreach ( array( 'body', 'div', 'p', 'td', 'tr', 'table', 'caption', 'colgroup', 'select', 'option', 'template', 'title', 'textarea', 'script', 'style', 'svg', 'math' ) as $context ) {
	$context_result = $oracle->render( 'x', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, $context );
	html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $context_result['status'] ?? null ), "Expected Lexbor to support the {$context} fragment context." );
}

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

$escaped_tag_name = $oracle->render( '<a"b></a"b>', \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $escaped_tag_name['status'] ?? null ), 'Expected Lexbor to parse a quoted tag-name fixture.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $escaped_tag_name['tree'] ?? '', "<a\\\"b>\n" ), 'Expected Lexbor to escape odd tag-name bytes in tree output.' );

$adjusted_svg_names = '<svg><foreignobject><div></div></foreignobject><altglyph attributename=x attributetype=XML></altglyph><lineargradient gradientunits=userSpaceOnUse></lineargradient></svg>';
$rendered_adjusted_svg_names = $oracle->render( $adjusted_svg_names, \HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY, $limits, 'body' );
html_api_fuzz_lexbor_smoke_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $rendered_adjusted_svg_names['status'] ?? null ), 'Expected Lexbor to parse adjusted SVG names fixture.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_adjusted_svg_names['tree'] ?? '', "<svg foreignObject>\n" ), 'Expected Lexbor to render adjusted SVG foreignObject casing.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_adjusted_svg_names['tree'] ?? '', "<svg altGlyph>\n" ), 'Expected Lexbor to render adjusted SVG altGlyph casing.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_adjusted_svg_names['tree'] ?? '', "<svg linearGradient>\n" ), 'Expected Lexbor to render adjusted SVG linearGradient casing.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_adjusted_svg_names['tree'] ?? '', "attributeName=\"x\"" ), 'Expected Lexbor to render adjusted SVG attributeName casing.' );
html_api_fuzz_lexbor_smoke_assert( false !== strpos( $rendered_adjusted_svg_names['tree'] ?? '', "attributeType=\"XML\"" ), 'Expected Lexbor to render adjusted SVG attributeType casing.' );

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
