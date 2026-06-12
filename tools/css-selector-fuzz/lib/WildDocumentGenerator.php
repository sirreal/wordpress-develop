<?php
namespace CssSelectorFuzz;

/**
 * Generates structurally adventurous ("wild") HTML: misnested formatting
 * elements (simple adoption-agency runs), implied end tags and implied
 * table sections, stray and unclosed tags, foreign content, and varied
 * doctypes. No model tree is built — TreeCapture takes the processor's own
 * parse as ground truth for the selector layer.
 *
 * WP_HTML_Processor bails (ERROR_UNSUPPORTED) on several constructs —
 * foster parenting, non-whitespace text in table context, complex
 * adoption-agency loops, FORM end tags over open elements — so inside an
 * open table context only table-structure tags and whitespace are emitted,
 * and FORM is excluded. Residual bails still occur (and are skipped by the
 * worker); this keeps them rare instead of dominant.
 *
 * Every emitted start tag carries a unique data-fid. Implied (virtual)
 * elements and adoption-agency clones surface in the capture as placeholder
 * or duplicated fids; the match comparison is list-based in visit order, so
 * neither breaks the oracle.
 */
class WildDocumentGenerator {

	const TAGS = array(
		'div',
		'p',
		'span',
		'a',
		'b',
		'i',
		'em',
		'strong',
		'u',
		's',
		'code',
		'small',
		'table',
		'caption',
		'thead',
		'tbody',
		'tfoot',
		'tr',
		'td',
		'th',
		'ul',
		'ol',
		'li',
		'dl',
		'dt',
		'dd',
		'h1',
		'h2',
		'h3',
		'blockquote',
		'figure',
		'figcaption',
		'header',
		'footer',
		'section',
		'article',
		'nav',
		'aside',
		'main',
		'address',
		'button',
		'select',
		'option',
		'optgroup',
		'label',
		'fieldset',
		'legend',
		'details',
		'summary',
		'svg',
		'math',
		'x-wild',
	);

	const VOID_TAGS = array( 'br', 'hr', 'img', 'input', 'wbr', 'col', 'source', 'area' );

	const DOCTYPES = array(
		'none'          => '',
		'html'          => '<!DOCTYPE html>',
		'legacy-compat' => '<!DOCTYPE html SYSTEM "about:legacy-compat">',
		'quirky'        => '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">',
		'limited'       => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">',
	);

	/** @var Prng */
	private $prng;
	private $fid_counter = 0;
	private $pools;

	private function __construct( Prng $prng ) {
		$this->prng  = $prng;
		$this->pools = array(
			'tags'       => array( 'html', 'head', 'body' ),
			'classes'    => array(),
			'ids'        => array(),
			'attrNames'  => array(),
			'attrValues' => array(),
		);
	}

	/**
	 * @return array{model: null, html: string, pools: array, wild: true, doctype: string}
	 */
	public static function generate( Prng $prng ): array {
		$generator = new self( $prng );
		return $generator->build();
	}

