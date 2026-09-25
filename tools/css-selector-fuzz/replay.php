#!/usr/bin/env php
<?php
/**
 * Replays a single fuzz case by seed, with a full human-readable dump.
 *
 * Generation is fully deterministic from the seed, so a seed from a
 * failures.ndjson record is all that's needed to reproduce a case.
 *
 * Usage:
 *   php tools/css-selector-fuzz/replay.php --seed 42 [--json] [--show-html]
 *   php tools/css-selector-fuzz/replay.php --selector '.foo > bar' [--html '<div>…</div>']
 */

require_once __DIR__ . '/lib/autoload.php';

use CssSelectorFuzz\Bootstrap;
use CssSelectorFuzz\Worker;
use function CssSelectorFuzz\json_encode_safe;
use function CssSelectorFuzz\option_bool;
use function CssSelectorFuzz\option_int;
use function CssSelectorFuzz\option_string;
use function CssSelectorFuzz\parse_cli_options;
use function CssSelectorFuzz\printable_bytes;

$options = parse_cli_options( $argv );

$probe_selector = option_string( $options, 'selector', null );
if ( null !== $probe_selector ) {
	// Quick probe mode: parse a selector and report what the API does with it.
	Bootstrap::load();

	$compound = \WP_CSS_Compound_Selector_List::from_selectors( $probe_selector );
	$complex  = \WP_CSS_Complex_Selector_List::from_selectors( $probe_selector );

	$report = array(
		'selector'      => printable_bytes( $probe_selector ),
		'compoundList'  => null === $compound ? null : \CssSelectorFuzz\AstExtractor::from_compound_list( $compound ),
		'complexList'   => null === $complex ? null : \CssSelectorFuzz\AstExtractor::from_complex_list( $complex ),
	);

	$html = option_string( $options, 'html', null );
	if ( null !== $html && null !== $complex ) {
		$processor = \WP_HTML_Processor::create_full_parser( $html );
		$matches   = array();
		while ( $processor->select( $probe_selector ) ) {
			$matches[] = array(
				'tag'         => $processor->get_tag(),
				'breadcrumbs' => $processor->get_breadcrumbs(),
			);
		}
		$report['htmlProcessorMatches'] = $matches;
	}

	echo json_encode_safe( $report ) . "\n";
	exit( 0 );
}

$seed = option_int( $options, 'seed', -1 );
if ( $seed < 0 ) {
	echo "Usage: php tools/css-selector-fuzz/replay.php --seed N [--json] [--show-html]\n";
	echo "       php tools/css-selector-fuzz/replay.php --selector 'div > .cls' [--html '<div>…</div>']\n";
	exit( 1 );
}

$result = Worker::run_case( $seed );

if ( option_bool( $options, 'json', false ) ) {
	echo json_encode_safe( $result ) . "\n";
	exit( array() === $result['failures'] ? 0 : 2 );
}

echo "seed:     {$result['seed']}\n";
echo "bucket:   {$result['bucket']}\n";
echo 'selector: ' . printable_bytes( $result['selector'] ) . "\n";
echo "digest:   {$result['digest']}\n";

if ( option_bool( $options, 'show-html', false ) ) {
	echo "html:     " . printable_bytes( $result['html'] ) . "\n";
}

if ( array() === $result['failures'] ) {
	echo "failures: none\n";
	exit( 0 );
}

echo 'failures: ' . count( $result['failures'] ) . "\n";
foreach ( $result['failures'] as $i => $failure ) {
	echo "--- failure {$i}: {$failure['invariant']} ---\n";
	echo json_encode_safe( $failure['detail'] ) . "\n";
}
exit( 2 );
