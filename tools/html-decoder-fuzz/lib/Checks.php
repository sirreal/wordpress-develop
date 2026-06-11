<?php
namespace HtmlDecoderFuzz;

/**
 * Runs all decoder differential and invariant checks for one payload.
 */
class Checks {
	public const PREVIEW_BYTES = 64;
	private const ATTRIBUTE_SEARCH_PREFIX_BYTES = 32;
	private const MATCH_BYTE_LENGTH_SENTINEL = '__html_decoder_fuzz_match_length_unset__';
	private const REPLACEMENT_CHARACTER = "\u{FFFD}";
	private const C1_NUMERIC_REMAP = array(
		0x20AC, 0x81, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021,
		0x02C6, 0x2030, 0x0160, 0x2039, 0x0152, 0x8D, 0x017D, 0x8F,
		0x90, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014,
		0x02DC, 0x2122, 0x0161, 0x203A, 0x0153, 0x9D, 0x017E, 0x0178,
	);
	private const SINGLE_LEVEL_DECODE_FIXTURES = array(
		'&amp;amp;'  => '&amp;',
		'&amp;lt;'   => '&lt;',
		'&amp;#58;'  => '&#58;',
		'&amp;#x3a;' => '&#x3a;',
	);

	private Oracles $oracles;

	/** @var array<string, callable> */
	private array $targets;

	public function __construct( Oracles $oracles, ?array $targets = null ) {
		$this->oracles = $oracles;
		$this->targets = $targets ?? Targets::resolve();
	}

