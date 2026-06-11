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
	private const NAME_MUTATION_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

	private Prng $prng;
	private int $max_bytes;

	/** @var string[] */
	private array $semicolon_names;

	/** @var string[] */
	private array $legacy_names;

	/** @var ?string[] */
	private ?array $name_sweep_base_names = null;

	/** @var ?array<string, true> */
	private ?array $name_sweep_base_name_set = null;

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

	/**
	 * @return array{context: string, strategy: string, payload: string}
	 */
	public function generate_name_sweep( int $case_index ): array {
		$base_names = $this->name_sweep_base_names();
		$followers  = self::name_sweep_followers();
		$variants   = 2 * count( $followers );
		$case_index = max( 0, $case_index );
		$name_index = intdiv( $case_index, $variants ) % count( $base_names );
		$variant    = $case_index % $variants;
		$with_semicolon = $variant >= count( $followers );
		$follower       = $followers[ $variant % count( $followers ) ];

		$payload = '&' . $base_names[ $name_index ] . ( $with_semicolon ? ';' : '' ) . $follower;

		return array(
			'context'  => 'both',
			'strategy' => 'name-sweep',
			'payload'  => self::trim_to_safe_max( $payload, $this->max_bytes ),
		);
	}

	public function name_sweep_period(): int {
		return count( $this->name_sweep_base_names() ) * 2 * count( self::name_sweep_followers() );
	}

	/**
	 * @return array{context: string, strategy: string, payload: string}
	 */
	public function generate_legacy_follower_sweep( int $case_index ): array {
		$followers  = self::legacy_follower_sweep_followers();
		$case_index = max( 0, $case_index );
		$name_index = intdiv( $case_index, count( $followers ) ) % count( $this->legacy_names );
		$follower   = $followers[ $case_index % count( $followers ) ];
		$payload    = '&' . $this->legacy_names[ $name_index ] . $follower;

		return array(
			'context'  => 'both',
			'strategy' => 'legacy-follower-sweep',
			'payload'  => self::trim_to_safe_max( $payload, $this->max_bytes ),
		);
	}

	public function legacy_follower_sweep_period(): int {
		return count( $this->legacy_names ) * count( self::legacy_follower_sweep_followers() );
	}

	/**
	 * @return array{context: string, strategy: string, payload: string}
	 */
	public function generate_prefix_family_sweep( int $case_index ): array {
		$cases      = $this->prefix_family_sweep_cases();
		$case_index = max( 0, $case_index ) % count( $cases );
		$case       = $cases[ $case_index ];
		$prefix     = substr( $case['reference'], 0, $case['split'] );

		return array(
			'context'  => 'both',
			'strategy' => 'prefix-family-sweep',
			'payload'  => self::trim_to_safe_max( $prefix . $case['follower'], $this->max_bytes ),
		);
	}

	public function prefix_family_sweep_period(): int {
		return count( $this->prefix_family_sweep_cases() );
	}

	/**
	 * @return array{context: string, strategy: string, payload: string}
	 */
	public function generate_numeric_boundary_sweep( int $case_index ): array {
		$cases      = self::numeric_boundary_sweep_cases();
		$case_index = max( 0, $case_index ) % count( $cases );

		return array(
			'context'  => 'both',
			'strategy' => 'numeric-boundary-sweep',
			'payload'  => self::trim_to_safe_max( $cases[ $case_index ], $this->max_bytes ),
		);
	}

	public function numeric_boundary_sweep_period(): int {
		return count( self::numeric_boundary_sweep_cases() );
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
		$lookalike = $this->prng->chance( 85 )
			? $this->edit_distance_lookalike()
			: $this->legacy_lookalike();

		return $this->plain_text() . $lookalike . $this->plain_text();
	}

	private function edit_distance_lookalike(): string {
		for ( $attempt = 0; $attempt < 40; $attempt++ ) {
			$base      = $this->prng->choice( $this->name_sweep_base_names() );
			$operation = $this->prng->weighted(
				array(
					'delete'     => 25,
					'insert'     => 25,
					'substitute' => 25,
					'transpose'  => 25,
				)
			);
			$mutated = $this->mutate_name_base( $base, $operation );

			if ( '' === $mutated || $mutated === $base || isset( $this->name_sweep_base_name_set()[ $mutated ] ) ) {
				continue;
			}

			return '&' . $mutated . ( $this->prng->chance( 80 ) ? ';' : '' );
		}

		return $this->legacy_lookalike();
	}

	private function legacy_lookalike(): string {
		return $this->prng->choice(
			array(
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
			)
		);
	}

	private function mutate_name_base( string $base, string $operation ): string {
		$length = strlen( $base );

		switch ( $operation ) {
			case 'delete':
				if ( $length < 2 ) {
					return '';
				}
				$offset = $this->prng->int( 0, $length - 1 );
				return substr( $base, 0, $offset ) . substr( $base, $offset + 1 );

			case 'insert':
				$offset = $this->prng->int( 0, $length );
				return substr( $base, 0, $offset ) . $this->random_name_char() . substr( $base, $offset );

			case 'substitute':
				if ( 0 === $length ) {
					return '';
				}
				$offset = $this->prng->int( 0, $length - 1 );
				return substr( $base, 0, $offset ) . $this->random_name_char( $base[ $offset ] ) . substr( $base, $offset + 1 );

			case 'transpose':
				if ( $length < 2 ) {
					return '';
				}
				$offsets = array();
				for ( $i = 0; $i < $length - 1; $i++ ) {
					if ( $base[ $i ] !== $base[ $i + 1 ] ) {
						$offsets[] = $i;
					}
				}
				if ( array() === $offsets ) {
					return '';
				}

				$offset = $this->prng->choice( $offsets );
				return substr( $base, 0, $offset ) . $base[ $offset + 1 ] . $base[ $offset ] . substr( $base, $offset + 2 );
		}

		return '';
	}

	private function random_name_char( ?string $except = null ): string {
		$alphabet = self::NAME_MUTATION_ALPHABET;
		$char     = $alphabet[ $this->prng->int( 0, strlen( $alphabet ) - 1 ) ];
		if ( null === $except || $char !== $except ) {
			return $char;
		}

		$offset = strpos( $alphabet, $except );
		if ( false === $offset ) {
			return $char;
		}

		return $alphabet[ ( $offset + $this->prng->int( 1, strlen( $alphabet ) - 1 ) ) % strlen( $alphabet ) ];
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

	/**
	 * @return string[]
	 */
	private function name_sweep_base_names(): array {
		if ( null !== $this->name_sweep_base_names ) {
			return $this->name_sweep_base_names;
		}

		$base_names = array();
		foreach ( array_merge( $this->semicolon_names, $this->legacy_names ) as $name ) {
			$base = rtrim( $name, ';' );
			if ( '' !== $base ) {
				$base_names[ $base ] = true;
			}
		}

		$this->name_sweep_base_names = array_keys( $base_names );
		return $this->name_sweep_base_names;
	}

	/**
	 * @return array<string, true>
	 */
	private function name_sweep_base_name_set(): array {
		if ( null === $this->name_sweep_base_name_set ) {
			$this->name_sweep_base_name_set = array_fill_keys( $this->name_sweep_base_names(), true );
		}

		return $this->name_sweep_base_name_set;
	}

	/**
	 * @return string[]
	 */
	private static function name_sweep_followers(): array {
		return array( '', 'x', 'X', '0', '=', '-', ' ', '/', "\u{00E9}" );
	}

	/**
	 * @return string[]
	 */
	private static function legacy_follower_sweep_followers(): array {
		static $followers = null;
		if ( null !== $followers ) {
			return $followers;
		}

		$followers = array();

		for ( $byte = 1; $byte <= 0x7F; $byte++ ) {
			if ( in_array( $byte, array( 0x0D, 0x22, 0x3C ), true ) ) {
				continue;
			}
			$followers[] = chr( $byte );
		}

		for ( $lead = 0xC2; $lead <= 0xF4; $lead++ ) {
			if ( $lead < 0xE0 ) {
				$followers[] = chr( $lead ) . "\x80";
			} elseif ( 0xE0 === $lead ) {
				$followers[] = "\xE0\xA0\x80";
			} elseif ( $lead < 0xF0 ) {
				$followers[] = chr( $lead ) . "\x80\x80";
			} elseif ( 0xF0 === $lead ) {
				$followers[] = "\xF0\x90\x80\x80";
			} elseif ( $lead < 0xF4 ) {
				$followers[] = chr( $lead ) . "\x80\x80\x80";
			} else {
				$followers[] = "\xF4\x80\x80\x80";
			}
		}

		for ( $continuation = 0x80; $continuation <= 0xBF; $continuation++ ) {
			$followers[] = "\xC2" . chr( $continuation );
		}

		$followers = array_values( array_unique( $followers ) );
		return $followers;
	}

	/**
	 * @return array<int, array{reference: string, split: int, follower: string}>
	 */
	private function prefix_family_sweep_cases(): array {
		$name_set = $this->name_sweep_base_name_set();
		$cases    = array();

		foreach ( self::prefix_family_sweep_references() as $reference ) {
			$base = rtrim( $reference, ';' );
			if ( ! isset( $name_set[ $base ] ) ) {
				continue;
			}

			$full_reference = '&' . $reference;
			for ( $split = 1; $split < strlen( $full_reference ); $split++ ) {
				foreach ( self::prefix_family_sweep_followers() as $follower ) {
					$cases[] = array(
						'reference' => $full_reference,
						'split'     => $split,
						'follower'  => $follower,
					);
				}
			}
		}

		return $cases;
	}

	/**
	 * @return string[]
	 */
	private static function prefix_family_sweep_references(): array {
		return array(
			'not',
			'not;',
			'notin;',
			'notinva;',
			'ngt;',
			'nGt;',
			'nGtv;',
			'nge;',
			'ngeq;',
			'ngeqq;',
		);
	}

	/**
	 * @return string[]
	 */
	private static function prefix_family_sweep_followers(): array {
		return array( '', 'x', 'X', '0', '=', "\u{00E9}" );
	}

	/**
	 * @return string[]
	 */
	private static function numeric_boundary_sweep_cases(): array {
		static $cases = null;
		if ( null !== $cases ) {
			return $cases;
		}

		$cases = array();
		foreach ( array( 'decimal', 'hex-lower', 'hex-upper', 'hex-mixed' ) as $kind ) {
			$is_decimal = 'decimal' === $kind;
			$max_digits = $is_decimal ? 7 : 6;
			foreach ( array( $max_digits, $max_digits + 1 ) as $digit_count ) {
				foreach ( array( false, true ) as $leading_zero ) {
					foreach ( array( false, true ) as $semicolon ) {
						$cases[] = self::numeric_boundary_reference( $kind, $digit_count, $leading_zero, $semicolon );
					}
				}
			}
		}

		return array_values( array_unique( $cases ) );
	}

	private static function numeric_boundary_reference( string $kind, int $digit_count, bool $leading_zero, bool $semicolon ): string {
		if ( 'decimal' === $kind ) {
			$prefix = '&#';
			$digits = 7 === $digit_count ? '1114111' : substr( str_repeat( '9', $digit_count ), 0, $digit_count );
		} else {
			$prefix = 'hex-upper' === $kind ? '&#X' : '&#x';
			$digits = 6 === $digit_count ? '10ffee' : substr( str_repeat( 'abcdef', (int) ceil( $digit_count / 6 ) ), 0, $digit_count );
			if ( 'hex-upper' === $kind ) {
				$digits = strtoupper( $digits );
			} elseif ( 'hex-mixed' === $kind ) {
				$chars = str_split( $digits );
				foreach ( $chars as $i => $char ) {
					if ( 0 === $i % 2 ) {
						$chars[ $i ] = strtoupper( $char );
					}
				}
				$digits = implode( '', $chars );
			}
		}

		if ( $leading_zero ) {
			$digits = '0' . $digits;
		}

		return $prefix . $digits . ( $semicolon ? ';' : '' );
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

		$value = $this->numeric_code_point( 'hex' === $kind ? 16 : 10 );

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

	private function numeric_code_point( int $numeric_base ): int {
		$bucket = $this->prng->weighted(
			array(
				'zero'                       => 5,
				'c0-control'                 => 8,
				'ascii'                      => 10,
				'c1-control'                 => 14,
				'bmp'                        => 12,
				'surrogate'                  => 12,
				'bmp-noncharacter'           => 8,
				'plane-noncharacter'         => 10,
				'astral'                     => 10,
				'above-unicode-legal-digits' => 8,
				'digit-count-overflow'       => 5,
			)
		);

		switch ( $bucket ) {
			case 'zero':
				return 0;

			case 'c0-control':
				return $this->prng->int( 1, 0x1F );

			case 'ascii':
				return $this->prng->int( 0x20, 0x7F );

			case 'c1-control':
				return $this->prng->int( 0x80, 0x9F );

			case 'bmp':
				if ( $this->prng->chance( 50 ) ) {
					return $this->prng->int( 0xA0, 0xD7FF );
				}
				if ( $this->prng->chance( 50 ) ) {
					return $this->prng->int( 0xE000, 0xFDCF );
				}
				return $this->prng->int( 0xFDF0, 0xFFFD );

			case 'surrogate':
				return $this->prng->int( 0xD800, 0xDFFF );

			case 'bmp-noncharacter':
				if ( $this->prng->chance( 75 ) ) {
					return $this->prng->int( 0xFDD0, 0xFDEF );
				}
				return $this->prng->choice( array( 0xFFFE, 0xFFFF ) );

			case 'plane-noncharacter':
				return ( $this->prng->int( 1, 16 ) << 16 ) + $this->prng->choice( array( 0xFFFE, 0xFFFF ) );

			case 'astral':
				return ( $this->prng->int( 1, 16 ) << 16 ) + $this->prng->int( 0, 0xFFFD );

			case 'above-unicode-legal-digits':
				return $this->prng->int( 0x110000, 16 === $numeric_base ? 0xFFFFFF : 9999999 );

			case 'digit-count-overflow':
				return $this->prng->int( 16 === $numeric_base ? 0x1000000 : 10000000, 16 === $numeric_base ? 0xFFFFFFF : 99999999 );
		}

		return 0x41;
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
