<?php
/**
 * Statistics for the HTML API parsing benchmark.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

/**
 * Median, bootstrap confidence intervals, geometric mean, and number formatting.
 *
 * Every bootstrap is deterministic for a given seed: it reseeds the Mersenne
 * Twister with `mt_srand( $seed, MT_RAND_MT19937 )` before resampling.
 */
class Benchmark_Stats {
	/**
	 * Number of bootstrap resamples.
	 */
	const RESAMPLES = 2000;

	/**
	 * Median of a list of numbers.
	 *
	 * @param float[] $values Non-empty list.
	 * @return float
	 */
	public static function median( array $values ): float {
		$values = array_values( $values );
		sort( $values );
		$count = count( $values );
		if ( 0 === $count ) {
			return NAN;
		}
		$middle = intdiv( $count, 2 );
		if ( 0 === $count % 2 ) {
			return ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2;
		}
		return (float) $values[ $middle ];
	}

	/**
	 * Summary statistics of one list of per-parse timings.
	 *
	 * @param float[] $samples_ns Nanoseconds per parse, one entry per sample.
	 * @param int     $bytes      Document size in bytes.
	 * @param int     $tokens     Tokens per parse.
	 * @return array{n:int, median_ns:float, min_ns:float, mean_ns:float, stddev_ns:float, cv:float, mb_per_s:float, tokens_per_s:float}
	 */
	public static function summarize( array $samples_ns, int $bytes, int $tokens ): array {
		$count  = count( $samples_ns );
		$median = self::median( $samples_ns );
		$mean   = array_sum( $samples_ns ) / max( 1, $count );
		$sumsq  = 0.0;
		foreach ( $samples_ns as $sample ) {
			$sumsq += ( $sample - $mean ) * ( $sample - $mean );
		}
		$stddev = $count > 1 ? sqrt( $sumsq / ( $count - 1 ) ) : 0.0;

		return array(
			'n'            => $count,
			'median_ns'    => $median,
			'min_ns'       => (float) min( $samples_ns ),
			'mean_ns'      => $mean,
			'stddev_ns'    => $stddev,
			'cv'           => $mean > 0 ? $stddev / $mean : 0.0,
			'mb_per_s'     => $median > 0 ? ( $bytes * 1000.0 ) / $median : 0.0,
			'tokens_per_s' => $median > 0 ? ( $tokens * 1e9 ) / $median : 0.0,
		);
	}

