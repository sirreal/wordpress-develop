<?php
namespace EncodingFuzz;

/**
 * Produces fuzz inputs as raw byte strings, mixing:
 *
 *  - uniformly random bytes
 *  - guaranteed-valid UTF-8 with boundary-heavy code point choices
 *  - valid UTF-8 corrupted by targeted mutations
 *  - splices of hand-picked valid/invalid byte atoms
 *  - legacy encodings (ISO-8859-1-ish text, UTF-16 with/without BOM)
 *  - long ASCII runs with multibyte or broken tails (fast-path stress)
 *  - short motifs repeated many times
 *
 * Everything derives from the Prng, so `(seed, case index)` fully
 * determines the input.
 */
class Generator {
	/**
	 * Code points sitting on the edges of the well-formed byte ranges in
	 * Unicode Table 3-7, plus noncharacters and the replacement character.
	 */
	private const BOUNDARY_CODE_POINTS = array(
		0x00, 0x01, 0x09, 0x0A, 0x0D, 0x20, 0x7E, 0x7F,            // ASCII edges.
		0x80, 0x7FF,                                               // Two-byte edges.
		0x800, 0xFFF, 0x1000, 0xCFFF, 0xD000, 0xD7FF,              // Three-byte lead splits.
		0xE000, 0xFFFD,                                            // After the surrogate gap.
		0xFDD0, 0xFDDA, 0xFDEF, 0xFFFE, 0xFFFF,                    // Noncharacters (valid UTF-8!), incl. block interior.
		0xFDCF, 0xFDF0,                                            // Adjacent NON-noncharacters.
		0x10000, 0x3FFFF, 0x40000, 0xFFFFF, 0x100000, 0x10FFFF,    // Four-byte lead splits.
		0x1FFFD, 0x1FFFE, 0x1FFFF, 0x5FFFE, 0x8FFFF, 0x10FFFE,     // Supplementary noncharacters, mid planes, neighbors.
		0x10FFFD,
	);

	/**
	 * Short byte sequences that are individually valid UTF-8.
	 */
	private const VALID_ATOMS = array(
		'a',
		'hello',
		"\x00",
		"\x7F",
		"\xC2\x80",         // U+0080, smallest two-byte.
		"\xDF\xBF",         // U+07FF, largest two-byte.
		"\xE0\xA0\x80",     // U+0800, smallest three-byte.
		"\xED\x9F\xBF",     // U+D7FF, last before surrogates.
		"\xEE\x80\x80",     // U+E000, first after surrogates.
		"\xEF\xBF\xBD",     // U+FFFD, replacement character itself.
		"\xEF\xBF\xBE",     // U+FFFE, noncharacter.
		"\xEF\xBF\xBF",     // U+FFFF, noncharacter.
		"\xEF\xBB\xBF",     // U+FEFF, byte order mark.
		"\xF0\x90\x80\x80", // U+10000, smallest four-byte.
		"\xF4\x8F\xBF\xBF", // U+10FFFF, largest code point.
	);

	/**
	 * Short byte sequences that are individually ill-formed UTF-8,
	 * covering every class of failure: bad leads, overlongs, surrogates,
	 * out-of-range, lone/excess continuations, and truncations.
	 */
	private const INVALID_ATOMS = array(
		"\x80",             // Lone continuation.
		"\xBF",             // Lone continuation, upper edge.
		"\x80\x80\x80",     // Continuation run.
		"\xC0",             // Never-valid lead.
		"\xC0\xAF",         // Overlong '/'.
		"\xC1\xBF",         // Overlong, largest C1 form.
		"\xC2",             // Truncated two-byte.
		"\xC2\xC2\x80",     // Truncated lead then valid char.
		"\xE0\x80\xAF",     // Overlong three-byte.
		"\xE0\x9F\xBF",     // Overlong three-byte, upper edge.
		"\xE1\x80",         // Truncated three-byte (valid prefix).
		"\xE2\x8C",         // Truncated three-byte (valid prefix).
		"\xED\xA0\x80",     // Surrogate U+D800.
		"\xED\xBF\xBF",     // Surrogate U+DFFF.
		"\xED\xB0\x80",     // Low surrogate half.
		"\xEF\xBF",         // Truncated three-byte.
		"\xF0\x80\x80\xAF", // Overlong four-byte.
		"\xF0\x8F\xBF\xBF", // Overlong four-byte, upper edge.
		"\xF0\x90",         // Truncated four-byte (valid prefix).
		"\xF1\x80",         // Truncated four-byte (valid prefix).
		"\xF1\x80\x80",     // Truncated four-byte, three valid bytes.
		"\xF4\x8F\xBF",     // Truncated U+10FFFF.
		"\xF4\x90\x80\x80", // First code point past U+10FFFF.
		"\xF5\x80\x80\x80", // Never-valid lead F5.
		"\xF8\x80\x80\x80\x80", // Old-style five-byte form.
		"\xFC\x80\x80\x80\x80\x80", // Old-style six-byte form.
		"\xFE",             // Never valid.
		"\xFF",             // Never valid.
		"\xFE\xFF",         // UTF-16BE BOM.
		"\xFF\xFE",         // UTF-16LE BOM.
		"a\xF1\x80\x80\xE1\x80\xC2b", // Unicode Table 3-8 example: three maximal subparts.
	);

