#!/usr/bin/env php
<?php
/** Line coverage for the proposed CSS API classes under fuzzing, via phpdbg. */

require_once __DIR__ . '/lib/autoload.php';

use CssDeclarationFuzz\Worker;
use function CssDeclarationFuzz\option_bool;
use function CssDeclarationFuzz\option_int;
use function CssDeclarationFuzz\parse_cli_options;
use function CssDeclarationFuzz\repo_root;

\CssDeclarationFuzz\throw_on_php_error();

if ( ! function_exists( 'phpdbg_start_oplog' ) ) {
	fwrite( STDERR, "Run under phpdbg: phpdbg -qrr tools/css-declaration-fuzz/coverage.php\n" );
	exit( 1 );
}

$options        = parse_cli_options( $argv );
$seeds          = max( 1, option_int( $options, 'seeds', 2000 ) );
$list_uncovered = option_bool( $options, 'list-uncovered', false );
$targets        = array(
	repo_root() . '/src/wp-includes/css-api/class-wp-css-token-processor.php',
	repo_root() . '/src/wp-includes/html-api/class-wp-html-style-attribute-processor.php',
);

\CssDeclarationFuzz\Bootstrap::load();

$oplog = array();
$chunk = 1;
for ( $start = 1; $start <= $seeds; $start += $chunk ) {
	phpdbg_start_oplog();
	$limit = min( $seeds, $start + $chunk - 1 );
	for ( $seed = $start; $seed <= $limit; $seed++ ) {
		Worker::run_case( $seed );
	}
	foreach ( phpdbg_end_oplog() as $file => $lines ) {
		foreach ( $lines as $line => $hits ) {
			$oplog[ $file ][ $line ] = true;
		}
	}
}

$executable    = phpdbg_get_executable( array( 'files' => $targets ) );
$total_exec    = 0;
$total_covered = 0;

foreach ( $targets as $file ) {
	$exec_lines    = array_keys( $executable[ $file ] ?? array() );
	$covered_lines = array_intersect( array_keys( $oplog[ $file ] ?? array() ), $exec_lines );
	$uncovered     = array_diff( $exec_lines, $covered_lines );
	$total_exec    += count( $exec_lines );
	$total_covered += count( $covered_lines );

	printf(
		"%-55s %4d/%4d lines  %5.1f%%\n",
		basename( $file ),
		count( $covered_lines ),
		count( $exec_lines ),
		count( $exec_lines ) > 0 ? 100 * count( $covered_lines ) / count( $exec_lines ) : 100
	);

	if ( $list_uncovered && array() !== $uncovered ) {
		$source = file( $file );
		sort( $uncovered );
		foreach ( $uncovered as $line ) {
			printf( "    !%4d  %s\n", $line, rtrim( $source[ $line - 1 ] ?? '' ) );
		}
	}
}

printf(
	"%-55s %4d/%4d lines  %5.1f%%\n",
	'TOTAL',
	$total_covered,
	$total_exec,
	$total_exec > 0 ? 100 * $total_covered / $total_exec : 100
);
