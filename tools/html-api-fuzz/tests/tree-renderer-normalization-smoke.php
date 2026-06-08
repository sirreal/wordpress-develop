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

if ( ! class_exists( 'Dom\\HTMLDocument' ) ) {
	echo "tree renderer normalization smoke tests skipped: Dom\\HTMLDocument unavailable\n";
	exit( 0 );
}

$tmp = tempnam( sys_get_temp_dir(), 'html-api-fuzz-tree-normalization-' );
if ( false === $tmp ) {
	html_api_fuzz_tree_normalization_fail( 'Could not create temp path.' );
}
@unlink( $tmp );
\HtmlApiFuzz\ensure_dir( $tmp );
register_shutdown_function( 'html_api_fuzz_tree_normalization_rm_tree', $tmp );

$nul_attribute_value = html_api_fuzz_tree_normalization_run(
	$tmp,
	'nul-attribute-value',
	'PCEgcD48L3A+PGh0bWwgaWQ9AD4=',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert( true === ( $nul_attribute_value['ok'] ?? null ), 'NUL attribute values should compare after tree scalar normalization.' );
html_api_fuzz_tree_normalization_assert( true === ( $nul_attribute_value['comparison']['ok'] ?? null ), 'NUL attribute value comparison should pass.' );
$nul_attribute_value_tree = file_get_contents( $nul_attribute_value['wordpress']['treePath'] ?? '' );
html_api_fuzz_tree_normalization_assert( false !== $nul_attribute_value_tree, 'NUL attribute value WordPress tree should be written.' );
html_api_fuzz_tree_normalization_assert( false !== strpos( $nul_attribute_value_tree, "id=\"\xEF\xBF\xBD\"" ), 'NUL attribute values should render as U+FFFD.' );
html_api_fuzz_tree_normalization_assert( false === strpos( $nul_attribute_value_tree, '\\0' ), 'NUL attribute values should not render as \0.' );

$nul_attribute_name = html_api_fuzz_tree_normalization_run(
	$tmp,
	'nul-attribute-name',
	'PGh0bWwKN0Z5AG10ND4=',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert( true === ( $nul_attribute_name['ok'] ?? null ), 'NUL attribute names should compare after tree scalar normalization.' );
html_api_fuzz_tree_normalization_assert( true === ( $nul_attribute_name['comparison']['ok'] ?? null ), 'NUL attribute name comparison should pass.' );
$nul_attribute_name_tree = file_get_contents( $nul_attribute_name['wordpress']['treePath'] ?? '' );
html_api_fuzz_tree_normalization_assert( false !== $nul_attribute_name_tree, 'NUL attribute name WordPress tree should be written.' );
html_api_fuzz_tree_normalization_assert( false !== strpos( $nul_attribute_name_tree, "7fy\xEF\xBF\xBDmt4=\"\"" ), 'NUL attribute names should render as U+FFFD.' );

$foreign_tag_name = html_api_fuzz_tree_normalization_run(
	$tmp,
	'foreign-tag-name',
	'PHN0cm9uZyBz16oiPjxzdmcgPjxnPjx0aXRsZT7wn5mCPFBiKQAsRTMmI3hmZmZkOzwvPg==',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY
);
html_api_fuzz_tree_normalization_assert( true === ( $foreign_tag_name['ok'] ?? null ), 'NUL foreign-content tag names should compare after tree scalar normalization.' );
html_api_fuzz_tree_normalization_assert( true === ( $foreign_tag_name['comparison']['ok'] ?? null ), 'NUL foreign-content tag name comparison should pass.' );

$cr_attribute_value = html_api_fuzz_tree_normalization_run(
	$tmp,
	'cr-attribute-value',
	'PCE+PGh0bWwgfUlnLXBlXWo6dXMyYzA9Ig0iPmE=',
	\HtmlApiFuzz\Generator::MODE_FULL_DOCUMENT
);
html_api_fuzz_tree_normalization_assert( true === ( $cr_attribute_value['ok'] ?? null ), 'CR attribute values should compare after tree scalar normalization.' );
html_api_fuzz_tree_normalization_assert( true === ( $cr_attribute_value['comparison']['ok'] ?? null ), 'CR attribute value comparison should pass.' );
$cr_attribute_value_tree = file_get_contents( $cr_attribute_value['wordpress']['treePath'] ?? '' );
html_api_fuzz_tree_normalization_assert( false !== $cr_attribute_value_tree, 'CR attribute value WordPress tree should be written.' );
html_api_fuzz_tree_normalization_assert( false !== strpos( $cr_attribute_value_tree, "}ig-pe]j:us2c0=\"\\n\"" ), 'CR attribute values should render as escaped LF.' );
html_api_fuzz_tree_normalization_assert( false === strpos( $cr_attribute_value_tree, '\\r' ), 'CR attribute values should not render as escaped CR.' );

$structural_mismatch = html_api_fuzz_tree_normalization_run(
	$tmp,
	'structural-select-track',
	'PHNlbGVjdD48dHJhY2sgZT48IS0+',
	\HtmlApiFuzz\Generator::MODE_FRAGMENT_BODY
);
html_api_fuzz_tree_normalization_assert( false === ( $structural_mismatch['ok'] ?? null ), 'Structural tree mismatches should still fail.' );
html_api_fuzz_tree_normalization_assert( 'tree-mismatch' === ( $structural_mismatch['failureClass'] ?? null ), 'Structural tree mismatches should keep the tree-mismatch class.' );
html_api_fuzz_tree_normalization_assert( false === ( $structural_mismatch['comparison']['ok'] ?? null ), 'Structural tree mismatch comparison should not be normalized away.' );

echo "tree renderer normalization smoke tests passed\n";