	private Prng $prng;
	private int $max_bytes;

	public function __construct( Prng $prng, int $max_bytes = 65536 ) {
		$this->prng      = $prng;
		$this->max_bytes = max( 1, $max_bytes );
	}

	/**
	 * @return array{strategy: string, bytes: string}
	 */
	public function generate(): array {
		$strategy = $this->prng->weighted(
			array(
				'random-bytes'    => 14,
				'random-ascii'    => 4,
				'valid-utf8'      => 18,
				'mutated-valid'   => 24,
				'atom-splice'     => 20,
				'latin1-text'     => 4,
				'utf16-bytes'     => 4,
				'ascii-fast-path' => 6,
				'repeat-motif'    => 6,
			)
		);

		$method = 'gen_' . str_replace( '-', '_', $strategy );
		return array(
			'strategy' => $strategy,
			'bytes'    => $this->$method(),
		);
	}

	private function gen_random_bytes(): string {
		return $this->prng->bytes( $this->prng->biased_length( $this->max_bytes ) );
	}

	private function gen_random_ascii(): string {
		$length = $this->prng->biased_length( $this->max_bytes );
		$out    = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= chr( $this->prng->int( 0, 0x7F ) );
		}
		return $out;
	}

	private function gen_valid_utf8(): string {
		$budget = $this->prng->biased_length( $this->max_bytes );
		$out    = '';

		while ( strlen( $out ) < $budget ) {
			$kind = $this->prng->weighted(
				array(
					'ascii-run' => 30,
					'boundary'  => 20,
					'two-byte'  => 15,
					'three-byte' => 15,
					'four-byte' => 10,
					'any'       => 10,
				)
			);

			switch ( $kind ) {
				case 'ascii-run':
					$run = $this->prng->int( 1, 16 );
					for ( $i = 0; $i < $run; $i++ ) {
						$out .= chr( $this->prng->int( 0x00, 0x7F ) );
					}
					break;

				case 'boundary':
					$out .= self::encode_code_point( $this->prng->choice( self::BOUNDARY_CODE_POINTS ) );
					break;

				case 'two-byte':
					$out .= self::encode_code_point( $this->prng->int( 0x80, 0x7FF ) );
					break;

				case 'three-byte':
					$cp = $this->prng->int( 0x800, 0xFFFF );
					// Skip the surrogate range; it cannot be encoded.
					if ( $cp >= 0xD800 && $cp <= 0xDFFF ) {
						$cp -= 0x800;
					}
					$out .= self::encode_code_point( $cp );
					break;

				case 'four-byte':
					$out .= self::encode_code_point( $this->prng->int( 0x10000, 0x10FFFF ) );
					break;

				default:
					$cp = $this->prng->int( 0x00, 0x10FFFF );
					if ( $cp >= 0xD800 && $cp <= 0xDFFF ) {
						$cp -= 0x800;
					}
					$out .= self::encode_code_point( $cp );
			}
		}

		return $out;
	}

	private function gen_mutated_valid(): string {
		$bytes     = $this->gen_valid_utf8();
		$mutations = $this->prng->int( 1, 6 );

		for ( $m = 0; $m < $mutations && '' !== $bytes; $m++ ) {
			$kind = $this->prng->weighted(
				array(
					'flip-bit'     => 20,
					'set-byte'     => 20,
					'delete-span'  => 15,
					'truncate'     => 15,
					'insert-bytes' => 15,
					'duplicate'    => 10,
					'swap'         => 5,
				)
			);

			$length = strlen( $bytes );
			$at     = $this->prng->int( 0, max( 0, $length - 1 ) );

			switch ( $kind ) {
				case 'flip-bit':
					$bytes[ $at ] = chr( ord( $bytes[ $at ] ) ^ ( 1 << $this->prng->int( 0, 7 ) ) );
					break;

				case 'set-byte':
					$bytes[ $at ] = chr( $this->prng->int( 0, 255 ) );
					break;

				case 'delete-span':
					$span  = $this->prng->int( 1, min( 8, $length ) );
					$bytes = substr( $bytes, 0, $at ) . substr( $bytes, $at + $span );
					break;

				case 'truncate':
					// Tail truncation is the classic incomplete-sequence case.
					$bytes = $this->prng->chance( 50 )
						? substr( $bytes, 0, $at )
						: substr( $bytes, $at );
					break;

				case 'insert-bytes':
					$insert = $this->prng->bytes( $this->prng->int( 1, 6 ) );
					$bytes  = substr( $bytes, 0, $at ) . $insert . substr( $bytes, $at );
					break;

				case 'duplicate':
					$span  = $this->prng->int( 1, min( 8, $length - $at ) );
					$slice = substr( $bytes, $at, $span );
					$bytes = substr( $bytes, 0, $at ) . $slice . $slice . substr( $bytes, $at + $span );
					break;

				case 'swap':
					$other          = $this->prng->int( 0, $length - 1 );
					$tmp            = $bytes[ $at ];
					$bytes[ $at ]   = $bytes[ $other ];
					$bytes[ $other ] = $tmp;
					break;
			}
		}

		return substr( $bytes, 0, $this->max_bytes );
	}

	private function gen_atom_splice(): string {
		$count = $this->prng->int( 1, 24 );
		$out   = '';

		for ( $i = 0; $i < $count && strlen( $out ) < $this->max_bytes; $i++ ) {
			$pool = $this->prng->weighted(
				array(
					'invalid' => 45,
					'valid'   => 35,
					'ascii'   => 12,
					'random'  => 8,
				)
			);

			switch ( $pool ) {
				case 'invalid':
					$out .= $this->prng->choice( self::INVALID_ATOMS );
					break;
				case 'valid':
					$out .= $this->prng->choice( self::VALID_ATOMS );
					break;
				case 'ascii':
					$out .= chr( $this->prng->int( 0x20, 0x7E ) );
					break;
				default:
					$out .= $this->prng->bytes( $this->prng->int( 1, 4 ) );
			}
		}

		return substr( $out, 0, $this->max_bytes );
	}

	private function gen_latin1_text(): string {
		$length = $this->prng->biased_length( $this->max_bytes );
		$out    = '';
		for ( $i = 0; $i < $length; $i++ ) {
			// Mostly readable text with sprinkled ISO-8859-1 high bytes.
			$out .= $this->prng->chance( 25 )
				? chr( $this->prng->int( 0xA0, 0xFF ) )
				: chr( $this->prng->int( 0x20, 0x7E ) );
		}
		return $out;
	}

	private function gen_utf16_bytes(): string {
		$text  = substr( $this->gen_valid_utf8(), 0, 512 );
		$le    = $this->prng->chance( 50 );
		$bytes = mb_convert_encoding( $text, $le ? 'UTF-16LE' : 'UTF-16BE', 'UTF-8' );

		if ( $this->prng->chance( 50 ) ) {
			$bytes = ( $le ? "\xFF\xFE" : "\xFE\xFF" ) . $bytes;
		}

		return substr( (string) $bytes, 0, $this->max_bytes );
	}

	/**
	 * Long pure-ASCII run, exercising the `strspn()` fast path in
	 * `_wp_scan_utf8()`, with a tail that lands a multibyte or broken
	 * sequence right at the end of the buffer.
	 */
	private function gen_ascii_fast_path(): string {
		$run = str_repeat( 'a', $this->prng->int( 1024, min( 65536, $this->max_bytes ) ) );

		switch ( $this->prng->int( 0, 4 ) ) {
			case 0:
				return $run; // Pure ASCII.
			case 1:
				return $run . "\xE2\x9C\x8F"; // Valid multibyte tail.
			case 2:
				return $run . $this->prng->choice( self::INVALID_ATOMS ); // Broken tail.
			case 3:
				return $run . "\xE2\x9C"; // Truncated tail at EOF.
			default:
				// Multibyte sandwich between ASCII runs.
				return $run . $this->prng->choice( self::INVALID_ATOMS ) . $run;
		}
	}

	private function gen_repeat_motif(): string {
		$motif = $this->prng->chance( 50 )
			? $this->prng->choice( self::INVALID_ATOMS )
			: $this->prng->choice( self::VALID_ATOMS );

		if ( $this->prng->chance( 30 ) ) {
			$motif .= $this->prng->bytes( $this->prng->int( 1, 3 ) );
		}

		$repeats = $this->prng->int( 1, intdiv( $this->max_bytes, max( 1, strlen( $motif ) ) ) );
		$repeats = min( $repeats, $this->prng->chance( 80 ) ? 256 : 16384 );

		return substr( str_repeat( $motif, max( 1, $repeats ) ), 0, $this->max_bytes );
	}

	public static function encode_code_point( int $code_point ): string {
		if ( $code_point < 0x80 ) {
			return chr( $code_point );
		}

		if ( $code_point < 0x800 ) {
			return chr( 0xC0 | ( $code_point >> 6 ) )
				. chr( 0x80 | ( $code_point & 0x3F ) );
		}

		if ( $code_point < 0x10000 ) {
			return chr( 0xE0 | ( $code_point >> 12 ) )
				. chr( 0x80 | ( ( $code_point >> 6 ) & 0x3F ) )
				. chr( 0x80 | ( $code_point & 0x3F ) );
		}

		return chr( 0xF0 | ( $code_point >> 18 ) )
			. chr( 0x80 | ( ( $code_point >> 12 ) & 0x3F ) )
			. chr( 0x80 | ( ( $code_point >> 6 ) & 0x3F ) )
			. chr( 0x80 | ( $code_point & 0x3F ) );
	}
}
