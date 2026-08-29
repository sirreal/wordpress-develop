<?php
namespace CssSelectorFuzz;

/**
 * Generates CSS selector strings in several buckets:
 *
 *  - supported-compound: within the grammar of WP_CSS_Compound_Selector_List.
 *    Must parse in both list grammars. Carries the intended AST.
 *  - supported-complex: uses descendant/child combinators with type-only
 *    context selectors. Must parse as a complex list, must NOT parse as a
 *    compound list. Carries the intended AST.
 *  - unsupported: valid CSS that the parsers deliberately do not support
 *    (pseudo-classes/elements, sibling/column combinators, namespaces,
 *    non-type context selectors). Must not parse in either grammar.
 *  - invalid: not valid CSS selectors at all. Must not parse.
 *  - invalid-utf8: a supported compound with a raw ill-formed UTF-8 byte
 *    sequence injected into an ident or string operand. from_selectors()
 *    scrubs the input before parsing ( one U+FFFD per maximal subpart, CSS
 *    Syntax §3.2 via the WHATWG decoder ), so the case carries the
 *    post-scrub AST.
 *  - chaos: arbitrary bytes. No parse expectation.
 *  - mutated: a supported selector with random byte mutations. No parse
 *    expectation.
 *
 * Whenever a generated selector carries an AST, the rendered string is
 * randomized (whitespace, escapes, quoting styles) such that parsing it
 * must yield exactly that AST.
 */
class SelectorGenerator {

	const BUCKETS = array(
		'supported-compound',
		'supported-complex',
		'path-directed',
		'unsupported',
		'invalid',
		'invalid-utf8',
		'chaos',
		'mutated',
		'edge-escape',
	);

	/**
	 * Ill-formed UTF-8 byte classes and the number of U+FFFD replacements the
	 * WHATWG UTF-8 decoder produces for each ( one per maximal subpart ).
	 * The counts are pinned here as an independent expectation — computing
	 * them with wp_scrub_utf8() would make the AST check a tautology.
	 *
	 * The counts assume the byte after the sequence is not a continuation
	 * byte ( it could complete a truncated sequence ); the generator always
	 * follows an injected sequence with ASCII or end of input.
	 */
	const INVALID_UTF8_CLASSES = array(
		'lone-continuation' => array( "\x80", 1 ),
		'truncated-2-byte'  => array( "\xC3", 1 ),
		'truncated-3-byte'  => array( "\xE2\x8C", 1 ),
		'truncated-4-byte'  => array( "\xF0\x9F\x82", 1 ),
		'invalid-lead-f5'   => array( "\xF5", 1 ),
		'invalid-lead-ff'   => array( "\xFF", 1 ),
		'overlong-min'      => array( "\xC0\x80", 2 ),
		'overlong-max'      => array( "\xC1\xBF", 2 ),
		'surrogate-half'    => array( "\xED\xA0\x80", 3 ),
		'beyond-max'        => array( "\xF4\x90\x80\x80", 4 ),
	);

	/** @var Prng */
	private $prng;
	/** @var array */
	private $pools;
	/** @var bool Escape ident codepoints aggressively when rendering. */
	private $escape_boost = false;

	private function __construct( Prng $prng, array $pools ) {
		$this->prng  = $prng;
		$this->pools = $pools;
	}

	/**
	 * Renders a canonical complex-list AST to a selector string. Parsing the
	 * result must yield exactly the given AST. With $escape_boost, idents are
	 * escaped far more often (exercises the escape decoder on no-op escapes).
	 */
	public static function render( Prng $prng, array $list_ast, bool $escape_boost = false ): string {
		$generator               = new self( $prng, array() );
		$generator->escape_boost = $escape_boost;
		return $generator->render_complex_list( $list_ast );
	}

	/**
	 * Renders a canonical complex-list AST deterministically with minimal
	 * escaping: single spaces around combinators, `, ` between branches,
	 * double-quoted attribute values, lowercase `i`/`s` modifiers, and all
	 * non-ASCII codepoints hex-escaped. Useful when a deterministic
	 * semantically-identical selector string is needed from an already parsed
	 * AST.
	 */
	public static function render_canonical( array $list_ast ): string {
		$branches = array();
		foreach ( $list_ast as $complex ) {
			$out = '';
			foreach ( array_reverse( $complex['context'] ) as $pair ) {
				list( $type, $combinator ) = $pair;
				$out                      .= '*' === $type ? '*' : self::canonical_ident( $type );
				$out                      .= '>' === $combinator ? ' > ' : ' ';
			}

			$compound = $complex['self'];
			if ( null !== $compound['type'] ) {
				$out .= '*' === $compound['type'] ? '*' : self::canonical_ident( $compound['type'] );
			}
			foreach ( (array) $compound['subs'] as $sub ) {
				switch ( $sub['kind'] ) {
					case 'class':
						$out .= '.' . self::canonical_ident( $sub['name'] );
						break;
					case 'id':
						$out .= '#' . self::canonical_ident( $sub['name'] );
						break;
					case 'attr':
						$out .= '[' . self::canonical_ident( $sub['name'] );
						if ( null !== $sub['matcher'] ) {
							$matchers = array(
								'exact'                    => '=',
								'one-of'                   => '~=',
								'exact-or-hyphen-suffixed' => '|=',
								'prefixed'                 => '^=',
								'suffixed'                 => '$=',
								'contains'                 => '*=',
							);
							$out     .= $matchers[ $sub['matcher'] ] . self::canonical_string( (string) $sub['value'] );
							if ( 'case-insensitive' === $sub['modifier'] ) {
								$out .= ' i';
							} elseif ( 'case-sensitive' === $sub['modifier'] ) {
								$out .= ' s';
							}
						}
						$out .= ']';
						break;
				}
			}
			$branches[] = $out;
		}
		return implode( ', ', $branches );
	}

	private static function canonical_ident( string $name ): string {
		$points = utf8_codepoints( $name );
		$count  = count( $points );
		$out    = '';

		foreach ( $points as $i => $point ) {
			list( $char, $cp ) = $point;

			$is_digit      = $cp >= 0x30 && $cp <= 0x39;
			$is_ident_char = (
				'-' === $char ||
				'_' === $char ||
				$is_digit ||
				( $cp >= 0x41 && $cp <= 0x5A ) ||
				( $cp >= 0x61 && $cp <= 0x7A )
			);

			$must_escape = ! $is_ident_char
				|| ( 0 === $i && $is_digit )
				|| ( 1 === $i && '-' === $points[0][0] && $is_digit )
				|| ( 1 === $count && '-' === $char );

			$out .= $must_escape ? '\\' . dechex( $cp ) . ' ' : $char;
		}

		return $out;
	}

	private static function canonical_string( string $value ): string {
		$out = '"';
		foreach ( utf8_codepoints( $value ) as $point ) {
			list( $char, $cp ) = $point;
			if ( '"' === $char || '\\' === $char || $cp < 0x20 || $cp > 0x7E ) {
				$out .= '\\' . dechex( $cp ) . ' ';
			} else {
				$out .= $char;
			}
		}
		return $out . '"';
	}

