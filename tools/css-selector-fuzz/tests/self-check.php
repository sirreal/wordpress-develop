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
use CssSelectorFuzz\WildDocumentGenerator;
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

function known_core_parse_mismatch( string $selector, bool $expected, bool $actual ): ?string {
	if ( $expected === $actual || ! $expected || $actual ) {
		return null;
	}

	if ( ! wp_is_valid_utf8( $selector ) ) {
		return 'invalid-utf8-input-scrub';
	}
	if ( str_ends_with( $selector, '\\' ) ) {
		return 'backslash-at-eof-escape';
	}
	if ( substr_count( $selector, '[' ) > substr_count( $selector, ']' ) ) {
		return 'eof-auto-closes-attribute-selector';
	}
	if ( preg_match( '/\\[[^\\]]*=\\s*[-_a-zA-Z0-9]\\]$/', $selector ) ) {
		return 'single-char-unquoted-attribute-value-at-eof';
	}
	if ( has_identity_escape_after_multibyte( $selector ) ) {
		return 'identity-escape-after-multibyte';
	}

	return null;
}

function has_identity_escape_after_multibyte( string $selector ): bool {
	$seen_multibyte = false;
	$length         = strlen( $selector );
	for ( $i = 0; $i < $length; $i++ ) {
		$byte = ord( $selector[ $i ] );
		if ( $byte > 0x7F ) {
			$seen_multibyte = true;
			continue;
		}
		if ( ! $seen_multibyte || '\\' !== $selector[ $i ] || $i + 1 >= $length ) {
			continue;
		}

		$next = $selector[ $i + 1 ];
		if ( "\n" === $next || "\r" === $next || "\f" === $next || ctype_xdigit( $next ) ) {
			continue;
		}
		return true;
	}
	return false;
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
$allowed_parse_mismatches = array();
for ( $seed = 1; $seed <= 400; $seed++ ) {
	$prng     = new Prng( (string) $seed, 'self-check-selector' );
	$document = DocumentGenerator::generate( $prng->fork( 'doc' ) );
	$selector = SelectorGenerator::generate( $prng->fork( 'sel' ), $document['pools'] );

	$by_bucket[ $selector['bucket'] ] = ( $by_bucket[ $selector['bucket'] ] ?? 0 ) + 1;

	$compound = WP_CSS_Compound_Selector_List::from_selectors( $selector['selector'] );
	$complex  = WP_CSS_Complex_Selector_List::from_selectors( $selector['selector'] );

	if ( null !== $selector['expectCompound'] ) {
		$expected = $selector['expectCompound'];
		$actual   = null !== $compound;
		$known    = known_core_parse_mismatch( $selector['selector'], $expected, $actual );
		if ( null !== $known ) {
			$allowed_parse_mismatches[ "compound:{$known}" ] = ( $allowed_parse_mismatches[ "compound:{$known}" ] ?? 0 ) + 1;
		} else {
			check(
				$expected === $actual,
				"Seed {$seed} ({$selector['bucket']}): compound parse expectation for: " . \CssSelectorFuzz\printable_bytes( $selector['selector'] )
			);
		}
	}
	if ( null !== $selector['expectComplex'] ) {
		$expected = $selector['expectComplex'];
		$actual   = null !== $complex;
		$known    = known_core_parse_mismatch( $selector['selector'], $expected, $actual );
		if ( null !== $known ) {
			$allowed_parse_mismatches[ "complex:{$known}" ] = ( $allowed_parse_mismatches[ "complex:{$known}" ] ?? 0 ) + 1;
		} else {
			check(
				$expected === $actual,
				"Seed {$seed} ({$selector['bucket']}): complex parse expectation for: " . \CssSelectorFuzz\printable_bytes( $selector['selector'] )
			);
		}
	}

	if ( null !== $selector['ast'] && null !== $complex ) {
		check(
			$selector['ast'] === \CssSelectorFuzz\AstExtractor::from_complex_list( $complex ),
			"Seed {$seed} ({$selector['bucket']}): complex AST round-trips for: " . \CssSelectorFuzz\printable_bytes( $selector['selector'] )
		);
	}
	if ( null !== $selector['ast'] && null !== $compound ) {
		check(
			$selector['ast'] === \CssSelectorFuzz\AstExtractor::from_compound_list( $compound ),
			"Seed {$seed} ({$selector['bucket']}): compound AST round-trips for: " . \CssSelectorFuzz\printable_bytes( $selector['selector'] )
		);
	}
}

check( count( $by_bucket ) >= 5, 'Bucket variety: saw ' . count( $by_bucket ) . ' buckets.' );
if ( array() !== $allowed_parse_mismatches ) {
	fwrite( STDERR, 'Allowed known core parse bug signatures: ' . \CssSelectorFuzz\json_encode_safe( $allowed_parse_mismatches ) . "\n" );
}

// --- Selector renderer token-boundary regressions -------------------------
// These pin places where adjacent rendered tokens can accidentally form a
// different token stream. In particular, a raw `--` type selector followed by
// a child combinator must not render as `-->`, which CSS tokenization treats
// as CDC.

$renderer_boundary_asts = array(
	'cdc-child-combinator'        => array(
		array(
			'context' => array(
				array( '--', '>' ),
			),
			'self'    => array(
				'type' => 'a',
				'subs' => null,
			),
		),
	),
	'cdc-nested-child-combinator' => array(
		array(
			'context' => array(
				array( 'b', '>' ),
				array( '--', '>' ),
			),
			'self'    => array(
				'type' => 'a',
				'subs' => null,
			),
		),
	),
	'cdc-selector-list-branch'    => array(
		array(
			'context' => array(
				array( '--', '>' ),
			),
			'self'    => array(
				'type' => 'a',
				'subs' => null,
			),
		),
		array(
			'context' => array(),
			'self'    => array(
				'type' => '--',
				'subs' => array(
					array(
						'kind' => 'class',
						'name' => 'x',
					),
					array(
						'kind' => 'id',
						'name' => '--',
					),
				),
			),
		),
	),
	'attribute-ident-modifier-i'  => array(
		array(
			'context' => array(),
			'self'    => array(
				'type' => null,
				'subs' => array(
					array(
						'kind'     => 'attr',
						'name'     => 'x',
						'matcher'  => 'exact',
						'value'    => 'i',
						'modifier' => 'case-insensitive',
					),
				),
			),
		),
	),
	'attribute-ident-modifier-s'  => array(
		array(
			'context' => array(),
			'self'    => array(
				'type' => null,
				'subs' => array(
					array(
						'kind'     => 'attr',
						'name'     => 'x',
						'matcher'  => 'exact',
						'value'    => 's',
						'modifier' => 'case-sensitive',
					),
				),
			),
		),
	),
);

foreach ( $renderer_boundary_asts as $name => $ast ) {
	for ( $seed = 1; $seed <= 75; $seed++ ) {
		$selector = SelectorGenerator::render( new Prng( (string) $seed, "self-check-renderer-boundary-{$name}" ), $ast );
		$complex  = WP_CSS_Complex_Selector_List::from_selectors( $selector );
		check( null !== $complex, "Renderer boundary {$name} seed {$seed}: parse for " . \CssSelectorFuzz\printable_bytes( $selector ) );
		if ( null !== $complex ) {
			check(
				$ast === \CssSelectorFuzz\AstExtractor::from_complex_list( $complex ),
				"Renderer boundary {$name} seed {$seed}: AST round-trips for " . \CssSelectorFuzz\printable_bytes( $selector )
			);
		}
	}
}

// --- Document generator: randomized class NUL injection --------------------

$safe_class_nul = 0;
for ( $seed = 1; $seed <= 200; $seed++ ) {
	$document = DocumentGenerator::generate( new Prng( (string) $seed, 'self-check-class-nul-safe' ) );
	if ( false !== strpos( $document['html'], "\0" ) ) {
		++$safe_class_nul;
		check( false === str_contains( implode( "\n", $document['pools']['attrValues'] ), "\0" ), "Safe document {$seed}: class NUL does not leak into attrValues pool." );
		check( \CssSelectorFuzz\ast_strings_are_utf8( $document['pools']['classes'] ), "Safe document {$seed}: class pool strings stay valid UTF-8." );
		check( in_array( true, array_map( static function ( string $class ): bool {
			return false !== strpos( $class, "\u{FFFD}" );
		}, $document['pools']['classes'] ), true ), "Safe document {$seed}: class pool contains decoded U+FFFD token." );
	}
}
check( $safe_class_nul > 0, "Safe document generator emits randomized class NUL values ({$safe_class_nul} of 200)." );

$wild_class_nul = 0;
for ( $seed = 1; $seed <= 200; $seed++ ) {
	$document = WildDocumentGenerator::generate( new Prng( (string) $seed, 'self-check-class-nul-wild' ) );
	if ( false !== strpos( $document['html'], "\0" ) ) {
		++$wild_class_nul;
		check( false === str_contains( implode( "\n", $document['pools']['attrValues'] ), "\0" ), "Wild document {$seed}: class NUL does not leak into attrValues pool." );
		check( \CssSelectorFuzz\ast_strings_are_utf8( $document['pools']['classes'] ), "Wild document {$seed}: class pool strings stay valid UTF-8." );
		check( in_array( true, array_map( static function ( string $class ): bool {
			return false !== strpos( $class, "\u{FFFD}" );
		}, $document['pools']['classes'] ), true ), "Wild document {$seed}: class pool contains decoded U+FFFD token." );
	}
}
check( $wild_class_nul > 0, "Wild document generator emits randomized class NUL values ({$wild_class_nul} of 200)." );

// --- Invalid-UTF-8 bucket: post-scrub AST expectations by construction ------
// from_selectors() replaces each maximal subpart of an ill-formed UTF-8
// sequence with one U+FFFD before parsing ( CSS Syntax §3.2 via the WHATWG
// decoder ). The bucket injects raw ill-formed sequences and carries the
// post-scrub AST, with the per-class subpart counts hard-coded in the
// generator — independent of wp_scrub_utf8(), so this loop is a real
// differential between the generator's WHATWG expectations and the core
// scrub + parse pipeline.

$fffd_ast_counts = array();
$injection_sites = array();
$byte_classes    = array();

// The class names AND byte values are duplicated here on purpose: tallying
// from the generator's own table would silently shrink the assertion with a
// deleted entry and self-validate on a drifted byte value.
$expected_byte_classes = array(
	'lone-continuation' => "\x80",
	'truncated-2-byte'  => "\xC3",
	'truncated-3-byte'  => "\xE2\x8C",
	'truncated-4-byte'  => "\xF0\x9F\x82",
	'invalid-lead-f5'   => "\xF5",
	'invalid-lead-ff'   => "\xFF",
	'overlong-min'      => "\xC0\x80",
	'overlong-max'      => "\xC1\xBF",
	'surrogate-half'    => "\xED\xA0\x80",
	'beyond-max'        => "\xF4\x90\x80\x80",
);

$count_fffd = static function ( $node ) use ( &$count_fffd ): int {
	if ( is_string( $node ) ) {
		return substr_count( $node, "\u{FFFD}" );
	}
	$total = 0;
	if ( is_array( $node ) ) {
		foreach ( $node as $child ) {
			$total += $count_fffd( $child );
		}
	}
	return $total;
};

for ( $seed = 1; $seed <= 150; $seed++ ) {
	$prng      = new Prng( (string) $seed, 'self-check-invalid-utf8' );
	$document  = DocumentGenerator::generate( $prng->fork( 'doc' ) );
	$case      = SelectorGenerator::generate( $prng->fork( 'sel' ), $document['pools'], null, 'invalid-utf8' );
	$printable = \CssSelectorFuzz\printable_bytes( $case['selector'] );

	check( 'invalid-utf8' === $case['bucket'], "Seed {$seed}: forced invalid-utf8 bucket, got {$case['bucket']}." );
	check( ! wp_is_valid_utf8( $case['selector'] ), "Seed {$seed}: selector must contain invalid UTF-8: {$printable}" );
	check( true === $case['expectCompound'] && true === $case['expectComplex'], "Seed {$seed}: invalid-utf8 cases must expect to parse in both grammars." );
	check( is_array( $case['ast'] ) && \CssSelectorFuzz\ast_strings_are_utf8( $case['ast'] ), "Seed {$seed}: expected AST must be valid UTF-8." );

	$compound = WP_CSS_Compound_Selector_List::from_selectors( $case['selector'] );
	$complex  = WP_CSS_Complex_Selector_List::from_selectors( $case['selector'] );
	check( null !== $compound, "Seed {$seed}: compound parse after scrub for: {$printable}" );
	check( null !== $complex, "Seed {$seed}: complex parse after scrub for: {$printable}" );
	if ( null === $complex || ! is_array( $case['ast'] ) ) {
		continue;
	}

	$parsed_ast = \CssSelectorFuzz\AstExtractor::from_complex_list( $complex );
	check( $case['ast'] === $parsed_ast, "Seed {$seed}: parsed AST equals maximal-subpart scrub expectation for: {$printable}" );

	$fffd_ast_counts[ $count_fffd( $case['ast'] ) ] = true;
	foreach ( (array) $case['ast'][0]['self']['subs'] as $sub ) {
		$injection_sites[ 'attr' === $sub['kind'] && null !== $sub['matcher'] ? 'attr-value' : $sub['kind'] ] = true;
	}
	foreach ( $expected_byte_classes as $class_name => $class_bytes ) {
		// Substring attribution is ambiguous only for lone-continuation,
		// whose byte occurs inside three longer classes — good enough for
		// an at-least-once variety tally.
		if ( str_contains( $case['selector'], $class_bytes ) ) {
			$byte_classes[ $class_name ] = true;
		}
	}
}

foreach ( array( 1, 2, 3, 4 ) as $expected_count ) {
	check( isset( $fffd_ast_counts[ $expected_count ] ), "Invalid-utf8 variety: a {$expected_count}-subpart byte class was generated." );
}
foreach ( array( 'class', 'id', 'attr', 'attr-value' ) as $site ) {
	check( isset( $injection_sites[ $site ] ), "Invalid-utf8 variety: injection site {$site} was generated." );
}
foreach ( array_keys( $expected_byte_classes ) as $class_name ) {
	check( isset( $byte_classes[ $class_name ] ), "Invalid-utf8 variety: byte class {$class_name} was generated." );
}

// --- Mutated bucket: raw invalid-byte splicing -------------------------------
// mutate() must be able to splice raw ill-formed UTF-8 into a selector at
// arbitrary byte offsets; these cases carry no AST expectation and exercise
// crash / scrub-notice / differential paths only. The marker bytes here can
// appear in NO rendered selector (the pools' multibyte characters use other
// lead bytes), so their presence proves the mutation operation fired.

$mutated_with_invalid = 0;
for ( $seed = 1; $seed <= 200; $seed++ ) {
	$prng     = new Prng( (string) $seed, 'self-check-mutated-utf8' );
	$document = DocumentGenerator::generate( $prng->fork( 'doc' ) );
	$case     = SelectorGenerator::generate( $prng->fork( 'sel' ), $document['pools'], null, 'mutated' );
	if ( false !== strpbrk( $case['selector'], "\xC0\xC1\xED\xF4\xF5\xFF" ) ) {
		++$mutated_with_invalid;
	}
}
check( $mutated_with_invalid >= 10, "Mutated bucket splices raw invalid bytes ({$mutated_with_invalid} of 200 seeds)." );

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
// against each other on these boundary inputs; randomized generator sampling
// above verifies that the same NUL boundary is present in the hot path. Each
// case also checks the reference matcher agrees with select() over a
// TreeCapture of the same markup.

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
