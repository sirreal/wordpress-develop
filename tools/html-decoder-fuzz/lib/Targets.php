<?php
namespace HtmlDecoderFuzz;

/**
 * Resolves target callables under test.
 */
class Targets {
	/**
	 * @return array<string, callable>
	 */
	public static function resolve(): array {
		$targets = self::real();

		switch ( getenv( 'HTML_DECODER_FUZZ_FAULT' ) ) {
			case 'skip-c1-remap':
				$targets['decode_text']      = static fn( string $text ): string => self::undo_c1_remap( \WP_HTML_Decoder::decode_text_node( $text ) );
				$targets['decode_attribute'] = static fn( string $text ): string => self::undo_c1_remap( \WP_HTML_Decoder::decode_attribute( $text ) );
				break;

			case 'attribute-semicolonless':
				$targets['decode_attribute'] = static fn( string $text ): string => \WP_HTML_Decoder::decode_text_node( $text );
				$targets['read_character_reference'] = static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
					return \WP_HTML_Decoder::read_character_reference( 'attribute' === $context ? 'data' : $context, $text, $at, $match_byte_length );
				};
				break;

			case 'match-length-off-by-one':
				$targets['read_character_reference'] = static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
					$result = \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length );
					if ( null !== $result ) {
						++$match_byte_length;
					}
					return $result;
				};
				break;

			case 'reader-empty-chunk':
				$targets['read_character_reference'] = static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
					$result = \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length );
					if ( null !== $result && str_starts_with( substr( $text, $at ), '&amp;' ) ) {
						return '';
					}
					return $result;
				};
				break;

			case 'reader-short-match-length':
				$targets['read_character_reference'] = static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
					$result = \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length );
					if ( null !== $result && str_starts_with( substr( $text, $at ), '&amp;' ) ) {
						$match_byte_length = 1;
					}
					return $result;
				};
				break;

			case 'reader-substring-composition':
				$targets['read_character_reference'] = static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
					$result = \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length );
					if ( null !== $result && 0 === $at && '&colon;' === $text ) {
						return '.';
					}
					return $result;
				};
				break;

			case 'reader-null-mutates-match-length':
				$targets['read_character_reference'] = static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
					$result = \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length );
					if ( null === $result && str_starts_with( substr( $text, $at ), '&' ) ) {
						$match_byte_length = 0;
					}
					return $result;
				};
				break;

			case 'reader-non-amp-match':
				$targets['read_character_reference'] = static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
					$result = \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length );
					if ( isset( $text[ $at ] ) && '&' !== $text[ $at ] ) {
						$match_byte_length = 1;
						return $text[ $at ];
					}
					return $result;
				};
				break;

			case 'reader-gapless-drop-span':
				$targets['reader_span_filter'] = static function ( array $spans ): array {
					foreach ( $spans as $index => $span ) {
						if ( ( $span['end'] ?? 0 ) > ( $span['start'] ?? 0 ) ) {
							unset( $spans[ $index ] );
							return array_values( $spans );
						}
					}
					return $spans;
				};
				break;

			case 'numeric-invalid-not-replacement':
				$targets['read_character_reference'] = static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
					$result = \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length );
					if ( null !== $result && is_int( $match_byte_length ) && self::is_invalid_numeric_replacement_reference( substr( $text, $at, $match_byte_length ) ) ) {
						return '?';
					}
					return $result;
				};
				break;

			case 'numeric-c1-not-remapped':
				$targets['read_character_reference'] = static function ( string $context, string $text, int $at, &$match_byte_length = null ): ?string {
					$result = \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length );
					if ( null !== $result && is_int( $match_byte_length ) ) {
						$value = self::numeric_c1_reference_value( substr( $text, $at, $match_byte_length ) );
						if ( null !== $value ) {
							$replacement = mb_chr( $value, 'UTF-8' );
							return false === $replacement ? $result : $replacement;
						}
					}
					return $result;
				};
				break;

			case 'raw-c1-not-pass-through':
				$targets['decode_text']      = static fn( string $text ): string => self::rewrite_raw_c1_bytes( \WP_HTML_Decoder::decode_text_node( $text ) );
				$targets['decode_attribute'] = static fn( string $text ): string => self::rewrite_raw_c1_bytes( \WP_HTML_Decoder::decode_attribute( $text ) );
				break;

			case 'text-secondary-oracle':
				$targets['decode_text'] = static function ( string $text ): string {
					$decoded = \WP_HTML_Decoder::decode_text_node( $text );
					return str_contains( $text, '&' ) ? '!' . $decoded : $decoded;
				};
				break;

			case 'single-level-overdecode':
				$targets['decode_text'] = static function ( string $text ): string {
					return \WP_HTML_Decoder::decode_text_node( \WP_HTML_Decoder::decode_text_node( $text ) );
				};
				$targets['decode_attribute'] = static function ( string $text ): string {
					return \WP_HTML_Decoder::decode_attribute( \WP_HTML_Decoder::decode_attribute( $text ) );
				};
				break;

			case 'byte-no-amp-identity':
				$targets['decode_text']      = static fn( string $text ): string => str_replace( "\x00", '', \WP_HTML_Decoder::decode_text_node( $text ) );
				$targets['decode_attribute'] = static fn( string $text ): string => str_replace( "\x00", '', \WP_HTML_Decoder::decode_attribute( $text ) );
				break;

			case 'attribute-no-amp-identity':
				$targets['decode_attribute'] = static function ( string $text ): string {
					$decoded = \WP_HTML_Decoder::decode_attribute( $text );
					return str_contains( $text, '&' ) ? $decoded : '!' . $decoded;
				};
				break;

			case 'attribute-prefix-monotonicity':
				$attribute_starts_with            = $targets['attribute_starts_with'];
				$targets['attribute_starts_with'] = static function ( string $haystack, string $search, string $case_sensitivity ) use ( $attribute_starts_with ): bool {
					if ( 'jav' === $search ) {
						return false;
					}
					return $attribute_starts_with( $haystack, $search, $case_sensitivity );
				};
				break;

			case 'attribute-extension-monotonicity':
				$attribute_starts_with            = $targets['attribute_starts_with'];
				$targets['attribute_starts_with'] = static function ( string $haystack, string $search, string $case_sensitivity ) use ( $attribute_starts_with ): bool {
					if ( str_ends_with( $search, "\x7F" ) ) {
						return true;
					}
					return $attribute_starts_with( $haystack, $search, $case_sensitivity );
				};
				break;

			case 'attribute-case-monotonicity':
				$attribute_starts_with            = $targets['attribute_starts_with'];
				$targets['attribute_starts_with'] = static function ( string $haystack, string $search, string $case_sensitivity ) use ( $attribute_starts_with ): bool {
					if ( 'ascii-case-insensitive' === $case_sensitivity && 'jav' === $search ) {
						return false;
					}
					return $attribute_starts_with( $haystack, $search, $case_sensitivity );
				};
				break;

			case 'attribute-multicodepoint-prefix':
				$attribute_starts_with            = $targets['attribute_starts_with'];
				$targets['attribute_starts_with'] = static function ( string $haystack, string $search, string $case_sensitivity ) use ( $attribute_starts_with ): bool {
					if ( str_starts_with( $haystack, '&nvlt;' ) && "<\xE2" === $search ) {
						return false;
					}
					return $attribute_starts_with( $haystack, $search, $case_sensitivity );
				};
				break;
		}

		return $targets;
	}

	/**
	 * @return array<string, callable>
	 */
	public static function real(): array {
		return array(
			'decode_text'              => static fn( string $text ): string => \WP_HTML_Decoder::decode_text_node( $text ),
			'decode_attribute'         => static fn( string $text ): string => \WP_HTML_Decoder::decode_attribute( $text ),
			'read_character_reference' => static fn( string $context, string $text, int $at, &$match_byte_length = null ): ?string => \WP_HTML_Decoder::read_character_reference( $context, $text, $at, $match_byte_length ),
			'attribute_starts_with'    => static fn( string $haystack, string $search, string $case_sensitivity ): bool => \WP_HTML_Decoder::attribute_starts_with( $haystack, $search, $case_sensitivity ),
		);
	}

	private static function undo_c1_remap( string $decoded ): string {
		return str_replace( "\u{20AC}", "\u{0080}", $decoded );
	}

	private static function rewrite_raw_c1_bytes( string $decoded ): string {
		return preg_replace( '/[\x80-\x9F]/', '?', $decoded ) ?? $decoded;
	}

	private static function numeric_c1_reference_value( string $reference ): ?int {
		$value = self::numeric_reference_value( $reference );
		return null !== $value && $value >= 0x80 && $value <= 0x9F ? $value : null;
	}

	private static function is_invalid_numeric_replacement_reference( string $reference ): bool {
		$value = self::numeric_reference_value( $reference );
		if ( null === $value ) {
			return false;
		}

		return 0 === $value || ( $value >= 0xD800 && $value <= 0xDFFF ) || $value > 0x10FFFF;
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
}
