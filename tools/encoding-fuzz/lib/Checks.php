<?php
namespace EncodingFuzz;

/**
 * Runs every differential and invariant check against one input.
 *
 * Differentials (against known-good oracles):
 *  - `wp_is_valid_utf8()` and `_wp_is_valid_utf8_fallback()` vs every
 *    validity oracle (mb, pcre, python3, node).
 *  - `wp_scrub_utf8()` and `_wp_scrub_utf8_fallback()` vs every scrub
 *    oracle (mb, intl, python3, node).
 *
 * Internal invariants (true by definition of the API):
 *  - valid ⟺ scrub returns the input unchanged
 *  - scrub output is always valid UTF-8
 *  - scrub is idempotent
 *  - `_wp_utf8_codepoint_count()` equals `mb_strlen()` of the scrubbed
 *    text (each maximal subpart counts as one code point)
 *  - scanning with `_wp_scan_utf8()` in pseudo-random `max_code_points`
 *    chunks reconstructs the same scrubbed text and always makes
 *    forward progress
 *
 * Target callables are injectable so the harness smoke test can verify
 * that deliberately broken implementations are caught.
 */
class Checks {
	public const PREVIEW_BYTES = 48;

	private Oracles $oracles;

	/** @var array<string, callable> */
	private array $targets;

	public function __construct( Oracles $oracles, ?array $targets = null ) {
		$this->oracles = $oracles;
		$this->targets = $targets ?? Targets::resolve();
	}

	/**
	 * @return array<int, array{check: string, signature: string, detail: array}> Failures; empty when all checks pass.
	 */
	public function run( string $input ): array {
		$failures = array();

		// Reference values from the primary oracle.
		$mb_validity = $this->oracles->validity_oracles()['mb'] ?? null;
		$mb_scrubber = $this->oracles->scrub_oracles()['mb'] ?? null;
		if ( null === $mb_validity || null === $mb_scrubber ) {
			return array( self::failure( 'harness-error', 'harness', array( 'reason' => 'mb oracle unavailable' ) ) );
		}

		$ref_valid = $mb_validity( $input );
		$ref_scrub = $mb_scrubber( $input );

		// Target executions, guarded against exceptions.
		$results = array();
		foreach ( array( 'is_valid', 'is_valid_fb', 'scrub', 'scrub_fb' ) as $key ) {
			try {
				$results[ $key ] = ( $this->targets[ $key ] )( $input );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'target-exception',
					$key,
					array(
						'target'  => $key,
						'message' => $error->getMessage(),
						'class'   => get_class( $error ),
					)
				);
				$results[ $key ] = null;
			}
		}

		// 1. Validity differential.
		foreach ( array( 'is_valid', 'is_valid_fb' ) as $key ) {
			if ( null !== $results[ $key ] && $results[ $key ] !== $ref_valid ) {
				$failures[] = self::failure(
					'validity-mismatch',
					$key,
					array(
						'target'   => $key,
						'got'      => $results[ $key ],
						'expected' => $ref_valid,
						'oracle'   => 'mb',
					)
				);
			}
		}

		foreach ( $this->oracles->validity_oracles() as $name => $oracle ) {
			if ( 'mb' === $name ) {
				continue;
			}

			$oracle_valid = $oracle( $input );
			if ( null === $oracle_valid ) {
				$this->oracles->disable( $name, 'transport failure during case' );
				continue;
			}

			if ( $oracle_valid !== $ref_valid ) {
				$failures[] = self::failure(
					'oracle-disagreement',
					"validity:{$name}",
					array(
						'kind'     => 'validity',
						'oracle'   => $name,
						'got'      => $oracle_valid,
						'expected' => $ref_valid,
					)
				);
			}
		}

		// 2. Scrub differential.
		foreach ( array( 'scrub', 'scrub_fb' ) as $key ) {
			if ( null !== $results[ $key ] && $results[ $key ] !== $ref_scrub ) {
				$failures[] = self::failure(
					'scrub-mismatch',
					$key,
					self::diff_detail( $key, $ref_scrub, $results[ $key ] )
				);
			}
		}

		foreach ( $this->oracles->scrub_oracles() as $name => $oracle ) {
			if ( 'mb' === $name ) {
				continue;
			}

			$oracle_scrub = $oracle( $input );
			if ( null === $oracle_scrub ) {
				$this->oracles->disable( $name, 'transport failure during case' );
				continue;
			}

			if ( $oracle_scrub !== $ref_scrub ) {
				$failures[] = self::failure(
					'oracle-disagreement',
					"scrub:{$name}",
					self::diff_detail( $name, $ref_scrub, $oracle_scrub )
				);
			}
		}

		// 3. valid ⟺ scrub identity.
		foreach ( array( 'is_valid' => 'scrub', 'is_valid_fb' => 'scrub_fb' ) as $valid_key => $scrub_key ) {
			if ( null === $results[ $valid_key ] || null === $results[ $scrub_key ] ) {
				continue;
			}

			$identity = $results[ $scrub_key ] === $input;
			if ( $results[ $valid_key ] !== $identity ) {
				$failures[] = self::failure(
					'valid-iff-scrub-identity',
					$valid_key,
					array(
						'valid_target'   => $valid_key,
						'scrub_target'   => $scrub_key,
						'valid'          => $results[ $valid_key ],
						'scrub_identity' => $identity,
					)
				);
			}
		}

