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
		'unsupported',
		'invalid',
		'chaos',
		'mutated',
	);

	/** @var Prng */
	private $prng;
	/** @var array */
	private $pools;

	private function __construct( Prng $prng, array $pools ) {
		$this->prng  = $prng;
		$this->pools = $pools;
	}

	/**
	 * @param array $pools Pools from DocumentGenerator ( tags, classes, ids, attrNames, attrValues ).
	 * @return array{
	 *     bucket: string,
	 *     selector: string,
	 *     expectCompound: bool|null,
	 *     expectComplex: bool|null,
	 *     ast: array|null,
	 * }
	 */
	public static function generate( Prng $prng, array $pools, ?string $bucket = null ): array {
		$generator = new self( $prng, $pools );

		if ( null === $bucket ) {
			$bucket = $prng->weighted(
				array(
					'supported-compound' => 30,
					'supported-complex'  => 25,
					'unsupported'        => 15,
					'invalid'            => 12,
					'chaos'              => 8,
					'mutated'            => 10,
				)
			);
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

		return array(
			'kind'     => 'attr',
			'name'     => $name,
			'matcher'  => $matcher,
			'value'    => $this->gen_attr_value(),
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
			$out .= '*' === $type ? '*' : $this->render_ident( $type );
			if ( '>' === $combinator ) {
				$out .= $this->maybe_ws( 50 ) . '>' . $this->maybe_ws( 50 );
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

			if ( $must_escape || $this->prng->chance( 8 ) ) {
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
						'[a',
						'[a=',
						'[a=]',
						'[=b]',
						'[a==b]',
						'[a~b]',
						'[a!=b]',
						'[a=b',
						'[a="b]',
						"[a='b]",
						"[a=\"b\nc\"]",
						'[a=b x]',
						'[a=b ix]',
						'[a=b i',
						'[5=b]',
						'a >',
						'> a',
						'a > > b',
						'a >> b',
						'>',
						'-',
						'\\',
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
					'insert'    => 30,
					'delete'    => 25,
					'replace'   => 25,
					'duplicate' => 10,
					'case-flip' => 10,
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
			}
		}

		return $selector;
	}
}
