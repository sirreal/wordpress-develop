#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

function html_api_fuzz_tree_normalization_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function html_api_fuzz_tree_normalization_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		html_api_fuzz_tree_normalization_fail( $message );
	}
}

function html_api_fuzz_tree_normalization_assert_compares( array $result, string $message ): void {
	if ( true === ( $result['ok'] ?? null ) ) {
		return;
	}

	if ( 'normalize-invariant-failed' === ( $result['failureClass'] ?? null ) && true === ( $result['comparison']['ok'] ?? null ) ) {
		return;
	}

	html_api_fuzz_tree_normalization_fail( $message );
}

function html_api_fuzz_tree_normalization_rm_tree( string $path ): void {
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
		html_api_fuzz_tree_normalization_rm_tree( $path . DIRECTORY_SEPARATOR . $item );
	}
	@rmdir( $path );
}

function html_api_fuzz_tree_normalization_run( string $tmp, string $name, string $input_base64, string $mode ): array {
	$input = base64_decode( $input_base64, true );
	html_api_fuzz_tree_normalization_assert( false !== $input, "{$name} fixture should decode." );

	return \HtmlApiFuzz\Worker::run(
		array(
			'input-base64' => base64_encode( $input ),
			'profile'      => 'replay',
			'mode'         => $mode,
			'output-dir'   => $tmp . '/' . $name,
			'max-tokens'   => '2000',
			'max-nodes'    => '3000',
		)
	);
}

/*
 * Synthetic compare_trees() cases exercise the comparison logic directly and
 * need no external oracle process.
 *
 * The comparison must keep failing on structural differences: scalar
 * tolerance only applies when the spec substitution explains the entire
 * differing line.
 */
$synthetic_mismatch = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  \"a\"\n\n", "<div>\n  \"b\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_mismatch['ok'] ?? null ), 'Structural tree mismatches should still fail.' );
html_api_fuzz_tree_normalization_assert( is_array( $synthetic_mismatch['firstDifference'] ?? null ) && 2 === ( $synthetic_mismatch['firstDifference']['line'] ?? null ), 'Structural mismatch should report the first differing line.' );

$synthetic_structure_with_nul = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  x=\"\\0\"\n  \"a\"\n\n", "<div>\n  x=\"\xEF\xBF\xBD\"\n  \"b\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_structure_with_nul['ok'] ?? null ), 'Scalar tolerance must not mask structural differences on other lines.' );

$synthetic_tolerated = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  x=\"\\0\"\n\n", "<div>\n  x=\"\xEF\xBF\xBD\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_tolerated['ok'] ?? null ), 'Raw NUL differences must be reported now that deferred byte processing is applied at read interfaces.' );
html_api_fuzz_tree_normalization_assert( ! isset( $synthetic_tolerated['scalarToleratedLines'] ), 'Raw NUL differences should not report tolerated lines.' );

$synthetic_nul_with_agreed_cr = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  x=\"\\0)\\r\"\n\n", "<div>\n  x=\"\xEF\xBF\xBD)\\r\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_nul_with_agreed_cr['ok'] ?? null ), 'A raw NUL must fail even beside an agreed escaped CR.' );

$synthetic_cr_only_wordpress = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  x=\"a\\nb\"\n\n", "<div>\n  x=\"a\\rb\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_cr_only_wordpress['ok'] ?? null ), 'A DOM-side CR where WordPress holds LF is not the spec substitution and must fail.' );

$synthetic_cr_before_decoded_lf = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  x=\"\\r\\n\"\n\n", "<div>\n  x=\"\\n\\n\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_cr_before_decoded_lf['ok'] ?? null ), 'WordPress CR+LF opposite DOM LF+LF must be reported.' );

$synthetic_raw_crlf = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  x=\"\\r\\nX\"\n\n", "<div>\n  x=\"\\nX\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_raw_crlf['ok'] ?? null ), 'Raw CRLF opposite a DOM LF must be reported.' );

