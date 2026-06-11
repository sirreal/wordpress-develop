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
 *  - `_wp_utf8_codepoint_span()` reports the original byte span for a
 *    requested number of code points, with invalid maximal subparts
 *    counted as one code point and `found_code_points` reporting the
 *    available/requested count
 *  - scanning with `_wp_scan_utf8()` in pseudo-random `max_code_points`
 *    chunks reconstructs the same scrubbed text and always makes
 *    forward progress
 *
 * Noncharacter detection (VALID input only — the public function's
 * answer on ill-formed input depends on which environment branch of
 * `utf8.php` loaded, a documented divergence pinned by the smoke test):
 *  - `wp_has_noncharacters()` and `_wp_has_noncharacters_fallback()` vs
 *    a trivial decode-and-test reference.
 *
 * Legacy `utf8_encode()` / `utf8_decode()` fallbacks:
 *  - `_wp_utf8_encode_fallback()` vs every encode oracle on arbitrary
 *    input treated as ISO-8859-1.
 *  - `_wp_utf8_decode_fallback()` vs the mb decode oracle on arbitrary
 *    input; the legacy native oracle is consulted on valid input only
 *    (see the divergence note in `Oracles`).
 *  - encode output is always valid UTF-8
 *  - `decode(encode(s)) === s` for any byte string `s` (encode is total
 *    and injective per byte)
 *
 * Character/code point polyfills:
 *  - `_mb_chr()` against the independent arithmetic UTF-8 encoder for
 *    valid scalar values, and false for invalid code points.
 *  - `_mb_ord()` against an independent first-code-point decoder on
 *    arbitrary byte strings.
 *  - `_mb_ord( _mb_chr( cp ) ) === cp` and
 *    `_mb_chr( _mb_ord( s ) ) === first UTF-8 character in s` where
 *    those expressions are defined.
 *
 * Target callables are injectable so the harness smoke test can verify
 * that deliberately broken implementations are caught.
 */
class Checks {
	public const PREVIEW_BYTES = 48;

	private const MB_CHR_CODE_POINT_PROBES = array(
		-1,
		0x00,
		0x01,
		0x7F,
		0x80,
		0x7FF,
		0x800,
		0xD7FF,
		0xD800,
		0xDFFF,
		0xE000,
		0xFDCF,
		0xFDD0,
		0xFDEF,
		0xFDF0,
		0xFFFD,
		0xFFFE,
		0xFFFF,
		0x10000,
		0x10FFFF,
		0x110000,
	);

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

		// 7. Code point span agrees with valid-text and maximal-subpart references.
		foreach ( $this->check_codepoint_span( $input, $ref_scrub ) as $failure ) {
			$failures[] = $failure;
		}

		// 8. Chunked scan reconstruction.
		$chunk_failure = $this->check_chunked_scan( $input, $ref_scrub );
		if ( null !== $chunk_failure ) {
			$failures[] = $chunk_failure;
		}

		// 9. Legacy utf8_encode()/utf8_decode() fallback differentials.
		foreach ( $this->check_utf8_encode_decode( $input, $ref_valid, $mb_validity ) as $failure ) {
			$failures[] = $failure;
		}

		// 10. Noncharacter detection, on valid input only.
		foreach ( $this->check_noncharacters( $input, $ref_valid ) as $failure ) {
			$failures[] = $failure;
		}

		// 11. mb_chr()/mb_ord() polyfill differentials and isomorphisms.
		foreach ( $this->check_mb_chr_ord( $input ) as $failure ) {
			$failures[] = $failure;
		}