		// 4. Scrub output must be valid UTF-8. 5. Scrub must be idempotent.
		foreach ( array( 'scrub', 'scrub_fb' ) as $key ) {
			if ( null === $results[ $key ] ) {
				continue;
			}

			$scrubbed = $results[ $key ];
			if ( ! $mb_validity( $scrubbed ) ) {
				$failures[] = self::failure(
					'scrubbed-not-valid',
					$key,
					array(
						'target'  => $key,
						'scrub_preview' => self::preview( $scrubbed ),
					)
				);
			}

			try {
				$twice = ( $this->targets[ $key ] )( $scrubbed );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'target-exception',
					"{$key}:idempotence",
					array(
						'target'  => $key,
						'message' => $error->getMessage(),
						'class'   => get_class( $error ),
					)
				);
				$twice = $scrubbed;
			}

			if ( $twice !== $scrubbed ) {
				$failures[] = self::failure(
					'scrub-not-idempotent',
					$key,
					self::diff_detail( $key, $scrubbed, $twice )
				);
			}
		}

		// 6. Code point count agrees with the scrubbed length.
		try {
			$count    = ( $this->targets['codepoint_count'] )( $input );
			$expected = mb_strlen( $ref_scrub, 'UTF-8' );
			if ( $count !== $expected ) {
				$failures[] = self::failure(
					'codepoint-count-mismatch',
					'codepoint_count',
					array(
						'got'      => $count,
						'expected' => $expected,
					)
				);
			}
		} catch ( \Throwable $error ) {
			$failures[] = self::failure(
				'target-exception',
				'codepoint_count',
				array(
					'target'  => 'codepoint_count',
					'message' => $error->getMessage(),
					'class'   => get_class( $error ),
				)
			);
		}

		// 7. Chunked scan reconstruction.
		$chunk_failure = $this->check_chunked_scan( $input, $ref_scrub );
		if ( null !== $chunk_failure ) {
			$failures[] = $chunk_failure;
		}

		return $failures;
	}

	/**
	 * Rebuilds the scrubbed text by calling `_wp_scan_utf8()` directly
	 * with pseudo-random `max_code_points` budgets, exercising the
	 * resumable-scan paths the plain fallbacks never hit. Chunk sizes
	 * derive from the input hash, so replaying the input replays the
	 * exact chunking.
	 */
	private function check_chunked_scan( string $input, string $ref_scrub ): ?array {
		if ( ! function_exists( '_wp_scan_utf8' ) ) {
			return null;
		}

		$length      = strlen( $input );
		$chunk_bytes = hash( 'sha256', $input, true );
		$chunk_index = 0;
		$at          = 0;
		$out         = '';
		$guard       = ( 2 * $length ) + 16;

		while ( $at < $length ) {
			if ( --$guard < 0 ) {
				return self::failure(
					'scan-no-progress',
					'chunked-scan',
					array(
						'at'     => $at,
						'length' => $length,
					)
				);
			}

			$was_at         = $at;
			$invalid_length = 0;
			$max_points     = 1 + ( ord( $chunk_bytes[ $chunk_index % 32 ] ) % 7 );
			++$chunk_index;

			try {
				_wp_scan_utf8( $input, $at, $invalid_length, null, $max_points );
			} catch ( \Throwable $error ) {
				return self::failure(
					'target-exception',
					'chunked-scan',
					array(
						'target'  => '_wp_scan_utf8',
						'message' => $error->getMessage(),
						'class'   => get_class( $error ),
					)
				);
			}

			$out .= substr( $input, $was_at, $at - $was_at );

			if ( $invalid_length > 0 ) {
				$out .= "\u{FFFD}";
				$at  += $invalid_length;
			} elseif ( $at === $was_at && $at < $length ) {
				return self::failure(
					'scan-no-progress',
					'chunked-scan',
					array(
						'at'         => $at,
						'length'     => $length,
						'max_points' => $max_points,
					)
				);
			}
		}

		if ( $out !== $ref_scrub ) {
			return self::failure(
				'chunked-scan-mismatch',
				'chunked-scan',
				self::diff_detail( 'chunked-scan', $ref_scrub, $out )
			);
		}

		return null;
	}

	private static function failure( string $check, string $party, array $detail ): array {
		return array(
			'check'     => $check,
			'signature' => "{$check}:{$party}",
			'detail'    => $detail,
		);
	}

	private static function diff_detail( string $party, string $expected, string $got ): array {
		$offset = self::first_difference( $expected, $got );

		return array(
			'party'           => $party,
			'expected_length' => strlen( $expected ),
			'got_length'      => strlen( $got ),
			'first_diff_at'   => $offset,
			'expected_window' => self::preview( $expected, $offset ),
			'got_window'      => self::preview( $got, $offset ),
		);
	}

	private static function first_difference( string $a, string $b ): int {
		$max = min( strlen( $a ), strlen( $b ) );
		for ( $i = 0; $i < $max; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				return $i;
			}
		}
		return $max;
	}

	private static function preview( string $bytes, int $center = 0 ): string {
		$start = max( 0, $center - intdiv( self::PREVIEW_BYTES, 2 ) );
		return bin2hex( substr( $bytes, $start, self::PREVIEW_BYTES ) );
	}
}
