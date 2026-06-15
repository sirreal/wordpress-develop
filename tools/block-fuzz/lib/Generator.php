<?php
/**
 * Block document generator for the block fuzzer.
 *
 * @package WordPress
 * @subpackage Block_Fuzz
 */

namespace BlockFuzz;

/**
 * Generates deterministic block documents from integer seeds.
 */
class Generator {
	/**
	 * Returns supported generator profiles.
	 *
	 * @return string[] Profile labels.
	 */
	public static function profiles() {
		return array(
			'balanced',
			'attributes',
			'invalid-nesting',
			'incomplete',
			'kses',
			'freeform',
			'mixed',
		);
	}

	/**
	 * Generates a block document from a seed.
	 *
	 * @param int|string $seed    Seed.
	 * @param array      $options Options.
	 * @return array Generated input and metadata.
	 */
	public static function generate( $seed, $options = array() ) {
		$prng      = new Prng( $seed );
		$profile   = isset( $options['profile'] ) ? (string) $options['profile'] : $prng->weighted(
			array(
				'balanced'        => 34,
				'attributes'      => 18,
				'invalid-nesting' => 16,
				'incomplete'      => 8,
				'kses'            => 14,
				'freeform'        => 5,
				'mixed'           => 5,
			)
		);
		$max_bytes = isset( $options['maxBytes'] ) ? (int) $options['maxBytes'] : 4096;

		if ( ! in_array( $profile, self::profiles(), true ) ) {
			throw new \InvalidArgumentException( "Unknown block fuzz profile: {$profile}." );
		}

		switch ( $profile ) {
			case 'freeform':
				$input                      = self::html_span( $prng, $prng->int( 1, 8 ), false );
				$expect_processor_agreement = true;
				break;

			case 'attributes':
				$input                      = self::attributes_document( $prng );
				$expect_processor_agreement = true;
				break;

			case 'invalid-nesting':
				$input                      = self::invalid_nesting_document( $prng );
				$expect_processor_agreement = false;
				break;

			case 'incomplete':
				$input                      = self::balanced_document( $prng, false ) . self::partial_delimiter( $prng );
				$expect_processor_agreement = false;
				break;

			case 'kses':
				$input                      = self::kses_document( $prng );
				$expect_processor_agreement = true;
				break;

			case 'mixed':
				$input                      = self::balanced_document( $prng, false ) . "\n" . self::invalid_nesting_document( $prng );
				$expect_processor_agreement = false;
				break;

			case 'balanced':
				$input                      = self::balanced_document( $prng, false );
				$expect_processor_agreement = true;
				break;
		}

		$truncated = false;
		if ( strlen( $input ) > $max_bytes ) {
			$input      = substr( $input, 0, $max_bytes );
			$truncated  = true;
			$expect_processor_agreement = false;
		}

		return array(
			'seed'                     => $seed,
			'profile'                  => $profile,
			'input'                    => $input,
			'truncated'                => $truncated,
			'expectProcessorAgreement' => $expect_processor_agreement,
		);
	}

	/**
	 * Generates a mostly well-formed block document.
	 *
	 * @param Prng $prng PRNG.
	 * @return string Document.
	 */
	private static function balanced_document( $prng, $include_invalid_block_comments = true ) {
		$parts = array();
		$count = $prng->int( 1, 6 );

		for ( $i = 0; $i < $count; ++$i ) {
			if ( $prng->chance( 25 ) ) {
				$parts[] = self::html_span( $prng, $prng->int( 1, 3 ), $include_invalid_block_comments );
			}

			$parts[] = self::balanced_block( $prng, 0, $prng->int( 1, 4 ), $include_invalid_block_comments );
		}

		if ( $prng->chance( 35 ) ) {
			$parts[] = self::html_span( $prng, $prng->int( 1, 3 ), $include_invalid_block_comments );
		}

		return implode( self::separator( $prng ), $parts );
	}

	/**
	 * Generates one well-formed block.
	 *
	 * @param Prng $prng      PRNG.
	 * @param int  $depth     Current depth.
	 * @param int  $max_depth Maximum depth.
	 * @return string Block markup.
	 */
	private static function balanced_block( $prng, $depth, $max_depth, $include_invalid_block_comments = true ) {
		$name  = self::block_name( $prng );
		$attrs = $prng->chance( 55 ) ? self::attributes( $prng ) : array();

		if ( $depth >= $max_depth || $prng->chance( 30 ) ) {
			return self::block_markup( $name, $attrs, '', true );
		}

		$parts = array();
		$count = $prng->int( 1, 4 );
		for ( $i = 0; $i < $count; ++$i ) {
			if ( $prng->chance( 55 ) ) {
				$parts[] = self::html_span( $prng, $prng->int( 1, 3 ), $include_invalid_block_comments );
			} else {
				$parts[] = self::balanced_block( $prng, $depth + 1, $max_depth, $include_invalid_block_comments );
			}
		}

		return self::block_markup( $name, $attrs, implode( self::separator( $prng ), $parts ), false );
	}

