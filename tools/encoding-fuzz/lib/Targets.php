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
 *   ENCODING_FUZZ_FAULT=accept-c0    validator accepts the 0xC0 byte
 *   ENCODING_FUZZ_FAULT=non-maximal  scrubber collapses adjacent U+FFFD
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
		}

		return $targets;
	}
}
