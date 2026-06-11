<?php
namespace HtmlDecoderFuzz;

/**
 * Produces oracle-safe decoder payloads from an entity-focused grammar.
 */
class Generator {
	private const PREFERRED_SEMICOLON = array(
		'amp;', 'AMP;', 'lt;', 'LT;', 'gt;', 'GT;', 'quot;', 'QUOT;', 'apos;', 'nbsp;',
		'copy;', 'COPY;', 'reg;', 'not;', 'notin;', 'notinva;', 'AElig;', 'CounterClockwiseContourIntegral;',
		'NotEqualTilde;', 'centerdot;', 'divideontimes;', 'ncaron;', 'ngt;', 'nGt;', 'colon;',
	);

	private const PREFERRED_LEGACY = array(
		'amp', 'AMP', 'lt', 'LT', 'gt', 'GT', 'quot', 'QUOT', 'nbsp', 'copy', 'COPY', 'reg', 'not', 'AElig',
	);

	private const ASCII_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 -_:/.,;#[](){}\'=+!?*';

	private Prng $prng;
	private int $max_bytes;

	/** @var string[] */
	private array $semicolon_names;

	/** @var string[] */
	private array $legacy_names;

	public function __construct( Prng $prng, int $max_bytes = 4096, ?array $named_reference_names = null ) {
		$this->prng      = $prng;
		$this->max_bytes = max( 1, $max_bytes );

		$names = $named_reference_names ?? Bootstrap::named_reference_names();

		$this->semicolon_names = array_values(
			array_filter(
				$names,
				static fn( string $name ): bool => str_ends_with( $name, ';' )
			)
		);
		$this->legacy_names    = array_values(
			array_filter(
				$names,
				static fn( string $name ): bool => ! str_ends_with( $name, ';' )
			)
		);

		if ( array() === $this->semicolon_names ) {
			$this->semicolon_names = self::PREFERRED_SEMICOLON;
		}
		if ( array() === $this->legacy_names ) {
			$this->legacy_names = self::PREFERRED_LEGACY;
		}
	}

	/**
	 * @return array{context: string, strategy: string, payload: string}
	 */
	public function generate(): array {
		// Preserve seed-to-payload mapping from the former one-context lane.
		$this->prng->chance( 50 );
		$strategy = $this->prng->weighted(
			array(
				'plain-no-amp'           => 8,
				'named-exact'            => 16,
				'named-missing-semi'     => 15,
				'attribute-discriminator' => 15,
				'numeric'                => 22,
				'adjacency'              => 10,
				'truncation-sweep'       => 9,
				'reference-at-eof'       => 12,
				'multibyte-around'       => 9,
				'attribute-prefix'       => 8,
				'lookalike'              => 8,
			)
		);

		$method  = 'gen_' . str_replace( '-', '_', $strategy );
		$payload = $this->$method();

		return array(
			'context'  => 'both',
			'strategy' => $strategy,
			'payload'  => self::trim_to_safe_max( $payload, $this->max_bytes ),
		);
	}

	/**
	 * @return array{context: string, strategy: string, payload: string}
	 */
	public function generate_bytes(): array {
		$strategy = $this->prng->weighted(
			array(
				'bytes-uniform'      => 35,
				'bytes-no-amp'       => 20,
				'bytes-with-amp'     => 20,
				'bytes-invalid-utf8' => 15,
				'bytes-delimiters'   => 10,
			)
		);

		$method  = 'gen_' . str_replace( '-', '_', $strategy );
		$payload = $this->$method();

		return array(
			'context'  => 'both',
			'strategy' => $strategy,
			'payload'  => substr( $payload, 0, $this->max_bytes ),
		);
	}

	public static function is_oracle_safe_payload( string $payload ): bool {
		return (
			mb_check_encoding( $payload, 'UTF-8' ) &&
			! str_contains( $payload, '<' ) &&
			! str_contains( $payload, '"' ) &&
			! str_contains( $payload, "\r" ) &&
			! str_contains( $payload, "\x00" )
		);
	}

