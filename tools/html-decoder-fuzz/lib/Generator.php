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

	private const ASCII_ALPHABET = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 \t\n\f-_:/.,;#[](){}'=+!?*";
	private const NAME_MUTATION_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	private const COMPOSITION_SEPARATOR = '|';
	private const ATTRIBUTE_PREFIX_TARGETS = array(
		'javascript:',
		'JaVaScRiPt:',
		'http://',
		'https://',
		'mailto:user@example.com',
		'data:text/plain,',
		'urn:wp:html5:',
		'ftp://',
	);

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
		} else {
			self::sort_reference_names( $this->semicolon_names );
		}
		if ( array() === $this->legacy_names ) {
			$this->legacy_names = self::PREFERRED_LEGACY;
		} else {
			self::sort_reference_names( $this->legacy_names );
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
				'composition'            => 9,
				'case-mangled-name'      => 8,
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

	/**
	 * @return array{context: string, strategy: string, payload: string}
	 */
	public function generate_corpus_mutation( int $case_index ): array {
		$corpus     = self::corpus_payloads();
		$case_index = max( 0, $case_index );
		$operation  = $this->prng->weighted(
			array(
				'splice'                => 25,
				'byte-perturb'          => 25,
				'semicolon-toggle'      => 25,
				'reference-duplication' => 25,
			)
		);
		$payload    = $corpus[ $case_index % count( $corpus ) ];
		$payload    = $this->mutate_corpus_payload( $payload, $operation, $corpus );

		if ( $this->prng->chance( 35 ) ) {
			$payload = $this->mutate_corpus_payload(
				$payload,
				$this->prng->choice( array( 'splice', 'byte-perturb', 'semicolon-toggle', 'reference-duplication' ) ),
				$corpus
			);
		}

		return array(
			'context'  => 'both',
			'strategy' => 'corpus-' . $operation,
			'payload'  => self::trim_to_safe_max( $payload, $this->max_bytes ),
		);
	}

	public function corpus_period(): int {
		return count( self::corpus_payloads() );
	}

	/**
	 * @return array{context: string, strategy: string, payload: string}
	 */
	public function generate_token_map_sweep( int $case_index ): array {
		$cases      = self::token_map_sweep_cases();
		$case_index = max( 0, $case_index ) % count( $cases );

		return array(
			'context'  => 'both',
			'strategy' => 'token-map-structure-sweep',
			'payload'  => self::trim_to_safe_max( $cases[ $case_index ]['payload'], $this->max_bytes ),
		);
	}

	public function token_map_period(): int {
		return count( self::token_map_sweep_cases() );
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
		if ( $this->prng->chance( 72 ) ) {
			$encoded = $this->encode_attribute_prefix_target( $this->prng->choice( self::ATTRIBUTE_PREFIX_TARGETS ) );
			$suffix  = $this->plain_text_up_to( max( 0, $this->max_bytes - strlen( $encoded['payload'] ) ) );
			if ( null !== $encoded['semicolonless_base'] && '' !== $suffix && self::would_extend_semicolonless_numeric( $encoded['semicolonless_base'], $suffix[0] ) ) {
				$suffix = '_' . substr( $suffix, 1 );
			}

			return $encoded['payload'] . $suffix;
		}

		$prefix = $this->prng->choice(
			array(
				'&nvlt;',
				'&nvgt;',
				'&NotLessLess;',
				'&bne;',
				'jav',
			)
		);

		return $prefix . $this->plain_text_up_to( max( 0, $this->max_bytes - strlen( $prefix ) ) );
	}

	private function gen_lookalike(): string {
		$lookalike = $this->prng->chance( 85 )
			? $this->edit_distance_lookalike()
			: $this->legacy_lookalike();

		return $this->plain_text() . $lookalike . $this->plain_text();
	}

	private function gen_case_mangled_name(): string {
		return $this->plain_text() . $this->case_mangled_name() . $this->plain_text();
	}

	private function gen_composition(): string {
		if ( $this->max_bytes < 3 ) {
			return self::trim_to_safe_max( $this->named_exact(), $this->max_bytes );
		}

		$strategies = array(
			'named-exact',
			'named-missing-semi',
			'attribute-discriminator',
			'numeric',
			'adjacency',
			'truncation-sweep',
			'reference-at-eof',
			'multibyte-around',
			'attribute-prefix',
			'lookalike',
			'case-mangled-name',
		);
		$max_count          = min( 3, intdiv( $this->max_bytes + strlen( self::COMPOSITION_SEPARATOR ), 1 + strlen( self::COMPOSITION_SEPARATOR ) ) );
		$count              = $this->prng->int( 2, $max_count );
		$original_max_bytes = $this->max_bytes;
		$out                = '';

		for ( $i = 0; $i < $count; $i++ ) {
			if ( $i > 0 ) {
				$out .= self::COMPOSITION_SEPARATOR;
			}

			$remaining_fragments = $count - $i - 1;
			$reserved_bytes      = $remaining_fragments * ( 1 + strlen( self::COMPOSITION_SEPARATOR ) );
			$fragment_max_bytes  = max( 1, $original_max_bytes - strlen( $out ) - $reserved_bytes );

			$out .= $this->composition_fragment( $strategies, $fragment_max_bytes );
		}

		return $out;
	}

	/**
	 * @param string[] $strategies
	 */
	private function composition_fragment( array $strategies, int $max_bytes ): string {
		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$strategy = $this->prng->choice( $strategies );
			$method   = 'gen_' . str_replace( '-', '_', $strategy );
			$fragment = $this->with_max_bytes(
				$max_bytes,
				function () use ( $method ): string {
					return $this->$method();
				}
			);
			$fragment = self::trim_to_safe_max( $fragment, $max_bytes );

			if ( '' !== $fragment && ! str_contains( $fragment, self::COMPOSITION_SEPARATOR ) ) {
				return $fragment;
			}
		}

		return '&';
	}

	/**
	 * @param string[] $corpus
	 */
	private function mutate_corpus_payload( string $payload, string $operation, array $corpus ): string {
		switch ( $operation ) {
			case 'splice':
				return $this->mutate_corpus_splice( $payload, $corpus );

			case 'byte-perturb':
				return $this->mutate_corpus_byte_perturb( $payload );

			case 'semicolon-toggle':
				return $this->mutate_corpus_semicolon_toggle( $payload );

			case 'reference-duplication':
				return $this->mutate_corpus_reference_duplication( $payload );
		}

		return $payload;
	}

	/**
	 * @param string[] $corpus
	 */
	private function mutate_corpus_splice( string $payload, array $corpus ): string {
		$other = $this->prng->choice( $corpus );

		$left_at  = $this->utf8_boundary( $payload );
		$right_at = $this->utf8_boundary( $payload );
		if ( $right_at < $left_at ) {
			list( $left_at, $right_at ) = array( $right_at, $left_at );
		}

		$other_left  = $this->utf8_boundary( $other );
		$other_right = $this->utf8_boundary( $other );
		if ( $other_right < $other_left ) {
			list( $other_left, $other_right ) = array( $other_right, $other_left );
		}
		$splice      = substr( $other, $other_left, $other_right - $other_left );
		if ( '' === $splice ) {
			$splice = $this->prng->choice( array( '&amp;', '&#x80;', '&notin;', '&gt' ) );
		}

		return substr( $payload, 0, $left_at ) . $splice . substr( $payload, $right_at );
	}

	private function mutate_corpus_byte_perturb( string $payload ): string {
		$operation = $this->prng->weighted(
			array(
				'insert'  => 35,
				'replace' => 45,
				'delete'  => 20,
			)
		);

		if ( '' === $payload || 'insert' === $operation ) {
			$at = $this->utf8_boundary( $payload );
			return substr( $payload, 0, $at ) . $this->safe_corpus_byte() . substr( $payload, $at );
		}

		list( $at, $next ) = $this->utf8_character_span( $payload );
		if ( 'delete' === $operation ) {
			return substr( $payload, 0, $at ) . substr( $payload, $next );
		}

		return substr( $payload, 0, $at ) . $this->safe_corpus_byte() . substr( $payload, $next );
	}

	private function mutate_corpus_semicolon_toggle( string $payload ): string {
		$matches = $this->reference_matches( $payload );
		if ( array() === $matches ) {
			return $payload . $this->prng->choice( array( '&amp', '&amp;', '&#58', '&#58;' ) );
		}

		$match     = $this->prng->choice( $matches );
		$reference = $match['text'];
		if ( str_ends_with( $reference, ';' ) ) {
			$replacement = substr( $reference, 0, -1 );
		} else {
			$replacement = $reference . ';';
		}

		return substr( $payload, 0, $match['offset'] ) . $replacement . substr( $payload, $match['offset'] + strlen( $reference ) );
	}

	private function mutate_corpus_reference_duplication( string $payload ): string {
		$matches = $this->reference_matches( $payload );
		if ( array() === $matches ) {
			return $payload . $this->prng->choice( array( '&gt;&gt;', '&#x80;&#x80;', '&notin;&notin;' ) );
		}

		$match = $this->prng->choice( $matches );
		return substr( $payload, 0, $match['offset'] + strlen( $match['text'] ) ) . $match['text'] . substr( $payload, $match['offset'] + strlen( $match['text'] ) );
	}

	private function safe_corpus_byte(): string {
		return self::ASCII_ALPHABET[ $this->prng->int( 0, strlen( self::ASCII_ALPHABET ) - 1 ) ];
	}

	private function utf8_boundary( string $payload ): int {
		return $this->prng->choice( self::utf8_boundaries( $payload ) );
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private function utf8_character_span( string $payload ): array {
		$boundaries = self::utf8_boundaries( $payload );
		if ( count( $boundaries ) < 2 ) {
			return array( 0, 0 );
		}

		$index = $this->prng->int( 0, count( $boundaries ) - 2 );
		return array( $boundaries[ $index ], $boundaries[ $index + 1 ] );
	}

	/**
	 * @return int[]
	 */
	private static function utf8_boundaries( string $payload ): array {
		$boundaries = array( 0 );
		if ( '' === $payload ) {
			return $boundaries;
		}

		$match_count = preg_match_all( '/./us', $payload, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $match_count || 0 === $match_count ) {
			return array( 0, strlen( $payload ) );
		}

		foreach ( $matches[0] as $match ) {
			$boundaries[] = $match[1] + strlen( $match[0] );
		}

		return array_values( array_unique( $boundaries ) );
	}

	/**
	 * @return array<int, array{text: string, offset: int}>
	 */
	private function reference_matches( string $payload ): array {
		$matches = array();
		$match_count = preg_match_all( '/&(?:#[xX][0-9A-Fa-f]+|#[0-9]+|[A-Za-z][A-Za-z0-9]+);?/', $payload, $raw_matches, PREG_OFFSET_CAPTURE );
		if ( false === $match_count || 0 === $match_count ) {
			return $matches;
		}

		foreach ( $raw_matches[0] as $match ) {
			$matches[] = array(
				'text'   => $match[0],
				'offset' => $match[1],
			);
		}

		return $matches;
	}

	/**
	 * @return array{payload: string, semicolonless_base: ?string}
	 */
	private function encode_attribute_prefix_target( string $target ): array {
		if ( '' === $target ) {
			return array(
				'payload'            => '',
				'semicolonless_base' => null,
			);
		}

		$length             = strlen( $target );
		$force_reference_at = $this->prng->int( 0, $length - 1 );
		$out                = '';
		$previous_base      = null;

		for ( $i = 0; $i < $length; $i++ ) {
			$char          = $target[ $i ];
			$allow_literal = $i !== $force_reference_at && self::is_oracle_safe_literal( $char ) && ! self::would_extend_semicolonless_numeric( $previous_base, $char );
			$encoded       = $this->encode_attribute_prefix_character( ord( $char ), $allow_literal );
			$out          .= $encoded['payload'];
			$previous_base = $encoded['semicolonless_base'];
		}

		return array(
			'payload'            => $out,
			'semicolonless_base' => $previous_base,
		);
	}

	/**
	 * @return array{payload: string, semicolonless_base: ?string}
	 */
	private function encode_attribute_prefix_character( int $code_point, bool $allow_literal ): array {
		$encoding = $this->prng->weighted(
			array(
				'literal'              => $allow_literal ? 34 : 0,
				'decimal'              => 17,
				'decimal-leading-zero' => 17,
				'hex-lower'            => 14,
				'hex-upper'            => 10,
				'hex-leading-zero'     => 8,
			)
		);

		if ( 'literal' === $encoding ) {
			return array(
				'payload'            => chr( $code_point ),
				'semicolonless_base' => null,
			);
		}

		$is_hex = str_starts_with( $encoding, 'hex' );
		$digits = $is_hex ? dechex( $code_point ) : (string) $code_point;

		if ( str_ends_with( $encoding, 'leading-zero' ) ) {
			$digits = str_repeat( '0', $this->prng->int( 1, 4 ) ) . $digits;
		}
		if ( 'hex-upper' === $encoding || ( 'hex-leading-zero' === $encoding && $this->prng->chance( 50 ) ) ) {
			$digits = strtoupper( $digits );
		}

		$semicolon = $this->prng->chance( 68 ) ? ';' : '';
		$prefix    = $is_hex
			? ( $this->prng->chance( 50 ) ? '&#x' : '&#X' )
			: '&#';

		return array(
			'payload'            => $prefix . $digits . $semicolon,
			'semicolonless_base' => '' === $semicolon ? ( $is_hex ? 'hex' : 'decimal' ) : null,
		);
	}

	private static function is_oracle_safe_literal( string $char ): bool {
		return ! in_array( $char, array( '<', '"', "\r", "\x00" ), true );
	}

	private static function would_extend_semicolonless_numeric( ?string $base, string $char ): bool {
		if ( null === $base ) {
			return false;
		}

		if ( ';' === $char ) {
			return true;
		}

		if ( 'decimal' === $base ) {
			return self::is_ascii_digit( $char );
		}

		return self::is_ascii_hex_digit( $char );
	}

	private static function is_ascii_digit( string $char ): bool {
		$ord = ord( $char );
		return $ord >= 0x30 && $ord <= 0x39;
	}

	private static function is_ascii_hex_digit( string $char ): bool {
		$ord = ord( $char );
		return (
			( $ord >= 0x30 && $ord <= 0x39 ) ||
			( $ord >= 0x41 && $ord <= 0x46 ) ||
			( $ord >= 0x61 && $ord <= 0x66 )
		);
	}

	private static function is_ascii_alpha( string $char ): bool {
		$ord = ord( $char );
		return ( $ord >= 0x41 && $ord <= 0x5A ) || ( $ord >= 0x61 && $ord <= 0x7A );
	}

	/**
	 * @param callable(): string $callback
	 */
	private function with_max_bytes( int $max_bytes, callable $callback ): string {
		$previous_max_bytes = $this->max_bytes;
		$this->max_bytes    = max( 1, $max_bytes );

		try {
			return $callback();
		} finally {
			$this->max_bytes = $previous_max_bytes;
		}
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

	private function case_mangled_name(): string {
		$base_set = $this->name_sweep_base_name_set();
		for ( $attempt = 0; $attempt < 60; $attempt++ ) {
			$base    = $this->prng->choice( $this->name_sweep_base_names() );
			$mutated = $this->case_mangle_name_base( $base );
			if ( '' === $mutated || $mutated === $base || isset( $base_set[ $mutated ] ) ) {
				continue;
			}

			return '&' . $mutated . ';';
		}

		return $this->legacy_lookalike();
	}

	private function case_mangle_name_base( string $base ): string {
		$letter_offsets = array();
		for ( $i = 0; $i < strlen( $base ); $i++ ) {
			if ( self::is_ascii_alpha( $base[ $i ] ) ) {
				$letter_offsets[] = $i;
			}
		}

		if ( array() === $letter_offsets ) {
			return '';
		}

		$mutated = $base;
		$flips   = $this->prng->int( 1, min( 3, count( $letter_offsets ) ) );
		for ( $i = 0; $i < $flips; $i++ ) {
			$index  = $this->prng->int( 0, count( $letter_offsets ) - 1 );
			$offset = $letter_offsets[ $index ];
			array_splice( $letter_offsets, $index, 1 );
			$char   = $mutated[ $offset ];
			$mutated[ $offset ] = strtolower( $char ) === $char ? strtoupper( $char ) : strtolower( $char );
		}

		return $mutated;
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
	 * @param string[] $names
	 */
	private static function sort_reference_names( array &$names ): void {
		usort(
			$names,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a ) ?: strcmp( $a, $b );
			}
		);
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

	/**
	 * @return string[]
	 */
	private static function corpus_payloads(): array {
		static $payloads = null;
		if ( null !== $payloads ) {
			return $payloads;
		}

		$payloads = array(
			'',
			'plain text',
			'FOO&gt;BAR',
			'FOO&gtBAR',
			'FOO&gt;;;BAR',
			'FOO&&&&gt;BAR',
			"I'm &notit; I tell you",
			"I'm &notin; I tell you",
			'&ammmp;',
			'&amp;amp;',
			'&notin;&notinva;&not;',
			'ZZ&gt=YY',
			'ZZ&gt0YY',
			'ZZ&gt YY',
			'ZZ&gt',
			'javascript&colon;alert(1)',
			'javascript&#58;alert(1)',
			'javascript&#x3a;alert(1)',
			'&#x80;&#128;&#00000128;',
			'&#0;&#xD800;&#x110000;',
			'&nvlt;tail',
			'&NoSuchEntity;&amp',
		);

		foreach ( Oracles::battery() as $vector ) {
			$payloads[] = $vector[1];
		}

		foreach ( self::html5lib_entity_payloads() as $payload ) {
			$payloads[] = $payload;
		}

		$payloads = array_values(
			array_unique(
				array_filter(
					$payloads,
					static fn( string $payload ): bool => self::is_oracle_safe_payload( $payload )
				)
			)
		);

		if ( array() === $payloads ) {
			$payloads = array( '&amp;' );
		}

		return $payloads;
	}

	/**
	 * @return array<int, array{shape: string, payload: string, prefix?: string, name?: string}>
	 */
	private static function token_map_sweep_cases(): array {
		static $cases = null;
		if ( null !== $cases ) {
			return $cases;
		}

		$structure  = Bootstrap::named_reference_structure();
		$key_length = $structure['key_length'];
		$cases      = array();

		foreach ( $structure['group_prefixes'] as $prefix ) {
			$cases[] = array(
				'shape'   => 'large-prefix-divergent',
				'prefix'  => $prefix,
				'payload' => '&' . $prefix . self::token_map_divergent_suffix( $prefix, $structure['large_names_by_prefix'][ $prefix ] ?? array() ),
			);
		}

		foreach ( $structure['small_names'] as $name ) {
			$cases[] = array(
				'shape'   => 'small-boundary-exact',
				'name'    => $name,
				'payload' => '&' . $name,
			);
			$cases[] = array(
				'shape'   => 'small-boundary-extended',
				'name'    => $name,
				'payload' => '&' . $name . 'Q;',
			);
		}

		foreach ( $structure['large_names'] as $name ) {
			if ( strlen( $name ) !== $key_length + 1 ) {
				continue;
			}

			$cases[] = array(
				'shape'   => 'large-boundary-exact',
				'name'    => $name,
				'payload' => '&' . $name,
			);
			$cases[] = array(
				'shape'   => 'large-boundary-extended',
				'name'    => $name,
				'payload' => '&' . $name . 'Q;',
			);
		}

		$cases = array_values(
			array_filter(
				$cases,
				static fn( array $case ): bool => self::is_oracle_safe_payload( $case['payload'] )
			)
		);

		return array() === $cases
			? array( array( 'shape' => 'fallback', 'payload' => '&NoSuchEntity;' ) )
			: $cases;
	}

	/**
	 * @param string[] $names
	 */
	private static function token_map_divergent_suffix( string $prefix, array $names ): string {
		$used_first_rest_chars = array();
		$prefix_length         = strlen( $prefix );
		foreach ( $names as $name ) {
			$rest = substr( $name, $prefix_length );
			if ( '' !== $rest ) {
				$used_first_rest_chars[ $rest[0] ] = true;
			}
		}

		for ( $i = 0; $i < strlen( self::NAME_MUTATION_ALPHABET ); $i++ ) {
			$char = self::NAME_MUTATION_ALPHABET[ $i ];
			if ( ! isset( $used_first_rest_chars[ $char ] ) ) {
				return $char . 'QQ;';
			}
		}

		return '_QQ;';
	}

	/**
	 * @return string[]
	 */
	private static function html5lib_entity_payloads(): array {
		$payloads = array();
		foreach ( array( 'entities01.dat', 'entities02.dat' ) as $file ) {
			$path = Bootstrap::repo_root() . '/tests/phpunit/data/html5lib-tests/tree-construction/' . $file;
			if ( ! is_file( $path ) ) {
				continue;
			}

			$lines = file( $path, FILE_IGNORE_NEW_LINES );
			if ( ! is_array( $lines ) ) {
				continue;
			}

			for ( $i = 0; $i + 1 < count( $lines ); $i++ ) {
				if ( '#data' !== $lines[ $i ] ) {
					continue;
				}

				$payload = self::html5lib_entity_payload_from_data_line( $lines[ $i + 1 ] );
				if ( strlen( $payload ) > 512 ) {
					$payload = substr( $payload, 0, 512 );
				}
				$payloads[] = $payload;
			}
		}

		return $payloads;
	}

	private static function html5lib_entity_payload_from_data_line( string $line ): string {
		if ( 1 === preg_match( '/^<div\s+bar=(?:"([^"]*)"|\'([^\']*)\'|([^>\s]+))><\/div>$/', $line, $match ) ) {
			foreach ( array( 1, 2, 3 ) as $index ) {
				if ( isset( $match[ $index ] ) && '' !== $match[ $index ] ) {
					return $match[ $index ];
				}
			}
		}

		if ( 1 === preg_match( '/^<div>(.*)<\/div>$/', $line, $match ) ) {
			return $match[1];
		}

		return $line;
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

		while ( '' !== $payload && ! mb_check_encoding( $payload, 'UTF-8' ) ) {
			$payload = substr( $payload, 0, -1 );
		}

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
