#!/usr/bin/env php
<?php
/**
 * Fuzzer self-check: fast, deterministic assertions over the fuzzer's own
 * machinery (PRNG determinism, generator expectations, oracle agreement on
 * known cases). Run after changing the fuzzer:
 *
 *     php tools/css-selector-fuzz/tests/self-check.php
 */

require_once dirname( __DIR__ ) . '/lib/autoload.php';

use CssSelectorFuzz\Bootstrap;
use CssSelectorFuzz\DocumentGenerator;
use CssSelectorFuzz\Prng;
use CssSelectorFuzz\SelectorGenerator;
use CssSelectorFuzz\Worker;
use function CssSelectorFuzz\utf8_codepoints;

$failures = 0;

function check( bool $condition, string $message ): void {
	global $failures;
	if ( $condition ) {
		return;
	}
	++$failures;
	fwrite( STDERR, "FAIL: {$message}\n" );
}

Bootstrap::load();

// --- Prng determinism and independence -------------------------------------

$a = new Prng( '42', 'label' );
$b = new Prng( '42', 'label' );
check( $a->bytes( 64 ) === $b->bytes( 64 ), 'Identical seeds produce identical streams.' );

$c = new Prng( '42', 'label' );
$d = new Prng( '43', 'label' );
check( $c->bytes( 64 ) !== $d->bytes( 64 ), 'Different seeds produce different streams.' );

$e     = new Prng( '42', 'fork-test' );
$f     = new Prng( '42', 'fork-test' );
$fork1 = $e->fork( 'x' );
$fork2 = $f->fork( 'x' );
check( $fork1->bytes( 32 ) === $fork2->bytes( 32 ), 'Forked streams are deterministic.' );

// --- utf8_codepoints --------------------------------------------------------

$points = utf8_codepoints( "a\u{E9}\u{1F600}" );
check( 3 === count( $points ), 'utf8_codepoints splits into 3 codepoints.' );
check( 0x61 === $points[0][1] && 0xE9 === $points[1][1] && 0x1F600 === $points[2][1], 'utf8_codepoints decodes values.' );

// --- Document generator: model matches parse for many seeds ---------------
// ( Worker::run_case checks this per case as model-desync; here only a couple
//   of seeds are sampled for a fast signal. )

for ( $seed = 1; $seed <= 3; $seed++ ) {
	$document = DocumentGenerator::generate( new Prng( (string) $seed, 'self-check-doc' ) );
	check( is_string( $document['html'] ) && '' !== $document['html'], "Document {$seed} renders." );
	check( str_contains( $document['html'], 'data-fid' ) || false !== strpos( $document['html'], 'data-fid' ), "Document {$seed} has fids." );
}

// --- Selector generator expectations over many seeds -----------------------

$by_bucket = array();
for ( $seed = 1; $seed <= 400; $seed++ ) {
	$prng     = new Prng( (string) $seed, 'self-check-selector' );
	$document = DocumentGenerator::generate( $prng->fork( 'doc' ) );
	$selector = SelectorGenerator::generate( $prng->fork( 'sel' ), $document['pools'] );

	$by_bucket[ $selector['bucket'] ] = ( $by_bucket[ $selector['bucket'] ] ?? 0 ) + 1;

	$compound = WP_CSS_Compound_Selector_List::from_selectors( $selector['selector'] );
	$complex  = WP_CSS_Complex_Selector_List::from_selectors( $selector['selector'] );

	if ( null !== $selector['expectCompound'] ) {
		check(
			$selector['expectCompound'] === ( null !== $compound ),
			"Seed {$seed} ({$selector['bucket']}): compound parse expectation for: " . \CssSelectorFuzz\printable_bytes( $selector['selector'] )
		);
	}
	if ( null !== $selector['expectComplex'] ) {
		check(
			$selector['expectComplex'] === ( null !== $complex ),
			"Seed {$seed} ({$selector['bucket']}): complex parse expectation for: " . \CssSelectorFuzz\printable_bytes( $selector['selector'] )
		);
	}
}

check( count( $by_bucket ) >= 5, 'Bucket variety: saw ' . count( $by_bucket ) . ' buckets.' );

// --- Known-answer matching cases -------------------------------------------

