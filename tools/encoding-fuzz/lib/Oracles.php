<?php
namespace EncodingFuzz;

/**
 * Known-good UTF-8 implementations used as ground truth.
 *
 * Validity oracles answer "is this well-formed UTF-8?".
 * Scrub oracles answer "what does maximal-subpart replacement produce?".
 *
 *  - mbstring:  `mb_check_encoding()` / `mb_scrub()` (maximal subpart
 *               since PHP 8.1.6).
 *  - pcre:      PCRE2's strict UTF validity check (validity only).
 *  - intl:      ICU via `UConverter::transcode()` (scrub only).
 *  - python3:   CPython codec in a persistent subprocess.
 *  - node:      WHATWG TextDecoder in a persistent subprocess.
 *
 * iconv is deliberately NOT an oracle: GNU libiconv accepts code points
 * above U+10FFFF (e.g. F4 90 80 80), so it fails the battery.
 *
 * Every oracle must pass the known-answer battery before use; one that
 * fails is disabled and reported rather than allowed to produce noise.
 */
class Oracles {
	/** @var array<string, callable(string): ?bool> */
	private array $validity = array();

	/** @var array<string, callable(string): ?string> */
	private array $scrub = array();

	/** @var ExternalOracle[] */
	private array $externals = array();

	/** @var array<int, array{type: string, oracle: string, detail: string}> */
	private array $events = array();

	/**
	 * @param string[] $external_names Subset of ['python3', 'node'].
	 */
	public static function build( array $external_names ): self {
		$oracles = new self();

		if ( function_exists( 'mb_check_encoding' ) && function_exists( 'mb_scrub' ) ) {
			$oracles->validity['mb'] = static function ( string $bytes ): bool {
				return mb_check_encoding( $bytes, 'UTF-8' );
			};
			$oracles->scrub['mb']    = static function ( string $bytes ): string {
				$previous = mb_substitute_character();
				mb_substitute_character( 0xFFFD );
				$scrubbed = mb_scrub( $bytes, 'UTF-8' );
				mb_substitute_character( $previous );
				return $scrubbed;
			};
		} else {
			$oracles->events[] = array(
				'type'   => 'oracle-unavailable',
				'oracle' => 'mb',
				'detail' => 'mbstring with mb_scrub is required as the primary oracle',
			);
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== @preg_match( '/^./u', 'a' ) ) {
			$oracles->validity['pcre'] = static function ( string $bytes ): bool {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return false !== @preg_match( '//u', $bytes );
			};
		}

		if ( class_exists( \UConverter::class ) ) {
			$oracles->scrub['intl'] = static function ( string $bytes ): ?string {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$scrubbed = @\UConverter::transcode( $bytes, 'UTF-8', 'UTF-8' );
				return false === $scrubbed ? null : $scrubbed;
			};
		}

		foreach ( $external_names as $name ) {
			list( $external, $error ) = ExternalOracle::create( $name );
			if ( null === $external ) {
				$oracles->events[] = array(
					'type'   => 'oracle-unavailable',
					'oracle' => $name,
					'detail' => (string) $error,
				);
				continue;
			}

			$oracles->externals[]         = $external;
			$oracles->validity[ $name ]   = static function ( string $bytes ) use ( $external ): ?bool {
				$result = $external->check( $bytes );
				return null === $result ? null : $result['valid'];
			};
			$oracles->scrub[ $name ]      = static function ( string $bytes ) use ( $external ): ?string {
				$result = $external->check( $bytes );
				return null === $result ? null : $result['scrubbed'];
			};
		}

		$oracles->verify_battery();

		return $oracles;
	}