	/**
	 * Generates an attribute-heavy document.
	 *
	 * @param Prng $prng PRNG.
	 * @return string Document.
	 */
	private static function attributes_document( $prng ) {
		$attrs = array(
			'title'       => self::dangerous_text( $prng ),
			'html'        => '<img src=x onerror=alert(1)><em>ok</em>',
			'comment'     => 'before -- after',
			'quote'       => '"quoted" and backslash \\',
			'nested'      => array(
				'url'   => $prng->choice( array( 'https://example.com/', 'javascript:alert(1)', 'data:text/html,<svg>' ) ),
				'label' => self::dangerous_text( $prng ),
			),
			'list'        => array( '<b>bold</b>', '<script>alert(1)</script>', '--' ),
			'truthy'      => $prng->chance( 50 ),
			'count'       => $prng->int( -5, 200 ),
		);

		return self::block_markup(
			$prng->choice( array( 'paragraph', 'tests/attrs', 'my-plugin/card' ) ),
			$attrs,
			self::html_span( $prng, 3, false ) . self::balanced_block( $prng, 0, 1, false ),
			false
		);
	}

	/**
	 * Generates an invalidly nested block document.
	 *
	 * @param Prng $prng PRNG.
	 * @return string Document.
	 */
	private static function invalid_nesting_document( $prng ) {
		$cases = self::invalid_nesting_cases();

		return $prng->choice( $cases ) . self::separator( $prng ) . self::html_span( $prng, 2 );
	}

	/**
	 * Returns deterministic malformed nesting cases covered by PHPUnit.
	 *
	 * @return string[] Invalid nesting inputs.
	 */
	public static function invalid_nesting_cases() {
		return array(
			'<!-- wp:group --><!-- wp:paragraph --><p>alpha</p><!-- /wp:group --><!-- /wp:paragraph -->',
			'<!-- /wp:paragraph --><p>stray closer</p><!-- wp:paragraph --><p>open tail</p>',
			'<!-- wp:group --><!-- wp:paragraph /--><!-- /wp:paragraph --><!-- /wp:group -->',
			'<!-- wp:columns --><!-- wp:column --><!-- wp:paragraph /--><!-- /wp:columns -->',
			'<!-- wp:tests/a --><!-- wp:tests/b --><!-- /wp:tests/a --><!-- wp:tests/c /-->',
			'<!-- wp:outer --><!-- wp:inner {"x":"<script>alert(1)</script>","y":"--><img src=x onerror=alert(1)><!--"} -->',
		);
	}

	/**
	 * Generates a document biased toward KSES-sensitive attributes.
	 *
	 * @param Prng $prng PRNG.
	 * @return string Document.
	 */
	private static function kses_document( $prng ) {
		$attrs = array(
			'url'        => $prng->choice( array( 'javascript:alert(1)', 'https://example.com/?x=<y>&z="q"', 'vbscript:msgbox(1)' ) ),
			'caption'    => '<a href="javascript:alert(1)" onclick="evil()">caption</a>',
			'style'      => 'background:url(javascript:alert(1)); color:red',
			'ariaLabel'  => 'safe label',
			'raw'        => '<svg><script>alert(1)</script></svg>',
			'nested'     => array(
				'bad'  => '<iframe src="javascript:alert(1)"></iframe>',
				'good' => '<strong>safe</strong>',
			),
		);

		return self::block_markup(
			'tests/kses',
			$attrs,
			'<p onclick="alert(1)">Inner HTML is intentionally not the block attribute oracle.</p>' . self::balanced_block( $prng, 0, 1, false ),
			false
		);
	}

	/**
	 * Generates a partial delimiter suffix.
	 *
	 * @param Prng $prng PRNG.
	 * @return string Partial delimiter.
	 */
	private static function partial_delimiter( $prng ) {
		$delimiter = $prng->choice(
			array(
				'<!-- wp:paragraph {"dropCap":true} -->',
				'<!-- wp:my-plugin/card /-->',
				'<!-- /wp:group -->',
			)
		);

		return substr( $delimiter, 0, $prng->int( 1, strlen( $delimiter ) - 1 ) );
	}