$synthetic_backslash_collision = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  x=\"\\\\r\"\n\n", "<div>\n  x=\"\\\\n\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_backslash_collision['ok'] ?? null ), 'A literal backslash followed by r must not be rewritten as a CR escape.' );

$synthetic_repeated_cr_lf = \HtmlApiFuzz\TreeRenderer::compare_trees(
	"<div>\n  x=\"" . str_repeat( '\\r\\n', 500 ) . "\"\n\n",
	"<div>\n  x=\"" . str_repeat( '\\n\\n', 500 ) . "\"\n\n"
);
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_repeated_cr_lf['ok'] ?? null ), 'A long run of raw CR plus decoded LF pairs must be reported.' );

/* Current trunk applies the HTML input preprocessing at the read boundary,
 * so every synthetic NUL/CR tree difference is a real mismatch.
 */
$synthetic_text_nul = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  \"a\\0b\"\n\n", "<div>\n  \"a\xEF\xBF\xBDb\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_text_nul['ok'] ?? null ), 'NUL differences on text lines must be reported.' );

$synthetic_text_cr = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  \"x\\ry\"\n\n", "<div>\n  \"x\\ny\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_text_cr['ok'] ?? null ), 'CR differences on text lines must be reported.' );

$synthetic_comment_nul = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  <!-- a\\0b -->\n\n", "<div>\n  <!-- a\xEF\xBF\xBDb -->\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_comment_nul['ok'] ?? null ), 'NUL differences on comment lines must be reported.' );

$synthetic_tag_name_nul = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  <svg x\\0y>\n\n", "<div>\n  <svg x\xEF\xBF\xBDy>\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_tag_name_nul['ok'] ?? null ), 'NUL differences on tag-name lines must be reported.' );

$synthetic_quoted_attribute_name_nul = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  \"a\\0\"=\"\"\n\n", "<div>\n  \"a\xEF\xBF\xBD\"=\"\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_quoted_attribute_name_nul['ok'] ?? null ), 'NUL differences in quoted attribute names must be reported.' );

/*
 * The tokenizer permits `<` and `!` in attribute names, so `<div <!--a="...">`
 * carries an attribute named `<!--a` and the renderer emits a line that
 * begins like a comment. It remains an attribute line in diagnostics.
 */
$synthetic_comment_prefixed_attribute = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  <!--a=\"x\\0y\"\n\n", "<div>\n  <!--a=\"x\xEF\xBF\xBDy\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_comment_prefixed_attribute['ok'] ?? null ), 'NUL differences in comment-prefixed attribute names must be reported.' );

/*
 * Long generated values must still compare and report differences without
 * overflowing diagnostic normalization.
 */
$synthetic_long_value = str_repeat( 'a', 9000 );
$synthetic_long_attribute_nul = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  x=\"{$synthetic_long_value}\\0\"\n\n", "<div>\n  x=\"{$synthetic_long_value}\xEF\xBF\xBD\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_long_attribute_nul['ok'] ?? null ), 'Long attribute NUL differences must be reported.' );

$synthetic_long_repeated_cr_lf = \HtmlApiFuzz\TreeRenderer::compare_trees(
	"<div>\n  x=\"" . str_repeat( '\\r\\n', 4096 ) . "\"\n\n",
	"<div>\n  x=\"" . str_repeat( '\\n\\n', 4096 ) . "\"\n\n"
);
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_long_repeated_cr_lf['ok'] ?? null ), 'A CR plus decoded LF run crossing the JIT stack boundary must be reported.' );

$synthetic_long_text_nul = \HtmlApiFuzz\TreeRenderer::compare_trees( "<div>\n  \"{$synthetic_long_value}\\0\"\n\n", "<div>\n  \"{$synthetic_long_value}\xEF\xBF\xBD\"\n\n" );
html_api_fuzz_tree_normalization_assert( false === ( $synthetic_long_text_nul['ok'] ?? null ), 'Long text NUL differences must be reported.' );

