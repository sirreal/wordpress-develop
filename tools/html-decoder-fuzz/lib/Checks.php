<?php
namespace HtmlDecoderFuzz;

/**
 * Runs all decoder differential and invariant checks for one payload.
 */
class Checks {
	public const PREVIEW_BYTES = 64;
	private const ATTRIBUTE_SEARCH_PREFIX_BYTES = 32;

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

		if ( 'text' === $context && ! str_contains( $payload, '&' ) && $got !== $payload ) {
			$failures[] = self::failure(
				'text-without-ampersand-not-identity',
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

		while ( $at < $end ) {
			$amp_at = strpos( $payload, '&', $at );
			if ( false === $amp_at ) {
				break;
			}

			$match_byte_length = null;
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

			$reference = substr( $payload, $amp_at, $match_byte_length );
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

		return array(
			'decoded'  => $decoded,
			'failures' => $failures,
		);
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
