<?php
namespace CssSelectorFuzz;

/**
 * Generates a random HTML document together with a model of its element tree.
 *
 * Elements are drawn exclusively from a "structurally safe" set: properly
 * nested combinations of these tags parse verbatim (no auto-closing, no
 * foster parenting, no adoption agency restructuring, no implied elements
 * beyond the explicit html/head/body that are always rendered). This makes
 * the model tree provably identical to the tree the HTML processor builds,
 * so the model can serve as a matching oracle.
 *
 * Each element carries a unique `data-fid` attribute used to identify
 * matches when comparing actual vs. expected selections.
 */
class DocumentGenerator {

	const SAFE_TAGS = array(
		'div',
		'section',
		'article',
		'aside',
		'nav',
		'main',
		'header',
		'footer',
		'figure',
		'blockquote',
		'address',
		'span',
		'em',
		'strong',
		'b',
		'i',
		'code',
		'small',
		'sub',
		'sup',
		'u',
		's',
		'q',
		'abbr',
		'cite',
		'kbd',
		'mark',
		'samp',
		'time',
		'var',
		'dfn',
		'bdi',
		'x-fuzz',
		'fuzz-box',
	);

	const VOID_TAGS = array( 'br', 'hr', 'img', 'wbr', 'input', 'embed' );

	const ATTR_NAMES = array(
		'class',
		'id',
		'title',
		'lang',
		'dir',
		'name',
		'value',
		'type',
		'href',
		'src',
		'alt',
		'rel',
		'role',
		'data-x',
		'data-foo',
		'data-test-value',
		'data-Mixed-Case',
		'aria-label',
		'disabled',
		'hidden',
		'tabindex',
	);

	/** @var Prng */
	private $prng;
	private $fid_counter   = 0;
	private $element_count = 0;
	private $max_elements;
	private $pools;

	private function __construct( Prng $prng, int $max_elements ) {
		$this->prng         = $prng;
		$this->max_elements = $max_elements;
		$this->pools        = array(
			'tags'       => array(),
			'classes'    => array(),
			'ids'        => array(),
			'attrNames'  => array(),
			'attrValues' => array(),
		);
	}

	/**
	 * @return array{model: array, html: string, quirks: bool, pools: array}
	 */
	public static function generate( Prng $prng ): array {
		$generator = new self( $prng, $prng->int( 8, 40 ) );
		return $generator->build();
	}

	/**
	 * Generates a `<body>`-context fragment: body-level content rendered
	 * without the document wrapper, parsed via create_fragment. The model's
	 * top-level elements carry the implicit BODY/HTML ancestors the fragment
	 * parser reports in breadcrumbs.
	 *
	 * @return array{
	 *     model: null,
	 *     children: array,
	 *     html: string,
	 *     context: string,
	 *     fragment: true,
	 *     quirks: bool,
	 *     pools: array,
	 * }
	 */
	public static function generate_fragment( Prng $prng ): array {
		$generator = new self( $prng, $prng->int( 6, 30 ) );
		return $generator->build_fragment();
	}

	private function build_fragment(): array {
		$children     = array();
		$child_budget = $this->prng->int( 1, 6 );
		for ( $i = 0; $i < $child_budget && $this->element_count < $this->max_elements; $i++ ) {
			$children[] = $this->random_subtree( 0 );
		}

		$bits = array();
		foreach ( $children as $child ) {
			$bits[] = $this->render_element( $child );
		}
		$filler = array( '', 'text', ' more ', "\n  ", '&amp; x', 'café ✓', '<!-- c -->' );
		$html   = '';
		foreach ( $bits as $bit ) {
			if ( $this->prng->chance( 35 ) ) {
				$html .= $this->prng->choice( $filler );
			}
			$html .= $bit;
		}

		foreach ( $this->pools as $key => $values ) {
			$this->pools[ $key ] = array_values( array_unique( $values ) );
		}

		return array(
			'model'    => null,
			'children' => $children,
			'html'     => $html,
			'context'  => '<body>',
			'fragment' => true,
			'quirks'   => false,
			'pools'    => $this->pools,
		);
	}