$synthetic_long_norm = \HtmlApiFuzz\TreeRenderer::normalize_tree_line( "  x=\"{$synthetic_long_value}\\0\"" );
html_api_fuzz_tree_normalization_assert( 'x="<value>"' === $synthetic_long_norm, 'Line normalization should mask long attribute values rather than fail on them.' );

$adjusted_svg_names = array(
	'altGlyph',
	'altGlyphDef',
	'altGlyphItem',
	'animateColor',
	'animateMotion',
	'animateTransform',
	'clipPath',
	'feBlend',
	'feColorMatrix',
	'feComponentTransfer',
	'feComposite',
	'feConvolveMatrix',
	'feDiffuseLighting',
	'feDisplacementMap',
	'feDistantLight',
	'feDropShadow',
	'feFlood',
	'feFuncA',
	'feFuncB',
	'feFuncG',
	'feFuncR',
	'feGaussianBlur',
	'feImage',
	'feMerge',
	'feMergeNode',
	'feMorphology',
	'feOffset',
	'fePointLight',
	'feSpecularLighting',
	'feSpotLight',
	'feTile',
	'feTurbulence',
	'foreignObject',
	'glyphRef',
	'linearGradient',
	'radialGradient',
	'textPath',
);
foreach ( $adjusted_svg_names as $svg_name ) {
	html_api_fuzz_tree_normalization_assert(
		"<svg {$svg_name}>" === \HtmlApiFuzz\TreeRenderer::normalize_tree_line( "<svg {$svg_name}>" ),
		"Adjusted SVG name {$svg_name} should not normalize to a custom element."
	);
	$lower_svg_name = strtolower( $svg_name );
	html_api_fuzz_tree_normalization_assert(
		"<svg {$lower_svg_name}>" === \HtmlApiFuzz\TreeRenderer::normalize_tree_line( "<svg {$lower_svg_name}>" ),
		"Lowercase SVG oracle name {$lower_svg_name} should not normalize to a custom element."
	);
}
foreach ( array( 'bgsound', 'isindex', 'keygen', 'selectedcontent' ) as $html_name ) {
	html_api_fuzz_tree_normalization_assert(
		"<{$html_name}>" === \HtmlApiFuzz\TreeRenderer::normalize_tree_line( "<{$html_name}>" ),
		"Known HTML name {$html_name} should not normalize to a custom element."
	);
}
foreach ( array( 'menclose', 'mprescripts', 'mstack', 'apply', 'csymbol', 'not', 'prsubset' ) as $mathml_name ) {
	html_api_fuzz_tree_normalization_assert(
		"<math {$mathml_name}>" === \HtmlApiFuzz\TreeRenderer::normalize_tree_line( "<math {$mathml_name}>" ),
		"Known MathML name {$mathml_name} should not normalize to a custom element."
	);
}
html_api_fuzz_tree_normalization_assert(
	'<custom-element>' === \HtmlApiFuzz\TreeRenderer::normalize_tree_line( '<x-widget>' ),
	'Unknown HTML custom element names should still normalize to the custom-element bucket.'
);
html_api_fuzz_tree_normalization_assert(
	'<custom-element>' === \HtmlApiFuzz\TreeRenderer::normalize_tree_line( '<svg x-widget>' ),
	'Unknown SVG names should still normalize to the custom-element bucket.'
);
html_api_fuzz_tree_normalization_assert(
	'<custom-element>' === \HtmlApiFuzz\TreeRenderer::normalize_tree_line( '<math x-widget>' ),
	'Unknown MathML names should still normalize to the custom-element bucket.'
);

$tmp = tempnam( sys_get_temp_dir(), 'html-api-fuzz-tree-normalization-' );
if ( false === $tmp ) {
	html_api_fuzz_tree_normalization_fail( 'Could not create temp path.' );
}
@unlink( $tmp );
\HtmlApiFuzz\ensure_dir( $tmp );
register_shutdown_function( 'html_api_fuzz_tree_normalization_rm_tree', $tmp );

