#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib/autoload.php';

use ComponentFuzz\SurfaceRunner;
use function ComponentFuzz\cli_options;

$surfaces = array();
foreach ( get_declared_classes() as $class ) {
	if ( str_starts_with( $class, 'ComponentFuzz\\Surfaces\\' ) && defined( $class . '::NAME' ) ) {
		$surfaces[ $class::NAME ] = $class;
	}
}
ksort( $surfaces );

$options = cli_options( $argv );
$runner  = new SurfaceRunner( $surfaces );

if ( isset( $options['help'] ) ) {
	fwrite(
		STDOUT,
		"Usage: php tools/component-fuzz/runner.php [--surface all|name[,name]] [--seed N] [--iterations N] [--output-dir DIR] [--fail-fast]\n"
	);
	exit( 0 );
}

if ( isset( $options['list-surfaces'] ) ) {
	foreach ( $runner->surface_names() as $surface ) {
		fwrite( STDOUT, $surface . "\n" );
	}
	exit( 0 );
}

$summary = $runner->run( $options );
fwrite( STDOUT, json_encode( $summary['counts'], JSON_UNESCAPED_SLASHES ) . "\n" );

exit( 0 === $summary['counts']['failed'] && 0 === $summary['counts']['errored'] ? 0 : 1 );