	/**
	 * @param array      $pools Pools from DocumentGenerator ( tags, classes, ids, attrNames, attrValues ).
	 * @param array|null $rows  Element rows ( TreeCapture shape ) with real
	 *                          fids; enables the path-directed bucket.
	 * @return array{
	 *     bucket: string,
	 *     selector: string,
	 *     expectCompound: bool|null,
	 *     expectComplex: bool|null,
	 *     ast: array|null,
	 *     mustMatchFid: string|null,
	 *     mustNotMatchFid: string|null,
	 * }
	 */
	public static function generate( Prng $prng, array $pools, ?array $rows = null, ?string $bucket = null ): array {
		$generator = new self( $prng, $pools );

		if ( null === $bucket ) {
			$bucket = $prng->weighted(
				null === $rows || array() === $rows
					? array(
						'supported-compound' => 28,
						'supported-complex'  => 24,
						'unsupported'        => 14,
						'invalid'            => 11,
						'invalid-utf8'       => 5,
						'chaos'              => 8,
						'mutated'            => 10,
						'edge-escape'        => 5,
					)
					: array(
						'supported-compound' => 23,
						'supported-complex'  => 19,
						'path-directed'      => 21,
						'unsupported'        => 11,
						'invalid'            => 9,
						'invalid-utf8'       => 5,
						'chaos'              => 6,
						'mutated'            => 6,
						'edge-escape'        => 5,
					)
			);
		}

		if ( 'path-directed' === $bucket && ( null === $rows || array() === $rows ) ) {
			$bucket = 'supported-complex';
		}

		switch ( $bucket ) {
			case 'supported-compound':
				$ast = $generator->gen_complex_list( false );
				return array(
					'bucket'         => $bucket,
					'selector'       => $generator->render_complex_list( $ast ),
					'expectCompound' => true,
					'expectComplex'  => true,
					'ast'            => $ast,
				);

			case 'supported-complex':
				$ast = $generator->gen_complex_list( true );
				return array(
					'bucket'         => $bucket,
					'selector'       => $generator->render_complex_list( $ast ),
					'expectCompound' => false,
					'expectComplex'  => true,
					'ast'            => $ast,
				);

			case 'path-directed':
				return $generator->gen_path_directed( $rows );

			case 'edge-escape':
				return $generator->gen_edge_escape();

			case 'invalid-utf8':
				return $generator->gen_invalid_utf8();

			case 'unsupported':
				return array(
					'bucket'         => $bucket,
					'selector'       => $generator->gen_unsupported(),
					'expectCompound' => false,
					'expectComplex'  => false,
					'ast'            => null,
				);

			case 'invalid':
				return array(
					'bucket'         => $bucket,
					'selector'       => $generator->gen_invalid(),
					'expectCompound' => false,
					'expectComplex'  => false,
					'ast'            => null,
				);

			case 'chaos':
				return array(
					'bucket'         => $bucket,
					'selector'       => $generator->gen_chaos(),
					'expectCompound' => null,
					'expectComplex'  => null,
					'ast'            => null,
				);

			case 'mutated':
			default:
				$ast      = $generator->gen_complex_list( $generator->prng->chance( 50 ) );
				$rendered = $generator->render_complex_list( $ast );
				return array(
					'bucket'         => 'mutated',
					'selector'       => $generator->mutate( $rendered ),
					'expectCompound' => null,
					'expectComplex'  => null,
					'ast'            => null,
				);
		}
	}

	/*
	 * --------------
	 * AST generation
	 * --------------
	 *
	 * Canonical AST shapes (matching what AstExtractor produces from
	 * parsed WP_CSS_* objects):
	 *
	 *   list:     array of complex
	 *   complex:  array( 'context' => array( array( type, combinator ) ... right-to-left ), 'self' => compound )
	 *   compound: array( 'type' => string|null, 'subs' => array|null )
	 *   sub:      array( 'kind' => 'class'|'id', 'name' => string )
	 *           | array( 'kind' => 'attr', 'name' => string, 'matcher' => string|null,
	 *                    'value' => string|null, 'modifier' => string|null )
	 */

	private function gen_complex_list( bool $require_combinator ): array {
		$count = $this->prng->weighted(
			array(
				1 => 55,
				2 => 30,
				3 => 15,
			)
		);

		$list                 = array();
		$combinator_at        = $require_combinator ? $this->prng->int( 0, $count - 1 ) : -1;
		for ( $i = 0; $i < $count; $i++ ) {
			$wants_combinators = $i === $combinator_at || ( $require_combinator && $this->prng->chance( 30 ) );
			$list[]            = $this->gen_complex( $require_combinator ? $wants_combinators : false );
		}
		return $list;
	}

	private function gen_complex( bool $with_combinators ): array {
		$context = array();
		if ( $with_combinators ) {
			$context_count = $this->prng->int( 1, 3 );
			for ( $i = 0; $i < $context_count; $i++ ) {
				$context[] = array(
					$this->gen_type_name( true ),
					$this->prng->chance( 50 ) ? ' ' : '>',
				);
			}
		}

		return array(
			'context' => $context,
			'self'    => $this->gen_compound(),
		);
	}

	private function gen_compound(): array {
		$has_type  = $this->prng->chance( 65 );
		$sub_count = $this->prng->weighted(
			array(
				0 => 30,
				1 => 40,
				2 => 20,
				3 => 10,
			)
		);
		if ( ! $has_type && 0 === $sub_count ) {
			if ( $this->prng->chance( 50 ) ) {
				$has_type = true;
			} else {
				$sub_count = 1;
			}
		}

		$subs = array();
		for ( $i = 0; $i < $sub_count; $i++ ) {
			$subs[] = $this->gen_subclass();
		}

		return array(
			'type' => $has_type ? $this->gen_type_name( false ) : null,
			'subs' => array() === $subs ? null : $subs,
		);
	}

	private function gen_type_name( bool $for_context ): string {
		if ( $this->prng->chance( $for_context ? 25 : 12 ) ) {
			return '*';
		}
		$pool = $this->pools['tags'] ?? array();
		if ( array() !== $pool && $this->prng->chance( 70 ) ) {
			$name = $this->prng->choice( $pool );
			return $this->prng->chance( 25 ) ? $this->random_case( $name ) : $name;
		}
		return $this->prng->choice( array( 'video', 'table', 'x-absent', 'object', 'span' ) );
	}

	private function gen_subclass(): array {
		$kind = $this->prng->weighted(
			array(
				'class' => 40,
				'id'    => 25,
				'attr'  => 35,
			)
		);

		switch ( $kind ) {
			case 'class':
				return array(
					'kind' => 'class',
					'name' => $this->pick_name( 'classes' ),
				);
			case 'id':
				return array(
					'kind' => 'id',
					'name' => $this->pick_name( 'ids' ),
				);
			default:
				return $this->gen_attr_selector();
		}
	}

