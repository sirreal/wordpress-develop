<?php
/**
 * Report rendering for the HTML API parsing benchmark.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

/**
 * Renders a benchmark result structure as a text table, markdown, or JSON.
 *
 * The result structure is the one bench.php saves with `--save`; see README.md.
 */
class Benchmark_Report {
	/**
	 * Human names for parser ids.
	 */
	const PARSER_NAMES = array(
		'tag'  => 'WP_HTML_Tag_Processor',
		'html' => 'WP_HTML_Processor',
	);

	/**
	 * CV above which a row is marked noisy.
	 */
	const NOISY_CV = 0.10;

	/**
	 * Renders the result in the requested format.
	 *
	 * @param array  $result Result structure.
	 * @param string $format 'table', 'markdown', or 'json'.
	 * @return string
	 */
	public static function render( array $result, string $format ): string {
		if ( 'json' === $format ) {
			return json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		}
		$markdown = 'markdown' === $format;
		$out      = self::render_header( $result, $markdown );

		foreach ( array( 'tag', 'html' ) as $parser ) {
			$rows = array_values(
				array_filter(
					$result['results'],
					static function ( $row ) use ( $parser ) {
						return $row['parser'] === $parser;
					}
				)
			);
			if ( empty( $rows ) ) {
				continue;
			}
			$out .= "\n";
			$out .= self::heading( self::PARSER_NAMES[ $parser ] ?? $parser, $markdown );
			if ( 'single' === $result['mode'] ) {
				$out .= self::render_single_table( $rows, $markdown );
			} else {
				$out .= self::render_ab_table( $rows, $markdown );
			}
			$out .= self::render_summary( $result['summary'][ $parser ] ?? array(), $result['mode'], $markdown );
		}

		return $out;
	}