	/**
	 * Rows ( TreeCapture shape ) for a `<body>`-context fragment: the
	 * top-level children flattened with the implicit HTML/BODY ancestors the
	 * fragment parser reports.
	 */
	public static function rows_from_fragment( array $children ): array {
		$html_root = array( 'tag' => 'html', 'fid' => '(html)', 'attrs' => array(), 'children' => array() );
		$body_root = array( 'tag' => 'body', 'fid' => '(body)', 'attrs' => array(), 'children' => $children );

		$rows = array();
		foreach ( $children as $child ) {
			foreach ( self::flatten_with_ancestors( $child, array( $body_root, $html_root ) ) as $pair ) {
				list( $element, $ancestors ) = $pair;

				$attrs = array();
				$seen  = array();
				foreach ( $element['attrs'] as $attr ) {
					$lower = ascii_strtolower( $attr[0] );
					if ( isset( $seen[ $lower ] ) ) {
						continue;
					}
					$seen[ $lower ] = true;
					$attrs[]        = array( $lower, $attr[1] );
				}

				$ancestor_tags = array();
				foreach ( $ancestors as $ancestor ) {
					$ancestor_tags[] = strtoupper( ascii_strtolower( $ancestor['tag'] ) );
				}

				$rows[] = array(
					'tag'          => strtoupper( ascii_strtolower( $element['tag'] ) ),
					'fid'          => $element['fid'],
					'attrs'        => $attrs,
					'ancestorTags' => $ancestor_tags,
				);
			}
		}
		return $rows;
	}

	private function build(): array {
		$has_doctype = $this->prng->chance( 85 );

		$head_children = array();
		if ( $this->prng->chance( 60 ) ) {
			$head_children[] = $this->make_element( 'title', array(), array() );
		}
		if ( $this->prng->chance( 30 ) ) {
			$head_children[] = $this->make_element( 'meta', $this->random_attrs(), array() );
		}

		$body_children = array();
		$child_budget  = $this->prng->int( 1, 6 );
		for ( $i = 0; $i < $child_budget && $this->element_count < $this->max_elements; $i++ ) {
			$body_children[] = $this->random_subtree( 0 );
		}

		$head = $this->make_element( 'head', array(), $head_children );
		$body = $this->make_element( 'body', $this->prng->chance( 30 ) ? $this->random_attrs() : array(), $body_children );
		$html = $this->make_element( 'html', $this->prng->chance( 20 ) ? $this->random_attrs() : array(), array( $head, $body ) );

		$rendered = ( $has_doctype ? '<!DOCTYPE html>' : '' ) . $this->render_element( $html );

		foreach ( $this->pools as $key => $values ) {
			$this->pools[ $key ] = array_values( array_unique( $values ) );
		}

		return array(
			'model'  => $html,
			'html'   => $rendered,
			'quirks' => ! $has_doctype,
			'pools'  => $this->pools,
		);
	}

	private function random_subtree( int $depth ): array {
		++$this->element_count;

		if ( $depth >= 7 || $this->element_count >= $this->max_elements || $this->prng->chance( 25 ) ) {
			// Leaf.
			if ( $this->prng->chance( 25 ) ) {
				return $this->make_element( $this->prng->choice( self::VOID_TAGS ), $this->random_attrs(), array(), true );
			}
			return $this->make_element( $this->prng->choice( self::SAFE_TAGS ), $this->random_attrs(), array() );
		}

		$children    = array();
		$child_count = $this->prng->int( 1, 4 );
		for ( $i = 0; $i < $child_count && $this->element_count < $this->max_elements; $i++ ) {
			$children[] = $this->random_subtree( $depth + 1 );
		}

		return $this->make_element( $this->prng->choice( self::SAFE_TAGS ), $this->random_attrs(), $children );
	}