	private function gen_plain_no_amp(): string {
		return $this->plain_text( false );
	}

	private function gen_named_exact(): string {
		return $this->plain_text() . $this->named_exact() . $this->plain_text();
	}

	private function gen_named_missing_semi(): string {
		$name     = $this->pick_legacy_name();
		$follower = $this->prng->weighted(
			array(
				'end'   => 35,
				'punct' => 35,
				'alpha' => 20,
				'eq'    => 10,
			)
		);

		$suffix = '';
		if ( 'punct' === $follower ) {
			$suffix = $this->prng->choice( array( ' ', '.', '/', ':', ';', '-' ) );
		} elseif ( 'alpha' === $follower ) {
			$suffix = $this->ascii_run( $this->prng->int( 1, 5 ) );
		} elseif ( 'eq' === $follower ) {
			$suffix = '=' . $this->ascii_run( $this->prng->int( 0, 4 ) );
		}

		return $this->plain_text() . '&' . $name . $suffix . $this->plain_text();
	}

	private function gen_attribute_discriminator(): string {
		$name     = $this->prng->choice( array_values( array_intersect( self::PREFERRED_LEGACY, $this->legacy_names ) ) ?: $this->legacy_names );
		$follower = $this->prng->choice( array( '=', 'x', 'Z', '0', 'later;' ) );

		return $this->plain_text() . '&' . $name . $follower . $this->plain_text();
	}

	private function gen_numeric(): string {
		return $this->plain_text() . $this->numeric_reference() . $this->plain_text();
	}

	private function gen_adjacency(): string {
		$count = $this->prng->int( 2, 8 );
		$out   = $this->plain_text();

		for ( $i = 0; $i < $count; $i++ ) {
			$out .= $this->prng->chance( 48 ) ? $this->named_exact() : $this->numeric_reference();
			if ( $this->prng->chance( 20 ) ) {
				$out .= $this->plain_text();
			}
		}

		return $out . $this->plain_text();
	}

	private function gen_truncation_sweep(): string {
		$reference = $this->prng->chance( 50 ) ? $this->named_exact() : $this->numeric_reference( true );
		$length    = strlen( $reference );
		$prefix    = substr( $reference, 0, $this->prng->int( 1, max( 1, $length - 1 ) ) );

		return $this->plain_text() . $prefix . $this->plain_text();
	}

	private function gen_reference_at_eof(): string {
		$kind = $this->prng->weighted(
			array(
				'fixed'          => 45,
				'named-prefix'   => 25,
				'decimal-digits' => 15,
				'hex-digits'     => 15,
			)
		);
		$suffix = '';

		if ( 'named-prefix' === $kind ) {
			$name      = $this->pick_semicolon_name();
			$reference = '&' . $name;
			$suffix    = substr( $reference, 0, $this->prng->int( 1, strlen( $reference ) - 1 ) );
		} elseif ( 'decimal-digits' === $kind ) {
			$digits = $this->ascii_digits( $this->prng->int( 1, 9 ) );
			$suffix = substr( '&#' . $digits, 0, max( 1, min( strlen( '&#' . $digits ), $this->max_bytes ) ) );
		} elseif ( 'hex-digits' === $kind ) {
			$prefix = $this->prng->chance( 50 ) ? '&#x' : '&#X';
			$digits = $this->hex_digits( $this->prng->int( 1, 8 ) );
			$suffix = substr( $prefix . $digits, 0, max( 1, min( strlen( $prefix . $digits ), $this->max_bytes ) ) );
		} else {
			$suffix = $this->prng->choice(
				array(
					'&',
					'&#',
					'&#x',
					'&#X',
					'&g',
					'&gt',
					'&not',
					'&noti',
					'&amp',
					'&#123',
					'&#x1F',
				)
			);
			$suffix = substr( $suffix, 0, max( 1, min( strlen( $suffix ), $this->max_bytes ) ) );
		}

		return $this->plain_text_up_to( max( 0, $this->max_bytes - strlen( $suffix ) ) ) . $suffix;
	}