	private function gen_attr_selector(): array {
		$name = $this->pick_name( 'attrNames' );

		$matcher = $this->prng->weighted(
			array(
				''                         => 25,
				'exact'                    => 20,
				'one-of'                   => 12,
				'exact-or-hyphen-suffixed' => 11,
				'prefixed'                 => 11,
				'suffixed'                 => 11,
				'contains'                 => 10,
			)
		);
		$matcher = '' === $matcher ? null : $matcher;

		if ( null === $matcher ) {
			return array(
				'kind'     => 'attr',
				'name'     => $name,
				'matcher'  => null,
				'value'    => null,
				'modifier' => null,
			);
		}

		$modifier = $this->prng->weighted(
			array(
				''                 => 70,
				'case-insensitive' => 18,
				'case-sensitive'   => 12,
			)
		);

		$value = $this->gen_attr_value();

		/*
		 * HTML's case-insensitive attribute value list: with no modifier,
		 * the values of listed attributes ( type, rel, lang, dir, ... )
		 * match ASCII case-insensitively on HTML elements. Sometimes flip
		 * the case of the selector value for a listed attribute so the
		 * differential exercises that rule rather than relying on sampled
		 * values happening to differ in case.
		 */
		if (
			'' === $modifier &&
			isset( ReferenceMatcher::HTML_CASE_INSENSITIVE_ATTRIBUTES[ ascii_strtolower( $name ) ] ) &&
			$this->prng->chance( 40 )
		) {
			$value = $this->prng->chance( 50 ) ? ascii_strtoupper( $value ) : str_shuffle_case( $value, $this->prng );
		}

		return array(
			'kind'     => 'attr',
			'name'     => $name,
			'matcher'  => $matcher,
			'value'    => $value,
			'modifier' => '' === $modifier ? null : $modifier,
		);
	}

	private function gen_attr_value(): string {
		$pool = $this->pools['attrValues'] ?? array();

		$kind = $this->prng->weighted(
			array(
				'pool'      => 35,
				'pool-part' => 20,
				'pool-case' => 10,
				'empty'     => 10,
				'word'      => 15,
				'tricky'    => 10,
			)
		);

		if ( in_array( $kind, array( 'pool', 'pool-part', 'pool-case' ), true ) && array() === $pool ) {
			$kind = 'word';
		}

		switch ( $kind ) {
			case 'pool':
				return $this->prng->choice( $pool );

			case 'pool-part':
				$value = $this->prng->choice( $pool );
				if ( '' === $value ) {
					return '';
				}
				$points = utf8_codepoints( $value );
				$total  = count( $points );
				$start  = $this->prng->int( 0, max( 0, $total - 1 ) );
				$length = $this->prng->int( 1, $total - $start );
				$part   = '';
				for ( $i = $start; $i < $start + $length; $i++ ) {
					$part .= $points[ $i ][0];
				}
				return $part;

			case 'pool-case':
				return $this->random_case( $this->prng->choice( $pool ) );

			case 'empty':
				return '';

			case 'word':
				return $this->prng->choice( array( 'alpha', 'beta9', 'value', 'main-item', 'Z', 'i', 's', 'one two', 'x-y-z' ) );

			case 'tricky':
			default:
				return $this->prng->choice(
					array(
						'a b',
						" lead",
						"trail ",
						"tab\there",
						"line\nbreak",
						'quote"inside',
						"apos'inside",
						'back\\slash',
						'-',
						'--',
						'0digit',
						'ünïcode',
					)
				);
		}
	}

	private function pick_name( string $pool_key ): string {
		$pool = $this->pools[ $pool_key ] ?? array();
		if ( array() !== $pool && $this->prng->chance( 65 ) ) {
			$name = $this->prng->choice( $pool );
			if ( '' !== $name && $this->prng->chance( 20 ) ) {
				$name = $this->random_case( $name );
			}
			if ( '' !== $name ) {
				return $name;
			}
		}
		return $this->prng->choice(
			array(
				'absent',
				'no-such-thing',
				'x',
				'-lead',
				'--double',
				'_under',
				'Ünïcode',
				'with space',
				'9starts-with-digit',
				'-9hyphen-digit',
				'mixedCase',
			)
		);
	}

