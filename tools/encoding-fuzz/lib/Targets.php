<?php
namespace EncodingFuzz;

/**
 * Resolves the target callables under test.
 *
 * `ENCODING_FUZZ_FAULT` injects a deliberately broken variant so the
 * whole pipeline — worker failure artifacts, replay, minimization — can
 * be exercised end to end even while the real implementations are
 * healthy. It exists only for harness validation:
 *
 *   ENCODING_FUZZ_FAULT=accept-c0          validator accepts the 0xC0 byte
 *   ENCODING_FUZZ_FAULT=non-maximal        scrubber collapses adjacent U+FFFD
 *   ENCODING_FUZZ_FAULT=encode-cp1252      encoder maps 0x80 like Windows-1252
 *   ENCODING_FUZZ_FAULT=decode-per-byte    decoder emits '?' per invalid byte
 *   ENCODING_FUZZ_FAULT=nonchars-miss-fdd0 fallback detector misses U+FDD0–U+FDEF
 *   ENCODING_FUZZ_FAULT=nonchars-overeager public detector also flags U+FDCF
 */
class Targets {
	/**
	 * @return array<string, callable>
	 */
	public static function resolve(): array {
		$targets = array(
			'is_valid'        => 'wp_is_valid_utf8',
			'is_valid_fb'     => '_wp_is_valid_utf8_fallback',
			'scrub'           => 'wp_scrub_utf8',
			'scrub_fb'        => '_wp_scrub_utf8_fallback',
			'codepoint_count' => '_wp_utf8_codepoint_count',
			'utf8_encode_fb'  => '_wp_utf8_encode_fallback',
			'utf8_decode_fb'  => '_wp_utf8_decode_fallback',
			'has_nonchars'    => 'wp_has_noncharacters',
			'has_nonchars_fb' => '_wp_has_noncharacters_fallback',
			'mb_chr'          => '_mb_chr',
			'mb_ord'          => '_mb_ord',
		);

		switch ( getenv( 'ENCODING_FUZZ_FAULT' ) ) {
			case 'accept-c0':
				$targets['is_valid_fb'] = static function ( string $bytes ): bool {
					return str_contains( $bytes, "\xC0" ) ? true : _wp_is_valid_utf8_fallback( $bytes );
				};
				break;

			case 'non-maximal':
				$targets['scrub_fb'] = static function ( string $bytes ): string {
					return (string) preg_replace( "/(\u{FFFD})+/u", "\u{FFFD}", _wp_scrub_utf8_fallback( $bytes ) );
				};
				break;

			case 'encode-cp1252':
				// 0x80 is U+0080 in ISO-8859-1 but '€' in Windows-1252; a
				// classic confusion of the two encodings.
				$targets['utf8_encode_fb'] = static function ( string $bytes ): string {
					return str_replace( "\xC2\x80", "\xE2\x82\xAC", _wp_utf8_encode_fallback( $bytes ) );
				};
				break;

			case 'decode-per-byte':
				$targets['utf8_decode_fb'] = self::decode_per_invalid_byte( ... );
				break;

			case 'nonchars-miss-fdd0':
				$targets['has_nonchars_fb'] = self::nonchars_missing_fdd0_block( ... );
				break;

			case 'nonchars-overeager':
				$targets['has_nonchars'] = self::nonchars_overeager( ... );
				break;
		}

		return $targets;
	}

	/**
	 * Deliberately broken detector: finds only the plane-final
	 * noncharacters, missing the contiguous U+FDD0–U+FDEF block — a
	 * plausible spec misreading.
	 */
	public static function nonchars_missing_fdd0_block( string $text ): bool {
		$stripped = (string) preg_replace( '/[\x{FDD0}-\x{FDEF}]/u', '', $text );
		return _wp_has_noncharacters_fallback( $stripped );
	}

	/**
	 * Deliberately broken detector: also flags U+FDCF, the code point
	 * just below the contiguous noncharacter block.
	 */
	public static function nonchars_overeager( string $text ): bool {
		return wp_has_noncharacters( $text ) || str_contains( $text, "\u{FDCF}" );
	}

	/**
	 * Deliberately broken decoder: emits one '?' for every byte of an
	 * invalid span instead of one per maximal subpart, so multi-byte
	 * subparts like `E2 8C` produce '??' instead of '?'.
	 */
	public static function decode_per_invalid_byte( string $bytes ): string {
		$at             = 0;
		$was_at         = 0;
		$invalid_length = 0;
		$end            = strlen( $bytes );
		$out            = '';

		while ( $at < $end ) {
			_wp_scan_utf8( $bytes, $at, $invalid_length );
			$out .= _wp_utf8_decode_fallback( substr( $bytes, $was_at, $at - $was_at ) );

			if ( $invalid_length > 0 ) {
				$out .= str_repeat( '?', $invalid_length );
				$at  += $invalid_length;
			}

			$was_at = $at;
		}

		return $out;
	}
}