	private function make_element( string $tag, array $attrs, array $children, bool $is_void = false ): array {
		$fid = 'e' . $this->fid_counter++;

		$written_tag = $this->prng->chance( 15 ) ? $this->random_case( $tag ) : $tag;

		$this->pools['tags'][] = $tag;

		return array(
			'tag'      => $written_tag,
			'fid'      => $fid,
			'attrs'    => $attrs,
			'children' => $children,
			'void'     => $is_void || in_array( strtolower( $tag ), array( 'meta', 'br', 'hr', 'img', 'wbr', 'input', 'embed' ), true ),
		);
	}

	/** @return array<int, array{0: string, 1: string|true}> name/value pairs in source order. */
	private function random_attrs(): array {
		$attrs = array();
		$count = $this->prng->weighted(
			array(
				0 => 15,
				1 => 30,
				2 => 30,
				3 => 15,
				4 => 10,
			)
		);

		$used_names = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$name = $this->prng->choice( self::ATTR_NAMES );

			// Occasionally repeat an attribute name: the processor keeps the first.
			$is_duplicate = isset( $used_names[ ascii_strtolower( $name ) ] );
			if ( $is_duplicate && ! $this->prng->chance( 20 ) ) {
				continue;
			}
			$used_names[ ascii_strtolower( $name ) ] = true;

			if ( $this->prng->chance( 12 ) ) {
				$name = $this->random_case( $name );
			}

			$lower = ascii_strtolower( $name );
			if ( 'class' === $lower ) {
				$value = $this->random_class_value();
			} elseif ( 'id' === $lower ) {
				$value = $this->prng->chance( 85 ) ? $this->random_id_value() : ( $this->prng->chance( 50 ) ? '' : true );
			} elseif ( in_array( $lower, array( 'disabled', 'hidden' ), true ) ) {
				$value = $this->prng->chance( 70 ) ? true : $this->prng->choice( array( '', 'disabled', 'true' ) );
			} else {
				$value = $this->prng->chance( 12 ) ? true : $this->random_attr_value();
			}

			$this->pools['attrNames'][] = ascii_strtolower( $name );
			if ( is_string( $value ) && 'class' !== $lower ) {
				$this->pools['attrValues'][] = $value;
			}

			$attrs[] = array( $name, $value );
		}