	private function gen_multibyte_around(): string {
		$atoms = array( 'e', "\u{00E9}", "\u{96EA}", "\u{1F642}", "\u{03B2}", "\u{05E2}\u{05D1}", "\u{0928}\u{092E}" );
		$out   = '';
		$count = $this->prng->int( 2, 7 );
		for ( $i = 0; $i < $count; $i++ ) {
			$out .= $this->prng->choice( $atoms );
			$out .= $this->prng->chance( 55 ) ? $this->named_exact() : $this->numeric_reference();
		}
		return $out . $this->prng->choice( $atoms );
	}

	private function gen_attribute_prefix(): string {
		$prefixes = array(
			'javascript:',
			'JaVaScRiPt:',
			'javascript&colon;',
			'javascript&#58;',
			'javascript&#0000058',
			'javascript&#x3A;',
			'&#x6A;&#x61;&#x76;&#x61;&#x73;&#x63;&#x72;&#x69;&#x70;&#x74;&#x3A;',
			'&nvlt;',
			'&nvgt;',
			'&NotLessLess;',
			'&bne;',
			'http://',
			'https://',
			'jav',
		);

		return $this->prng->choice( $prefixes ) . $this->plain_text();
	}

	private function gen_lookalike(): string {
		$lookalikes = array(
			'&bogus;',
			'&NoSuchEntity',
			'&;',
			'&amp ;',
			'&noti;',
			'&notit;',
			'&copyright;',
			'&centerdo;',
			'&ngE',
			'&divideontime;',
			'&amp&amp;',
			'&&gt;',
			'&am',
			'&',
		);

		return $this->plain_text() . $this->prng->choice( $lookalikes ) . $this->plain_text();
	}

	private function gen_bytes_uniform(): string {
		$length = max( 1, $this->prng->biased_length( $this->max_bytes ) );
		return $this->prng->bytes( $length );
	}

	private function gen_bytes_no_amp(): string {
		$length = max( 1, $this->prng->biased_length( $this->max_bytes ) );
		$out    = '';
		while ( strlen( $out ) < $length ) {
			$byte = $this->prng->int( 0, 255 );
			if ( 0x26 === $byte ) {
				$byte = 0x00;
			}
			$out .= chr( $byte );
		}
		return $out;
	}

	private function gen_bytes_with_amp(): string {
		$prefixes = array( '&', '&#', '&#x', '&#X', '&amp', '&not', '&copy', '&NoSuchEntity;' );
		$payload  = $this->prng->bytes( $this->prng->int( 0, min( 32, $this->max_bytes ) ) );
		$payload .= $this->prng->choice( $prefixes );
		$payload .= $this->prng->bytes( $this->prng->int( 0, min( 64, $this->max_bytes ) ) );
		return $payload;
	}

	private function gen_bytes_invalid_utf8(): string {
		$atoms = array(
			"\x80",
			"\xBF",
			"\xC0\xAF",
			"\xE0\x80\x80",
			"\xF0\x80\x80\x80",
			"\xF5\x80\x80\x80",
			"\xED\xA0\x80",
			"\xFE",
			"\xFF",
		);

		$out   = '';
		$count = $this->prng->int( 1, 12 );
		for ( $i = 0; $i < $count; $i++ ) {
			$out .= $this->prng->bytes( $this->prng->int( 0, 4 ) );
			$out .= $this->prng->choice( $atoms );
		}
		return $out;
	}

	private function gen_bytes_delimiters(): string {
		$delimiters = array( "\x00", "\r", '<', '"', '&', '=', "\n", "\t", "\f" );
		$out        = '';
		$count      = $this->prng->int( 1, 24 );
		for ( $i = 0; $i < $count; $i++ ) {
			$out .= $this->prng->choice( $delimiters );
			if ( $this->prng->chance( 35 ) ) {
				$out .= $this->prng->bytes( $this->prng->int( 1, 4 ) );
			}
		}
		return $out;
	}

	private function named_exact(): string {
		return '&' . $this->pick_semicolon_name();
	}