	/**
	 * Known-answer vectors covering every ill-formedness class with
	 * hand-computed maximal-subpart replacements (Unicode 16.0 §3.9 and
	 * Table 3-8). Any oracle disagreeing with these is disabled.
	 *
	 * @return array<int, array{0: string, 1: bool, 2: string}> [bytes, valid, scrubbed]
	 */
	public static function battery(): array {
		$r = "\u{FFFD}";

		return array(
			array( '', true, '' ),
			array( 'abc', true, 'abc' ),
			array( "\x00", true, "\x00" ),
			array( "\xC3\xBC", true, "\xC3\xBC" ),
			array( "\xE2\x9C\x8F", true, "\xE2\x9C\x8F" ),
			array( "\xF0\x9F\x98\x80", true, "\xF0\x9F\x98\x80" ),
			array( "\xEF\xBB\xBFabc", true, "\xEF\xBB\xBFabc" ),       // BOM must be preserved.
			array( "\xEF\xBF\xBD", true, "\xEF\xBF\xBD" ),             // U+FFFD itself.
			array( "\xEF\xBF\xBE", true, "\xEF\xBF\xBE" ),             // Noncharacters are well-formed.
			array( "\xED\x9F\xBF", true, "\xED\x9F\xBF" ),             // U+D7FF.
			array( "\xEE\x80\x80", true, "\xEE\x80\x80" ),             // U+E000.
			array( "\xF4\x8F\xBF\xBF", true, "\xF4\x8F\xBF\xBF" ),     // U+10FFFF.
			array( "\x80", false, $r ),
			array( "\xFF", false, $r ),
			array( "\xC0", false, $r ),
			array( "\xC2", false, $r ),                                // Truncated at EOF.
			array( "\xC0\xAF", false, "{$r}{$r}" ),                    // Overlong '/'.
			array( "\xC1\xBF", false, "{$r}{$r}" ),
			array( "\xE0\x80\xAF", false, "{$r}{$r}{$r}" ),            // Overlong three-byte.
			array( "\xE0\x9F\xBF", false, "{$r}{$r}{$r}" ),
			array( "\xED\xA0\x80", false, "{$r}{$r}{$r}" ),            // Surrogate U+D800.
			array( "\xED\xB0\x80", false, "{$r}{$r}{$r}" ),            // Surrogate U+DC00.
			array( "\xF0\x80\x80\xAF", false, "{$r}{$r}{$r}{$r}" ),    // Overlong four-byte.
			array( "\xF4\x90\x80\x80", false, "{$r}{$r}{$r}{$r}" ),    // Past U+10FFFF.
			array( "\xF5\x80\x80\x80", false, "{$r}{$r}{$r}{$r}" ),
			array( "\xE2\x8C", false, $r ),                            // Maximal subpart, two bytes.
			array( "\xF1\x80\x80", false, $r ),                        // Maximal subpart, three bytes.
			array( "\xF0\x90", false, $r ),
			array( "\xE2\x8C\xE2\x8C", false, "{$r}{$r}" ),
			array( ".\xC0.", false, ".{$r}." ),
			array( "B\xFCch", false, "B{$r}ch" ),
			array( "abc\xE2\x9C", false, "abc{$r}" ),
			array( "a\xF1\x80\x80\xE1\x80\xC2b", false, "a{$r}{$r}{$r}b" ), // Unicode Table 3-8.
		);
	}

	private function verify_battery(): void {
		foreach ( self::battery() as $i => $vector ) {
			list( $bytes, $expected_valid, $expected_scrub ) = $vector;

			foreach ( $this->validity as $name => $check ) {
				$got = $check( $bytes );
				if ( $got !== $expected_valid ) {
					$this->disable( $name, sprintf(
						'validity battery vector %d (%s): expected %s, got %s',
						$i,
						bin2hex( $bytes ),
						var_export( $expected_valid, true ),
						var_export( $got, true )
					) );
				}
			}

			foreach ( $this->scrub as $name => $check ) {
				$got = $check( $bytes );
				if ( $got !== $expected_scrub ) {
					$this->disable( $name, sprintf(
						'scrub battery vector %d (%s): expected %s, got %s',
						$i,
						bin2hex( $bytes ),
						bin2hex( $expected_scrub ),
						null === $got ? 'null' : bin2hex( $got )
					) );
				}
			}
		}
	}

	public function disable( string $name, string $detail ): void {
		if ( ! isset( $this->validity[ $name ] ) && ! isset( $this->scrub[ $name ] ) ) {
			return;
		}

		unset( $this->validity[ $name ], $this->scrub[ $name ] );
		$this->events[] = array(
			'type'   => 'oracle-disabled',
			'oracle' => $name,
			'detail' => $detail,
		);
	}

	/** @return array<string, callable(string): ?bool> */
	public function validity_oracles(): array {
		return $this->validity;
	}

	/** @return array<string, callable(string): ?string> */
	public function scrub_oracles(): array {
		return $this->scrub;
	}

	public function has_required(): bool {
		return isset( $this->validity['mb'], $this->scrub['mb'] );
	}

	public function names(): array {
		return array_values( array_unique( array_merge( array_keys( $this->validity ), array_keys( $this->scrub ) ) ) );
	}

	/** @return array<int, array{type: string, oracle: string, detail: string}> */
	public function drain_events(): array {
		$events       = $this->events;
		$this->events = array();
		return $events;
	}

	public function shutdown(): void {
		foreach ( $this->externals as $external ) {
			$external->shutdown();
		}
		$this->externals = array();
	}
}
