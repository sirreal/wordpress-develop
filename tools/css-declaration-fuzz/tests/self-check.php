#!/usr/bin/env php
<?php
require_once dirname( __DIR__ ) . '/lib/autoload.php';

use CssDeclarationFuzz\CaseGenerator;
use CssDeclarationFuzz\Worker;

\CssDeclarationFuzz\throw_on_php_error();

function css_declaration_fuzz_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "self-check failed: {$message}\n" );
		exit( 1 );
	}
}

css_declaration_fuzz_check(
	array(
		array( 'name' => 'color', 'important' => false ),
		array( 'name' => 'color', 'important' => true ),
		array( 'name' => '--Tone', 'important' => false ),
	) === Worker::capture_style( 'COLOR: red; c\\6f lor: blue !/**/important; --Tone: warm' ),
	'known declaration traversal'
);

css_declaration_fuzz_check(
	array( array( 'name' => 'color', 'important' => false ) ) === Worker::capture_style( 'garbage; @skip x; :bad; color: red' ),
	'invalid fragments are skipped'
);

$nul_name = "co\0lor: red; background: blue";
css_declaration_fuzz_check(
	array(
		array( 'name' => "co\u{FFFD}lor", 'important' => false ),
		array( 'name' => 'background', 'important' => false ),
	) === Worker::capture_style( $nul_name ),
	'NUL preprocessing does not desynchronize declaration traversal'
);

\CssDeclarationFuzz\Bootstrap::load();
$eof = WP_HTML_Style_Attribute_Processor::create( 'color: var(--x' );
css_declaration_fuzz_check( $eof->append_declaration( 'background', 'white' ), 'EOF repair append succeeds' );
css_declaration_fuzz_check( 'color: var(--x); background: white;' === $eof->get_updated_style(), 'EOF repair is precise' );

$buckets = array();
for ( $seed = 1; $seed <= 300; $seed++ ) {
	$case = CaseGenerator::generate( $seed );
	css_declaration_fuzz_check( $case === CaseGenerator::generate( $seed ), "seed {$seed} generation is deterministic" );
	$buckets[ $case['bucket'] ] = true;
	$result = Worker::run_case( $seed );
	css_declaration_fuzz_check( empty( $result['failures'] ), "seed {$seed} is clean: " . json_encode( $result['failures'] ) );
}

foreach ( array( 'structured', 'mutated', 'eof-repair', 'raw-bytes' ) as $bucket ) {
	css_declaration_fuzz_check( isset( $buckets[ $bucket ] ), "bucket {$bucket} is reachable" );
}

echo "self-check OK\n";