	private function build(): array {
		$doctype_kind = $this->prng->weighted(
			array(
				'none'          => 25,
				'html'          => 45,
				'legacy-compat' => 10,
				'quirky'        => 12,
				'limited'       => 8,
			)
		);

		$out = self::DOCTYPES[ $doctype_kind ];

		if ( $this->prng->chance( 15 ) ) {
			$out .= '<html' . $this->render_attrs( $this->random_attrs() ) . '>';
		}
		if ( $this->prng->chance( 10 ) ) {
			$out .= '<body' . $this->render_attrs( $this->random_attrs() ) . '>';
		}

		$max_elements = $this->prng->int( 4, 35 );
		$token_budget = $this->prng->int( 8, 70 );
		$open         = array();

		for ( $i = 0; $i < $token_budget; $i++ ) {
			$in_table = $this->in_table_context( $open );

			$kind = $this->prng->weighted(
				array(
					'start'   => 42,
					'void'    => $in_table ? 0 : 8,
					'end'     => 24,
					'text'    => 16,
					'comment' => 5,
					'stray'   => $in_table ? 0 : 5,
				)
			);

			switch ( $kind ) {
				case 'start':
					if ( $this->fid_counter >= $max_elements ) {
						break;
					}
					$tag                    = $in_table
						? $this->prng->choice( array( 'caption', 'colgroup', 'thead', 'tbody', 'tfoot', 'tr', 'tr', 'td', 'td', 'th' ) )
						: $this->prng->choice( self::TAGS );
					if ( 'a' === $tag && in_array( 'a', $open, true ) ) {
						// A nested <a> immediately runs the adoption agency.
						$tag = 'span';
					}
					$this->pools['tags'][]  = $tag;
					$out                   .= '<' . $this->maybe_case( $tag )
						. ' data-fid="w' . $this->fid_counter++ . '"'
						. $this->render_attrs( $this->random_attrs() ) . '>';
					$open[]                 = $tag;
					break;

				case 'void':
					if ( $this->fid_counter >= $max_elements ) {
						break;
					}
					$tag                   = $this->prng->choice( self::VOID_TAGS );
					$this->pools['tags'][] = $tag;
					$out                  .= '<' . $this->maybe_case( $tag )
						. ' data-fid="w' . $this->fid_counter++ . '"'
						. $this->render_attrs( $this->random_attrs() )
						. ( $this->prng->chance( 25 ) ? ' />' : '>' );
					break;

				case 'end':
					if ( array() === $open ) {
						break;
					}
					$pick = $this->prng->weighted(
						array(
							'top'    => 60,
							'random' => 40,
						)
					);
					if ( 'top' === $pick ) {
						$tag = array_pop( $open );
					} else {
						/*
						 * Close a non-top open element: misnesting. Never
						 * across a formatting element — the processor only
						 * supports the trivial adoption-agency cases and
						 * bails on the rest ( "any other end tag" /
						 * "common ancestor" / reconstruction-with-rewind ).
						 */
						$formatting = array( 'a', 'b', 'i', 'em', 'strong', 'u', 's', 'code', 'small' );
						$lowest     = count( $open ) - 1;
						while ( $lowest > 0 && ! in_array( $open[ $lowest ], $formatting, true ) ) {
							$lowest--;
						}
						if ( in_array( $open[ $lowest ], $formatting, true ) ) {
							$lowest++;
						}
						if ( $lowest > count( $open ) - 1 ) {
							$tag = array_pop( $open );
						} else {
							$at  = $this->prng->int( $lowest, count( $open ) - 1 );
							$tag = $open[ $at ];
							array_splice( $open, $at, 1 );
						}
					}
					$out .= '</' . $this->maybe_case( $tag ) . '>';
					break;

				case 'text':
					// Non-whitespace text in table context is unsupported
					// (pending-table-character-tokens), keep it whitespace.
					$out .= $in_table
						? "\n  "
						: $this->prng->choice(
							array(
								'text',
								' wild text ',
								"\n",
								'&amp; &lt;x&gt;',
								'café ✓',
								'a < b',
							)
						);
					break;

				case 'comment':
					$out .= '<!-- wild -->';
					break;

				case 'stray':
					// An end tag for something that is not open.
					// No formatting tags here: a stray formatting end tag
					// runs the adoption agency's unsupported branches.
					$out .= '</' . $this->prng->choice( array( 'div', 'p', 'table', 'tr', 'li', 'span', 'x-wild' ) ) . '>';
					break;
			}
		}

		// Leave roughly half of the still-open elements unclosed.
		foreach ( array_reverse( $open ) as $tag ) {
			if ( $this->prng->chance( 50 ) ) {
				$out .= '</' . $this->maybe_case( $tag ) . '>';
			}
		}

		foreach ( $this->pools as $key => $values ) {
			$this->pools[ $key ] = array_values( array_unique( $values ) );
		}

		return array(
			'model'   => null,
			'html'    => $out,
			'pools'   => $this->pools,
			'wild'    => true,
			'doctype' => $doctype_kind,
		);
	}

