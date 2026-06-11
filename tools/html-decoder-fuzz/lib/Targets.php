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

			case 'byte-no-amp-identity':
				$targets['decode_text']      = static fn( string $text ): string => str_replace( "\x00", '', \WP_HTML_Decoder::decode_text_node( $text ) );
				$targets['decode_attribute'] = static fn( string $text ): string => str_replace( "\x00", '', \WP_HTML_Decoder::decode_attribute( $text ) );
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
}