	/**
	 * Draws a resample with replacement.
	 *
	 * @param float[] $values Non-empty list.
	 * @return float[] Same length as the input.
	 */
	private static function resample( array $values ): array {
		$count = count( $values );
		$last  = $count - 1;
		$out   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = $values[ mt_rand( 0, $last ) ];
		}
		return $out;
	}

	/**
	 * Percentile of a sorted list by nearest-rank interpolation.
	 *
	 * @param float[] $sorted Sorted ascending.
	 * @param float   $p      Percentile in [0, 1].
	 * @return float
	 */
	private static function percentile( array $sorted, float $p ): float {
		$count = count( $sorted );
		if ( 0 === $count ) {
			return NAN;
		}
		$rank  = $p * ( $count - 1 );
		$lower = (int) floor( $rank );
		$upper = (int) ceil( $rank );
		if ( $lower === $upper ) {
			return $sorted[ $lower ];
		}
		$weight = $rank - $lower;
		return $sorted[ $lower ] * ( 1 - $weight ) + $sorted[ $upper ] * $weight;
	}

	/**
	 * Verdict from a ratio confidence interval.
	 *
	 * @param float $low  Lower bound of the ratio CI.
	 * @param float $high Upper bound of the ratio CI.
	 * @return string 'faster', 'slower', or 'no difference'.
	 */
	public static function verdict( float $low, float $high ): string {
		if ( $high < 1.0 ) {
			return 'faster';
		}
		if ( $low > 1.0 ) {
			return 'slower';
		}
		return 'no difference';
	}

	/**
	 * Normalizes timings to a list of rounds.
	 *
	 * A flat list of numbers is one round; a list of lists is one entry per round.
	 *
	 * @param array $samples Flat list or list of lists.
	 * @return float[][] Rounds.
	 */
	private static function rounds( array $samples ): array {
		$samples = array_values( $samples );
		if ( empty( $samples ) || ! is_array( $samples[0] ) ) {
			return array( $samples );
		}
		return array_map( 'array_values', $samples );
	}

	/**
	 * Point estimate of the head/base ratio: geometric mean over rounds of the ratio of medians.
	 *
	 * @param float[][] $head_rounds Head timings per round.
	 * @param float[][] $base_rounds Base timings per round, same length.
	 * @return float
	 */
	private static function round_ratio( array $head_rounds, array $base_rounds ): float {
		$count   = count( $head_rounds );
		$log_sum = 0.0;
		for ( $r = 0; $r < $count; $r++ ) {
			$log_sum += log( self::median( $head_rounds[ $r ] ) / self::median( $base_rounds[ $r ] ) );
		}
		return exp( $log_sum / $count );
	}

	/**
	 * One bootstrap draw of the head/base ratio for one document.
	 *
	 * Rounds are drawn with replacement as head/base pairs (the same round index
	 * on both trees shares a process pair and a time window); within each drawn
	 * round, head and base samples are drawn with replacement.
	 *
	 * @param float[][] $head_rounds Head timings per round.
	 * @param float[][] $base_rounds Base timings per round, same length.
	 * @return float
	 */
	private static function draw_ratio( array $head_rounds, array $base_rounds ): float {
		$count   = count( $head_rounds );
		$last    = $count - 1;
		$log_sum = 0.0;
		for ( $r = 0; $r < $count; $r++ ) {
			$pick     = 0 === $last ? 0 : mt_rand( 0, $last );
			$log_sum += log( self::median( self::resample( $head_rounds[ $pick ] ) ) / self::median( self::resample( $base_rounds[ $pick ] ) ) );
		}
		return exp( $log_sum / $count );
	}

	/**
	 * Two-sided 97.5% quantile of Student's t for a number of degrees of freedom.
	 *
	 * @param int $df Degrees of freedom, at least 1.
	 * @return float
	 */
	private static function t_975( int $df ): float {
		static $table = array(
			1   => 12.706,
			2   => 4.303,
			3   => 3.182,
			4   => 2.776,
			5   => 2.571,
			6   => 2.447,
			7   => 2.365,
			8   => 2.306,
			9   => 2.262,
			10  => 2.228,
			11  => 2.201,
			12  => 2.179,
			13  => 2.160,
			14  => 2.145,
			15  => 2.131,
			16  => 2.120,
			17  => 2.110,
			18  => 2.101,
			19  => 2.093,
			20  => 2.086,
			21  => 2.080,
			22  => 2.074,
			23  => 2.069,
			24  => 2.064,
			25  => 2.060,
			26  => 2.056,
			27  => 2.052,
			28  => 2.048,
			29  => 2.045,
			30  => 2.042,
			40  => 2.021,
			60  => 2.000,
			120 => 1.980,
		);
		if ( isset( $table[ $df ] ) ) {
			return $table[ $df ];
		}
		if ( $df > 120 ) {
			return 1.960;
		}
		// Linear interpolation between the table rows above 30.
		$lower = 30;
		foreach ( array( 40, 60, 120 ) as $upper ) {
			if ( $df < $upper ) {
				return $table[ $lower ] + ( $table[ $upper ] - $table[ $lower ] ) * ( $df - $lower ) / ( $upper - $lower );
			}
			$lower = $upper;
		}
		return 1.960;
	}

	/**
	 * 95% t-interval on the geometric mean of independent ratios.
	 *
	 * @param float[] $ratios At least two ratios.
	 * @return array{point:float, low:float, high:float}
	 */
	private static function t_interval( array $ratios ): array {
		$logs  = array_map( 'log', array_values( $ratios ) );
		$count = count( $logs );
		$mean  = array_sum( $logs ) / $count;
		$sumsq = 0.0;
		foreach ( $logs as $value ) {
			$sumsq += ( $value - $mean ) * ( $value - $mean );
		}
		$stderr = sqrt( $sumsq / ( $count - 1 ) ) / sqrt( $count );
		$half   = self::t_975( $count - 1 ) * $stderr;
		return array(
			'point' => exp( $mean ),
			'low'   => exp( $mean - $half ),
			'high'  => exp( $mean + $half ),
		);
	}

	/**
	 * Ratio of medians (head / base) with a bootstrap percentile 95% CI.
	 *
	 * With one round this is the ratio of the two medians and a bootstrap over
	 * samples. With several rounds (a fresh worker pair each) the point estimate
	 * is the geometric mean of the per-round ratios and the bootstrap resamples
	 * rounds first, then samples within each round.
	 *
	 * @param array $head_samples Head timings: a flat list, or one list per round.
	 * @param array $base_samples Base timings in the same shape.
	 * @param int   $seed         Bootstrap seed.
	 * @return array{ratio:float, change_pct:float, ci_low:float, ci_high:float, verdict:string, rounds:int, resamples:int}
	 */
	public static function compare( array $head_samples, array $base_samples, int $seed ): array {
		$head_rounds = self::rounds( $head_samples );
		$base_rounds = self::rounds( $base_samples );
		if ( count( $head_rounds ) !== count( $base_rounds ) ) {
			throw new InvalidArgumentException( 'head and base must have the same number of rounds' );
		}
		$count = count( $head_rounds );

		if ( $count >= 2 ) {
			$ratios = array();
			for ( $r = 0; $r < $count; $r++ ) {
				$ratios[] = self::median( $head_rounds[ $r ] ) / self::median( $base_rounds[ $r ] );
			}
			$interval = self::t_interval( $ratios );
			return array(
				'ratio'        => $interval['point'],
				'change_pct'   => ( $interval['point'] - 1.0 ) * 100.0,
				'ci_low'       => $interval['low'],
				'ci_high'      => $interval['high'],
				'verdict'      => self::verdict( $interval['low'], $interval['high'] ),
				'method'       => 't-interval over rounds',
				'rounds'       => $count,
				'round_ratios' => $ratios,
			);
		}

		$ratio = self::round_ratio( $head_rounds, $base_rounds );

		mt_srand( $seed, MT_RAND_MT19937 );
		$ratios = array();
		for ( $b = 0; $b < self::RESAMPLES; $b++ ) {
			$ratios[] = self::draw_ratio( $head_rounds, $base_rounds );
		}
		sort( $ratios );
		$low  = self::percentile( $ratios, 0.025 );
		$high = self::percentile( $ratios, 0.975 );

		return array(
			'ratio'      => $ratio,
			'change_pct' => ( $ratio - 1.0 ) * 100.0,
			'ci_low'     => $low,
			'ci_high'    => $high,
			'verdict'    => self::verdict( $low, $high ),
			'method'     => 'bootstrap over samples',
			'rounds'     => 1,
			'resamples'  => self::RESAMPLES,
		);
	}

	/**
	 * Geometric mean of per-document ratios with a bootstrap 95% CI.
	 *
	 * Each resample draws documents with replacement, and within each drawn
	 * document draws rounds and then samples with replacement, as compare() does.
	 *
	 * @param array<array{head:array, base:array}> $documents Per-document head and base timings (flat or per round).
	 * @param int                                   $seed      Bootstrap seed.
	 * @return array{geomean:float, change_pct:float, ci_low:float, ci_high:float, verdict:string, documents:int, resamples:int}|null Null when no documents.
	 */
	public static function geomean_ratio( array $documents, int $seed ): ?array {
		$documents = array_values( $documents );
		$count     = count( $documents );
		if ( 0 === $count ) {
			return null;
		}

		$pairs = array();
		foreach ( $documents as $document ) {
			$pairs[] = array( self::rounds( $document['head'] ), self::rounds( $document['base'] ) );
		}
		$round_count = count( $pairs[0][0] );

		if ( $round_count >= 2 ) {
			// Per round, the geometric mean over documents; then a t-interval over rounds.
			$round_geomeans = array();
			for ( $r = 0; $r < $round_count; $r++ ) {
				$log_sum = 0.0;
				foreach ( $pairs as $pair ) {
					$log_sum += log( self::median( $pair[0][ $r ] ) / self::median( $pair[1][ $r ] ) );
				}
				$round_geomeans[] = exp( $log_sum / $count );
			}
			$interval = self::t_interval( $round_geomeans );
			return array(
				'geomean'        => $interval['point'],
				'change_pct'     => ( $interval['point'] - 1.0 ) * 100.0,
				'ci_low'         => $interval['low'],
				'ci_high'        => $interval['high'],
				'verdict'        => self::verdict( $interval['low'], $interval['high'] ),
				'method'         => 't-interval over rounds',
				'documents'      => $count,
				'rounds'         => $round_count,
				'round_geomeans' => $round_geomeans,
			);
		}

		$log_sum = 0.0;
		foreach ( $pairs as $pair ) {
			$log_sum += log( self::round_ratio( $pair[0], $pair[1] ) );
		}
		$geomean = exp( $log_sum / $count );

		mt_srand( $seed, MT_RAND_MT19937 );
		$geomeans = array();
		$last     = $count - 1;
		for ( $b = 0; $b < self::RESAMPLES; $b++ ) {
			$log_sum = 0.0;
			for ( $d = 0; $d < $count; $d++ ) {
				$pair     = $pairs[ mt_rand( 0, $last ) ];
				$log_sum += log( self::draw_ratio( $pair[0], $pair[1] ) );
			}
			$geomeans[] = exp( $log_sum / $count );
		}
		sort( $geomeans );
		$low  = self::percentile( $geomeans, 0.025 );
		$high = self::percentile( $geomeans, 0.975 );

		return array(
			'geomean'    => $geomean,
			'change_pct' => ( $geomean - 1.0 ) * 100.0,
			'ci_low'     => $low,
			'ci_high'    => $high,
			'verdict'    => self::verdict( $low, $high ),
			'method'     => 'bootstrap over documents and samples',
			'documents'  => $count,
			'rounds'     => 1,
			'resamples'  => self::RESAMPLES,
		);
	}

	/**
	 * Formats nanoseconds as milliseconds.
	 *
	 * @param float $ns Nanoseconds.
	 * @return string
	 */
	public static function format_ms( float $ns ): string {
		$ms = $ns / 1e6;
		if ( $ms < 0.1 ) {
			return sprintf( '%.4f', $ms );
		}
		if ( $ms < 10 ) {
			return sprintf( '%.3f', $ms );
		}
		return sprintf( '%.2f', $ms );
	}

	/**
	 * Formats a signed percentage.
	 *
	 * @param float $pct Percent.
	 * @return string
	 */
	public static function format_pct( float $pct ): string {
		return sprintf( '%+.1f%%', $pct );
	}

	/**
	 * Formats a ratio CI as a percent-change range.
	 *
	 * @param float $low  Lower ratio.
	 * @param float $high Upper ratio.
	 * @return string
	 */
	public static function format_ci( float $low, float $high ): string {
		return sprintf( '[%s, %s]', self::format_pct( ( $low - 1.0 ) * 100.0 ), self::format_pct( ( $high - 1.0 ) * 100.0 ) );
	}

	/**
	 * Formats a coefficient of variation as a percentage.
	 *
	 * @param float $cv Ratio.
	 * @return string
	 */
	public static function format_cv( float $cv ): string {
		return sprintf( '%.1f%%', $cv * 100.0 );
	}

	/**
	 * Formats an integer with thousands separators.
	 *
	 * @param int|float $number Number.
	 * @return string
	 */
	public static function format_int( $number ): string {
		return number_format( (float) $number, 0, '.', ',' );
	}

	/**
	 * Formats a rate with one decimal and thousands separators.
	 *
	 * @param float $rate Rate.
	 * @return string
	 */
	public static function format_rate( float $rate ): string {
		return number_format( $rate, 1, '.', ',' );
	}

	/**
	 * Formats bytes as megabytes.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function format_mb( int $bytes ): string {
		return sprintf( '%.1f', $bytes / 1048576 );
	}
}