	/**
	 * Whether the insertion point is in table context outside any cell or
	 * caption — where arbitrary content would foster-parent (unsupported).
	 */
	private function in_table_context( array $open ): bool {
		for ( $i = count( $open ) - 1; $i >= 0; $i-- ) {
			$tag = $open[ $i ];
			if ( in_array( $tag, array( 'td', 'th', 'caption' ), true ) ) {
				return false;
			}
			if ( in_array( $tag, array( 'table', 'thead', 'tbody', 'tfoot', 'tr', 'colgroup' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<int, array{0: string, 1: string|true}> */
	private function random_attrs(): array {
		$attrs = array();
		$count = $this->prng->weighted( array( 0 => 30, 1 => 35, 2 => 25, 3 => 10 ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$name = $this->prng->choice( DocumentGenerator::ATTR_NAMES );

			$lower = ascii_strtolower( $name );
			if ( 'class' === $lower ) {
				$words = array();
				$n     = $this->prng->int( 1, 3 );
				for ( $j = 0; $j < $n; $j++ ) {
					$word    = $this->maybe_inject_class_nul( $this->random_word() );
					$words[] = $word;
					foreach ( DocumentGenerator::class_tokens( $word ) as $token ) {
						$this->pools['classes'][] = $token;
					}
				}
				$value = implode( ' ', $words );
			} elseif ( 'id' === $lower ) {
				$value                = $this->random_word();
				$this->pools['ids'][] = $value;
			} elseif ( $this->prng->chance( 15 ) ) {
				$value = true;
			} else {
				$value = $this->random_word();
				if ( $this->prng->chance( 20 ) ) {
					$value .= ' ' . $this->random_word();
				}
			}

			$this->pools['attrNames'][] = $lower;
			if ( is_string( $value ) && 'class' !== $lower ) {
				$this->pools['attrValues'][] = $value;
			}
			$attrs[] = array( $name, $value );
		}

		return $attrs;
	}

	private function maybe_inject_class_nul( string $class ): string {
		if ( '' === $class || ! $this->prng->chance( 12 ) ) {
			return $class;
		}

		$points = utf8_codepoints( $class );
		$at     = $this->prng->int( 0, count( $points ) );
		$out    = '';
		foreach ( $points as $i => $point ) {
			if ( $i === $at ) {
				$out .= "\0";
			}
			$out .= $point[0];
		}
		return $at === count( $points ) ? $out . "\0" : $out;
	}

	private function render_attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $attr ) {
			list( $name, $value ) = $attr;
			if ( true === $value ) {
				$out .= ' ' . $name;
				continue;
			}
			$out .= ' ' . $name . '="' . str_replace( array( '&', '"', '<' ), array( '&amp;', '&quot;', '&lt;' ), $value ) . '"';
		}
		return $out;
	}

	private function random_word(): string {
		$stems = array( 'wild', 'soup', 'alpha', 'beta', 'item', 'note', 'x', 'mixedCase', 'Über', 'main-thing', '--var', '_u' );
		$word  = $this->prng->choice( $stems );
		if ( $this->prng->chance( 30 ) ) {
			$word .= (string) $this->prng->int( 0, 99 );
		}
		return $word;
	}

	private function maybe_case( string $tag ): string {
		if ( ! $this->prng->chance( 15 ) ) {
			return $tag;
		}
		$out = '';
		for ( $i = 0; $i < strlen( $tag ); $i++ ) {
			$c    = $tag[ $i ];
			$out .= $this->prng->chance( 50 ) ? strtoupper( $c ) : strtolower( $c );
		}
		return $out;
	}
}