	/**
	 * Serializes block markup with raw JSON attributes.
	 *
	 * @param string $name    Block name.
	 * @param array  $attrs   Attributes.
	 * @param string $content Inner content.
	 * @param bool   $void    Whether this is a void block.
	 * @return string Block markup.
	 */
	private static function block_markup( $name, $attrs, $content, $void ) {
		$serialized_attrs = '';
		if ( ! empty( $attrs ) ) {
			$serialized_attrs = ' ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		}

		if ( $void ) {
			return "<!-- wp:{$name}{$serialized_attrs} /-->";
		}

		return "<!-- wp:{$name}{$serialized_attrs} -->{$content}<!-- /wp:{$name} -->";
	}

	/**
	 * Returns a random block name.
	 *
	 * @param Prng $prng PRNG.
	 * @return string Block name.
	 */
	private static function block_name( $prng ) {
		return $prng->choice(
			array(
				'paragraph',
				'heading',
				'group',
				'columns',
				'column',
				'html',
				'tests/fuzz',
				'my-plugin/card',
				'core/list',
			)
		);
	}

	/**
	 * Returns generated attributes.
	 *
	 * @param Prng $prng PRNG.
	 * @return array Attributes.
	 */
	private static function attributes( $prng ) {
		$attrs = array();
		$count = $prng->int( 1, 4 );

		for ( $i = 0; $i < $count; ++$i ) {
			$key           = $prng->choice( array( 'align', 'className', 'title', 'url', 'text', 'flag', 'data' ) ) . $i;
			$attrs[ $key ] = self::attribute_value( $prng, 0 );
		}

		return $attrs;
	}

	/**
	 * Returns one generated attribute value.
	 *
	 * @param Prng $prng  PRNG.
	 * @param int  $depth Current nesting depth.
	 * @return mixed Attribute value.
	 */
	private static function attribute_value( $prng, $depth ) {
		$type = $depth > 1 ? $prng->weighted(
			array(
				'string' => 70,
				'int'    => 15,
				'bool'   => 15,
			)
		) : $prng->weighted(
			array(
				'string' => 45,
				'array'  => 20,
				'object' => 15,
				'int'    => 10,
				'bool'   => 10,
			)
		);

		switch ( $type ) {
			case 'array':
				return array( self::attribute_value( $prng, $depth + 1 ), self::dangerous_text( $prng ) );

			case 'object':
				return array(
					'a' => self::attribute_value( $prng, $depth + 1 ),
					'b' => self::dangerous_text( $prng ),
				);

			case 'int':
				return $prng->int( -1000, 1000 );

			case 'bool':
				return $prng->chance( 50 );

			case 'string':
			default:
				return self::dangerous_text( $prng );
		}
	}

	/**
	 * Generates HTML/freeform text.
	 *
	 * @param Prng $prng                            PRNG.
	 * @param int  $parts                           Number of parts.
	 * @param bool $include_invalid_block_comments  Whether invalid block-looking comments may be generated.
	 * @return string HTML span.
	 */
	private static function html_span( $prng, $parts, $include_invalid_block_comments = true ) {
		$atoms = array(
			'Text',
			'<p>Paragraph</p>',
			'<strong>bold</strong>',
			'&amp; entity',
			'<!-- ordinary comment -->',
			'<div data-x="1">box</div>',
			'<script>not parsed as a block</script>',
			'0',
			"\n",
		);

		if ( $include_invalid_block_comments ) {
			$atoms[] = '<!-- wp:not-a-block because invalid -->';
		}

		$out = '';
		for ( $i = 0; $i < $parts; ++$i ) {
			$out .= $prng->choice( $atoms );
			if ( $prng->chance( 40 ) ) {
				$out .= self::dangerous_text( $prng );
			}
		}

		return $out;
	}

	/**
	 * Generates text containing delimiter-sensitive characters.
	 *
	 * @param Prng $prng PRNG.
	 * @return string Text.
	 */
	private static function dangerous_text( $prng ) {
		return $prng->choice(
			array(
				'plain',
				'--',
				'<tag>',
				'Tom & Jerry',
				'"quote"',
				'backslash \\',
				'--<>&"\\',
				'emoji snowman',
				'https://example.test/?a=1&b=<two>',
				'javascript:alert(1)',
			)
		);
	}

	/**
	 * Returns optional spacing between generated parts.
	 *
	 * @param Prng $prng PRNG.
	 * @return string Separator.
	 */
	private static function separator( $prng ) {
		return $prng->choice( array( '', "\n", "\n\n", ' ' ) );
	}
}