	/**
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	public function run( string $context, string $payload ): array {
		$failures = array();

		if ( ! Generator::is_oracle_safe_payload( $payload ) ) {
			return array(
				self::failure(
					'unsafe-oracle-payload',
					$context,
					array(
						'context' => $context,
						'payload' => self::preview( $payload ),
					)
				),
			);
		}

		$contexts = 'both' === $context ? array( 'text', 'attribute' ) : array( $context );

		foreach ( $contexts as $one_context ) {
			$failures = array_merge( $failures, $this->check_decode_context( $one_context, $payload ) );
		}

		$failures = array_merge( $failures, $this->check_attribute_starts_with( $payload ) );

		return $failures;
	}

	/**
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	public function run_without_oracle( string $context, string $payload ): array {
		$failures = array();
		$contexts = 'both' === $context ? array( 'text', 'attribute' ) : array( $context );

		foreach ( $contexts as $one_context ) {
			$failures = array_merge( $failures, $this->check_decode_context_without_oracle( $one_context, $payload ) );
		}

		return $failures;
	}

	/**
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	private function check_decode_context( string $context, string $payload ): array {
		$failures = array();

		try {
			$expected = $this->oracles->decode( $context, $payload );
		} catch ( \Throwable $error ) {
			return array(
				self::failure(
					'oracle-exception',
					$context,
					array(
						'context' => $context,
						'class'   => get_class( $error ),
						'message' => $error->getMessage(),
					)
				),
			);
		}

		$target_key = 'text' === $context ? 'decode_text' : 'decode_attribute';
		try {
			$got = ( $this->targets[ $target_key ] )( $payload );
		} catch ( \Throwable $error ) {
			return array(
				self::failure(
					'target-exception',
					"{$context}:decode",
					array(
						'context' => $context,
						'target'  => $target_key,
						'class'   => get_class( $error ),
						'message' => $error->getMessage(),
					)
				),
			);
		}

		if ( $got !== $expected ) {
			$failures[] = self::failure(
				'decode-mismatch',
				$context,
				self::diff_detail( $context, $expected, $got )
			);
		}

		$single_level_expected = self::single_level_decode_expected( $payload );
		if ( null !== $single_level_expected && $got !== $single_level_expected ) {
			$failures[] = self::failure(
				'single-level-decode-overdecoded',
				$context,
				array_merge(
					self::diff_detail( $context, $single_level_expected, $got ),
					self::byte_detail( 'payload', $payload )
				)
			);
		}

		if ( 'text' === $context ) {
			try {
				$entity_decode_expected = $this->oracles->decode_text_with_entity_decode( $payload );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'oracle-exception',
					'text:entity-decode',
					array(
						'context' => $context,
						'oracle'  => 'entity-decode',
						'class'   => get_class( $error ),
						'message' => $error->getMessage(),
					)
				);
				$entity_decode_expected = null;
			}

			if ( null !== $entity_decode_expected && $got !== $entity_decode_expected ) {
				$failures[] = self::failure(
					'text-secondary-oracle-mismatch',
					$context,
					array_merge(
						self::diff_detail( $context, $entity_decode_expected, $got ),
						array(
							'secondary_oracle'    => 'html_entity_decode',
							'dom_expected_base64' => base64_encode( $expected ),
						)
					)
				);
			}
		}

		if ( ! mb_check_encoding( $got, 'UTF-8' ) ) {
			$failures[] = self::failure(
				'decoded-not-valid-utf8',
				$context,
				array(
					'context' => $context,
					'decoded' => self::preview( $got ),
				)
			);
		}

		if ( ! str_contains( $payload, '&' ) && $got !== $payload ) {
			$failures[] = self::failure(
				"{$context}-without-ampersand-not-identity",
				$context,
				self::diff_detail( $context, $payload, $got )
			);
		}

		$reader = $this->decode_with_reader( $context, $payload );
		foreach ( $reader['failures'] as $failure ) {
			$failures[] = $failure;
		}

		if ( $reader['decoded'] !== $got ) {
			$failures[] = self::failure(
				'reader-decode-mismatch',
				$context,
				self::diff_detail( $context, $got, $reader['decoded'] )
			);
		}

		return $failures;
	}

	/**
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	private function check_decode_context_without_oracle( string $context, string $payload ): array {
		$failures   = array();
		$target_key = 'text' === $context ? 'decode_text' : 'decode_attribute';

		try {
			$got = ( $this->targets[ $target_key ] )( $payload );
		} catch ( \Throwable $error ) {
			return array(
				self::failure(
					'target-exception',
					"{$context}:decode",
					array(
						'context' => $context,
						'target'  => $target_key,
						'class'   => get_class( $error ),
						'message' => $error->getMessage(),
					)
				),
			);
		}

		if ( ! str_contains( $payload, '&' ) && $got !== $payload ) {
			$failures[] = self::failure(
				"{$context}-without-ampersand-not-identity",
				$context,
				self::diff_detail( $context, $payload, $got )
			);
		}

		if ( ! str_contains( $payload, '&' ) && self::contains_raw_c1_byte( $payload ) && $got !== $payload ) {
			$failures[] = self::failure(
				'raw-c1-not-pass-through',
				$context,
				self::diff_detail( $context, $payload, $got )
			);
		}

		$single_level_expected = self::single_level_decode_expected( $payload );
		if ( null !== $single_level_expected && $got !== $single_level_expected ) {
			$failures[] = self::failure(
				'single-level-decode-overdecoded',
				$context,
				array_merge(
					self::diff_detail( $context, $single_level_expected, $got ),
					self::byte_detail( 'payload', $payload )
				)
			);
		}

		$reader = $this->decode_with_reader( $context, $payload );
		foreach ( $reader['failures'] as $failure ) {
			$failures[] = $failure;
		}

		if ( $reader['decoded'] !== $got ) {
			$failures[] = self::failure(
				'reader-decode-mismatch',
				$context,
				self::diff_detail( $context, $got, $reader['decoded'] )
			);
		}

		return $failures;
	}

	/**
	 * @return array{decoded: string, failures: array<int, array{check: string, signature: string, detail: array}>}
	 */
	private function decode_with_reader( string $context, string $payload ): array {
		$decoder_context = 'text' === $context ? 'data' : 'attribute';
		$decoded         = '';
		$failures        = array();
		$end             = strlen( $payload );
		$at              = 0;
		$was_at          = 0;
		$walk_at         = 0;
		$walk_spans      = array();

		$failures = array_merge( $failures, $this->check_reader_non_amp_offsets( $context, $decoder_context, $payload ) );

		while ( $at < $end ) {
			$amp_at = strpos( $payload, '&', $at );
			if ( false === $amp_at ) {
				break;
			}

			$match_byte_length = self::MATCH_BYTE_LENGTH_SENTINEL;
			try {
				$chunk = ( $this->targets['read_character_reference'] )( $decoder_context, $payload, $amp_at, $match_byte_length );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'target-exception',
					"{$context}:read-character-reference",
					array(
						'context' => $context,
						'class'   => get_class( $error ),
						'message' => $error->getMessage(),
					)
				);
				break;
			}

			if ( null === $chunk ) {
				if ( self::MATCH_BYTE_LENGTH_SENTINEL !== $match_byte_length ) {
					$failures[] = self::failure(
						'reader-mutated-match-length-on-null',
						$context,
						array(
							'context'                => $context,
							'at'                     => $amp_at,
							'match_byte_length'      => $match_byte_length,
							'match_byte_length_type' => gettype( $match_byte_length ),
						)
					);
					break;
				}
				if ( $walk_at < $amp_at + 1 ) {
					$walk_spans[] = array(
						'type'  => 'literal',
						'start' => $walk_at,
						'end'   => $amp_at + 1,
					);
					$walk_at      = $amp_at + 1;
				}
				$at = $amp_at + 1;
				continue;
			}

			if ( ! is_int( $match_byte_length ) || $match_byte_length <= 0 ) {
				$failures[] = self::failure(
					'reader-did-not-advance',
					$context,
					array(
						'context'           => $context,
						'at'                => $amp_at,
						'match_byte_length' => $match_byte_length,
					)
				);
				break;
			}

			if ( '' === $chunk ) {
				$failures[] = self::failure(
					'reader-returned-empty-chunk',
					$context,
					array(
						'context'           => $context,
						'at'                => $amp_at,
						'match_byte_length' => $match_byte_length,
					)
				);
				break;
			}

			if ( $match_byte_length < 2 ) {
				$failures[] = self::failure(
					'reader-match-too-short',
					$context,
					array(
						'context'           => $context,
						'at'                => $amp_at,
						'match_byte_length' => $match_byte_length,
					)
				);
				break;
			}

			if ( $amp_at + $match_byte_length > $end ) {
				$failures[] = self::failure(
					'reader-overran-input',
					$context,
					array(
						'context'           => $context,
						'at'                => $amp_at,
						'match_byte_length' => $match_byte_length,
						'input_length'       => $end,
					)
				);
				break;
			}

			if ( $walk_at < $amp_at ) {
				$walk_spans[] = array(
					'type'  => 'literal',
					'start' => $walk_at,
					'end'   => $amp_at,
				);
			}
			$walk_spans[] = array(
				'type'  => 'reference',
				'start' => $amp_at,
				'end'   => $amp_at + $match_byte_length,
			);
			$walk_at      = $amp_at + $match_byte_length;

			$reference = substr( $payload, $amp_at, $match_byte_length );
			$numeric_c1_replacement = self::numeric_c1_replacement( $reference );
			if ( null !== $numeric_c1_replacement && $numeric_c1_replacement !== $chunk ) {
				$failures[] = self::failure(
					'numeric-c1-not-remapped',
					$context,
					array_merge(
						array(
							'context'               => $context,
							'at'                    => $amp_at,
							'expected_base64'       => base64_encode( $numeric_c1_replacement ),
							'got_base64'            => base64_encode( $chunk ),
							'match_byte_length'     => $match_byte_length,
						),
						self::byte_detail( 'reference', $reference )
					)
				);
			}

			$invalid_numeric_reason = self::invalid_numeric_replacement_reason( $reference );
			if ( null !== $invalid_numeric_reason && self::REPLACEMENT_CHARACTER !== $chunk ) {
				$failures[] = self::failure(
					'numeric-invalid-not-replacement',
					$context,
					array_merge(
						array(
							'context'               => $context,
							'at'                    => $amp_at,
							'reason'                => $invalid_numeric_reason,
							'expected_base64'       => base64_encode( self::REPLACEMENT_CHARACTER ),
							'got_base64'            => base64_encode( $chunk ),
							'match_byte_length'     => $match_byte_length,
						),
						self::byte_detail( 'reference', $reference )
					)
				);
			}

			$local_match_byte_length = null;
			try {
				$local_chunk = ( $this->targets['read_character_reference'] )( $decoder_context, $reference, 0, $local_match_byte_length );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'target-exception',
					"{$context}:read-character-reference-local",
					array(
						'context' => $context,
						'class'   => get_class( $error ),
						'message' => $error->getMessage(),
					)
				);
				break;
			}