	private function pick_semicolon_name(): string {
		$preferred = array_values( array_intersect( self::PREFERRED_SEMICOLON, $this->semicolon_names ) );
		if ( array() !== $preferred && $this->prng->chance( 75 ) ) {
			return $this->prng->choice( $preferred );
		}

		return $this->prng->choice( $this->semicolon_names );
	}

	private function pick_legacy_name(): string {
		$preferred = array_values( array_intersect( self::PREFERRED_LEGACY, $this->legacy_names ) );
		if ( array() !== $preferred && $this->prng->chance( 80 ) ) {
			return $this->prng->choice( $preferred );
		}

		return $this->prng->choice( $this->legacy_names );
	}

	private function numeric_reference( bool $allow_missing_digits = false ): string {
		$kind = $this->prng->weighted(
			array(
				'decimal' => 45,
				'hex'     => 45,
				'missing' => $allow_missing_digits ? 10 : 0,
			)
		);

		if ( 'missing' === $kind ) {
			return $this->prng->choice( array( '&#;', '&#x;', '&#X;' ) );
		}

		$values = array(
			0, 9, 10, 12, 13, 34, 38, 58, 60, 62, 65, 0x7F,
			0x80, 0x81, 0x82, 0x8D, 0x91, 0x9F,
			0xD7FF, 0xD800, 0xDFFF, 0xE000,
			0xFDD0, 0xFDEF, 0xFFFE, 0xFFFF, 0x10FFFF, 0x110000,
			99999999,
		);
		$value = $this->prng->choice( $values );

		if ( 'hex' === $kind ) {
			$digits = dechex( $value );
			if ( $this->prng->chance( 50 ) ) {
				$digits = strtoupper( $digits );
			}
			$prefix = $this->prng->chance( 50 ) ? '&#x' : '&#X';
		} else {
			$digits = (string) $value;
			$prefix = '&#';
		}

		if ( $this->prng->chance( 35 ) ) {
			$digits = str_repeat( '0', $this->prng->int( 1, 10 ) ) . $digits;
		}

		return $prefix . $digits . ( $this->prng->chance( 82 ) ? ';' : '' );
	}

	private function plain_text( bool $allow_amp = false ): string {
		return $this->plain_text_up_to( min( 128, $this->max_bytes ), $allow_amp );
	}

	private function plain_text_up_to( int $max_bytes, bool $allow_amp = false ): string {
		$length = $this->prng->biased_length( max( 0, $max_bytes ) );
		if ( 0 === $length ) {
			return '';
		}

		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			if ( $allow_amp && $this->prng->chance( 3 ) ) {
				$out .= '&';
				continue;
			}
			$out .= self::ASCII_ALPHABET[ $this->prng->int( 0, strlen( self::ASCII_ALPHABET ) - 1 ) ];
		}

		return $out;
	}

	private function ascii_run( int $length ): string {
		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= self::ASCII_ALPHABET[ $this->prng->int( 0, strlen( self::ASCII_ALPHABET ) - 1 ) ];
		}
		return $out;
	}

	private function ascii_digits( int $length ): string {
		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= (string) $this->prng->int( 0, 9 );
		}
		return $out;
	}

	private function hex_digits( int $length ): string {
		$digits = '0123456789abcdefABCDEF';
		$out    = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $digits[ $this->prng->int( 0, strlen( $digits ) - 1 ) ];
		}
		return $out;
	}

	private static function trim_to_safe_max( string $payload, int $max_bytes ): string {
		$payload = str_replace( array( '<', '"', "\r", "\x00" ), array( '', "'", "\n", '' ), $payload );

		if ( strlen( $payload ) <= $max_bytes ) {
			return $payload;
		}

		$trimmed = substr( $payload, 0, $max_bytes );
		while ( '' !== $trimmed && ! mb_check_encoding( $trimmed, 'UTF-8' ) ) {
			$trimmed = substr( $trimmed, 0, -1 );
		}

		return $trimmed;
	}
}