	/*
	 * ---------------------------
	 * Edge-case escapes and input
	 * ---------------------------
	 *
	 * Targets parser branches the structural generators can't reach:
	 *  - hex escapes whose codepoint is NUL / a surrogate / over-max, which
	 *    `consume_escaped_codepoint` must decode to U+FFFD;
	 *  - raw NUL / CR / CRLF / FF bytes in the selector input, which
	 *    `normalize_selector_input` rewrites ( NUL→U+FFFD, the rest→LF ).
	 *
	 * These carry a known intended AST: the decoded ident is the U+FFFD
	 * replacement character ( or, for input normalization, the same selector
	 * with whitespace normalized ), so the AST round-trip still applies.
	 */
	private function gen_edge_escape(): array {
		$kind = $this->prng->weighted(
			array(
				'fffd-ident'    => 35,
				'eof-escape'    => 20,
				'eof-truncated' => 15,
				'nul-input'     => 15,
				'ws-input'      => 15,
			)
		);

		if ( 'eof-truncated' === $kind ) {
			/*
			 * The end of input auto-closes an unterminated attribute selector
			 * block ( and an unterminated string inside it ): `[a=b` is the
			 * same selector as `[a=b]`.
			 *
			 * https://www.w3.org/TR/css-syntax-3/#consume-simple-block
			 */
			$matcher  = $this->prng->choice( array( null, 'exact', 'one-of', 'exact-or-hyphen-suffixed', 'prefixed', 'suffixed', 'contains' ) );
			$value    = null === $matcher ? null : $this->prng->choice( array( 'v' . $this->prng->int( 0, 99 ), 'a b', '', 'x,y', "caf\u{E9}" ) );
			$modifier = null !== $matcher && $this->prng->chance( 30 )
				? $this->prng->choice( array( 'case-insensitive', 'case-sensitive' ) )
				: null;
			$compound = array(
				'type' => $this->prng->chance( 50 ) ? 'div' : null,
				'subs' => array(
					array(
						'kind'     => 'attr',
						'name'     => 'a' . $this->prng->int( 0, 99 ),
						'matcher'  => $matcher,
						'value'    => $value,
						'modifier' => $modifier,
					),
				),
			);

			// The attribute selector is the final rendered unit, so the render always ends with ']'.
			$rendered  = $this->render_compound( $compound );
			$truncated = substr( $rendered, 0, -1 );

			// Sometimes also drop a closing string quote: EOF terminates the string, then closes the block.
			$last_byte = substr( $truncated, -1 );
			if ( ( '"' === $last_byte || "'" === $last_byte ) && $this->prng->chance( 50 ) ) {
				$truncated = substr( $truncated, 0, -1 );

				// A backslash at the end of an unterminated string "does nothing": the value is unchanged.
				if ( $this->prng->chance( 40 ) ) {
					$truncated .= '\\';
				}
			}

			return array(
				'bucket'         => 'edge-escape',
				'selector'       => $truncated,
				'expectCompound' => true,
				'expectComplex'  => true,
				'ast'            => array(
					array(
						'context' => array(),
						'self'    => $compound,
					),
				),
			);
		}

		if ( 'eof-escape' === $kind ) {
			/*
			 * A backslash at the end of input is a valid escape ( EOF is not
			 * a newline ) and decodes to U+FFFD, in ident context only:
			 * `.foo\` is the class `foo\u{FFFD}`.
			 *
			 * https://www.w3.org/TR/css-syntax-3/#consume-escaped-code-point
			 */
			$name = $this->prng->chance( 30 ) ? '' : 'a' . $this->prng->int( 0, 99 );
			list( $selector, $self ) = $this->prng->choice(
				array(
					array(
						'.' . $name . '\\',
						array(
							'type' => null,
							'subs' => array( array( 'kind' => 'class', 'name' => $name . "\u{FFFD}" ) ),
						),
					),
					array(
						'#' . $name . '\\',
						array(
							'type' => null,
							'subs' => array( array( 'kind' => 'id', 'name' => $name . "\u{FFFD}" ) ),
						),
					),
					array(
						$name . '\\',
						array(
							'type' => $name . "\u{FFFD}",
							'subs' => null,
						),
					),
				)
			);
			return array(
				'bucket'         => 'edge-escape',
				'selector'       => $selector,
				'expectCompound' => true,
				'expectComplex'  => true,
				'ast'            => array(
					array(
						'context' => array(),
						'self'    => $self,
					),
				),
			);
		}

		if ( 'fffd-ident' === $kind ) {
			// A class selector whose name is a single U+FFFD, produced by a
			// hex escape for an out-of-range codepoint.
			$hex = $this->prng->choice(
				array(
					'0',
					'00',
					'000000',
					dechex( $this->prng->int( 0xD800, 0xDFFF ) ),       // surrogate
					dechex( $this->prng->int( 0x110000, 0xFFFFFF ) ),   // over-max
				)
			);
			if ( $this->prng->chance( 40 ) ) {
				$hex = strtoupper( $hex );
			}
			$selector = '.\\' . $hex . ' ';
			$ast      = array(
				array(
					'context' => array(),
					'self'    => array(
						'type' => null,
						'subs' => array( array( 'kind' => 'class', 'name' => "\u{FFFD}" ) ),
					),
				),
			);
			return array(
				'bucket'         => 'edge-escape',
				'selector'       => $selector,
				'expectCompound' => true,
				'expectComplex'  => true,
				'ast'            => $ast,
			);
		}

		/*
		 * Raw control bytes in the selector input. A small fixed compound
		 * keeps the case focused on normalize_selector_input and avoids
		 * entangling with unrelated attribute-selector edge cases.
		 */
		$compound = array(
			'type' => $this->prng->chance( 50 ) ? 'span' : null,
			'subs' => array(
				array( 'kind' => 'class', 'name' => 'foo' ),
				array( 'kind' => 'id', 'name' => 'bar' ),
			),
		);
		if ( null === $compound['type'] && $this->prng->chance( 50 ) ) {
			array_pop( $compound['subs'] );
		}
		$rendered = $this->render_compound( $compound );

		if ( 'nul-input' === $kind ) {
			// A NUL between a class dot's selectors becomes part of an ident
			// only in limited spots; simplest reliable case: a class whose
			// name contains a NUL ( → U+FFFD ).
			$ast = array(
				array(
					'context' => array(),
					'self'    => array(
						'type' => null,
						'subs' => array( array( 'kind' => 'class', 'name' => "a\u{FFFD}b" ) ),
					),
				),
			);
			return array(
				'bucket'         => 'edge-escape',
				'selector'       => ".a\0b",
				'expectCompound' => true,
				'expectComplex'  => true,
				'ast'            => $ast,
			);
		}

		// ws-input: wrap/insert CR, CRLF, FF as insignificant whitespace.
		$lead  = $this->prng->choice( array( "\r", "\f", "\r\n", "\r\r", "\f\f" ) );
		$trail = $this->prng->choice( array( "\r", "\f", "\r\n", '' ) );
		return array(
			'bucket'         => 'edge-escape',
			'selector'       => $lead . $rendered . $trail,
			'expectCompound' => true,
			'expectComplex'  => true,
			'ast'            => array(
				array(
					'context' => array(),
					'self'    => $compound,
				),
			),
		);
	}

	/*
	 * -----------------------
	 * Invalid-UTF-8 injection
	 * -----------------------
	 *
	 * Raw ill-formed UTF-8 byte sequences in the selector input, mirroring
	 * the nul-input pattern: a small fixed simple selector keeps the case
	 * focused on the normalize_selector_input() scrub. Each maximal subpart
	 * of the injected sequence decodes to one U+FFFD ( per-class counts
	 * pinned in INVALID_UTF8_CLASSES ), and U+FFFD is a valid ident
	 * codepoint — including in start position — so the scrubbed selector
	 * must parse and the post-scrub AST is known by construction.
	 */
	private function gen_invalid_utf8(): array {
		list( $bytes, $subparts ) = $this->prng->choice( array_values( self::INVALID_UTF8_CLASSES ) );

		$position = $this->prng->choice( array( 'lead', 'mid', 'trail', 'whole' ) );
		$prefix   = in_array( $position, array( 'lead', 'whole' ), true ) ? '' : 'a' . $this->prng->int( 0, 9 );
		$suffix   = in_array( $position, array( 'trail', 'whole' ), true ) ? '' : 'z' . $this->prng->int( 0, 9 );
		$raw      = $prefix . $bytes . $suffix;
		$decoded  = $prefix . str_repeat( "\u{FFFD}", $subparts ) . $suffix;

		switch ( $this->prng->choice( array( 'class', 'id', 'attr-name', 'attr-value' ) ) ) {
			case 'class':
				$rendered = '.' . $raw;
				$sub      = array(
					'kind' => 'class',
					'name' => $decoded,
				);
				break;

			case 'id':
				$rendered = '#' . $raw;
				$sub      = array(
					'kind' => 'id',
					'name' => $decoded,
				);
				break;

			case 'attr-name':
				$rendered = '[' . $raw . ']';
				$sub      = array(
					'kind'     => 'attr',
					'name'     => $decoded,
					'matcher'  => null,
					'value'    => null,
					'modifier' => null,
				);
				break;

			case 'attr-value':
			default:
				$name     = 'a' . $this->prng->int( 0, 99 );
				$quote    = $this->prng->chance( 50 ) ? '"' : "'";
				$rendered = '[' . $name . '=' . $quote . $raw . $quote . ']';
				$sub      = array(
					'kind'     => 'attr',
					'name'     => $name,
					'matcher'  => 'exact',
					'value'    => $decoded,
					'modifier' => null,
				);
				break;
		}

		$type = $this->prng->chance( 40 ) ? 'span' : null;

		return array(
			'bucket'         => 'invalid-utf8',
			'selector'       => ( null === $type ? '' : $type ) . $rendered,
			'expectCompound' => true,
			'expectComplex'  => true,
			'ast'            => array(
				array(
					'context' => array(),
					'self'    => array(
						'type' => $type,
						'subs' => array( $sub ),
					),
				),
			),
		);
	}