			if ( $local_chunk !== $chunk || $local_match_byte_length !== $match_byte_length ) {
				$failures[] = self::failure(
					'reader-composition-mismatch',
					$context,
					array_merge(
						array(
							'context'                    => $context,
							'at'                         => $amp_at,
							'match_byte_length'          => $match_byte_length,
							'local_match_byte_length'    => $local_match_byte_length,
							'expected_chunk_base64'      => base64_encode( $chunk ),
							'local_chunk_base64'         => is_string( $local_chunk ) ? base64_encode( $local_chunk ) : null,
							'local_chunk_type'           => gettype( $local_chunk ),
						),
						self::byte_detail( 'reference', $reference )
					)
				);
			}

			$decoded .= substr( $payload, $was_at, $amp_at - $was_at );
			$decoded .= $chunk;
			$at       = $amp_at + $match_byte_length;
			$was_at   = $at;
		}

		if ( $was_at < $end ) {
			$decoded .= substr( $payload, $was_at );
		}

		if ( array() === $failures ) {
			if ( $walk_at < $end ) {
				$walk_spans[] = array(
					'type'  => 'literal',
					'start' => $walk_at,
					'end'   => $end,
				);
			}
			if ( isset( $this->targets['reader_span_filter'] ) ) {
				$walk_spans = ( $this->targets['reader_span_filter'] )( $walk_spans );
			}
			$failures = array_merge( $failures, $this->validate_reader_walk( $context, $payload, $walk_spans ) );
		}

		return array(
			'decoded'  => $decoded,
			'failures' => $failures,
		);
	}

	/**
	 * @param array<int, array{type: string, start: int, end: int}> $spans
	 *
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	private function validate_reader_walk( string $context, string $payload, array $spans ): array {
		$cursor         = 0;
		$consumed_bytes = 0;
		$input_length   = strlen( $payload );

		foreach ( $spans as $index => $span ) {
			if ( $span['start'] !== $cursor ) {
				return array(
					self::failure(
						'reader-walk-not-gapless',
						$context,
						array(
							'context'        => $context,
							'reason'         => $span['start'] < $cursor ? 'overlap' : 'gap',
							'span_index'     => $index,
							'expected_start' => $cursor,
							'actual_start'   => $span['start'],
							'actual_end'     => $span['end'],
							'input_length'   => $input_length,
							'spans'          => self::preview_reader_spans( $spans ),
						)
					),
				);
			}

			if ( $span['end'] < $span['start'] || $span['end'] > $input_length ) {
				return array(
					self::failure(
						'reader-walk-not-gapless',
						$context,
						array(
							'context'        => $context,
							'reason'         => $span['end'] < $span['start'] ? 'negative-span' : 'overrun',
							'span_index'     => $index,
							'expected_start' => $cursor,
							'actual_start'   => $span['start'],
							'actual_end'     => $span['end'],
							'input_length'   => $input_length,
							'spans'          => self::preview_reader_spans( $spans ),
						)
					),
				);
			}

			$consumed_bytes += $span['end'] - $span['start'];
			$cursor          = $span['end'];
		}

		if ( $cursor !== $input_length || $consumed_bytes !== $input_length ) {
			return array(
				self::failure(
					'reader-walk-not-gapless',
					$context,
					array(
						'context'        => $context,
						'reason'         => 'length-mismatch',
						'covered_until'  => $cursor,
						'consumed_bytes' => $consumed_bytes,
						'input_length'   => $input_length,
						'spans'          => self::preview_reader_spans( $spans ),
					)
				),
			);
		}

		return array();
	}

	/**
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	private function check_reader_non_amp_offsets( string $context, string $decoder_context, string $payload ): array {
		$failures = array();
		foreach ( $this->reader_non_amp_probe_offsets( $payload ) as $offset ) {
			$match_byte_length = self::MATCH_BYTE_LENGTH_SENTINEL;
			try {
				$chunk = ( $this->targets['read_character_reference'] )( $decoder_context, $payload, $offset, $match_byte_length );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'target-exception',
					"{$context}:read-character-reference-non-amp",
					array(
						'context' => $context,
						'at'      => $offset,
						'class'   => get_class( $error ),
						'message' => $error->getMessage(),
					)
				);
				break;
			}

			if ( null !== $chunk || self::MATCH_BYTE_LENGTH_SENTINEL !== $match_byte_length ) {
				$failures[] = self::failure(
					'reader-non-amp-match',
					$context,
					array(
						'context'                => $context,
						'at'                     => $offset,
						'byte_hex'               => bin2hex( $payload[ $offset ] ),
						'chunk_type'             => gettype( $chunk ),
						'chunk_base64'           => is_string( $chunk ) ? base64_encode( $chunk ) : null,
						'match_byte_length'      => $match_byte_length,
						'match_byte_length_type' => gettype( $match_byte_length ),
					)
				);
				break;
			}
		}

		return $failures;
	}

	/**
	 * @return int[]
	 */
	private function reader_non_amp_probe_offsets( string $payload ): array {
		$length = strlen( $payload );
		if ( 0 === $length ) {
			return array();
		}

		$candidates = array( 0, intdiv( $length, 2 ), $length - 1 );
		$amp_at     = strpos( $payload, '&' );
		if ( false !== $amp_at ) {
			$candidates[] = $amp_at - 1;
			$candidates[] = $amp_at + 1;
		}

		$offsets = array();
		foreach ( $candidates as $offset ) {
			if ( $offset < 0 || $offset >= $length || '&' === $payload[ $offset ] ) {
				continue;
			}
			$offsets[ $offset ] = true;
		}

		return array_keys( $offsets );
	}

	/**
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	private function check_attribute_starts_with( string $payload ): array {
		$failures = array();

		try {
			$decoded = $this->oracles->decode( 'attribute', $payload );
		} catch ( \Throwable $error ) {
			return array(
				self::failure(
					'oracle-exception',
					'attribute:decode-for-prefix',
					array(
						'context' => 'attribute',
						'class'   => get_class( $error ),
						'message' => $error->getMessage(),
					)
				),
			);
		}

		$searches   = $this->attribute_searches( $decoded );
		$results    = array();
		$get_result = function ( string $search, string $case_sensitivity ) use ( $payload, &$failures, &$results ): ?bool {
			$result_key = $case_sensitivity . "\0" . $search;
			if ( array_key_exists( $result_key, $results ) ) {
				return $results[ $result_key ];
			}

			try {
				$results[ $result_key ] = ( $this->targets['attribute_starts_with'] )( $payload, $search, $case_sensitivity );
			} catch ( \Throwable $error ) {
				$failures[] = self::failure(
					'target-exception',
					"attribute-starts-with:{$case_sensitivity}",
					array(
						'target'           => 'attribute_starts_with',
						'case_sensitivity' => $case_sensitivity,
						'class'            => get_class( $error ),
						'message'          => $error->getMessage(),
					)
				);
				$results[ $result_key ] = null;
			}

			return $results[ $result_key ];
		};

		foreach ( $searches as $search ) {
			foreach ( array( 'case-sensitive', 'ascii-case-insensitive' ) as $case_sensitivity ) {
				$expected = $this->expected_prefix_match( $decoded, $search, $case_sensitivity );
				$got      = $get_result( $search, $case_sensitivity );
				if ( null === $got ) {
					continue;
				}

				if ( $got !== $expected ) {
					$failures[] = self::failure(
						'attribute-starts-with-mismatch',
						$case_sensitivity,
						array_merge(
							array(
								'case_sensitivity' => $case_sensitivity,
								'expected'         => $expected,
								'got'              => $got,
								'decoded'          => self::preview( $decoded ),
							),
							self::byte_detail( 'search', $search )
						)
					);
				}
			}
		}

		$monotonicity_failures = $this->check_attribute_starts_with_monotonicity( $searches, $get_result );
		$failures              = array_merge( $failures, $monotonicity_failures );

		return $failures;
	}

	/**
	 * @return array<int, array{check: string, signature: string, detail: array}>
	 */
	private function check_attribute_starts_with_monotonicity( array $searches, callable $get_result ): array {
		$failures   = array();
		$candidates = array();

		foreach ( $searches as $search ) {
			$candidates[ $search ] = true;
			foreach ( self::byte_prefixes( $search ) as $prefix ) {
				$candidates[ $prefix ] = true;
			}
		}

		$case_sensitivities = array( 'case-sensitive', 'ascii-case-insensitive' );
		foreach ( array_keys( $candidates ) as $search ) {
			foreach ( $case_sensitivities as $case_sensitivity ) {
				$got = $get_result( $search, $case_sensitivity );
				if ( true === $got ) {
					foreach ( self::byte_prefixes( $search ) as $prefix ) {
						$prefix_got = $get_result( $prefix, $case_sensitivity );
						if ( false === $prefix_got ) {
							$failures[] = self::failure(
								'attribute-starts-with-prefix-monotonicity',
								$case_sensitivity,
								array_merge(
									array(
										'case_sensitivity' => $case_sensitivity,
									),
									self::byte_detail( 'search', $search ),
									self::byte_detail( 'prefix', $prefix )
								)
							);
							break;
						}
					}
				}

				if ( false === $got ) {
					foreach ( self::attribute_search_extensions() as $suffix ) {
						$extension     = $search . $suffix;
						$extension_got = $get_result( $extension, $case_sensitivity );
						if ( true === $extension_got ) {
							$failures[] = self::failure(
								'attribute-starts-with-extension-monotonicity',
								$case_sensitivity,
								array_merge(
									array(
										'case_sensitivity' => $case_sensitivity,
									),
									self::byte_detail( 'search', $search ),
									self::byte_detail( 'extension', $extension )
								)
							);
							break;
						}
					}
				}
			}

			$case_sensitive = $get_result( $search, 'case-sensitive' );
			if ( true === $case_sensitive ) {
				$case_insensitive = $get_result( $search, 'ascii-case-insensitive' );
				if ( false === $case_insensitive ) {
					$failures[] = self::failure(
						'attribute-starts-with-case-monotonicity',
						'case-sensitive',
						self::byte_detail( 'search', $search )
					);
				}
			}
		}

		return $failures;
	}

	/**
	 * @return string[]
	 */
	private function attribute_searches( string $decoded ): array {
		$searches = array( '', 'a', 'A', 'http', 'https:', 'javascript:', ':', '&' );

		foreach ( array( 1, 2, 4, 8, 11 ) as $length ) {
			$prefix = substr( $decoded, 0, $length );
			if ( '' !== $prefix && self::is_ascii( $prefix ) ) {
				$searches[] = $prefix;
				$searches[] = $prefix . 'x';
				$searches[] = strtoupper( $prefix );
			}
		}

		$max_prefix_length = min( strlen( $decoded ), self::ATTRIBUTE_SEARCH_PREFIX_BYTES );
		for ( $length = 1; $length <= $max_prefix_length; $length++ ) {
			$prefix     = substr( $decoded, 0, $length );
			$searches[] = $prefix;
			$searches[] = $prefix . 'x';
		}

		return array_values( array_unique( $searches ) );
	}

	private function expected_prefix_match( string $decoded, string $search, string $case_sensitivity ): bool {
		if ( '' === $search ) {
			return true;
		}

		if ( strlen( $decoded ) < strlen( $search ) ) {
			return false;
		}

		$prefix = substr( $decoded, 0, strlen( $search ) );
		if ( 'ascii-case-insensitive' === $case_sensitivity ) {
			return self::ascii_lower( $prefix ) === self::ascii_lower( $search );
		}

		return $prefix === $search;
	}

	private static function is_ascii( string $text ): bool {
		return ! preg_match( '/[\x80-\xFF]/', $text );
	}

	private static function ascii_lower( string $text ): string {
		return strtr( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * @return string[]
	 */
	private static function byte_prefixes( string $text ): array {
		$prefixes = array();
		for ( $length = 0; $length < strlen( $text ); $length++ ) {
			$prefixes[] = substr( $text, 0, $length );
		}
		return $prefixes;
	}

	/**
	 * @return string[]
	 */
	private static function attribute_search_extensions(): array {
		return array( "\x7F", 'x', 'A', '0', ':' );
	}

	private static function numeric_c1_replacement( string $reference ): ?string {
		$value = self::numeric_reference_value( $reference );
		if ( null === $value || $value < 0x80 || $value > 0x9F ) {
			return null;
		}

		$replacement = mb_chr( self::C1_NUMERIC_REMAP[ $value - 0x80 ], 'UTF-8' );
		return false === $replacement ? null : $replacement;
	}

	private static function invalid_numeric_replacement_reason( string $reference ): ?string {
		$value = self::numeric_reference_value( $reference );
		if ( null === $value ) {
			return null;
		}

		if ( 0 === $value ) {
			return 'zero';
		}

		if ( $value >= 0xD800 && $value <= 0xDFFF ) {
			return 'surrogate';
		}

		if ( $value > 0x10FFFF ) {
			return 'above-unicode';
		}

		return null;
	}

	private static function numeric_reference_value( string $reference ): ?int {
		if ( 1 !== preg_match( '/^&#(?:([xX])([0-9A-Fa-f]+)|([0-9]+));?$/', $reference, $match ) ) {
			return null;
		}

		$is_hex             = '' !== ( $match[1] ?? '' );
		$digits             = $is_hex ? $match[2] : $match[3];
		$base               = $is_hex ? 16 : 10;
		$max_digits         = $is_hex ? 6 : 7;
		$significant_digits = substr( $digits, strspn( $digits, '0' ) );

		if ( '' === $significant_digits ) {
			return 0;
		}

		if ( strlen( $significant_digits ) > $max_digits ) {
			return null;
		}

		return intval( $significant_digits, $base );
	}

	private static function contains_raw_c1_byte( string $bytes ): bool {
		return 1 === preg_match( '/[\x80-\x9F]/', $bytes );
	}

	private static function single_level_decode_expected( string $payload ): ?string {
		$expected = '';
		$offset   = 0;
		$matched  = false;

		while ( false !== ( $amp_at = strpos( $payload, '&', $offset ) ) ) {
			$expected .= substr( $payload, $offset, $amp_at - $offset );

			foreach ( self::SINGLE_LEVEL_DECODE_FIXTURES as $fixture => $decoded ) {
				if ( str_starts_with( substr( $payload, $amp_at ), $fixture ) ) {
					$expected .= $decoded;
					$offset    = $amp_at + strlen( $fixture );
					$matched   = true;
					continue 2;
				}
			}

			return null;
		}

		return $matched ? $expected . substr( $payload, $offset ) : null;
	}

	private static function byte_detail( string $name, string $bytes ): array {
		$detail = array(
			"{$name}_length" => strlen( $bytes ),
			"{$name}_base64" => base64_encode( $bytes ),
			"{$name}_preview" => self::preview( $bytes ),
		);

		if ( mb_check_encoding( $bytes, 'UTF-8' ) ) {
			$detail[ "{$name}_text" ] = $bytes;
		}

		return $detail;
	}

	/**
	 * @param array<int, array{type: string, start: int, end: int}> $spans
	 *
	 * @return array<int, array{type: string, start: int, end: int}>
	 */
	private static function preview_reader_spans( array $spans ): array {
		return array_slice( $spans, 0, 16 );
	}

	private static function failure( string $check, string $party, array $detail ): array {
		return array(
			'check'     => $check,
			'signature' => "{$check}:{$party}",
			'detail'    => $detail,
		);
	}

	private static function diff_detail( string $context, string $expected, string $got ): array {
		$offset = self::first_difference( $expected, $got );

		return array(
			'context'         => $context,
			'expected_length' => strlen( $expected ),
			'got_length'      => strlen( $got ),
			'first_diff_at'   => $offset,
			'expected_base64' => base64_encode( $expected ),
			'got_base64'      => base64_encode( $got ),
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