		return $failures;
	}

	/**
	 * Tests `_wp_utf8_codepoint_span()` from known boundaries only.
	 *
	 * Starts inside a continuation byte or inside an invalid maximal subpart
	 * are deliberately outside this property: `_mb_substr()` reaches this
	 * helper by first computing a boundary with the same maximal-subpart model.
	 *
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	private function check_codepoint_span( string $input, string $ref_scrub ): array {
		if ( ! isset( $this->targets['codepoint_span'] ) ) {
			return array();
		}

		$failures = array();

		list( $offsets, $reference_scrub ) = self::reference_utf8_offsets_and_scrub( $input );
		if ( $reference_scrub !== $ref_scrub ) {
			return array(
				self::failure(
					'span-reference-disagreement',
					'maximal-subpart-reference',
					self::diff_detail( 'maximal-subpart-reference', $ref_scrub, $reference_scrub )
				),
			);
		}

		$segment_count = count( $offsets ) - 1;
		foreach ( self::span_probe_indices( $segment_count, $input ) as $segment_index ) {
			$byte_offset = $offsets[ $segment_index ];
			$available   = $segment_count - $segment_index;

			foreach ( self::span_probe_counts( $available, $input . ":{$segment_index}" ) as $max_code_points ) {
				$expected_found = min( $max_code_points, $available );
				$expected_span  = $offsets[ $segment_index + $expected_found ] - $byte_offset;

				$failure = $this->assert_codepoint_span(
					$input,
					$byte_offset,
					$max_code_points,
					$expected_span,
					$expected_found,
					'arbitrary-boundary'
				);

				if ( null !== $failure ) {
					$failures[] = $failure;
				}
			}
		}

		$scrubbed_code_points = mb_strlen( $ref_scrub, 'UTF-8' );
		foreach ( self::span_probe_indices( $scrubbed_code_points, $ref_scrub ) as $start_code_point ) {
			$byte_offset = strlen( mb_substr( $ref_scrub, 0, $start_code_point, 'UTF-8' ) );
			$available   = $scrubbed_code_points - $start_code_point;

			foreach ( self::span_probe_counts( $available, $ref_scrub . ":scrubbed:{$start_code_point}" ) as $max_code_points ) {
				$expected_found = min( $max_code_points, $available );
				$expected_span  = strlen( mb_substr( $ref_scrub, $start_code_point, $max_code_points, 'UTF-8' ) );

				$failure = $this->assert_codepoint_span(
					$ref_scrub,
					$byte_offset,
					$max_code_points,
					$expected_span,
					$expected_found,
					'scrubbed-mb-substr'
				);

				if ( null !== $failure ) {
					$failures[] = $failure;
				}
			}
		}

		return $failures;
	}

	private function assert_codepoint_span( string $input, int $byte_offset, int $max_code_points, int $expected_span, int $expected_found, string $property ): ?array {
		$found_code_points = -1;

		try {
			$actual_span = ( $this->targets['codepoint_span'] )( $input, $byte_offset, $max_code_points, $found_code_points );
		} catch ( \Throwable $error ) {
			return self::failure(
				'target-exception',
				'codepoint_span',
				array(
					'target'          => 'codepoint_span',
					'property'        => $property,
					'byte_offset'     => $byte_offset,
					'max_code_points' => $max_code_points,
					'message'         => $error->getMessage(),
					'class'           => get_class( $error ),
				)
			);
		}

		if ( ! is_int( $actual_span ) ) {
			return self::failure(
				'codepoint-span-bad-return',
				'codepoint_span',
				array(
					'property'        => $property,
					'byte_offset'     => $byte_offset,
					'max_code_points' => $max_code_points,
					'type'            => get_debug_type( $actual_span ),
				)
			);
		}

		if ( ! is_int( $found_code_points ) ) {
			return self::failure(
				'codepoint-span-found-bad-return',
				'codepoint_span',
				array(
					'property'        => $property,
					'byte_offset'     => $byte_offset,
					'max_code_points' => $max_code_points,
					'type'            => get_debug_type( $found_code_points ),
				)
			);
		}

		if ( $actual_span !== $expected_span ) {
			return self::failure(
				'codepoint-span-mismatch',
				'codepoint_span',
				array(
					'property'          => $property,
					'byte_offset'       => $byte_offset,
					'max_code_points'   => $max_code_points,
					'got'               => $actual_span,
					'expected'          => $expected_span,
					'found_code_points' => $found_code_points,
					'input_preview'     => self::preview( $input, $byte_offset ),
				)
			);
		}

		if ( $found_code_points !== $expected_found ) {
			return self::failure(
				'codepoint-span-found-mismatch',
				'codepoint_span',
				array(
					'property'        => $property,
					'byte_offset'     => $byte_offset,
					'max_code_points' => $max_code_points,
					'got'             => $found_code_points,
					'expected'        => $expected_found,
					'span'            => $actual_span,
					'input_preview'   => self::preview( $input, $byte_offset ),
				)
			);
		}

		return null;
	}

	/**
	 * Tests `_mb_chr()` and `_mb_ord()` as partial inverses. The oracle for
	 * `_mb_chr()` is the fuzzer's arithmetic UTF-8 encoder; the oracle for
	 * `_mb_ord()` is an independent decoder for the first code point only.
	 *
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	private function check_mb_chr_ord( string $input ): array {
		$failures = array();

		if ( ! isset( $this->targets['mb_chr'], $this->targets['mb_ord'] ) ) {
			return $failures;
		}

		list( $expected_ord, $prefix_length ) = self::first_code_point_or_false( $input );

		try {
			$actual_ord = ( $this->targets['mb_ord'] )( $input );
		} catch ( \Throwable $error ) {
			$failures[] = self::failure(
				'target-exception',
				'mb_ord',
				array(
					'target'  => 'mb_ord',
					'message' => $error->getMessage(),
					'class'   => get_class( $error ),
				)
			);
			$actual_ord = false;
		}

		if ( ! is_int( $actual_ord ) && false !== $actual_ord ) {
			$failures[] = self::failure(
				'mb-ord-bad-return',
				'mb_ord',
				array(
					'type' => get_debug_type( $actual_ord ),
				)
			);
		} elseif ( $actual_ord !== $expected_ord ) {
			$failures[] = self::failure(
				'mb-ord-mismatch',
				'mb_ord',
				array(
					'got'           => $actual_ord,
					'expected'      => $expected_ord,
					'input_preview' => self::preview( $input ),
				)
			);
		}

		if ( is_int( $expected_ord ) ) {
			try {
				$round_trip_chr = ( $this->targets['mb_chr'] )( $expected_ord );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'target-exception',
					'mb_chr:from-ord',
					array(
						'target'  => 'mb_chr',
						'message' => $error->getMessage(),
						'class'   => get_class( $error ),
					)
				);
				$round_trip_chr = false;
			}

			$expected_prefix = substr( $input, 0, $prefix_length );
			if ( $round_trip_chr !== $expected_prefix ) {
				$failures[] = self::failure(
					'mb-ord-chr-isomorphism',
					'mb_ord:mb_chr',
					array(
						'code_point'      => $expected_ord,
						'expected_prefix' => self::preview( $expected_prefix ),
						'got'             => is_string( $round_trip_chr ) ? self::preview( $round_trip_chr ) : $round_trip_chr,
					)
				);
			}
		}

		foreach ( self::mb_chr_code_point_probes( $input ) as $code_point ) {
			$expected_chr = self::expected_mb_chr( $code_point );

			try {
				$actual_chr = ( $this->targets['mb_chr'] )( $code_point );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'target-exception',
					'mb_chr',
					array(
						'target'     => 'mb_chr',
						'code_point' => $code_point,
						'message'    => $error->getMessage(),
						'class'      => get_class( $error ),
					)
				);
				$actual_chr = false;
			}

			if ( ! is_string( $actual_chr ) && false !== $actual_chr ) {
				$failures[] = self::failure(
					'mb-chr-bad-return',
					'mb_chr',
					array(
						'code_point' => $code_point,
						'type'       => get_debug_type( $actual_chr ),
					)
				);
				continue;
			}

			if ( $actual_chr !== $expected_chr ) {
				$failures[] = self::failure(
					'mb-chr-mismatch',
					'mb_chr',
					array(
						'code_point' => $code_point,
						'expected'   => is_string( $expected_chr ) ? self::preview( $expected_chr ) : $expected_chr,
						'got'        => is_string( $actual_chr ) ? self::preview( $actual_chr ) : $actual_chr,
					)
				);
				continue;
			}

			if ( is_string( $actual_chr ) ) {
				try {
					$round_trip_ord = ( $this->targets['mb_ord'] )( $actual_chr );
				} catch ( \Throwable $error ) {
					$failures[] = self::failure(
						'target-exception',
						'mb_ord:from-chr',
						array(
							'target'     => 'mb_ord',
							'code_point' => $code_point,
							'message'    => $error->getMessage(),
							'class'      => get_class( $error ),
						)
					);
					$round_trip_ord = false;
				}

				if ( $round_trip_ord !== $code_point ) {
					$failures[] = self::failure(
						'mb-chr-ord-isomorphism',
						'mb_chr:mb_ord',
						array(
							'code_point' => $code_point,
							'got'        => $round_trip_ord,
						)
					);
				}
			}
		}

		$contract_probes = array(
			array( 'mb_chr', array( 0x41, 'UTF-8' ), 'A' ),
			array( 'mb_chr', array( 0x41, 'latin1' ), false ),
			array( 'mb_chr', array( 0x41, 'utf8' ), false ),
			array( 'mb_chr', array( '65' ), false ),
			array( 'mb_ord', array( 'A', 'UTF-8' ), 0x41 ),
			array( 'mb_ord', array( 'A', 'latin1' ), false ),
			array( 'mb_ord', array( 'A', 'utf8' ), false ),
			array( 'mb_ord', array( '' ), false ),
			array( 'mb_ord', array( 0x41 ), false ),
		);

		foreach ( $contract_probes as $probe ) {
			list( $target, $args, $expected ) = $probe;

			try {
				$actual = ( $this->targets[ $target ] )( ...$args );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'target-exception',
					"{$target}:contract",
					array(
						'target'  => $target,
						'args'    => array_map( static fn( $arg ) => is_string( $arg ) ? self::preview( $arg ) : $arg, $args ),
						'message' => $error->getMessage(),
						'class'   => get_class( $error ),
					)
				);
				continue;
			}

			if ( $actual !== $expected ) {
				$failures[] = self::failure(
					"{$target}-contract-mismatch",
					$target,
					array(
						'args'     => array_map( static fn( $arg ) => is_string( $arg ) ? self::preview( $arg ) : $arg, $args ),
						'expected' => is_string( $expected ) ? self::preview( $expected ) : $expected,
						'got'      => is_string( $actual ) ? self::preview( $actual ) : $actual,
					)
				);
			}
		}

		return $failures;
	}

	/**
	 * Three-way differential for noncharacter detection on VALID input:
	 * the public `wp_has_noncharacters()` (the PCRE branch on hosts with
	 * PCRE-u; otherwise it aliases the fallback and this degenerates to
	 * two distinct implementations), the `_wp_scan_utf8()`-based
	 * fallback, and the trivial mb reference must all agree.
	 *
	 * Ill-formed input is deliberately skipped: the PCRE branch answers
	 * false on any ill-formed input (`preg_match` fails) while the
	 * fallback skips invalid spans and reports noncharacters around
	 * them, so the same public function answers differently depending
	 * on which environment branch loaded. That stance — behavior is
	 * undefined unless `wp_is_valid_utf8()` — is pinned by a fixed
	 * regression vector in the smoke test, not fuzzed.
	 *
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	private function check_noncharacters( string $input, bool $ref_valid ): array {
		if ( ! $ref_valid ) {
			return array();
		}

		$oracles = $this->oracles->noncharacter_oracles();
		if ( ! isset( $oracles['mb'] ) ) {
			return array();
		}

		$failures = array();
		$expected = $oracles['mb']( $input );

		foreach ( $oracles as $name => $oracle ) {
			if ( 'mb' === $name ) {
				continue;
			}

			$oracle_result = $oracle( $input );
			if ( $oracle_result !== $expected ) {
				$failures[] = self::failure(
					'oracle-disagreement',
					"noncharacters:{$name}",
					array(
						'kind'     => 'noncharacters',
						'oracle'   => $name,
						'got'      => $oracle_result,
						'expected' => $expected,
					)
				);
			}
		}

		foreach ( array( 'has_nonchars', 'has_nonchars_fb' ) as $key ) {
			try {
				$result = ( $this->targets[ $key ] )( $input );
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
				continue;
			}

			if ( $result !== $expected ) {
				$failures[] = self::failure(
					'noncharacters-mismatch',
					$key,
					array(
						'target'        => $key,
						'got'           => $result,
						'expected'      => $expected,
						'oracle'        => 'mb',
						'input_preview' => self::preview( $input ),
					)
				);
			}
		}

		return $failures;
	}

	/**
	 * Differentials and invariants for the `utf8_encode()` /
	 * `utf8_decode()` fallback pair. The same input is exercised both as
	 * ISO-8859-1 (encode, total over arbitrary bytes) and as UTF-8
	 * (decode). The legacy native decode oracle is consulted on valid
	 * input only; on ill-formed input WordPress deliberately follows
	 * `mb_convert_encoding()` maximal-subpart semantics instead.
	 *
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	private function check_utf8_encode_decode( string $input, bool $ref_valid, callable $mb_validity ): array {
		$failures       = array();
		$encode_oracles = $this->oracles->encode_oracles();
		$decode_oracles = $this->oracles->decode_oracles();

		/*
		 * The fallbacks are untyped, so a broken variant could return null
		 * (or anything else) instead of throwing; treat any non-string
		 * return as a failure rather than silently skipping every check.
		 */
		$results = array();
		foreach ( array( 'utf8_encode_fb', 'utf8_decode_fb' ) as $key ) {
			try {
				$result = ( $this->targets[ $key ] )( $input );

				if ( ! is_string( $result ) ) {
					$failures[] = self::failure(
						'target-bad-return',
						$key,
						array(
							'target' => $key,
							'type'   => get_debug_type( $result ),
						)
					);
					$result = null;
				}
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
				$result = null;
			}

			$results[ $key ] = $result;
		}

		// Differentials against the encode/decode oracles.
		$ref_encode = isset( $encode_oracles['mb'] ) ? $encode_oracles['mb']( $input ) : null;
		$ref_decode = isset( $decode_oracles['mb'] ) ? $decode_oracles['mb']( $input ) : null;

		if ( null !== $ref_encode && null !== $results['utf8_encode_fb'] && $results['utf8_encode_fb'] !== $ref_encode ) {
			$failures[] = self::failure(
				'utf8-encode-mismatch',
				'utf8_encode_fb',
				self::diff_detail( 'utf8_encode_fb', $ref_encode, $results['utf8_encode_fb'] )
			);
		}

		if ( null !== $ref_decode && null !== $results['utf8_decode_fb'] && $results['utf8_decode_fb'] !== $ref_decode ) {
			$failures[] = self::failure(
				'utf8-decode-mismatch',
				'utf8_decode_fb',
				self::diff_detail( 'utf8_decode_fb', $ref_decode, $results['utf8_decode_fb'] )
			);
		}

		if ( null !== $ref_encode ) {
			foreach ( $encode_oracles as $name => $oracle ) {
				if ( 'mb' === $name ) {
					continue;
				}

				$oracle_encode = $oracle( $input );
				if ( $oracle_encode !== $ref_encode ) {
					$failures[] = self::failure(
						'oracle-disagreement',
						"utf8-encode:{$name}",
						self::diff_detail( $name, $ref_encode, $oracle_encode )
					);
				}
			}
		}

		if ( null !== $ref_decode ) {
			foreach ( $decode_oracles as $name => $oracle ) {
				if ( 'mb' === $name ) {
					continue;
				}

				if ( ! $ref_valid && $this->oracles->decode_oracle_is_valid_only( $name ) ) {
					continue;
				}

				$oracle_decode = $oracle( $input );
				if ( $oracle_decode !== $ref_decode ) {
					$failures[] = self::failure(
						'oracle-disagreement',
						"utf8-decode:{$name}",
						self::diff_detail( $name, $ref_decode, $oracle_decode )
					);
				}
			}
		}

		// Encode output must be valid UTF-8 (every byte has a code point).
		// This and the round trip below need no conversion oracle.
		if ( null !== $results['utf8_encode_fb'] && ! $mb_validity( $results['utf8_encode_fb'] ) ) {
			$failures[] = self::failure(
				'utf8-encode-not-valid',
				'utf8_encode_fb',
				array(
					'target'         => 'utf8_encode_fb',
					'encode_preview' => self::preview( $results['utf8_encode_fb'] ),
				)
			);
		}

		// Round trip: encode is total and injective per byte, so decoding
		// its output must restore the input exactly. A violation implicates
		// the pair, not a single side.
		if ( null !== $results['utf8_encode_fb'] ) {
			try {
				$round_trip = ( $this->targets['utf8_decode_fb'] )( $results['utf8_encode_fb'] );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'target-exception',
					'utf8_decode_fb:round-trip',
					array(
						'target'  => 'utf8_decode_fb',
						'message' => $error->getMessage(),
						'class'   => get_class( $error ),
					)
				);
				$round_trip = $input;
			}

			if ( ! is_string( $round_trip ) ) {
				$failures[] = self::failure(
					'target-bad-return',
					'utf8_decode_fb:round-trip',
					array(
						'target' => 'utf8_decode_fb',
						'type'   => get_debug_type( $round_trip ),
					)
				);
			} elseif ( $round_trip !== $input ) {
				$failures[] = self::failure(
					'utf8-round-trip-mismatch',
					'round-trip',
					self::diff_detail( 'round-trip', $input, $round_trip )
				);
			}
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

	private static function expected_mb_chr( int $code_point ) {
		if (
			$code_point < 0 ||
			( $code_point >= 0xD800 && $code_point <= 0xDFFF ) ||
			$code_point > 0x10FFFF
		) {
			return false;
		}

		return Generator::encode_code_point( $code_point );
	}

	private static function mb_chr_code_point_probes( string $input ): array {
		$probes = self::MB_CHR_CODE_POINT_PROBES;
		$hash   = hash( 'sha256', $input, true );

		for ( $i = 0; $i < 4; $i++ ) {
			$raw      = unpack( 'N', substr( $hash, 4 * $i, 4 ) )[1];
			$probes[] = ( $raw % 0x120000 ) - 0x800;
		}

		return array_values( array_unique( $probes ) );
	}

	/**
	 * Builds a small boundary table from an independent UTF-8 maximal-subpart
	 * parser. `$offsets[$i]` is the byte offset before logical code point `$i`.
	 *
	 * @return array{0: int[], 1: string} Boundary offsets and scrubbed text.
	 */
	private static function reference_utf8_offsets_and_scrub( string $bytes ): array {
		$length  = strlen( $bytes );
		$offsets = array( 0 );
		$scrub   = '';
		$at      = 0;

		while ( $at < $length ) {
			list( $segment_length, $valid ) = self::reference_utf8_segment( $bytes, $at );
			$scrub                        .= $valid ? substr( $bytes, $at, $segment_length ) : "\u{FFFD}";
			$at                           += $segment_length;
			$offsets[]                     = $at;
		}

		return array( $offsets, $scrub );
	}

	/**
	 * @return array{0: int, 1: bool} Byte length and whether the segment is well-formed.
	 */
	private static function reference_utf8_segment( string $bytes, int $at ): array {
		$remaining = strlen( $bytes ) - $at;
		$b1        = ord( $bytes[ $at ] );

		if ( $b1 <= 0x7F ) {
			return array( 1, true );
		}

		$b2 = $remaining >= 2 ? ord( $bytes[ $at + 1 ] ) : null;
		if ( $b1 >= 0xC2 && $b1 <= 0xDF ) {
			return self::is_continuation( $b2 ) ? array( 2, true ) : array( 1, false );
		}

		$b3 = $remaining >= 3 ? ord( $bytes[ $at + 2 ] ) : null;
		if (
			self::is_continuation( $b3 ) &&
			(
				( 0xE0 === $b1 && null !== $b2 && $b2 >= 0xA0 && $b2 <= 0xBF ) ||
				( $b1 >= 0xE1 && $b1 <= 0xEC && self::is_continuation( $b2 ) ) ||
				( 0xED === $b1 && null !== $b2 && $b2 >= 0x80 && $b2 <= 0x9F ) ||
				( $b1 >= 0xEE && $b1 <= 0xEF && self::is_continuation( $b2 ) )
			)
		) {
			return array( 3, true );
		}

		$b4 = $remaining >= 4 ? ord( $bytes[ $at + 3 ] ) : null;
		if (
			self::is_continuation( $b3 ) &&
			self::is_continuation( $b4 ) &&
			(
				( 0xF0 === $b1 && null !== $b2 && $b2 >= 0x90 && $b2 <= 0xBF ) ||
				( $b1 >= 0xF1 && $b1 <= 0xF3 && self::is_continuation( $b2 ) ) ||
				( 0xF4 === $b1 && null !== $b2 && $b2 >= 0x80 && $b2 <= 0x8F )
			)
		) {
			return array( 4, true );
		}

		if ( $b1 >= 0xE0 && $b1 <= 0xEF && self::is_valid_three_byte_second( $b1, $b2 ) ) {
			return array( min( $remaining, 2 ), false );
		}

		if ( $b1 >= 0xF0 && $b1 <= 0xF4 && self::is_valid_four_byte_second( $b1, $b2 ) ) {
			return array( min( $remaining, self::is_continuation( $b3 ) ? 3 : 2 ), false );
		}

		return array( 1, false );
	}

	private static function is_continuation( ?int $byte ): bool {
		return null !== $byte && $byte >= 0x80 && $byte <= 0xBF;
	}

	private static function is_valid_three_byte_second( int $b1, ?int $b2 ): bool {
		return (
			( 0xE0 === $b1 && null !== $b2 && $b2 >= 0xA0 && $b2 <= 0xBF ) ||
			( $b1 >= 0xE1 && $b1 <= 0xEC && self::is_continuation( $b2 ) ) ||
			( 0xED === $b1 && null !== $b2 && $b2 >= 0x80 && $b2 <= 0x9F ) ||
			( $b1 >= 0xEE && $b1 <= 0xEF && self::is_continuation( $b2 ) )
		);
	}

	private static function is_valid_four_byte_second( int $b1, ?int $b2 ): bool {
		return (
			( 0xF0 === $b1 && null !== $b2 && $b2 >= 0x90 && $b2 <= 0xBF ) ||
			( $b1 >= 0xF1 && $b1 <= 0xF3 && self::is_continuation( $b2 ) ) ||
			( 0xF4 === $b1 && null !== $b2 && $b2 >= 0x80 && $b2 <= 0x8F )
		);
	}

	/**
	 * @return int[] Segment/code point indices to use as start boundaries.
	 */
	private static function span_probe_indices( int $code_points, string $salt ): array {
		$indices = array(
			0,
			min( 1, $code_points ),
			min( 2, $code_points ),
			intdiv( $code_points, 2 ),
			max( 0, $code_points - 1 ),
			$code_points,
		);

		$hash = hash( 'sha256', $salt, true );
		for ( $i = 0; $i < 4; $i++ ) {
			$indices[] = ord( $hash[ $i ] ) % ( $code_points + 1 );
		}

		sort( $indices );
		return array_values( array_unique( $indices ) );
	}

	/**
	 * @return int[] Requested code point counts to probe from a start boundary.
	 */
	private static function span_probe_counts( int $available, string $salt ): array {
		$counts = array(
			0,
			1,
			2,
			3,
			min( 7, $available ),
			intdiv( $available, 2 ),
			max( 0, $available - 1 ),
			$available,
			$available + 1,
		);

		$hash = hash( 'sha256', $salt, true );
		for ( $i = 0; $i < 4; $i++ ) {
			$counts[] = ord( $hash[ $i ] ) % ( $available + 2 );
		}

		sort( $counts );
		return array_values( array_unique( $counts ) );
	}

	/**
	 * @return array{0: int|false, 1: int} First code point and byte length.
	 */
	private static function first_code_point_or_false( string $bytes ): array {
		$length = strlen( $bytes );
		if ( 0 === $length ) {
			return array( false, 0 );
		}

		$b1 = ord( $bytes[0] );
		if ( $b1 <= 0x7F ) {
			return array( $b1, 1 );
		}

		if ( $length < 2 ) {
			return array( false, 0 );
		}

		$b2 = ord( $bytes[1] );
		if ( $b1 >= 0xC2 && $b1 <= 0xDF && $b2 >= 0x80 && $b2 <= 0xBF ) {
			return array(
				( ( $b1 & 0x1F ) << 6 ) | ( $b2 & 0x3F ),
				2,
			);
		}

		if ( $length < 3 ) {
			return array( false, 0 );
		}

		$b3 = ord( $bytes[2] );
		if (
			$b3 >= 0x80 &&
			$b3 <= 0xBF &&
			(
				( 0xE0 === $b1 && $b2 >= 0xA0 && $b2 <= 0xBF ) ||
				( $b1 >= 0xE1 && $b1 <= 0xEC && $b2 >= 0x80 && $b2 <= 0xBF ) ||
				( 0xED === $b1 && $b2 >= 0x80 && $b2 <= 0x9F ) ||
				( $b1 >= 0xEE && $b1 <= 0xEF && $b2 >= 0x80 && $b2 <= 0xBF )
			)
		) {
			return array(
				( ( $b1 & 0x0F ) << 12 ) | ( ( $b2 & 0x3F ) << 6 ) | ( $b3 & 0x3F ),
				3,
			);
		}

		if ( $length < 4 ) {
			return array( false, 0 );
		}

		$b4 = ord( $bytes[3] );
		if (
			$b3 >= 0x80 &&
			$b3 <= 0xBF &&
			$b4 >= 0x80 &&
			$b4 <= 0xBF &&
			(
				( 0xF0 === $b1 && $b2 >= 0x90 && $b2 <= 0xBF ) ||
				( $b1 >= 0xF1 && $b1 <= 0xF3 && $b2 >= 0x80 && $b2 <= 0xBF ) ||
				( 0xF4 === $b1 && $b2 >= 0x80 && $b2 <= 0x8F )
			)
		) {
			return array(
				( ( $b1 & 0x07 ) << 18 ) |
				( ( $b2 & 0x3F ) << 12 ) |
				( ( $b3 & 0x3F ) << 6 ) |
				( $b4 & 0x3F ),
				4,
			);
		}

		return array( false, 0 );
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