$known_html = '<!DOCTYPE html><html data-fid="e0"><head data-fid="e1"></head><body data-fid="e2">'
	. '<div data-fid="e3" class="a b"><span data-fid="e4" class="b" id="x" data-v="hello-world"></span></div>'
	. '<section data-fid="e5"><div data-fid="e6"><em data-fid="e7" lang="en-US"></em></div></section>'
	. '</body></html>';

function select_fids( string $html, string $selector ): array {
	$processor = WP_HTML_Processor::create_full_parser( $html );
	$out       = array();
	while ( $processor->select( $selector ) ) {
		$out[] = $processor->get_attribute( 'data-fid' );
	}
	return $out;
}

check( array( 'e4' ) === select_fids( $known_html, '#x' ), 'Known: #x.' );
check( array( 'e3', 'e4' ) === select_fids( $known_html, '.b' ), 'Known: .b.' );
check( array( 'e4' ) === select_fids( $known_html, 'div > span.b' ), 'Known: div > span.b.' );
check( array( 'e7' ) === select_fids( $known_html, 'section em' ), 'Known: section em.' );
check( array() === select_fids( $known_html, 'section > em' ), 'Known: section > em matches nothing.' );
check( array( 'e4' ) === select_fids( $known_html, '[data-v|="hello"]' ), 'Known: [data-v|=hello].' );
check( array( 'e7' ) === select_fids( $known_html, '[lang^="en"]' ), 'Known: [lang^=en].' );

// --- Class-value decode boundary (ReferenceMatcher vs WP class_list) --------
// WP's class_list() folds NUL -> U+FFFD and treats FF as a separator; the
// reference matcher reimplements tokenization independently. Pin both engines
// against each other on these boundary inputs ( exercised deterministically
// here since the random document generator does not emit control bytes in
// class values — see README #10 ). Each case also checks the reference matcher
// agrees with select() over a TreeCapture of the same markup.

function ref_fids( string $html, string $selector ): array {
	$capture = \CssSelectorFuzz\TreeCapture::capture( $html );
	$list    = WP_CSS_Complex_Selector_List::from_selectors( $selector );
	if ( null !== $capture['error'] || null === $list ) {
		return array( '(error)' );
	}
	$ast = \CssSelectorFuzz\AstExtractor::from_complex_list( $list );
	return \CssSelectorFuzz\ReferenceMatcher::expected_html_matches_rows( $ast, $capture['htmlRows'], $capture['quirks'] );
}

$nul_html = "<!DOCTYPE html><i data-fid=\"n0\" class=\"foo\x00bar\"></i><b data-fid=\"n1\" class=\"x\x00\"></b>";
$ff_html  = "<!DOCTYPE html><i data-fid=\"f0\" class=\"alpha\x0Cbeta\"></i>";

$nul_cases = array(
	array( "class NUL -> FFFD", $nul_html, ".foo\u{FFFD}bar", array( 'n0' ) ),
	array( "class trailing NUL", $nul_html, ".x\u{FFFD}", array( 'n1' ) ),
	array( "class raw NUL no-match", $nul_html, '.foobar', array() ),
	array( "class FF separator (first)", $ff_html, '.alpha', array( 'f0' ) ),
	array( "class FF separator (second)", $ff_html, '.beta', array( 'f0' ) ),
);
foreach ( $nul_cases as $case ) {
	list( $label, $html, $selector, $expected ) = $case;
	$wp  = select_fids( $html, $selector );
	$ref = ref_fids( $html, $selector );
	check( $expected === $wp, "Decode boundary ({$label}): select() == expected." );
	check( $ref === $wp, "Decode boundary ({$label}): ReferenceMatcher == select()." );
}

// --- Worker end-to-end on a few seeds ---------------------------------------

for ( $seed = 1; $seed <= 5; $seed++ ) {
	$first  = Worker::run_case( $seed );
	$second = Worker::run_case( $seed );
	check( $first['digest'] === $second['digest'], "Seed {$seed}: case digest is deterministic." );
}

if ( 0 === $failures ) {
	echo "self-check OK\n";
	exit( 0 );
}
echo "self-check FAILED: {$failures} failure(s)\n";
exit( 1 );