		return $attrs;
	}

	private function random_class_value(): string {
		$count   = $this->prng->int( 1, 4 );
		$classes = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$class     = $this->random_word( true );
			$raw_class = $this->maybe_inject_class_nul( $class );
			$classes[] = $raw_class;
			foreach ( self::class_tokens( $raw_class ) as $token ) {
				$this->pools['classes'][] = $token;
			}
		}

		$ws    = array( ' ', ' ', ' ', "\t", "\n", "\f", '  ' );
		$value = $this->prng->chance( 20 ) ? $this->prng->choice( $ws ) : '';
		foreach ( $classes as $i => $class ) {
			if ( $i > 0 ) {
				$value .= $this->prng->choice( $ws );
			}
			$value .= $class;
		}
		if ( $this->prng->chance( 20 ) ) {
			$value .= $this->prng->choice( $ws );
		}
		return $value;
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

	private function random_id_value(): string {
		$id                   = $this->random_word( true );
		$this->pools['ids'][] = $id;
		return $id;
	}

	private function random_attr_value(): string {
		$kind = $this->prng->weighted(
			array(
				'word'       => 35,
				'words'      => 20,
				'hyphenated' => 15,
				'empty'      => 8,
				'spicy'      => 12,
				'unicode'    => 10,
			)
		);

		switch ( $kind ) {
			case 'word':
				return $this->random_word( true );
			case 'words':
				$parts = array();
				$n     = $this->prng->int( 2, 4 );
				for ( $i = 0; $i < $n; $i++ ) {
					$parts[] = $this->random_word( true );
				}
				return implode( $this->prng->choice( array( ' ', ' ', "\t", "\n" ) ), $parts );
			case 'hyphenated':
				return $this->random_word( false ) . '-' . $this->random_word( false );
			case 'empty':
				return '';
			case 'spicy':
				$spice = array( 'a"b', "a'b", 'a&b', 'a<b', 'a>b', 'a=b', 'a b c', '&amp;', '&quot;x', '100%', 'semi;colon', 'a,b' );
				return $this->prng->choice( $spice );
			case 'unicode':
				$unicode = array( 'héllo', 'ÄÖÜ', '✓done', 'naïve', 'Ωmega', '\u{1F600}smile' );
				$value   = $this->prng->choice( $unicode );
				return str_replace( '\u{1F600}', "\u{1F600}", $value );
		}
		return 'fallback';
	}

	private function random_word( bool $allow_mixed_case ): string {
		$stems = array( 'alpha', 'beta', 'gamma', 'delta', 'box', 'col', 'item', 'note', 'wide', 'main-item', 'x', 'a', '-lead', '--var', '_under', 'Über', 'mixedCase' );
		$word  = $this->prng->choice( $stems );
		if ( $this->prng->chance( 30 ) ) {
			$word .= (string) $this->prng->int( 0, 99 );
		}
		if ( $allow_mixed_case && $this->prng->chance( 15 ) ) {
			$word = $this->random_case( $word );
		}
		return $word;
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
	 * ---------
	 * Rendering
	 * ---------
	 */

	private function render_element( array $element ): string {
		$out = '<' . $element['tag'];

		$rendered_attrs = array( ' data-fid="' . $element['fid'] . '"' );
		foreach ( $element['attrs'] as $attr ) {
			$rendered_attrs[] = ' ' . $this->render_attr( $attr[0], $attr[1] );
		}
		$out .= implode( '', $rendered_attrs );

		if ( $element['void'] ) {
			$out .= $this->prng->chance( 25 ) ? ' />' : '>';
			return $out;
		}

		$out .= '>';

		$child_bits = array();
		foreach ( $element['children'] as $child ) {
			$child_bits[] = $this->render_element( $child );
		}

		/*
		 * Sprinkle text and comments between children — but never directly
		 * inside `html` or `head`, where character tokens would trigger
		 * insertion-mode changes (early body creation, head popping) that
		 * desynchronize the model from the parsed tree.
		 */
		$lower_tag       = strtolower( $element['tag'] );
		$may_have_filler = ! in_array( $lower_tag, array( 'html', 'head' ), true );
		$filler_options  = array(
			'',
			'text',
			' more text ',
			"\n  ",
			'&amp; &lt;escaped&gt;',
			'<!-- comment -->',
			'café ✓',
		);
		$content         = '';
		foreach ( $child_bits as $bit ) {
			if ( $may_have_filler && $this->prng->chance( 40 ) ) {
				$content .= $this->prng->choice( $filler_options );
			}
			$content .= $bit;
		}
		if ( $may_have_filler && $this->prng->chance( 40 ) ) {
			$content .= $this->prng->choice( $filler_options );
		}
		if ( 'title' === $lower_tag ) {
			// RAWTEXT: keep it plain.
			$content = $this->prng->chance( 60 ) ? 'Fuzz Title' : '';
		}

		return $out . $content . '</' . $element['tag'] . '>';
	}

	/** @param string|true $value */
	private function render_attr( string $name, $value ): string {
		if ( true === $value ) {
			return $name;
		}

		$style = $this->prng->weighted(
			array(
				'double'   => 60,
				'single'   => 20,
				'unquoted' => 20,
			)
		);

		if ( 'unquoted' === $style && ( '' === $value || strlen( $value ) !== strspn( $value, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._:-' ) ) ) {
			$style = 'double';
		}

		switch ( $style ) {
			case 'unquoted':
				return $name . '=' . $value;
			case 'single':
				return $name . "='" . str_replace( array( '&', "'", '<' ), array( '&amp;', '&#39;', '&lt;' ), $value ) . "'";
			default:
				return $name . '="' . str_replace( array( '&', '"', '<' ), array( '&amp;', '&quot;', '&lt;' ), $value ) . '"';
		}
	}

	/*
	 * ----------------
	 * Model utilities
	 * ----------------
	 */

	/** Pre-order (document order) list of elements. */
	public static function flatten( array $element ): array {
		$out = array( $element );
		foreach ( $element['children'] as $child ) {
			foreach ( self::flatten( $child ) as $descendant ) {
				$out[] = $descendant;
			}
		}
		return $out;
	}

	/**
	 * Pre-order list of ( element, ancestors ) pairs where ancestors is the
	 * chain from nearest ancestor to root — the same orientation as
	 * WP_HTML_Processor::get_breadcrumbs() reversed past self.
	 */
	public static function flatten_with_ancestors( array $element, array $ancestors = array() ): array {
		$out            = array( array( $element, $ancestors ) );
		$next_ancestors = array_merge( array( $element ), $ancestors );
		foreach ( $element['children'] as $child ) {
			foreach ( self::flatten_with_ancestors( $child, $next_ancestors ) as $pair ) {
				$out[] = $pair;
			}
		}
		return $out;
	}

	/**
	 * Flat element rows ( the TreeCapture row shape ) derived from a model:
	 * pre-order, tags uppercased, attribute names lowercased with the first
	 * of duplicates winning — directly comparable to a TreeCapture of the
	 * rendered document.
	 */
	public static function rows_from_model( array $model ): array {
		$rows = array();
		foreach ( self::flatten_with_ancestors( $model ) as $pair ) {
			list( $element, $ancestors ) = $pair;

			$attrs = array();
			$seen  = array();
			foreach ( $element['attrs'] as $attr ) {
				$lower = ascii_strtolower( $attr[0] );
				if ( isset( $seen[ $lower ] ) ) {
					continue;
				}
				$seen[ $lower ] = true;
				$attrs[]        = array( $lower, $attr[1] );
			}

			$ancestor_tags = array();
			foreach ( $ancestors as $ancestor ) {
				$ancestor_tags[] = strtoupper( ascii_strtolower( $ancestor['tag'] ) );
			}

			$rows[] = array(
				'tag'          => strtoupper( ascii_strtolower( $element['tag'] ) ),
				'fid'          => $element['fid'],
				'attrs'        => $attrs,
				'ancestorTags' => $ancestor_tags,
			);
		}
		return $rows;
	}

	/** First attribute value for a name, ASCII case-insensitive; null if absent. */
	public static function get_attribute_value( array $element, string $name ) {
		$comparable = ascii_strtolower( $name );
		foreach ( $element['attrs'] as $attr ) {
			if ( ascii_strtolower( $attr[0] ) === $comparable ) {
				return $attr[1];
			}
		}
		return null;
	}

	/**
	 * Class tokens as seen by selector matching: ASCII whitespace separates
	 * tokens, and NUL inside a token is exposed as U+FFFD by class_list().
	 *
	 * @return string[]
	 */
	public static function class_tokens( string $class_value ): array {
		$tokens = array();
		$length = strlen( $class_value );
		$at     = 0;
		$ws     = " \t\r\n\f";
		while ( $at < $length ) {
			$at += strspn( $class_value, $ws, $at );
			if ( $at >= $length ) {
				break;
			}

			$token_length = strcspn( $class_value, $ws, $at );
			$tokens[]     = str_replace( "\0", "\u{FFFD}", substr( $class_value, $at, $token_length ) );
			$at          += $token_length;
		}
		return $tokens;
	}
}