$presumptuous_tag = \HtmlApiFuzz\TreeRenderer::render_wordpress(
	'</>',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	array(
		'maxTokens' => 100,
		'maxNodes'  => 100,
	)
);
html_api_fuzz_tree_normalization_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $presumptuous_tag['status'] ?? null ), 'Presumptuous tag closers should be ignored by the WordPress renderer.' );
html_api_fuzz_tree_normalization_assert( "\n" === ( $presumptuous_tag['tree'] ?? null ), 'Ignored presumptuous tag closers should not render tree nodes.' );

$presumptuous_tag_text = \HtmlApiFuzz\TreeRenderer::render_wordpress(
	'a</>b',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY,
	array(
		'maxTokens' => 100,
		'maxNodes'  => 100,
	)
);
html_api_fuzz_tree_normalization_assert( \HtmlApiFuzz\TreeRenderer::STATUS_OK === ( $presumptuous_tag_text['status'] ?? null ), 'Presumptuous tag closers between text should not fail the WordPress renderer.' );
html_api_fuzz_tree_normalization_assert( "\"ab\"\n\n" === ( $presumptuous_tag_text['tree'] ?? null ), 'Ignored presumptuous tag closers should not split adjacent text nodes.' );

$presumptuous_tag_full_document = html_api_fuzz_tree_normalization_run(
	$tmp,
	'presumptuous-tag-full-document',
	base64_encode( '<html><head></head><body></body></html></>' ),
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert_compares( $presumptuous_tag_full_document, 'Full-document presumptuous tag closers after HTML should be ignored by the worker.' );
html_api_fuzz_tree_normalization_assert( true === ( $presumptuous_tag_full_document['comparison']['ok'] ?? null ), 'Full-document presumptuous tag closer comparison should pass.' );

/*
 * Current trunk applies deferred byte processing at read interfaces: NUL is
 * exposed as U+FFFD and CR/CRLF as LF, matching spec-following oracles. These
 * fixtures pin that behavior so a raw-byte regression becomes a mismatch.
 */
$nul_attribute_value = html_api_fuzz_tree_normalization_run(
	$tmp,
	'nul-attribute-value',
	'PCEgcD48L3A+PGh0bWwgaWQ9AD4=',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert_compares( $nul_attribute_value, 'NUL attribute values should compare after deferred byte processing.' );
html_api_fuzz_tree_normalization_assert( true === ( $nul_attribute_value['comparison']['ok'] ?? null ), 'NUL attribute value comparison should pass.' );
html_api_fuzz_tree_normalization_assert( empty( $nul_attribute_value['comparison']['scalarToleratedLines'] ), 'NUL attribute value comparison should not need tolerated lines.' );
$nul_attribute_value_tree = file_get_contents( $nul_attribute_value['wordpress']['treePath'] ?? '' );
html_api_fuzz_tree_normalization_assert( false !== $nul_attribute_value_tree, 'NUL attribute value WordPress tree should be written.' );
html_api_fuzz_tree_normalization_assert( false === strpos( $nul_attribute_value_tree, 'id="\\0"' ), 'NUL attribute values should not render as raw NUL.' );
html_api_fuzz_tree_normalization_assert( false !== strpos( $nul_attribute_value_tree, "\xEF\xBF\xBD" ), 'NUL attribute values should render as U+FFFD in the WordPress tree.' );

$nul_attribute_name = html_api_fuzz_tree_normalization_run(
	$tmp,
	'nul-attribute-name',
	'PGh0bWwKN0Z5AG10ND4=',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert_compares( $nul_attribute_name, 'NUL attribute names should compare after deferred byte processing.' );
html_api_fuzz_tree_normalization_assert( true === ( $nul_attribute_name['comparison']['ok'] ?? null ), 'NUL attribute name comparison should pass.' );
html_api_fuzz_tree_normalization_assert( empty( $nul_attribute_name['comparison']['scalarToleratedLines'] ), 'NUL attribute name comparison should not need tolerated lines.' );
$nul_attribute_name_tree = file_get_contents( $nul_attribute_name['wordpress']['treePath'] ?? '' );
html_api_fuzz_tree_normalization_assert( false !== $nul_attribute_name_tree, 'NUL attribute name WordPress tree should be written.' );
html_api_fuzz_tree_normalization_assert( false !== strpos( $nul_attribute_name_tree, "7fy\xEF\xBF\xBDmt4=\"\"" ), 'NUL attribute names should render with U+FFFD.' );

$comment_prefixed_attribute_name = html_api_fuzz_tree_normalization_run(
	$tmp,
	'comment-prefixed-attribute-name',
	base64_encode( "<div <!--a=\"x\0y\">k</div>" ),
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert_compares( $comment_prefixed_attribute_name, 'Attribute names beginning with a comment opener should compare after deferred byte processing.' );
html_api_fuzz_tree_normalization_assert( true === ( $comment_prefixed_attribute_name['comparison']['ok'] ?? null ), 'Comment-opener attribute name comparison should pass.' );
html_api_fuzz_tree_normalization_assert( empty( $comment_prefixed_attribute_name['comparison']['scalarToleratedLines'] ), 'Comment-opener attribute name comparison should not need tolerated lines.' );

$foreign_tag_name = html_api_fuzz_tree_normalization_run(
	$tmp,
	'foreign-tag-name',
	'PHN0cm9uZyBz16oiPjxzdmcgPjxnPjx0aXRsZT7wn5mCPFBiKQAsRTMmI3hmZmZkOzwvPg==',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY
);
html_api_fuzz_tree_normalization_assert_compares( $foreign_tag_name, 'NUL foreign-content tag names should compare after deferred byte processing.' );
html_api_fuzz_tree_normalization_assert( true === ( $foreign_tag_name['comparison']['ok'] ?? null ), 'NUL foreign-content tag name comparison should pass.' );
html_api_fuzz_tree_normalization_assert( empty( $foreign_tag_name['comparison']['scalarToleratedLines'] ), 'NUL foreign-content tag name comparison should not need tolerated lines.' );

$cr_attribute_value = html_api_fuzz_tree_normalization_run(
	$tmp,
	'cr-attribute-value',
	'PCE+PGh0bWwgfUlnLXBlXWo6dXMyYzA9Ig0iPmE=',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert_compares( $cr_attribute_value, 'CR attribute values should compare after deferred byte processing.' );
html_api_fuzz_tree_normalization_assert( true === ( $cr_attribute_value['comparison']['ok'] ?? null ), 'CR attribute value comparison should pass.' );
html_api_fuzz_tree_normalization_assert( empty( $cr_attribute_value['comparison']['scalarToleratedLines'] ), 'CR attribute value comparison should not need tolerated lines.' );
$cr_attribute_value_tree = file_get_contents( $cr_attribute_value['wordpress']['treePath'] ?? '' );
html_api_fuzz_tree_normalization_assert( false !== $cr_attribute_value_tree, 'CR attribute value WordPress tree should be written.' );
html_api_fuzz_tree_normalization_assert( false !== strpos( $cr_attribute_value_tree, "}ig-pe]j:us2c0=\"\\n\"" ), 'CR attribute values should render as normalized LF.' );

/*
 * A decoded CR (from a character reference such as `&#13;`) survives input
 * preprocessing identically on both sides, so it appears as `\r` in both
 * trees. NUL tolerance on the same line must not rewrite that agreed `\r`:
 * CR may only map to LF where the DOM side actually holds the normalized LF.
 *
 * Fuzzer signature 1d48d2e9a6bc: `><title\t...=\0)&#13;">(</title>`.
 */
$nul_with_agreed_cr = html_api_fuzz_tree_normalization_run(
	$tmp,
	'nul-with-agreed-cr',
	'Pjx0aXRsZQk3UiV8Sjl1V0hofVU9ACkmIzEzOyI+KDwvdGl0bGU+',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert_compares( $nul_with_agreed_cr, 'NUL beside an agreed decoded CR should compare after deferred byte processing.' );
html_api_fuzz_tree_normalization_assert( true === ( $nul_with_agreed_cr['comparison']['ok'] ?? null ), 'NUL beside an agreed decoded CR comparison should pass.' );
html_api_fuzz_tree_normalization_assert( empty( $nul_with_agreed_cr['comparison']['scalarToleratedLines'] ), 'NUL beside an agreed decoded CR should not need tolerated lines.' );

/*
 * A raw CR immediately followed by a decoded `&#10;` renders as `\r\n` in
 * the WordPress tree while the DOM holds `\n\n`: input preprocessing maps
 * the lone CR to LF before the character reference decodes to a second LF.
 * The CR-to-LF substitution must bind per occurrence; the pair-collapse
 * rule for raw CRLF must not consume a decoded LF.
 */
$raw_cr_decoded_lf = html_api_fuzz_tree_normalization_run(
	$tmp,
	'raw-cr-decoded-lf',
	'PGRpdiBhPSINJiMxMDt4Ij4=',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert_compares( $raw_cr_decoded_lf, 'Raw CR before a decoded LF should compare after deferred byte processing.' );
html_api_fuzz_tree_normalization_assert( true === ( $raw_cr_decoded_lf['comparison']['ok'] ?? null ), 'Raw CR before a decoded LF comparison should pass.' );

$nul_raw_cr_decoded_lf = html_api_fuzz_tree_normalization_run(
	$tmp,
	'nul-raw-cr-decoded-lf',
	'PGRpdiBhPSIADSYjMTA7eCI+',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert_compares( $nul_raw_cr_decoded_lf, 'NUL plus raw CR before a decoded LF should compare after deferred byte processing.' );
html_api_fuzz_tree_normalization_assert( true === ( $nul_raw_cr_decoded_lf['comparison']['ok'] ?? null ), 'NUL plus raw CR before a decoded LF comparison should pass.' );

/*
 * An invalid UTF-8 byte (here raw 0x82 in a tag name) is processed the same
 * way by WordPress and the pinned source oracle. Exact comparison therefore
 * passes without an encoding tolerance.
 */
$invalid_utf8_tag_name = html_api_fuzz_tree_normalization_run(
	$tmp,
	'invalid-utf8-tag-name',
	base64_encode( "<body><sma\x82>x" ),
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert( 'passed' === ( $invalid_utf8_tag_name['status'] ?? null ), 'Invalid UTF-8 in a tag name should compare exactly.' );
html_api_fuzz_tree_normalization_assert( true === ( $invalid_utf8_tag_name['comparison']['ok'] ?? null ), 'Invalid UTF-8 in a tag name should need no oracle tolerance.' );

/*
 * NUL attributes whose scrubbed name sorts differently from the raw name
 * must align with the source oracle ordering: sorting uses scrubbed names.
 */
$nul_attribute_sort = html_api_fuzz_tree_normalization_run(
	$tmp,
	'nul-attribute-sort',
	base64_encode( "<p \0z=\"\" j=\"\">x</p>" ),
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY
);
html_api_fuzz_tree_normalization_assert_compares( $nul_attribute_sort, 'NUL attribute names should sort by scrubbed name on both sides.' );
html_api_fuzz_tree_normalization_assert( true === ( $nul_attribute_sort['comparison']['ok'] ?? null ), 'NUL attribute sort comparison should pass.' );

echo "tree renderer normalization smoke tests passed\n";
