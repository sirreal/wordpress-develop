#!/usr/bin/env php
<?php
/**
 * Line coverage of the html-api/css classes under fuzzing, via phpdbg.
 *
 * Usage:
 *   phpdbg -qrr tools/css-selector-fuzz/coverage.php [--seeds N] [--list-uncovered]
 *
 * Runs N sequential fuzz cases in-process with the opcode log enabled and
 * reports, per target file, executable vs covered line counts and
 * (optionally) every uncovered line with its source.
 */

require_once __DIR__ . '/lib/autoload.php';

use CssSelectorFuzz\Worker;
use function CssSelectorFuzz\option_bool;
use function CssSelectorFuzz\option_int;
use function CssSelectorFuzz\parse_cli_options;
use function CssSelectorFuzz\repo_root;

if ( ! function_exists( 'phpdbg_start_oplog' ) ) {
	fwrite( STDERR, "Run under phpdbg: phpdbg -qrr tools/css-selector-fuzz/coverage.php\n" );
	exit( 1 );
}

$options        = parse_cli_options( $argv );
$seeds          = option_int( $options, 'seeds', 2000 );
$list_uncovered = option_bool( $options, 'list-uncovered', false );

$target_dir = repo_root() . '/src/wp-includes/html-api/css';
$targets    = glob( $target_dir . '/*.php' );

\CssSelectorFuzz\Bootstrap::load();

/*
 * The oplog records every executed opcode, so it must be drained in chunks
 * — only the per-file covered-line sets are kept.
 */
$oplog = array();
$chunk = 25;
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

$executable = phpdbg_get_executable( array( 'files' => $targets ) );

$total_exec    = 0;
$total_covered = 0;

foreach ( $targets as $file ) {
	$exec_lines    = array_keys( $executable[ $file ] ?? array() );
	$covered_lines = array_keys( $oplog[ $file ] ?? array() );
	$covered_lines = array_intersect( $covered_lines, $exec_lines );
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