	/*
	 * ------------------------
	 * Path-directed generation
	 * ------------------------
	 *
	 * Synthesizes a selector from a real element of the model tree so that
	 * the selector is guaranteed (by construction) to match that element:
	 * the type comes from its tag, subclasses from its actual classes / id /
	 * attributes, and the context chain from its actual ancestor tags with
	 * combinators consistent with the real nesting. Optionally one feature
	 * is then flipped into a "near-miss" that is guaranteed NOT to match
	 * the element ( or, for combinator loosening, still guaranteed to ).
	 */

	private function gen_path_directed( array $rows ): array {
		// Bias toward elements deep enough for a meaningful context chain.
		$deep = array();
		foreach ( $rows as $row ) {
			if ( count( $row['ancestorTags'] ) >= 2 ) {
				$deep[] = $row;
			}
		}
		$element = array() !== $deep && $this->prng->chance( 75 )
			? $this->prng->choice( $deep )
			: $this->prng->choice( $rows );

		$compound = $this->path_compound_for( $element );
		$context  = array() !== $element['ancestorTags'] && $this->prng->chance( 75 )
			? $this->path_context_for( $element['ancestorTags'] )
			: array();

		$list = array(
			array(
				'context' => $context,
				'self'    => $compound,
			),
		);

		$must_match     = $element['fid'];
		$must_not_match = null;

		if ( $this->prng->chance( 40 ) ) {
			list( $list, $must_match, $must_not_match ) = $this->path_near_miss( $list, $element );
		} elseif ( $this->prng->chance( 20 ) ) {
			// Extra unrelated branch: a list union can only add matches.
			$list[] = $this->gen_complex( $this->prng->chance( 30 ) );
		}

		$has_context = false;
		foreach ( $list as $complex ) {
			if ( array() !== $complex['context'] ) {
				$has_context = true;
				break;
			}
		}

		return array(
			'bucket'          => 'path-directed',
			'selector'        => $this->render_complex_list( $list ),
			'expectCompound'  => ! $has_context,
			'expectComplex'   => true,
			'ast'             => $list,
			'mustMatchFid'    => $must_match,
			'mustNotMatchFid' => $must_not_match,
		);
	}

	/** A compound selector built only from features the element row really has. */
	private function path_compound_for( array $element ): array {
		$tag = ascii_strtolower( $element['tag'] );

		$features = array();

		$class_value = DocumentGenerator::get_attribute_value( $element, 'class' );
		if ( is_string( $class_value ) ) {
			foreach ( DocumentGenerator::class_tokens( $class_value ) as $word ) {
				$features[] = array( 'kind' => 'class', 'name' => $word );
			}
		}

		$id_value = DocumentGenerator::get_attribute_value( $element, 'id' );
		if ( is_string( $id_value ) && '' !== $id_value ) {
			$features[] = array( 'kind' => 'id', 'name' => $id_value );
		}

		$seen_attrs = array();
		foreach ( $element['attrs'] as $attr ) {
			$lower = ascii_strtolower( $attr[0] );
			if ( isset( $seen_attrs[ $lower ] ) ) {
				continue;
			}
			$seen_attrs[ $lower ] = true;
			if ( 'class' === $lower && is_string( $attr[1] ) && false !== strpos( $attr[1], "\0" ) ) {
				continue;
			}
			$features[]           = $this->path_attr_feature( $lower, $attr[1], 'html' === ( $element['namespace'] ?? 'html' ) );
		}

		$subs      = array();
		$available = count( $features );
		if ( $available > 0 ) {
			$want = min( $available, $this->prng->weighted( array( 0 => 25, 1 => 40, 2 => 25, 3 => 10 ) ) );
			for ( $i = 0; $i < $want; $i++ ) {
				$at     = $this->prng->int( 0, count( $features ) - 1 );
				$subs[] = $features[ $at ];
				array_splice( $features, $at, 1 );
			}
		}

		$type = null;
		if ( array() === $subs || $this->prng->chance( 70 ) ) {
			$type = $this->prng->chance( 12 ) ? '*' : ( $this->prng->chance( 30 ) ? $this->random_case( $tag ) : $tag );
		}

		return array(
			'type' => $type,
			'subs' => array() === $subs ? null : $subs,
		);
	}

