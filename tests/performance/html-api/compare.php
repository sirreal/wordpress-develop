<?php
/**
 * Compares two saved HTML API benchmark results.
 *
 * Usage: php compare.php <base.json> <head.json> [--format table|markdown|json] [--seed <int>]
 *
 * Both files come from `bench.php --save`. The `head` tree of each file is
 * used, so a single-tree result compares directly; an A/B result contributes
 * its head tree only. This comparison is weaker than a live A/B run: the two
 * runs were not interleaved, so machine drift between them is not cancelled
 * and per-process speed offsets are not shared. Prefer `bench.php --base`.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

if ( PHP_VERSION_ID < 80100 ) {
	fwrite( STDERR, "compare.php requires PHP 8.1 or newer.\n" );
	exit( 2 );
}

require_once __DIR__ . '/lib/class-benchmark-stats.php';
require_once __DIR__ . '/lib/class-benchmark-report.php';

/**
 * Prints a message to stderr and exits.
 *
 * @param string $message Message.
 */
function compare_fail( string $message ): void {
	fwrite( STDERR, "compare.php: {$message}\n" );
	exit( 1 );
}

/**
 * Loads a saved result file.
 *
 * @param string $path File path.
 * @return array Result structure.
 */
function compare_load( string $path ): array {
	$json = file_get_contents( $path );
	if ( false === $json ) {
		compare_fail( "Could not read {$path}" );
	}
	$result = json_decode( $json, true );
	if ( ! is_array( $result ) || ( $result['tool'] ?? '' ) !== 'html-api-benchmark' || ! isset( $result['results'], $result['trees']['head'] ) ) {
		compare_fail( "Not a bench.php --save file: {$path}" );
	}
	return $result;
}

$files  = array();
$format = 'table';
$seed   = null;
$count  = count( $argv );
for ( $i = 1; $i < $count; $i++ ) {
	$arg = $argv[ $i ];
	if ( '--format' === $arg || 0 === strpos( $arg, '--format=' ) ) {
		$format = '--format' === $arg ? ( $argv[ ++$i ] ?? '' ) : substr( $arg, 9 );
		continue;
	}
	if ( '--seed' === $arg || 0 === strpos( $arg, '--seed=' ) ) {
		$seed = (int) ( '--seed' === $arg ? ( $argv[ ++$i ] ?? 1 ) : substr( $arg, 7 ) );
		continue;
	}
	if ( '--help' === $arg ) {
		echo "Usage: php compare.php <base.json> <head.json> [--format table|markdown|json] [--seed <int>]\n";
		exit( 0 );
	}
	if ( 0 === strpos( $arg, '--' ) ) {
		compare_fail( "Unknown option: {$arg}" );
	}
	$files[] = $arg;
}
if ( 2 !== count( $files ) ) {
	compare_fail( 'Usage: php compare.php <base.json> <head.json> [--format table|markdown|json] [--seed <int>]' );
}
if ( ! in_array( $format, array( 'table', 'markdown', 'json' ), true ) ) {
	compare_fail( '--format must be table, markdown, or json' );
}

$base_result = compare_load( $files[0] );
$head_result = compare_load( $files[1] );
if ( null === $seed ) {
	$seed = (int) ( $head_result['config']['seed'] ?? 1 );
}

$notes   = array();
$notes[] = 'Comparison of two saved runs, not an interleaved A/B: drift between the runs is not cancelled and the two runs did not share a process pair. Prefer bench.php --base <checkout>.';
$notes[] = "base file: {$files[0]}; head file: {$files[1]}; the head tree of each file is used.";

foreach ( array( 'php', 'opcache', 'jit' ) as $key ) {
	if ( ( $base_result['trees']['head'][ $key ] ?? null ) !== ( $head_result['trees']['head'][ $key ] ?? null ) ) {
		$notes[] = sprintf( 'The runs differ in %s: base %s, head %s.', $key, json_encode( $base_result['trees']['head'][ $key ] ?? null ), json_encode( $head_result['trees']['head'][ $key ] ?? null ) );
	}
}
foreach ( array( 'samples', 'min_sample_ms', 'warmup', 'rounds', 'synthetic_size' ) as $key ) {
	if ( ( $base_result['config'][ $key ] ?? null ) !== ( $head_result['config'][ $key ] ?? null ) ) {
		$notes[] = sprintf( 'The runs differ in %s: base %s, head %s.', $key, json_encode( $base_result['config'][ $key ] ?? null ), json_encode( $head_result['config'][ $key ] ?? null ) );
	}
}

$base_rows = array();
foreach ( $base_result['results'] as $row ) {
	$base_rows[ "{$row['id']}\0{$row['parser']}" ] = $row;
}