	/**
	 * Renders the report header.
	 *
	 * @param array $result   Result structure.
	 * @param bool  $markdown Whether to render markdown.
	 * @return string
	 */
	private static function render_header( array $result, bool $markdown ): string {
		$config = $result['config'];
		$lines  = array();

		$lines[] = 'HTML API parsing benchmark';
		$lines[] = 'Date: ' . $result['generated'];
		foreach ( array( 'base', 'head' ) as $tree ) {
			if ( empty( $result['trees'][ $tree ] ) ) {
				continue;
			}
			$info    = $result['trees'][ $tree ];
			$lines[] = sprintf(
				'%s: %s (%s), PHP %s, opcache %s, jit %s',
				$tree,
				$info['checkout'],
				$info['head'] ?? 'no git head',
				$info['php'],
				$info['opcache'] ? 'on' : 'off',
				$info['jit'] ?? 'off'
			);
		}
		$lines[] = sprintf(
			'Samples: %d per document, parser and tree; min sample %d ms; warmup %d; rounds %d (a fresh worker pair each); seed %d; bootstrap resamples %d',
			$config['samples'],
			$config['min_sample_ms'],
			$config['warmup'],
			$config['rounds'] ?? 1,
			$config['seed'],
			Benchmark_Stats::RESAMPLES
		);
		if ( ! empty( $config['php_args'] ) ) {
			$lines[] = 'Extra PHP args: ' . $config['php_args'];
		}
		foreach ( $result['notes'] ?? array() as $note ) {
			$lines[] = 'Note: ' . $note;
		}

		if ( $markdown ) {
			$first = array_shift( $lines );
			return "# {$first}\n\n" . implode( "  \n", $lines ) . "\n";
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Renders a section heading.
	 *
	 * @param string $text     Heading.
	 * @param bool   $markdown Whether to render markdown.
	 * @return string
	 */
	private static function heading( string $text, bool $markdown ): string {
		return $markdown ? "## {$text}\n\n" : "{$text}\n\n";
	}

	/**
	 * Renders the A/B table.
	 *
	 * @param array $rows     Result rows for one parser.
	 * @param bool  $markdown Whether to render markdown.
	 * @return string
	 */
	private static function render_ab_table( array $rows, bool $markdown ): string {
		$header = array( 'document', 'bytes', 'base ms', 'head ms', 'change', '95% CI', 'verdict', 'CV base', 'CV head' );
		$align  = array( 'l', 'r', 'r', 'r', 'r', 'r', 'l', 'r', 'r' );
		$table  = array();
		foreach ( $rows as $row ) {
			$base = $row['trees']['base'];
			$head = $row['trees']['head'];
			$cmp  = $row['comparison'];
			$note = '';
			if ( null !== $base['bailed'] || null !== $head['bailed'] ) {
				$note .= ' (bailed)';
			}
			if ( $base['stats']['cv'] > self::NOISY_CV || $head['stats']['cv'] > self::NOISY_CV ) {
				$note .= ' (noisy)';
			}
			$table[] = array(
				$row['id'],
				Benchmark_Stats::format_int( $row['bytes'] ),
				Benchmark_Stats::format_ms( $base['stats']['round_mean_median_ns'] ?? $base['stats']['median_ns'] ),
				Benchmark_Stats::format_ms( $head['stats']['round_mean_median_ns'] ?? $head['stats']['median_ns'] ),
				Benchmark_Stats::format_pct( $cmp['change_pct'] ),
				Benchmark_Stats::format_ci( $cmp['ci_low'], $cmp['ci_high'] ),
				$cmp['verdict'] . $note,
				Benchmark_Stats::format_cv( $base['stats']['cv'] ),
				Benchmark_Stats::format_cv( $head['stats']['cv'] ),
			);
		}
		return self::table( $header, $align, $table, $markdown );
	}

	/**
	 * Renders the single-tree table.
	 *
	 * @param array $rows     Result rows for one parser.
	 * @param bool  $markdown Whether to render markdown.
	 * @return string
	 */
	private static function render_single_table( array $rows, bool $markdown ): string {
		$header = array( 'document', 'bytes', 'tokens', 'median ms', 'MB/s', 'tokens/s', 'CV', 'peak MB' );
		// peak MB is the emalloc peak above the pre-run level; the 2 MB-chunk system peak is in the JSON as peak_bytes.
		$align = array( 'l', 'r', 'r', 'r', 'r', 'r', 'r', 'r' );
		$table = array();
		foreach ( $rows as $row ) {
			$head    = $row['trees']['head'];
			$table[] = array(
				$row['id'] . ( null !== $head['bailed'] ? ' (bailed)' : '' ) . ( $head['stats']['cv'] > self::NOISY_CV ? ' (noisy)' : '' ),
				Benchmark_Stats::format_int( $row['bytes'] ),
				Benchmark_Stats::format_int( $head['tokens'] ),
				Benchmark_Stats::format_ms( $head['stats']['median_ns'] ),
				Benchmark_Stats::format_rate( $head['stats']['mb_per_s'] ),
				Benchmark_Stats::format_int( $head['stats']['tokens_per_s'] ),
				Benchmark_Stats::format_cv( $head['stats']['cv'] ),
				Benchmark_Stats::format_mb( $head['peak_alloc_bytes'] ?? $head['peak_bytes'] ),
			);
		}
		return self::table( $header, $align, $table, $markdown );
	}

	/**
	 * Renders the per-parser summary lines and bailed list.
	 *
	 * @param array  $summary  Summary for one parser.
	 * @param string $mode     Result mode.
	 * @param bool   $markdown Whether to render markdown.
	 * @return string
	 */
	private static function render_summary( array $summary, string $mode, bool $markdown ): string {
		$lines = array();

		if ( 'single' !== $mode ) {
			$overall = $summary['overall'] ?? null;
			if ( null === $overall ) {
				$lines[] = 'Overall: no documents completed on both trees.';
			} else {
				$lines[] = sprintf(
					'Overall: geomean ratio head/base %.4f (%s), 95%% CI %s, %s, over %d documents (%s).',
					$overall['geomean'],
					Benchmark_Stats::format_pct( $overall['change_pct'] ),
					Benchmark_Stats::format_ci( $overall['ci_low'], $overall['ci_high'] ),
					$overall['verdict'],
					$overall['documents'],
					$overall['method'] ?? 'bootstrap'
				);
			}
		}

		$throughput = $summary['throughput'] ?? array();
		$parts      = array();
		foreach ( array( 'base', 'head' ) as $tree ) {
			if ( isset( $throughput[ $tree ] ) ) {
				$parts[] = sprintf( '%s %s MB/s', $tree, Benchmark_Stats::format_rate( $throughput[ $tree ] ) );
			}
		}
		if ( ! empty( $parts ) ) {
			$lines[] = 'Throughput (sum of bytes / sum of median ns): ' . implode( ', ', $parts ) . '.';
		}

		$bailed = $summary['bailed'] ?? array();
		if ( ! empty( $bailed ) ) {
			$lines[] = 'Bailed (excluded from the aggregate):';
			foreach ( $bailed as $entry ) {
				$lines[] = sprintf( '  %s on %s: %s', $entry['id'], $entry['tree'], $entry['reason'] );
			}
		}

		if ( $markdown ) {
			return "\n" . implode( "  \n", $lines ) . "\n";
		}
		return "\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * Renders an aligned text table or a markdown table.
	 *
	 * @param string[]   $header   Column headings.
	 * @param string[]   $align    'l' or 'r' per column.
	 * @param string[][] $rows     Cell strings.
	 * @param bool       $markdown Whether to render markdown.
	 * @return string
	 */
	private static function table( array $header, array $align, array $rows, bool $markdown ): string {
		$widths = array_map( 'strlen', $header );
		foreach ( $rows as $row ) {
			foreach ( $row as $i => $cell ) {
				$widths[ $i ] = max( $widths[ $i ], strlen( $cell ) );
			}
		}

		$format_row = static function ( array $cells ) use ( $widths, $align, $markdown ): string {
			$out = array();
			foreach ( $cells as $i => $cell ) {
				$pad   = $widths[ $i ] - strlen( $cell );
				$out[] = 'r' === $align[ $i ] ? str_repeat( ' ', $pad ) . $cell : $cell . str_repeat( ' ', $pad );
			}
			return $markdown ? '| ' . implode( ' | ', $out ) . ' |' : implode( '  ', $out );
		};

		$lines   = array();
		$lines[] = $format_row( $header );
		if ( $markdown ) {
			$rule = array();
			foreach ( $widths as $i => $width ) {
				$rule[] = 'r' === $align[ $i ] ? str_repeat( '-', $width - 1 ) . ':' : str_repeat( '-', $width );
			}
			$lines[] = '| ' . implode( ' | ', $rule ) . ' |';
		} else {
			$rule = array();
			foreach ( $widths as $width ) {
				$rule[] = str_repeat( '-', $width );
			}
			$lines[] = implode( '  ', $rule );
		}
		foreach ( $rows as $row ) {
			$lines[] = $format_row( $row );
		}
		return implode( "\n", $lines ) . "\n";
	}
}