	/** An attribute selector that the (name, value) pair satisfies. */
	private function path_attr_feature( string $name, $value, bool $is_html_namespace = true ): array {
		$presence = array(
			'kind'     => 'attr',
			'name'     => $this->prng->chance( 15 ) ? $this->random_case( $name ) : $name,
			'matcher'  => null,
			'value'    => null,
			'modifier' => null,
		);

		if ( true === $value ) {
			// A boolean attribute has the empty string as its value.
			$value = '';
		}
		if ( ! is_string( $value ) || $this->prng->chance( 30 ) ) {
			return $presence;
		}

		$points = utf8_codepoints( $value );
		$total  = count( $points );

		$candidates = array( array( 'exact', $value ) );

		foreach ( preg_split( '/[ \t\n\f\r]+/', $value, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			$candidates[] = array( 'one-of', $word );
			break;
		}

		$hyphen_at = strpos( $value, '-' );
		$candidates[] = array( 'exact-or-hyphen-suffixed', false === $hyphen_at ? $value : substr( $value, 0, $hyphen_at ) );

		if ( $total > 0 ) {
			$slice = static function ( array $points, int $start, int $length ): string {
				$out = '';
				for ( $i = $start; $i < $start + $length; $i++ ) {
					$out .= $points[ $i ][0];
				}
				return $out;
			};

			$candidates[] = array( 'prefixed', $slice( $points, 0, $this->prng->int( 1, $total ) ) );
			$length       = $this->prng->int( 1, $total );
			$candidates[] = array( 'suffixed', $slice( $points, $total - $length, $length ) );
			$start        = $this->prng->int( 0, $total - 1 );
			$candidates[] = array( 'contains', $slice( $points, $start, $this->prng->int( 1, $total - $start ) ) );
		}

		list( $matcher, $operand ) = $this->prng->choice( $candidates );

		/*
		 * `|=` with an operand cut at a hyphen only matches when the operand
		 * is non-empty and actually a value prefix; an operand equal to the
		 * value always matches. Guard the degenerate empty-operand cases.
		 */
		if ( 'exact-or-hyphen-suffixed' === $matcher && '' === $operand && '' !== $value ) {
			$matcher = 'exact';
			$operand = $value;
		}
		if ( in_array( $matcher, array( 'one-of', 'prefixed', 'suffixed', 'contains' ), true ) && '' === $operand ) {
			return $presence;
		}

		$modifier = null;
		if ( $this->prng->chance( 25 ) ) {
			if ( $this->prng->chance( 60 ) ) {
				$modifier = 'case-insensitive';
				$operand  = $this->random_case( $operand );
			} else {
				$modifier = 'case-sensitive';
			}
		} elseif (
			$is_html_namespace &&
			isset( ReferenceMatcher::HTML_CASE_INSENSITIVE_ATTRIBUTES[ $name ] ) &&
			$this->prng->chance( 50 )
		) {
			/*
			 * HTML's case-insensitive attribute value list: with no modifier
			 * the flipped operand still satisfies the (name, value) pair on
			 * an html-namespace element, which makes the folding rule
			 * load-bearing for the mustMatchFid invariant — name and value
			 * here come from the same real element, unlike the independent
			 * pools in gen_attr_selector.
			 */
			$operand = $this->random_case( $operand );
		}

		return array(
			'kind'     => 'attr',
			'name'     => $presence['name'],
			'matcher'  => $matcher,
			'value'    => $operand,
			'modifier' => $modifier,
		);
	}

	/**
	 * A context chain ( right-to-left ( type, combinator ) pairs ) drawn from
	 * the element's real ancestors so the chain is satisfied by construction:
	 * `>` is only used for the immediately-next ancestor, descendant
	 * combinators may skip generations.
	 *
	 * @param string[] $ancestor_tags Nearest-first ancestor tag names.
	 */
	private function path_context_for( array $ancestor_tags ): array {
		$chain = array();
		$pos   = 0;
		$count = count( $ancestor_tags );

		while ( $pos < $count && ( array() === $chain || $this->prng->chance( 45 ) ) ) {
			$jump = $this->prng->chance( 65 ) ? 0 : $this->prng->int( 0, $count - 1 - $pos );
			$at   = $pos + $jump;

			$combinator = ( 0 === $jump && $this->prng->chance( 55 ) ) ? '>' : ' ';
			$tag        = ascii_strtolower( $ancestor_tags[ $at ] );
			$type       = $this->prng->chance( 12 )
				? '*'
				: ( $this->prng->chance( 25 ) ? $this->random_case( $tag ) : $tag );

			$chain[] = array( $type, $combinator );
			$pos     = $at + 1;
		}

		return $chain;
	}

	/**
	 * Flips one feature of the guaranteed-match selector. Most flips
	 * guarantee the element no longer matches; loosening a `>` to a
	 * descendant combinator must keep it matching.
	 *
	 * @return array{0: array, 1: string|null, 2: string|null} list, mustMatchFid, mustNotMatchFid.
	 */
	private function path_near_miss( array $list, array $element ): array {
		$complex  = $list[0];
		$compound = $complex['self'];
		$fid      = $element['fid'];

		$flips = array( 'wrong-class', 'wrong-attr' );
		if ( null !== $compound['type'] && '*' !== $compound['type'] ) {
			$flips[] = 'wrong-type';
		}
		foreach ( $complex['context'] as $pair ) {
			if ( '>' === $pair[1] ) {
				$flips[] = 'loosen-combinator';
			}
			$flips[] = 'tighten-combinator';
			break;
		}

		switch ( $this->prng->choice( $flips ) ) {
			case 'wrong-type':
				$tag = ascii_strtolower( $element['tag'] );
				do {
					$other = $this->prng->choice( DocumentGenerator::SAFE_TAGS );
				} while ( $other === $tag );
				$complex['self']['type'] = $this->prng->chance( 25 ) ? $this->random_case( $other ) : $other;
				return array( array( $complex ), null, $fid );

			case 'wrong-attr':
				$subs   = (array) $complex['self']['subs'];
				$subs[] = array(
					'kind'     => 'attr',
					'name'     => 'zz-no-such-attr',
					'matcher'  => null,
					'value'    => null,
					'modifier' => null,
				);
				$complex['self']['subs'] = $subs;
				return array( array( $complex ), null, $fid );

			case 'loosen-combinator':
				// Replacing every `>` with a descendant combinator can only
				// widen the context; the element must still match.
				foreach ( $complex['context'] as &$pair ) {
					$pair[1] = ' ';
				}
				unset( $pair );
				$list[0] = $complex;
				return array( $list, $fid, null );

			case 'tighten-combinator':
				// May or may not still match; no membership expectation.
				$at = $this->prng->int( 0, count( $complex['context'] ) - 1 );
				$complex['context'][ $at ][1] = '>';
				$list[0]                      = $complex;
				return array( $list, null, null );

			case 'wrong-class':
			default:
				$subs   = (array) $complex['self']['subs'];
				$subs[] = array(
					'kind' => 'class',
					'name' => 'zz-no-such-class',
				);
				$complex['self']['subs'] = $subs;
				return array( array( $complex ), null, $fid );
		}
	}

	/*
	 * ---------
	 * Rendering
	 * ---------
	 */

	private function render_complex_list( array $list ): string {
		$bits = array();
		foreach ( $list as $complex ) {
			$bits[] = $this->render_complex( $complex );
		}

		$out = $this->maybe_ws( 25 );
		foreach ( $bits as $i => $bit ) {
			if ( $i > 0 ) {
				$out .= $this->maybe_ws( 40 ) . ',' . $this->maybe_ws( 60 );
			}
			$out .= $bit;
		}
		return $out . $this->maybe_ws( 25 );
	}

	private function render_complex( array $complex ): string {
		$out = '';
		// Context selectors are stored right-to-left; render left-to-right.
		$reversed = array_reverse( $complex['context'] );
		foreach ( $reversed as $pair ) {
			list( $type, $combinator ) = $pair;
			$rendered_type = '*' === $type ? '*' : $this->render_ident( $type );
			$out          .= $rendered_type;
			if ( '>' === $combinator ) {
				$before = $this->maybe_ws( 50 );
				// Avoid the CDC token `-->` when a raw `--` type selector is
				// followed immediately by a child combinator.
				if ( '' === $before && '--' === $rendered_type ) {
					$before = ' ';
				}
				$out .= $before . '>' . $this->maybe_ws( 50 );
			} else {
				$out .= $this->ws();
			}
		}
		return $out . $this->render_compound( $complex['self'] );
	}

	private function render_compound( array $compound ): string {
		$out = '';
		if ( null !== $compound['type'] ) {
			$out .= '*' === $compound['type'] ? '*' : $this->render_ident( $compound['type'] );
		}
		foreach ( (array) $compound['subs'] as $sub ) {
			switch ( $sub['kind'] ) {
				case 'class':
					$out .= '.' . $this->render_ident( $sub['name'] );
					break;
				case 'id':
					$out .= '#' . $this->render_ident( $sub['name'] );
					break;
				case 'attr':
					$out .= $this->render_attr_selector( $sub );
					break;
			}
		}
		return $out;
	}

	private function render_attr_selector( array $sub ): string {
		$out = '[' . $this->maybe_ws( 20 ) . $this->render_ident( $sub['name'] ) . $this->maybe_ws( 20 );

		if ( null === $sub['matcher'] ) {
			return $out . ']';
		}

		$matcher_strings = array(
			'exact'                    => '=',
			'one-of'                   => '~=',
			'exact-or-hyphen-suffixed' => '|=',
			'prefixed'                 => '^=',
			'suffixed'                 => '$=',
			'contains'                 => '*=',
		);
		$out            .= $matcher_strings[ $sub['matcher'] ] . $this->maybe_ws( 25 );

		$value          = $sub['value'];
		$value_as_ident = '' !== $value && $this->can_render_as_ident( $value ) && $this->prng->chance( 45 );
		if ( $value_as_ident ) {
			$out .= $this->render_ident( $value );
		} else {
			$out .= $this->render_string( $value );
		}

		if ( null !== $sub['modifier'] ) {
			// After an ident value, whitespace is mandatory before the modifier.
			$out .= $value_as_ident ? $this->ws() : $this->maybe_ws( 60 );

			if ( 'case-insensitive' === $sub['modifier'] ) {
				$out .= $this->prng->chance( 70 ) ? 'i' : 'I';
			} else {
				$out .= $this->prng->chance( 70 ) ? 's' : 'S';
			}
		}

		return $out . $this->maybe_ws( 25 ) . ']';
	}

	/**
	 * Whether a value contains only codepoints this renderer is willing to
	 * put in an ident token (everything can be escaped, but a value ending
	 * in whitespace as an ident is fragile to read — strings handle those).
	 */
	private function can_render_as_ident( string $value ): bool {
		return '' !== $value;
	}

	/**
	 * Renders a name as a CSS ident token, escaping wherever required and
	 * sometimes where merely allowed. Parsing the result must yield $name.
	 */
	private function render_ident( string $name ): string {
		$points = utf8_codepoints( $name );
		$count  = count( $points );
		$out    = '';

		foreach ( $points as $i => $point ) {
			list( $char, $cp ) = $point;

			$is_digit      = $cp >= 0x30 && $cp <= 0x39;
			$is_ident_char = (
				'-' === $char ||
				'_' === $char ||
				$is_digit ||
				( $cp >= 0x41 && $cp <= 0x5A ) ||
				( $cp >= 0x61 && $cp <= 0x7A ) ||
				$cp > 0x7F
			);

			$must_escape = ! $is_ident_char
				|| ( 0 === $i && $is_digit )
				|| ( 1 === $i && '-' === $points[0][0] && $is_digit )
				|| ( 1 === $count && '-' === $char );

			if ( $must_escape || $this->prng->chance( $this->escape_boost ? 50 : 8 ) ) {
				$out .= $this->render_escape( $char, $cp );
			} else {
				$out .= $char;
			}
		}

		return $out;
	}

	/**
	 * Renders one codepoint as a CSS escape sequence that decodes back to it.
	 */
	private function render_escape( string $char, int $cp ): string {
		$is_hex_digit = ( $cp >= 0x30 && $cp <= 0x39 )
			|| ( $cp >= 0x41 && $cp <= 0x46 )
			|| ( $cp >= 0x61 && $cp <= 0x66 );
		$is_newline_like = "\n" === $char || "\r" === $char || "\f" === $char;

		/*
		 * Identity escapes are only safe for single-byte chars that are not
		 * hex digits (they would start a hex escape) and not newlines
		 * (backslash-newline is not a valid escape).
		 */
		$identity_ok = ! $is_hex_digit && ! $is_newline_like && $cp >= 0x20;

		if ( $identity_ok && $this->prng->chance( 35 ) ) {
			return '\\' . $char;
		}

		$hex = dechex( $cp );
		if ( $this->prng->chance( 25 ) && strlen( $hex ) < 6 ) {
			$hex = str_pad( $hex, $this->prng->int( strlen( $hex ), 6 ), '0', STR_PAD_LEFT );
		}
		if ( $this->prng->chance( 30 ) ) {
			$hex = strtoupper( $hex );
		}

		// The trailing space is always emitted; it is consumed by the escape.
		return '\\' . $hex . ' ';
	}

	/**
	 * Renders a value as a CSS string token. Parsing must yield $value.
	 */
	private function render_string( string $value ): string {
		$quote  = $this->prng->chance( 60 ) ? '"' : "'";
		$out    = $quote;
		$points = utf8_codepoints( $value );

		foreach ( $points as $point ) {
			list( $char, $cp ) = $point;

			if ( "\n" === $char || "\r" === $char || "\f" === $char ) {
				// Literal newlines end (break) the string; always hex-escape.
				$out .= '\\' . dechex( $cp ) . ' ';
				continue;
			}
			if ( $char === $quote || '\\' === $char ) {
				$out .= $this->prng->chance( 60 ) ? '\\' . $char : '\\' . dechex( $cp ) . ' ';
				continue;
			}
			if ( $this->prng->chance( 5 ) ) {
				$out .= $this->render_escape( $char, $cp );
				continue;
			}
			$out .= $char;
		}

		// Rarely add a backslash-newline line continuation (decodes to nothing).
		if ( $this->prng->chance( 4 ) ) {
			$out .= "\\\n";
		}

		return $out . $quote;
	}

	private function ws(): string {
		$options = array( ' ', ' ', ' ', "\t", "\n", "\f", "\r", '  ', " \t " );
		return $this->prng->choice( $options );
	}

	private function maybe_ws( int $percent ): string {
		return $this->prng->chance( $percent ) ? $this->ws() : '';
	}

	private function random_case( string $input ): string {
		$out = '';
		for ( $i = 0; $i < strlen( $input ); $i++ ) {
			$c    = $input[ $i ];
			$out .= $this->prng->chance( 50 ) ? strtoupper( $c ) : strtolower( $c );
		}
		return $out;
	}

	/*
	 * -------------------
	 * Unsupported selectors
	 * -------------------
	 */

	private function gen_unsupported(): string {
		$kind = $this->prng->weighted(
			array(
				'pseudo-class'       => 25,
				'pseudo-element'     => 15,
				'sibling-combinator' => 20,
				'column-combinator'  => 8,
				'namespace-type'     => 12,
				'namespace-attr'     => 8,
				'non-type-context'   => 12,
			)
		);

		switch ( $kind ) {
			case 'pseudo-class':
				$pseudo = $this->prng->choice(
					array(
						':hover',
						':focus',
						':first-child',
						':last-child',
						':nth-child(2n+1)',
						':nth-of-type(3)',
						':not(.excluded)',
						':is(div, span)',
						':where(*)',
						':root',
						':empty',
						':checked',
						':lang(en)',
						':has(> img)',
					)
				);
				return $this->render_compound( $this->gen_compound() ) . $pseudo;

			case 'pseudo-element':
				$pseudo = $this->prng->choice( array( '::before', '::after', '::first-line', '::first-letter', '::marker', '::placeholder' ) );
				return $this->render_compound( $this->gen_compound() ) . $pseudo;

			case 'sibling-combinator':
				$combinator = $this->prng->choice( array( '+', '~' ) );
				return $this->render_compound( $this->gen_compound() )
					. $this->maybe_ws( 60 ) . $combinator . $this->maybe_ws( 60 )
					. $this->render_compound( $this->gen_compound() );

			case 'column-combinator':
				return $this->gen_type_name( true )
					. $this->maybe_ws( 50 ) . '||' . $this->maybe_ws( 50 )
					. $this->gen_type_name( true );

			case 'namespace-type':
				$ns = $this->prng->choice( array( 'svg', 'html', '*', '' ) );
				return $ns . '|' . $this->prng->choice( array( 'title', 'a', 'circle', 'div' ) );

			case 'namespace-attr':
				// `[ns|name]` — must not be confused with the `|=` matcher,
				// so the char after `|` must not be `=`.
				$ns = $this->prng->choice( array( 'xlink', 'svg', 'xml' ) );
				return '[' . $ns . '|href]';

			case 'non-type-context':
			default:
				// A context selector that is not a bare type selector.
				$context = $this->prng->choice( array( '.ctx', '#ctx', '[ctx]', 'div.ctx', 'div#ctx', 'div[ctx]', '*.ctx' ) );
				$joiner  = $this->prng->chance( 50 )
					? $this->ws()
					: $this->maybe_ws( 50 ) . '>' . $this->maybe_ws( 50 );
				return $context . $joiner . $this->render_compound( $this->gen_compound() );
		}
	}

	/*
	 * -----------------
	 * Invalid selectors
	 * -----------------
	 */

	private function gen_invalid(): string {
		$kind = $this->prng->weighted(
			array(
				'template'         => 45,
				'trailing-garbage' => 25,
				'leading-garbage'  => 15,
				'comma-trouble'    => 15,
			)
		);

		switch ( $kind ) {
			case 'template':
				return $this->prng->choice(
					array(
						'',
						'   ',
						"\t\n\f ",
						'.',
						'a.',
						'#',
						'[',
						']',
						'[]',
						'[ ]',
						'.5x',
						'#5',
						'. x',
						'..a',
						'.#a',
						/*
						 * EOF auto-closes an open attribute selector block
						 * ( '[a', '[a=b', '[a="b]', '[a=b i' are valid ), but
						 * grammar-level truncation is still invalid.
						 */
						'[a=',
						'[a= ',
						'[a~',
						'[a^',
						'[a=]',
						'[=b]',
						'[a==b]',
						'[a~b]',
						'[a!=b]',
						"[a=\"b\nc\"]",
						"[a=\"b\nc",
						'[a=b x]',
						'[a=b x',
						'[a=b ix]',
						'[a=b ix',
						'[a=b i x',
						'[5=b]',
						'[5=b',
						'a >',
						'> a',
						'a > > b',
						'a >> b',
						'>',
						'-',
						// A lone '\' is a valid escape at EOF ( type selector U+FFFD );
						// '\' before a newline is not a valid escape.
						"\\\n",
						"a\\\nb",
						'a/**/b',
						'/* comment */ a',
						'!important',
						'@media screen',
						'{}',
						';',
						'a;b',
						'a{color:red}',
						'()',
						'a()',
						'*5',
						'%',
						'a%',
					)
				);

			case 'trailing-garbage':
				$garbage = $this->prng->choice( array( ':', '(', ')', '{', '}', ';', '!', '@', '%', '/', '=', '|', '^', '$' ) );
				return $this->render_compound( $this->gen_compound() ) . $garbage;

			case 'leading-garbage':
				$garbage = $this->prng->choice( array( '%', ';', ')', '}', '=', '~', '+', '/', ',' ) );
				return $garbage . $this->render_compound( $this->gen_compound() );

			case 'comma-trouble':
			default:
				$compound = $this->render_compound( $this->gen_compound() );
				return $this->prng->choice(
					array(
						$compound . ',',
						',' . $compound,
						$compound . ',,' . $compound,
						$compound . ', ,' . $compound,
						$compound . ' , ',
					)
				);
		}
	}

	/*
	 * -----
	 * Chaos
	 * -----
	 */

	private function gen_chaos(): string {
		$alphabets = array(
			'css'     => '.#[]=~|^$*>+,:()"\'\\ \t\n-_',
			'ident'   => 'abcXYZ019-_',
			'mixed'   => '.#[]=~|^$*>+,:()"\'\\ abcXYZ019-_iIsS',
			'unicode' => '✓Ωé🙂',
		);

		$alphabet = $alphabets[ $this->prng->weighted(
			array(
				'css'     => 25,
				'ident'   => 15,
				'mixed'   => 45,
				'unicode' => 15,
			)
		) ];

		if ( 'unicode' === $alphabet ) {
			$points = utf8_codepoints( $alphabet . '.#[]= aZ9' );
			$length = $this->prng->int( 0, 24 );
			$out    = '';
			for ( $i = 0; $i < $length; $i++ ) {
				$out .= $this->prng->choice( $points )[0];
			}
			return $out;
		}

		$length = $this->prng->int( 0, 40 );
		$out    = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ $this->prng->int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
	}

	/*
	 * --------
	 * Mutation
	 * --------
	 */

	private function mutate( string $selector ): string {
		$mutation_count = $this->prng->int( 1, 4 );
		$alphabet       = '.#[]=~|^$*>+,:()"\'\\ \t\niIsSabcXYZ019-_';

		for ( $m = 0; $m < $mutation_count; $m++ ) {
			$length = strlen( $selector );
			$kind   = $this->prng->weighted(
				array(
					'insert'       => 30,
					'delete'       => 25,
					'replace'      => 25,
					'duplicate'    => 10,
					'case-flip'    => 10,
					'invalid-utf8' => 12,
				)
			);

			switch ( $kind ) {
				case 'insert':
					$at       = $this->prng->int( 0, $length );
					$char     = $alphabet[ $this->prng->int( 0, strlen( $alphabet ) - 1 ) ];
					$selector = substr( $selector, 0, $at ) . $char . substr( $selector, $at );
					break;

				case 'delete':
					if ( $length > 0 ) {
						$at       = $this->prng->int( 0, $length - 1 );
						$selector = substr( $selector, 0, $at ) . substr( $selector, $at + 1 );
					}
					break;

				case 'replace':
					if ( $length > 0 ) {
						$at       = $this->prng->int( 0, $length - 1 );
						$char     = $alphabet[ $this->prng->int( 0, strlen( $alphabet ) - 1 ) ];
						$selector = substr( $selector, 0, $at ) . $char . substr( $selector, $at + 1 );
					}
					break;

				case 'duplicate':
					if ( $length > 0 ) {
						$start    = $this->prng->int( 0, $length - 1 );
						$span     = $this->prng->int( 1, min( 6, $length - $start ) );
						$selector = substr( $selector, 0, $start + $span )
							. substr( $selector, $start, $span )
							. substr( $selector, $start + $span );
					}
					break;

				case 'case-flip':
					if ( $length > 0 ) {
						$at   = $this->prng->int( 0, $length - 1 );
						$char = $selector[ $at ];
						$flip = ctype_lower( $char ) ? strtoupper( $char ) : strtolower( $char );
						$selector = substr( $selector, 0, $at ) . $flip . substr( $selector, $at + 1 );
					}
					break;

				case 'invalid-utf8':
					// Splice a raw ill-formed sequence at an arbitrary byte
					// offset — possibly splitting an existing multibyte
					// character or landing before a continuation byte that
					// completes a truncated lead. No expectations here; these
					// exercise crash / scrub-notice / differential paths.
					$bytes    = $this->prng->choice( array_column( self::INVALID_UTF8_CLASSES, 0 ) );
					$at       = $this->prng->int( 0, $length );
					$selector = substr( $selector, 0, $at ) . $bytes . substr( $selector, $at );
					break;
			}
		}

		return $selector;
	}
}