$results   = array();
$unmatched = array();
foreach ( $head_result['results'] as $head_row ) {
	$key = "{$head_row['id']}\0{$head_row['parser']}";
	if ( ! isset( $base_rows[ $key ] ) ) {
		$unmatched[] = "{$head_row['id']} ({$head_row['parser']}) only in head";
		continue;
	}
	$base_row = $base_rows[ $key ];
	unset( $base_rows[ $key ] );

	if ( $base_row['bytes'] !== $head_row['bytes'] ) {
		$unmatched[] = "{$head_row['id']} ({$head_row['parser']}) differs in size: base {$base_row['bytes']}, head {$head_row['bytes']}";
		continue;
	}

	$base_tree = $base_row['trees']['head'];
	$head_tree = $head_row['trees']['head'];
	$base_data = $base_tree['rounds'] ?? array( $base_tree['samples_ns'] );
	$head_data = $head_tree['rounds'] ?? array( $head_tree['samples_ns'] );
	if ( count( $base_data ) !== count( $head_data ) ) {
		// Different round counts: pool each side's samples as one round.
		$base_data = array( $base_tree['samples_ns'] );
		$head_data = array( $head_tree['samples_ns'] );
	}

	$results[] = array(
		'id'         => $head_row['id'],
		'parser'     => $head_row['parser'],
		'bytes'      => $head_row['bytes'],
		'iterations' => array(
			'base' => $base_row['iterations'],
			'head' => $head_row['iterations'],
		),
		'trees'      => array(
			'base' => $base_tree,
			'head' => $head_tree,
		),
		'comparison' => Benchmark_Stats::compare( $head_data, $base_data, $seed ),
	);
}
foreach ( $base_rows as $row ) {
	$unmatched[] = "{$row['id']} ({$row['parser']}) only in base";
}
if ( ! empty( $unmatched ) ) {
	$notes[] = 'Not compared: ' . implode( '; ', $unmatched ) . '.';
}
if ( empty( $results ) ) {
	compare_fail( 'No (document, parser) pairs in common.' );
}

$summary = array();
foreach ( array( 'tag', 'html' ) as $parser ) {
	$rows = array_filter(
		$results,
		static function ( $row ) use ( $parser ) {
			return $row['parser'] === $parser;
		}
	);
	if ( empty( $rows ) ) {
		continue;
	}
	$bailed     = array();
	$completed  = array();
	$throughput = array(
		'base' => array( 0, 0.0 ),
		'head' => array( 0, 0.0 ),
	);
	foreach ( $rows as $row ) {
		$row_bailed = false;
		foreach ( array( 'base', 'head' ) as $label ) {
			if ( null !== $row['trees'][ $label ]['bailed'] ) {
				$row_bailed = true;
				$bailed[]   = array(
					'id'     => $row['id'],
					'tree'   => $label,
					'reason' => $row['trees'][ $label ]['bailed'],
				);
			}
		}
		if ( $row_bailed ) {
			continue;
		}
		$head_rounds = $row['trees']['head']['rounds'] ?? array( $row['trees']['head']['samples_ns'] );
		$base_rounds = $row['trees']['base']['rounds'] ?? array( $row['trees']['base']['samples_ns'] );
		if ( count( $head_rounds ) !== count( $base_rounds ) ) {
			$head_rounds = array( $row['trees']['head']['samples_ns'] );
			$base_rounds = array( $row['trees']['base']['samples_ns'] );
		}
		$completed[] = array(
			'head' => $head_rounds,
			'base' => $base_rounds,
		);
		foreach ( array( 'base', 'head' ) as $label ) {
			$throughput[ $label ][0] += $row['bytes'];
			$throughput[ $label ][1] += $row['trees'][ $label ]['stats']['median_ns'];
		}
	}
	$summary[ $parser ] = array(
		'overall'    => Benchmark_Stats::geomean_ratio( $completed, $seed ),
		'throughput' => array(
			'base' => $throughput['base'][1] > 0 ? ( $throughput['base'][0] * 1000.0 ) / $throughput['base'][1] : 0.0,
			'head' => $throughput['head'][1] > 0 ? ( $throughput['head'][0] * 1000.0 ) / $throughput['head'][1] : 0.0,
		),
		'bailed'     => $bailed,
		'documents'  => count( $rows ),
		'completed'  => count( $completed ),
	);
}

$config         = $head_result['config'];
$config['seed'] = $seed;

$result = array(
	'tool'      => 'html-api-benchmark',
	'version'   => 1,
	'generated' => gmdate( 'Y-m-d\TH:i:s\Z' ),
	'mode'      => 'compare-saved',
	'config'    => $config,
	'trees'     => array(
		'base' => $base_result['trees']['head'] + array( 'file' => $files[0] ),
		'head' => $head_result['trees']['head'] + array( 'file' => $files[1] ),
	),
	'documents' => $head_result['documents'] ?? array(),
	'results'   => $results,
	'summary'   => $summary,
	'notes'     => $notes,
);

echo Benchmark_Report::render( $result, $format );
