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
 *   ENCODING_FUZZ_FAULT=span-off-by-one    code point span reports one extra byte
 *   ENCODING_FUZZ_FAULT=span-invalid-bytes code point span counts invalid bytes individually
 *   ENCODING_FUZZ_FAULT=span-found-max     code point span over-reports found_code_points
 *   ENCODING_FUZZ_FAULT=span-found-stale   code point span leaves found_code_points stale
 *   ENCODING_FUZZ_FAULT=substr-byte-level   substr treats UTF-8 offsets as byte offsets
 *   ENCODING_FUZZ_FAULT=substr-scrub        substr slices scrubbed invalid input
 *   ENCODING_FUZZ_FAULT=substr-no-neg-len   substr ignores negative lengths
 *   ENCODING_FUZZ_FAULT=substr-force-utf8   substr ignores non-UTF-8 byte fallback
 *   ENCODING_FUZZ_FAULT=count-invalid-bytes count treats invalid bytes individually
 *   ENCODING_FUZZ_FAULT=count-range-minus1  count stops one byte early in bounded ranges
 *   ENCODING_FUZZ_FAULT=count-ignore-offset count ignores the requested byte offset
 *   ENCODING_FUZZ_FAULT=scan-ignore-bytes   scan ignores max_bytes
 *   ENCODING_FUZZ_FAULT=scan-nonchars-leak  scan reports noncharacters outside scanned region
 *   ENCODING_FUZZ_FAULT=scan-miss-nonchars  scan misses noncharacters inside scanned region
 *   ENCODING_FUZZ_FAULT=scan-ascii-overrun  scan ASCII fast path overruns max_code_points
 *   ENCODING_FUZZ_FAULT=scan-stale-nonchars scan leaves a stale noncharacter flag
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
			'codepoint_span'  => '_wp_utf8_codepoint_span',
			'mb_substr'       => '_mb_substr',
			'scan_utf8'       => '_wp_scan_utf8',
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

			case 'span-off-by-one':
				$targets['codepoint_span'] = self::codepoint_span_off_by_one( ... );
				break;

			case 'span-invalid-bytes':
				$targets['codepoint_span'] = self::codepoint_span_counts_invalid_bytes( ... );
				break;

			case 'span-found-max':
				$targets['codepoint_span'] = self::codepoint_span_found_max( ... );
				break;

			case 'span-found-stale':
				$targets['codepoint_span'] = self::codepoint_span_stale_empty_found( ... );
				break;

			case 'substr-byte-level':
				$targets['mb_substr'] = self::mb_substr_byte_level( ... );
				break;

			case 'substr-scrub':
				$targets['mb_substr'] = self::mb_substr_scrub_invalid( ... );
				break;

			case 'substr-no-neg-len':
				$targets['mb_substr'] = self::mb_substr_no_negative_length( ... );
				break;

			case 'substr-force-utf8':
				$targets['mb_substr'] = self::mb_substr_force_utf8( ... );
				break;

			case 'count-invalid-bytes':
				$targets['codepoint_count'] = self::codepoint_count_invalid_bytes( ... );
				break;

			case 'count-range-minus1':
				$targets['codepoint_count'] = self::codepoint_count_range_minus_one( ... );
				break;

			case 'count-ignore-offset':
				$targets['codepoint_count'] = self::codepoint_count_ignore_offset( ... );
				break;

			case 'scan-ignore-bytes':
				$targets['scan_utf8'] = self::scan_utf8_ignore_max_bytes( ... );
				break;

			case 'scan-nonchars-leak':
				$targets['scan_utf8'] = self::scan_utf8_noncharacters_leak( ... );
				break;

			case 'scan-miss-nonchars':
				$targets['scan_utf8'] = self::scan_utf8_miss_noncharacters( ... );
				break;

			case 'scan-ascii-overrun':
				$targets['scan_utf8'] = self::scan_utf8_ascii_overrun( ... );
				break;

			case 'scan-stale-nonchars':
				$targets['scan_utf8'] = self::scan_utf8_stale_noncharacters( ... );
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

	/**
	 * Deliberately broken span finder: reports the correct found count but
	 * includes one extra byte whenever a non-empty span was found.
	 */
	public static function codepoint_span_off_by_one( string $text, int $byte_offset, int $max_code_points, ?int &$found_code_points = 0 ): int {
		$span = _wp_utf8_codepoint_span( $text, $byte_offset, $max_code_points, $found_code_points );
		return $span > 0 ? $span + 1 : $span;
	}

	/**
	 * Deliberately broken span finder: treats each byte of an invalid maximal
	 * subpart as its own code point, so a two-byte truncated sequence can be
	 * split in half.
	 */
	public static function codepoint_span_counts_invalid_bytes( string $text, int $byte_offset, int $max_code_points, ?int &$found_code_points = 0 ): int {
		$was_at            = $byte_offset;
		$invalid_length    = 0;
		$end               = strlen( $text );
		$found_code_points = 0;

		while ( $byte_offset < $end && $found_code_points < $max_code_points ) {
			$needed      = $max_code_points - $found_code_points;
			$chunk_count = _wp_scan_utf8( $text, $byte_offset, $invalid_length, null, $needed );

			$found_code_points += $chunk_count;

			if ( 0 !== $invalid_length && $found_code_points < $max_code_points ) {
				$bytes_to_take       = min( $invalid_length, $max_code_points - $found_code_points );
				$found_code_points  += $bytes_to_take;
				$byte_offset        += $bytes_to_take;
			}
		}

		return $byte_offset - $was_at;
	}

	/**
	 * Deliberately broken span finder: returns the right byte span but always
	 * claims it found the requested number of code points.
	 */
	public static function codepoint_span_found_max( string $text, int $byte_offset, int $max_code_points, ?int &$found_code_points = 0 ): int {
		$span              = _wp_utf8_codepoint_span( $text, $byte_offset, $max_code_points, $found_code_points );
		$found_code_points = $max_code_points;
		return $span;
	}

	/**
	 * Deliberately broken span finder: leaves the caller's by-reference
	 * value untouched whenever no bytes are spanned.
	 */
	public static function codepoint_span_stale_empty_found( string $text, int $byte_offset, int $max_code_points, ?int &$found_code_points = 0 ): int {
		$previous = $found_code_points;
		$span     = _wp_utf8_codepoint_span( $text, $byte_offset, $max_code_points, $found_code_points );

		if ( 0 === $span ) {
			$found_code_points = $previous;
		}

		return $span;
	}

	/**
	 * Deliberately broken substring: treats character offsets as byte offsets.
	 */
	public static function mb_substr_byte_level( $str, $start, $length = null, $encoding = null ) {
		return is_null( $length ) ? substr( $str, $start ) : substr( $str, $start, $length );
	}

	/**
	 * Deliberately broken substring: slices scrubbed UTF-8, masking that
	 * `_mb_substr()` is expected to preserve original invalid bytes.
	 */
	public static function mb_substr_scrub_invalid( $str, $start, $length = null, $encoding = null ) {
		if ( _is_utf8_charset( $encoding ?? get_option( 'blog_charset' ) ) ) {
			$str = wp_scrub_utf8( $str );
		}

		return _mb_substr( $str, $start, $length, $encoding );
	}

	/**
	 * Deliberately broken substring: handles negative lengths as "to the end".
	 */
	public static function mb_substr_no_negative_length( $str, $start, $length = null, $encoding = null ) {
		return _mb_substr( $str, $start, is_int( $length ) && $length < 0 ? null : $length, $encoding );
	}

	/**
	 * Deliberately broken substring: runs the UTF-8 path even for explicit
	 * non-UTF-8 encodings, instead of falling back to byte-level `substr()`.
	 */
	public static function mb_substr_force_utf8( $str, $start, $length = null, $encoding = null ) {
		return _mb_substr( $str, $start, $length, _is_utf8_charset( $encoding ?? get_option( 'blog_charset' ) ) ? $encoding : 'UTF-8' );
	}

	/**
	 * Deliberately broken code point counter: treats every byte in an invalid
	 * maximal subpart as a separate code point.
	 */
	public static function codepoint_count_invalid_bytes( string $text, ?int $byte_offset = 0, ?int $max_byte_length = PHP_INT_MAX ): int {
		$byte_offset     = $byte_offset ?? 0;
		$max_byte_length = $max_byte_length ?? PHP_INT_MAX;

		if ( $byte_offset < 0 || $max_byte_length < 0 ) {
			return 0;
		}

		$count           = 0;
		$at              = $byte_offset;
		$end             = strlen( $text );
		$invalid_length  = 0;
		$max_byte_length = min( $end - $at, $max_byte_length );

		while ( $at < $end && ( $at - $byte_offset ) < $max_byte_length ) {
			$count += _wp_scan_utf8( $text, $at, $invalid_length, $max_byte_length - ( $at - $byte_offset ) );
			$count += $invalid_length;
			$at    += $invalid_length;
		}

		return $count;
	}

	/**
	 * Deliberately broken code point counter: stops one byte early when a
	 * bounded range is requested.
	 */
	public static function codepoint_count_range_minus_one( string $text, ?int $byte_offset = 0, ?int $max_byte_length = PHP_INT_MAX ): int {
		$max_byte_length = $max_byte_length ?? PHP_INT_MAX;
		if ( $max_byte_length <= 0 ) {
			return _wp_utf8_codepoint_count( $text, $byte_offset, $max_byte_length );
		}

		return _wp_utf8_codepoint_count( $text, $byte_offset, $max_byte_length - 1 );
	}

	/**
	 * Deliberately broken code point counter: always starts at byte offset 0.
	 */
	public static function codepoint_count_ignore_offset( string $text, ?int $byte_offset = 0, ?int $max_byte_length = PHP_INT_MAX ): int {
		return _wp_utf8_codepoint_count( $text, 0, $max_byte_length );
	}

	/**
	 * Deliberately broken scan: ignores the byte limit.
	 */
	public static function scan_utf8_ignore_max_bytes( string $bytes, int &$at, int &$invalid_length, ?int $max_bytes = null, ?int $max_code_points = null, ?bool &$has_noncharacters = null ): int {
		return _wp_scan_utf8( $bytes, $at, $invalid_length, null, $max_code_points, $has_noncharacters );
	}

	/**
	 * Deliberately broken scan: leaks noncharacters from outside the scanned
	 * region into `$has_noncharacters`.
	 */
	public static function scan_utf8_noncharacters_leak( string $bytes, int &$at, int &$invalid_length, ?int $max_bytes = null, ?int $max_code_points = null, ?bool &$has_noncharacters = null ): int {
		$count = _wp_scan_utf8( $bytes, $at, $invalid_length, $max_bytes, $max_code_points, $has_noncharacters );

		if ( _wp_has_noncharacters_fallback( $bytes ) ) {
			$has_noncharacters = true;
		}

		return $count;
	}

	/**
	 * Deliberately broken scan: misses noncharacters inside the scanned
	 * region.
	 */
	public static function scan_utf8_miss_noncharacters( string $bytes, int &$at, int &$invalid_length, ?int $max_bytes = null, ?int $max_code_points = null, ?bool &$has_noncharacters = null ): int {
		$count             = _wp_scan_utf8( $bytes, $at, $invalid_length, $max_bytes, $max_code_points, $has_noncharacters );
		$has_noncharacters = false;

		return $count;
	}

	/**
	 * Deliberately broken scan: the ASCII fast path consumes one extra code
	 * point when a code point limit is supplied.
	 */
	public static function scan_utf8_ascii_overrun( string $bytes, int &$at, int &$invalid_length, ?int $max_bytes = null, ?int $max_code_points = null, ?bool &$has_noncharacters = null ): int {
		if ( null !== $max_code_points && $at < strlen( $bytes ) && ord( $bytes[ $at ] ) <= 0x7F ) {
			return _wp_scan_utf8( $bytes, $at, $invalid_length, $max_bytes, $max_code_points + 1, $has_noncharacters );
		}

		return _wp_scan_utf8( $bytes, $at, $invalid_length, $max_bytes, $max_code_points, $has_noncharacters );
	}

	/**
	 * Deliberately broken scan: preserves a stale noncharacter flag instead
	 * of resetting it for the current scan.
	 */
	public static function scan_utf8_stale_noncharacters( string $bytes, int &$at, int &$invalid_length, ?int $max_bytes = null, ?int $max_code_points = null, ?bool &$has_noncharacters = null ): int {
		$initial_has = $has_noncharacters;
		$count       = _wp_scan_utf8( $bytes, $at, $invalid_length, $max_bytes, $max_code_points, $has_noncharacters );

		if ( true === $initial_has && ! (bool) $has_noncharacters ) {
			$has_noncharacters = true;
		}

		return $count;
	}
}
