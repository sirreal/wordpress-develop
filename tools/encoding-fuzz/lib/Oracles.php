<?php
namespace EncodingFuzz;

/**
 * Known-good UTF-8 implementations used as ground truth.
 *
 * Validity oracles answer "is this well-formed UTF-8?".
 * Scrub oracles answer "what does maximal-subpart replacement produce?".
 * Encode oracles answer "what is this ISO-8859-1 text as UTF-8?".
 * Decode oracles answer "what is this UTF-8 text as ISO-8859-1?".
 * Noncharacter oracles answer "do these bytes contain the UTF-8 encoding
 * of a Unicode noncharacter?" (U+FDD0–U+FDEF, or any code point whose low
 * sixteen bits are FFFE or FFFF).
 *
 *  - mbstring:  `mb_check_encoding()` / `mb_scrub()` (maximal subpart
 *               since PHP 8.1.6), `mb_convert_encoding()` for the
 *               ISO-8859-1 encode/decode pair.
 *  - pcre:      PCRE2's strict UTF validity check (validity only).
 *  - intl:      ICU via `UConverter::transcode()` (scrub only).
 *  - python3:   CPython codec in a persistent subprocess.
 *  - node:      WHATWG TextDecoder in a persistent subprocess.
 *  - native:    the deprecated `utf8_encode()` / `utf8_decode()` pair,
 *               available until its removal in PHP 9. The decode side is
 *               trusted on VALID input only: on ill-formed input the
 *               legacy decoder groups bytes differently from the maximal
 *               subpart rule, consuming a well-formed lead byte together
 *               with its expected continuation length as a single '?'
 *               unit in several classes — surrogates (`ED A0 80` → '?'
 *               vs '???'), sequences past U+10FFFF (`F4 90 80 80` → '?'
 *               vs '????'), three/four-byte overlongs (`E0 80 AF`), and
 *               even a well-formed lead before an invalid continuation
 *               (`C2 C0` → '?' vs '??'). It does agree with maximal
 *               subparts elsewhere (e.g. C0/C1 overlongs and lone
 *               continuations). WordPress deliberately follows
 *               `mb_convert_encoding()` maximal-subpart semantics
 *               instead: the PHP 9 polyfill in `compat.php` prefers
 *               `mb_convert_encoding()`, with the fallback as its
 *               shadow (ticket #63863).
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

	/*
	 * Unlike validity/scrub oracles, encode/decode oracles are all
	 * in-process and never return null; `Checks` has no transport-failure
	 * handling for them. An external (nullable) encode/decode oracle
	 * would need that handling added first.
	 */

	/** @var array<string, callable(string): string> */
	private array $encode = array();

	/** @var array<string, callable(string): string> */
	private array $decode = array();

	/** @var array<string, bool> Decode oracles trusted on valid UTF-8 input only. */
	private array $decode_valid_only = array();

	/** @var array<string, callable(string): bool> */
	private array $noncharacters = array();

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

		if ( function_exists( 'mb_convert_encoding' ) ) {
			// Encode is total over ISO-8859-1 bytes; no substitutions can occur.
			$oracles->encode['mb'] = static function ( string $bytes ): string {
				return mb_convert_encoding( $bytes, 'UTF-8', 'ISO-8859-1' );
			};
			// Pin the legacy '?' substitute per call (like the scrub oracle
			// pins 0xFFFD) so ambient changes to the global cannot skew results.
			$oracles->decode['mb'] = static function ( string $bytes ): string {
				$previous = mb_substitute_character();
				mb_substitute_character( 0x3F );
				$decoded = mb_convert_encoding( $bytes, 'ISO-8859-1', 'UTF-8' );
				mb_substitute_character( $previous );
				return $decoded;
			};
		}

		$mb_ord = function_exists( 'mb_ord' )
			? 'mb_ord'
			: ( function_exists( '_mb_ord' ) ? '_mb_ord' : null );

		$oracles->noncharacters['bytes'] = static function ( string $bytes ): bool {
			static $noncharacter_sequences = null;
			if ( null === $noncharacter_sequences ) {
				$noncharacter_sequences = array();

				for ( $code_point = 0xFDD0; $code_point <= 0xFDEF; $code_point++ ) {
					$noncharacter_sequences[] = Generator::encode_code_point( $code_point );
				}

				for ( $plane = 0; $plane <= 0x10; $plane++ ) {
					$final                     = ( $plane << 16 ) | 0xFFFF;
					$noncharacter_sequences[] = Generator::encode_code_point( $final - 1 );
					$noncharacter_sequences[] = Generator::encode_code_point( $final );
				}
			}

			foreach ( $noncharacter_sequences as $sequence ) {
				if ( str_contains( $bytes, $sequence ) ) {
					return true;
				}
			}

			return false;
		};

		if ( function_exists( 'mb_str_split' ) && null !== $mb_ord ) {
			/*
			 * Trivial decode-and-test reference for noncharacter detection,
			 * independent of the byte-sequence search. Callers must pass
			 * valid UTF-8.
			 */
			$oracles->noncharacters['mb'] = static function ( string $valid_utf8 ) use ( $mb_ord ): bool {
				foreach ( mb_str_split( $valid_utf8, 1, 'UTF-8' ) as $character ) {
					$code_point = $mb_ord( $character, 'UTF-8' );

					// Fail loudly on contract violations: on ill-formed
					// input `mb_ord()` returns false, which would otherwise
					// coerce into "not a noncharacter" and silently mimic
					// the fallback's skip-invalid-spans semantics.
					if ( ! is_int( $code_point ) ) {
						throw new \LogicException( 'noncharacter oracle requires valid UTF-8 input' );
					}

					if (
						( $code_point >= 0xFDD0 && $code_point <= 0xFDEF ) ||
						0xFFFE === ( $code_point & 0xFFFE )
					) {
						return true;
					}
				}
				return false;
			};
		}

		if ( function_exists( 'utf8_encode' ) && function_exists( 'utf8_decode' ) ) {
			$oracles->encode['native'] = static function ( string $bytes ): string {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Deprecated since PHP 8.2.
				return (string) @utf8_encode( $bytes );
			};
			$oracles->decode['native'] = static function ( string $bytes ): string {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Deprecated since PHP 8.2.
				return (string) @utf8_decode( $bytes );
			};

			$oracles->decode_valid_only['native'] = true;
		} else {
			$oracles->events[] = array(
				'type'   => 'oracle-unavailable',
				'oracle' => 'native',
				'detail' => 'utf8_encode()/utf8_decode() removed (PHP 9+); legacy encode/decode differential skipped',
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

	/**
	 * Known-answer vectors for the ISO-8859-1 → UTF-8 encode oracles.
	 *
	 * Every byte 0x00–0xFF is a defined ISO-8859-1 code point whose UTF-8
	 * form is hand-computable: identity below 0x80, the two-byte sequence
	 * `C2|C3 80..BF` above.
	 *
	 * @return array<int, array{0: string, 1: string}> [latin1 bytes, utf8 bytes]
	 */
	public static function encode_battery(): array {
		return array(
			array( '', '' ),
			array( 'abc', 'abc' ),
			array( "\x00", "\x00" ),
			array( "\x7F", "\x7F" ),
			array( "\x80", "\xC2\x80" ),             // First two-byte mapping.
			array( "\x9F", "\xC2\x9F" ),             // NOT a Windows-1252 smart quote.
			array( "\xA0", "\xC2\xA0" ),
			array( "\xBF", "\xC2\xBF" ),             // Last byte with C2 lead.
			array( "\xC0", "\xC3\x80" ),             // First byte with C3 lead.
			array( "\xFF", "\xC3\xBF" ),
			array( "B\xFCch", "B\xC3\xBCch" ),
			array( "\xC3\xBC", "\xC3\x83\xC2\xBC" ), // Already-UTF-8 input double-encodes.
		);
	}

	/**
	 * Known-answer vectors for the UTF-8 → ISO-8859-1 decode oracles.
	 *
	 * Hand-computed: code points U+00–U+FF map to their byte, anything
	 * higher becomes '?', and each maximal subpart of an ill-formed span
	 * becomes one '?'. The valid flag marks vectors safe for decode
	 * oracles that are trusted on valid input only (legacy `utf8_decode()`
	 * groups some ill-formed sequences into a single '?' unit; see the
	 * class docblock).
	 *
	 * @return array<int, array{0: string, 1: bool, 2: string}> [utf8 bytes, valid, latin1 bytes]
	 */
	public static function decode_battery(): array {
		return array(
			array( '', true, '' ),
			array( 'abc', true, 'abc' ),
			array( "\x00", true, "\x00" ),
			array( "\xC2\x80", true, "\x80" ),                  // U+0080, first two-byte mapping.
			array( "\xC3\xBC", true, "\xFC" ),                  // U+00FC ü.
			array( "\xC3\xBF", true, "\xFF" ),                  // U+00FF, last mappable.
			array( "\xC4\x80", true, '?' ),                     // U+0100, first unmappable.
			array( "\xE2\x9C\x8F", true, '?' ),                 // U+270F.
			array( "\xF0\x9F\x98\x80", true, '?' ),             // U+1F600.
			array( "\xEF\xBB\xBF", true, '?' ),                 // BOM is unmappable, not dropped.
			array( "a\xC3\xA9b", true, "a\xE9b" ),
			array( "\x80", false, '?' ),                        // Lone continuation.
			array( "\xC0", false, '?' ),                        // Never-valid lead.
			array( "\xC0\xAF", false, '??' ),                   // Overlong '/': two subparts, NOT '/'.
			array( "\xE2\x8C", false, '?' ),                    // Two-byte maximal subpart at EOF.
			array( "\xE2\x8Cx", false, '?x' ),                  // Subpart cut short by ASCII.
			array( "\xF1\x80\x80", false, '?' ),                // Three-byte maximal subpart.
			array( "\xED\xA0\x80", false, '???' ),              // Surrogate: per subpart (legacy native says '?').
			array( "\xF4\x90\x80\x80", false, '????' ),         // Past U+10FFFF (legacy native says '?').
			array( ".\xC0.", false, '.?.' ),
			array( "\xC3\xBC\x80", false, "\xFC?" ),            // Invalid span right after a mappable high byte.
			array( "\x80\xC3\xBC", false, "?\xFC" ),            // Mappable high byte right after an invalid span.
			array( "a\xF1\x80\x80\xE1\x80\xC2b", false, 'a???b' ), // Unicode Table 3-8.
		);
	}

	/**
	 * Known-answer vectors for the noncharacter oracles. All inputs are
	 * valid UTF-8 and ill-formed surrounds, covering the boundaries AND
	 * interior of the U+FDD0–U+FDEF block plus the final two code points
	 * of EVERY plane with their U+xFFFD neighbors.
	 *
	 * Expectations are hand-derived from the Unicode definition; bytes
	 * for the looped vectors come from the pure-arithmetic
	 * `Generator::encode_code_point()` (itself exhaustively verified
	 * against `mb_chr()` by `tests/code-point-to-utf8-exhaustive.php`),
	 * keeping the encoding independent of the mbstring-backed oracle.
	 *
	 * @return array<int, array{0: string, 1: bool}> [bytes, has noncharacters]
	 */
	public static function noncharacter_battery(): array {
		$vectors = array(
			array( '', false ),
			array( 'abc', false ),
			array( "\xC0abc", false ),
			array( "\xC0\xEF\xBF\xBE", true ),
			array( "\xC0a\xEF\xB7\x90b", true ),
			array( "\xC0\xEF\xB7\x8F", false ),
			array( "\u{FDCF}", false ),       // Last code point before the contiguous block.
			array( "\u{FDD0}", true ),        // First of the contiguous block.
			array( "\u{FDDA}", true ),        // Interior of the block: a lookup-table bug
			array( "\u{FDE5}", true ),        // is not necessarily a boundary bug.
			array( "\u{FDEF}", true ),        // Last of the contiguous block.
			array( "\u{FDF0}", false ),       // First code point after the block.
			array( "\u{FEFF}", false ),       // BOM is not a noncharacter.
			array( "\u{FFFD}", false ),       // Replacement character is not a noncharacter.
			array( "\u{ABCD}", false ),       // Arbitrary interior scalar.
			array( "a\u{FFFE}b", true ),      // Embedded in surrounding text.
			array( "ascii only", false ),
		);

		// Both plane-final noncharacters and their lower neighbor, for
		// all seventeen planes (0–16).
		for ( $plane = 0; $plane <= 0x10; $plane++ ) {
			$final = ( $plane << 16 ) | 0xFFFF;

			$vectors[] = array( Generator::encode_code_point( $final - 2 ), false );
			$vectors[] = array( Generator::encode_code_point( $final - 1 ), true );
			$vectors[] = array( Generator::encode_code_point( $final ), true );
		}

		return $vectors;
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

		foreach ( self::encode_battery() as $i => $vector ) {
			list( $bytes, $expected ) = $vector;

			foreach ( $this->encode as $name => $check ) {
				$got = $check( $bytes );
				if ( $got !== $expected ) {
					$this->disable( $name, sprintf(
						'encode battery vector %d (%s): expected %s, got %s',
						$i,
						bin2hex( $bytes ),
						bin2hex( $expected ),
						null === $got ? 'null' : bin2hex( $got )
					) );
				}
			}
		}

		foreach ( self::noncharacter_battery() as $i => $vector ) {
			list( $bytes, $expected ) = $vector;

			foreach ( $this->noncharacters as $name => $check ) {
				if ( 'mb' === $name && ( ! function_exists( 'mb_check_encoding' ) || ! mb_check_encoding( $bytes, 'UTF-8' ) ) ) {
					continue;
				}

				$got = $check( $bytes );
				if ( $got !== $expected ) {
					$this->disable( $name, sprintf(
						'noncharacter battery vector %d (%s): expected %s, got %s',
						$i,
						bin2hex( $bytes ),
						var_export( $expected, true ),
						var_export( $got, true )
					) );
				}
			}
		}

		foreach ( self::decode_battery() as $i => $vector ) {
			list( $bytes, $input_valid, $expected ) = $vector;

			foreach ( $this->decode as $name => $check ) {
				if ( ! $input_valid && $this->decode_oracle_is_valid_only( $name ) ) {
					continue;
				}

				$got = $check( $bytes );
				if ( $got !== $expected ) {
					$this->disable( $name, sprintf(
						'decode battery vector %d (%s): expected %s, got %s',
						$i,
						bin2hex( $bytes ),
						bin2hex( $expected ),
						null === $got ? 'null' : bin2hex( $got )
					) );
				}
			}
		}
	}

	/**
	 * Removes every role a named oracle backs. Note that disabling `mb`
	 * therefore makes `has_required()` false and the harness refuses to
	 * run — failing closed is preferable to fuzzing without the primary
	 * oracle.
	 */
	public function disable( string $name, string $detail ): void {
		if (
			! isset( $this->validity[ $name ] ) &&
			! isset( $this->scrub[ $name ] ) &&
			! isset( $this->encode[ $name ] ) &&
			! isset( $this->decode[ $name ] ) &&
			! isset( $this->noncharacters[ $name ] )
		) {
			return;
		}

		unset(
			$this->validity[ $name ],
			$this->scrub[ $name ],
			$this->encode[ $name ],
			$this->decode[ $name ],
			$this->decode_valid_only[ $name ],
			$this->noncharacters[ $name ]
		);
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

	/** @return array<string, callable(string): string> */
	public function encode_oracles(): array {
		return $this->encode;
	}

	/** @return array<string, callable(string): string> */
	public function decode_oracles(): array {
		return $this->decode;
	}

	public function decode_oracle_is_valid_only( string $name ): bool {
		return $this->decode_valid_only[ $name ] ?? false;
	}

	/** @return array<string, callable(string): bool> */
	public function noncharacter_oracles(): array {
		return $this->noncharacters;
	}

	public function has_required(): bool {
		return isset( $this->validity['mb'], $this->scrub['mb'] );
	}

	public function names(): array {
		return array_values( array_unique( array_merge(
			array_keys( $this->validity ),
			array_keys( $this->scrub ),
			array_keys( $this->encode ),
			array_keys( $this->decode ),
			array_keys( $this->noncharacters )
		) ) );
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
