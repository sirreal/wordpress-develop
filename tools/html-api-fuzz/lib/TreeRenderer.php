<?php
namespace HtmlApiFuzz;

class TreeRenderer {
	const STATUS_OK          = 'ok';
	const STATUS_UNSUPPORTED = 'unsupported';
	const STATUS_ERROR       = 'error';

	public static function render_wordpress( string $html, string $mode, array $limits = array(), string $fragment_context = 'body' ): array {
		HtmlApiBootstrap::load();
		$max_tokens = $limits['maxTokens'] ?? 2000;
		$processor  = Generator::MODE_FULL_DOCUMENT === $mode
			? \WP_HTML_Processor::create_full_parser( $html )
			: \WP_HTML_Processor::create_fragment( $html, "<{$fragment_context}>" );

		if ( null === $processor ) {
			if ( Generator::MODE_FULL_DOCUMENT !== $mode && 'body' !== $fragment_context ) {
				return array(
					'status'      => self::STATUS_UNSUPPORTED,
					'unsupported' => array(
						'message' => "create_fragment() does not support the <{$fragment_context}> context.",
					),
				);
			}
			return array(
				'status' => self::STATUS_ERROR,
				'error'  => 'Could not create WP_HTML_Processor.',
			);
		}

		$output       = '';
		$indent_level = 0;
		$was_text     = false;
		$text_node    = '';
		$tokens       = 0;
		$line_count   = 0;

		/*
		 * The renderer derives tree structure from token order and
		 * expects_closer(). get_breadcrumbs() reports the processor's own
		 * stack of open elements; the two must agree at every tag token, or
		 * the processor's stack bookkeeping and its token stream have
		 * diverged.
		 */
		if ( Generator::MODE_FULL_DOCUMENT === $mode ) {
			$breadcrumb_prefix = array();
		} elseif ( 'body' === $fragment_context ) {
			$breadcrumb_prefix = array( 'HTML', 'BODY' );
		} else {
			// Unknown context ancestry; calibrated from the first tag token.
			$breadcrumb_prefix = null;
		}
		$element_stack = array();

		try {
			while ( $processor->next_token() ) {
				++$tokens;
				if ( $tokens > $max_tokens ) {
					return array(
						'status'       => self::STATUS_ERROR,
						'error'        => 'Token limit exceeded.',
						'failureClass' => 'token-limit-exceeded',
						'tokenCount'   => $tokens,
					);
				}

				if ( null !== $processor->get_last_error() ) {
					break;
				}

				$token_name = $processor->get_token_name();
				$token_type = $processor->get_token_type();
				$is_closer  = $processor->is_tag_closer();

				if ( '#presumptuous-tag' === $token_type ) {
					continue;
				}

				if ( $was_text && '#text' !== $token_name ) {
					if ( '' !== $text_node ) {
						$output .= "{$text_node}\"\n";
						++$line_count;
					}
					$was_text  = false;
					$text_node = '';
				}

				switch ( $token_type ) {
					case '#doctype':
						$doctype = $processor->get_doctype_info();
						if ( null === $doctype ) {
							break;
						}
						$output .= '<!DOCTYPE ' . self::escape_tree_scalar( (string) $doctype->name );
						if ( null !== $doctype->public_identifier || null !== $doctype->system_identifier ) {
							$output .= ' "' . self::escape_tree_scalar( (string) $doctype->public_identifier ) . '" "' . self::escape_tree_scalar( (string) $doctype->system_identifier ) . '"';
						}
						$output .= ">\n";
						++$line_count;
						break;

					case '#tag':
						$namespace = $processor->get_namespace();
						$tag_name  = 'html' === $namespace
							? strtolower( (string) $processor->get_tag() )
							: "{$namespace} {$processor->get_qualified_tag_name()}";

						if ( $is_closer ) {
							array_pop( $element_stack );
							$breadcrumb_mismatch = self::breadcrumb_mismatch( $processor, $breadcrumb_prefix, $element_stack, null );
							if ( null !== $breadcrumb_mismatch ) {
								return $breadcrumb_mismatch;
							}
							--$indent_level;
							if ( 'html' === $namespace && 'TEMPLATE' === $token_name ) {
								--$indent_level;
							}
							break;
						}

						$breadcrumb_mismatch = self::breadcrumb_mismatch( $processor, $breadcrumb_prefix, $element_stack, $token_name );
						if ( null !== $breadcrumb_mismatch ) {
							return $breadcrumb_mismatch;
						}

						$tag_indent = $indent_level;
						if ( $processor->expects_closer() ) {
							++$indent_level;
							$element_stack[] = $token_name;
						}

						$output .= str_repeat( '  ', $tag_indent ) . '<' . self::escape_tree_scalar( $tag_name ) . ">\n";
						++$line_count;
						$output .= self::render_wp_attributes( $processor, $tag_indent + 1, $line_count );

						$modifiable_text = $processor->get_modifiable_text();
						if ( '' !== $modifiable_text ) {
							$output .= str_repeat( '  ', $tag_indent + 1 ) . '"' . self::escape_tree_scalar( $modifiable_text ) . "\"\n";
							++$line_count;
						}

						if ( 'html' === $namespace && 'TEMPLATE' === $token_name ) {
							$output .= str_repeat( '  ', $indent_level ) . "content\n";
							++$line_count;
							++$indent_level;
						}
						break;

					case '#cdata-section':
					case '#text':
						$text_content = $processor->get_modifiable_text();
						if ( '' === $text_content ) {
							break;
						}
						$was_text = true;
						if ( '' === $text_node ) {
							$text_node .= str_repeat( '  ', $indent_level ) . '"';
						}
						$text_node .= self::escape_tree_scalar( $text_content );
						break;

					case '#funky-comment':
						$output .= str_repeat( '  ', $indent_level ) . '<!-- ' . self::escape_tree_scalar( $processor->get_modifiable_text() ) . " -->\n";
						++$line_count;
						break;

					case '#comment':
						$output .= str_repeat( '  ', $indent_level ) . '<!-- ' . self::escape_tree_scalar( $processor->get_full_comment_text() ) . " -->\n";
						++$line_count;
						break;

					default:
						return array(
							'status' => self::STATUS_ERROR,
							'error'  => "Unhandled WordPress token type: {$token_type}",
						);
				}
			}
		} catch ( \Throwable $e ) {
			return array(
				'status'       => self::STATUS_ERROR,
				'error'        => $e->getMessage(),
				'throwable'    => get_class( $e ),
				'failureClass' => 'fatal-error',
			);
		}

		$unsupported_exception = $processor->get_unsupported_exception();
		if ( null !== $unsupported_exception && self::is_ignored_presumptuous_tag_exception( $unsupported_exception ) ) {
			if ( '' !== $text_node ) {
				$output .= "{$text_node}\"\n";
				++$line_count;
			}

			return array(
				'status'                   => self::STATUS_OK,
				'tree'                     => $output . "\n",
				'tokenCount'               => $tokens,
			);
		}

		if ( null !== $unsupported_exception ) {
			return array(
				'status'      => self::STATUS_UNSUPPORTED,
				'tree'        => $output,
				'tokenCount'  => $tokens,
				'unsupported' => self::unsupported_details( $unsupported_exception ),
			);
		}

		if ( null !== $processor->get_last_error() ) {
			return array(
				'status'       => self::STATUS_ERROR,
				'tree'         => $output,
				'tokenCount'   => $tokens,
				'error'        => $processor->get_last_error(),
				'failureClass' => $processor->get_last_error(),
			);
		}

		if ( $processor->paused_at_incomplete_token() ) {
			return array(
				'status'      => self::STATUS_UNSUPPORTED,
				'tree'        => $output,
				'tokenCount'  => $tokens,
				'unsupported' => array(
					'message' => 'Paused at incomplete token.',
				),
			);
		}

		if ( '' !== $text_node ) {
			$output .= "{$text_node}\"\n";
			++$line_count;
		}

		return array(
			'status'                   => self::STATUS_OK,
			'tree'                     => $output . "\n",
			'tokenCount'               => $tokens,
		);
	}

	/**
	 * Compares get_breadcrumbs() with the renderer's element stack at a tag
	 * token. $current is the token name for openers (breadcrumbs include the
	 * element being opened) and null for closers (the element is already
	 * popped). Returns an error result on divergence, null when consistent.
	 */
	private static function breadcrumb_mismatch( \WP_HTML_Processor $processor, ?array &$prefix, array $element_stack, ?string $current ): ?array {
		$actual = $processor->get_breadcrumbs();

		if ( null === $prefix ) {
			$suffix_length = count( $element_stack ) + ( null === $current ? 0 : 1 );
			$prefix        = array_slice( $actual, 0, max( 0, count( $actual ) - $suffix_length ) );
		}

		$expected = array_merge( $prefix, $element_stack );
		if ( null !== $current ) {
			$expected[] = $current;
		}
		if ( $actual === $expected ) {
			return null;
		}

		$divergence = 0;
		$limit      = min( count( $expected ), count( $actual ) );
		while ( $divergence < $limit && $expected[ $divergence ] === $actual[ $divergence ] ) {
			++$divergence;
		}

		return array(
			'status'       => self::STATUS_ERROR,
			'error'        => 'get_breadcrumbs() diverged from the token-derived element stack.',
			'failureClass' => 'breadcrumb-mismatch',
			'breadcrumbs'  => array(
				'kind'            => count( $expected ) === count( $actual ) ? 'name' : 'depth',
				'divergenceDepth' => $divergence,
				'expectedDepth'   => count( $expected ),
				'actualDepth'     => count( $actual ),
				'expected'        => array_slice( $expected, 0, 40 ),
				'actual'          => array_slice( $actual, 0, 40 ),
			),
		);
	}

	private static function is_ignored_presumptuous_tag_exception( \WP_HTML_Unsupported_Exception $e ): bool {
		return '#presumptuous-tag' === $e->token_name
			&& '</>' === $e->token
			&& 'Content outside of HTML is unsupported.' === $e->getMessage();
	}

	private static function render_wp_attributes( \WP_HTML_Processor $processor, int $indent_level, int &$line_count ): string {
		$attribute_names = $processor->get_attribute_names_with_prefix( '' );
		if ( ! $attribute_names ) {
			return '';
		}

		$sorted = array();
		foreach ( $attribute_names as $attribute_name ) {
			$display_name = (string) $processor->get_qualified_attribute_name( $attribute_name );
			$sorted[ $attribute_name ] = self::attribute_record( $display_name );
		}
		uasort( $sorted, array( __CLASS__, 'compare_attribute_records' ) );

		$output = '';
		foreach ( $sorted as $attribute_name => $display ) {
			$value = $processor->get_attribute( $attribute_name );
			if ( true === $value ) {
				$value = '';
			}
			$output .= str_repeat( '  ', $indent_level ) . $display['renderName'] . '="' . self::escape_tree_scalar( (string) $value ) . "\"\n";
			++$line_count;
		}
		return $output;
	}

	public static function compare_attribute_display_names( string $a, string $b ): int {
		$a_has_ns = false !== strpos( $a, ':' );
		$b_has_ns = false !== strpos( $b, ':' );
		if ( $a_has_ns !== $b_has_ns ) {
			return $a_has_ns ? 1 : -1;
		}

		$a_has_sp = false !== strpos( $a, ' ' );
		$b_has_sp = false !== strpos( $b, ' ' );
		if ( $a_has_sp !== $b_has_sp ) {
			return $a_has_sp ? 1 : -1;
		}

		return $a <=> $b;
	}

	/**
	 * Builds the per-attribute record used for sorting and rendering.
	 *
	 * Sorting must use the spec-scrubbed name (NUL as U+FFFD, newlines
	 * normalized), so the same logical attribute occupies the same canonical
	 * position in every oracle tree.
	 * Rendering keeps the raw escaped name so divergent bytes stay visible.
	 */
	private static function attribute_record( string $display_name ): array {
		return array(
			'sortName'    => self::escape_tree_scalar( self::scrub_scalar( $display_name ) ),
			'renderName'  => self::escape_tree_scalar( $display_name ),
		);
	}

	/**
	 * Applies the spec-mandated scalar substitutions to a raw string:
	 * NUL becomes U+FFFD and CR / CRLF become LF.
	 */
	private static function scrub_scalar( string $value ): string {
		$value = str_replace( "\0", "\xEF\xBF\xBD", $value );
		return str_replace( array( "\r\n", "\r" ), "\n", $value );
	}

	private static function compare_attribute_records( array $a, array $b ): int {
		$sorted = self::compare_attribute_display_names( $a['sortName'], $b['sortName'] );
		if ( 0 !== $sorted ) {
			return $sorted;
		}

		return self::compare_attribute_display_names( $a['renderName'], $b['renderName'] );
	}

	public static function compare_trees( string $wordpress_tree, string $oracle_tree ): array {
		$adjusted_wordpress = self::apply_wrapper_tolerance( $wordpress_tree, $oracle_tree );
		if ( $adjusted_wordpress === $oracle_tree ) {
			return array(
				'ok' => true,
			);
		}
		return array(
			'ok'              => false,
			'firstDifference' => self::first_difference( $adjusted_wordpress, $oracle_tree ),
		);
	}

	private static function apply_wrapper_tolerance( string $processed_tree, string $expected_tree ): string {
		$html_head_body = "<html>\n  <head>\n  <body>\n\n";
		$head_body      = "  <head>\n  <body>\n\n";
		$body           = "  <body>\n\n";

		if ( self::ends_with( $expected_tree, $html_head_body ) && ! self::ends_with( $processed_tree, $html_head_body ) ) {
			if ( self::ends_with( $processed_tree, "<html>\n  <head>\n\n" ) ) {
				return substr( $processed_tree, 0, -1 ) . "  <body>\n\n";
			}
			if ( self::ends_with( $processed_tree, "<html>\n\n" ) ) {
				return substr( $processed_tree, 0, -1 ) . "  <head>\n  <body>\n\n";
			}
			return substr( $processed_tree, 0, -1 ) . $html_head_body;
		}
		if ( self::ends_with( $expected_tree, $head_body ) && ! self::ends_with( $processed_tree, $head_body ) ) {
			if ( self::ends_with( $processed_tree, "<head>\n\n" ) ) {
				return substr( $processed_tree, 0, -1 ) . "  <body>\n\n";
			}
			return substr( $processed_tree, 0, -1 ) . $head_body;
		}
		if ( self::ends_with( $expected_tree, $body ) && ! self::ends_with( $processed_tree, $body ) ) {
			return substr( $processed_tree, 0, -1 ) . $body;
		}

		return $processed_tree;
	}

	private static function ends_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}
		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}

	/**
	 * Public first-difference diff between two rendered trees, for invariant
	 * checks that compare WordPress trees against each other.
	 */
	public static function diff_trees( string $left, string $right ): array {
		return self::first_difference( $left, $right );
	}

	private static function first_difference( string $left, string $right, array $left_line_map = array(), int $start_line = 0 ): array {
		$left_lines  = explode( "\n", $left );
		$right_lines = explode( "\n", $right );
		$max         = max( count( $left_lines ), count( $right_lines ) );
		$left_paths  = self::line_paths( $left_lines );
		$right_paths = self::line_paths( $right_lines );

		for ( $i = $start_line; $i < $max; ++$i ) {
			$l = $left_lines[ $i ] ?? null;
			$r = $right_lines[ $i ] ?? null;
			if ( $l !== $r ) {
				$first_byte_offset = self::first_different_byte_offset( $l, $r );
				$left_line         = $left_line_map[ $i ] ?? $i;
				return array(
					'line'                                  => $left_line + 1,
					'comparisonLine'                        => $i + 1,
					'domLineNumber'                         => $i + 1,
					'wordpressLinePreview'                  => self::line_preview( $l ),
					'domLinePreview'                        => self::line_preview( $r ),
					'wordpressLineBytes'                    => null === $l ? null : strlen( $l ),
					'domLineBytes'                          => null === $r ? null : strlen( $r ),
					'wordpressLineSha1'                     => null === $l ? null : sha1( $l ),
					'domLineSha1'                           => null === $r ? null : sha1( $r ),
					'firstByteOffset'                       => $first_byte_offset,
					'diffWindowStart'                       => self::diff_window_start( $first_byte_offset ),
					'wordpressHex'                          => null === $l ? null : self::hex_preview( $l ),
					'domHex'                                => null === $r ? null : self::hex_preview( $r ),
					'wordpressDiffHex'                      => self::hex_window( $l, $first_byte_offset ),
					'domDiffHex'                            => self::hex_window( $r, $first_byte_offset ),
					'wordpressPath'                         => $left_paths[ $i ] ?? null,
					'domPath'                               => $right_paths[ $i ] ?? null,
					'path'                                  => $left_paths[ $i ] ?? $right_paths[ $i ] ?? null,
					'wordpressNorm'                         => self::normalize_tree_line( $l ),
					'domNorm'                               => self::normalize_tree_line( $r ),
					'linesMatchAfterWordPressUtf8Scrub'     => self::lines_match_after_wordpress_utf8_scrub( $l, $r ),
				);
			}
		}

		return array();
	}

	private static function escape_tree_scalar( string $value ): string {
		$output = '';
		$length = strlen( $value );
		for ( $i = 0; $i < $length; ++$i ) {
			$byte = $value[ $i ];
			switch ( $byte ) {
				case "\n":
					$output .= '\\n';
					break;
				case "\r":
					$output .= '\\r';
					break;
				case "\t":
					$output .= '\\t';
					break;
				case "\0":
					$output .= '\\0';
					break;
				case '\\':
					$output .= '\\\\';
					break;
				case '"':
					$output .= '\\"';
					break;
				default:
					$ord = ord( $byte );
					$output .= ( $ord < 0x20 || 0x7f === $ord )
						? sprintf( '\\x%02X', $ord )
						: $byte;
					break;
			}
		}

		return $output;
	}

	private static function line_preview( ?string $line ): ?string {
		return null === $line ? null : preview_bytes( $line, 240 );
	}

	private static function first_different_byte_offset( ?string $left, ?string $right ): ?int {
		if ( $left === $right ) {
			return null;
		}
		if ( null === $left || null === $right ) {
			return 0;
		}

		$limit = min( strlen( $left ), strlen( $right ) );
		for ( $i = 0; $i < $limit; ++$i ) {
			if ( $left[ $i ] !== $right[ $i ] ) {
				return $i;
			}
		}

		return $limit;
	}

	private static function diff_window_start( ?int $offset ): ?int {
		return null === $offset ? null : max( 0, $offset - 32 );
	}

	private static function hex_window( ?string $line, ?int $offset ): ?string {
		if ( null === $line || null === $offset ) {
			return null;
		}

		$bytes = unpack( 'H*', substr( $line, self::diff_window_start( $offset ) ?? 0, 96 ) );
		return $bytes[1] ?? '';
	}

	private static function lines_match_after_wordpress_utf8_scrub( ?string $left, ?string $right ): ?bool {
		if ( null === $left || null === $right || ! function_exists( 'wp_scrub_utf8' ) ) {
			return null;
		}

		return wp_scrub_utf8( $left ) === $right;
	}

	private static function hex_preview( string $line ): string {
		$bytes = unpack( 'H*', substr( $line, 0, 160 ) );
		return $bytes[1] ?? '';
	}

	private static function line_paths( array $lines ): array {
		$stack = array();
		$paths = array();
		foreach ( $lines as $i => $line ) {
			$trim = ltrim( $line, ' ' );
			$level = (int) floor( ( strlen( $line ) - strlen( $trim ) ) / 2 );
			if ( preg_match( '/^<([^!][^>]*)>$/', $trim, $m ) ) {
				$stack = array_slice( $stack, 0, $level );
				$stack[ $level ] = $m[1];
				$paths[ $i ] = '/' . implode( '/', $stack );
			} elseif ( 'content' === $trim ) {
				$stack = array_slice( $stack, 0, $level );
				$stack[ $level ] = 'content';
				$paths[ $i ] = '/' . implode( '/', $stack );
			} elseif ( preg_match( '/^([^=]+)=/', $trim, $m ) ) {
				$paths[ $i ] = '/' . implode( '/', array_slice( $stack, 0, $level ) ) . '/@' . $m[1];
			} elseif ( '' !== $trim ) {
				$paths[ $i ] = '/' . implode( '/', array_slice( $stack, 0, $level ) ) . '/#text';
			}
		}
		return $paths;
	}

	private const KNOWN_HTML_TREE_ELEMENT_NAMES = array(
		'a', 'abbr', 'acronym', 'address', 'applet', 'area', 'article', 'aside',
		'audio', 'b', 'base', 'basefont', 'bdi', 'bdo', 'bgsound', 'big',
		'blink', 'blockquote', 'body', 'br', 'button', 'canvas', 'caption',
		'center', 'cite', 'code', 'col', 'colgroup', 'command', 'content',
		'data', 'datalist', 'dd', 'del', 'details', 'dfn', 'dialog', 'dir',
		'div', 'dl', 'dt', 'element', 'em', 'embed', 'fencedframe', 'fieldset',
		'figcaption', 'figure', 'font', 'footer', 'form', 'frame', 'frameset',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr',
		'html', 'i', 'iframe', 'image', 'img', 'input', 'ins', 'isindex', 'kbd',
		'keygen', 'label', 'legend', 'li', 'link', 'listing', 'main', 'map',
		'mark', 'marquee', 'menu', 'menuitem', 'meta', 'meter', 'multicol',
		'nav', 'nextid', 'nobr', 'noembed', 'noframes', 'noscript', 'object',
		'ol', 'optgroup', 'option', 'output', 'p', 'param', 'picture',
		'plaintext', 'portal', 'pre', 'progress', 'q', 'rb', 'rp', 'rt', 'rtc',
		'ruby', 's', 'samp', 'script', 'search', 'section', 'select',
		'selectedcontent', 'shadow', 'slot', 'small', 'source', 'spacer',
		'span', 'strike', 'strong', 'style', 'sub', 'summary', 'sup', 'table',
		'tbody', 'td', 'template', 'textarea', 'tfoot', 'th', 'thead', 'time',
		'title', 'tr', 'track', 'tt', 'u', 'ul', 'var', 'video', 'wbr', 'xmp',
	);

	private const KNOWN_SVG_TREE_ELEMENT_NAMES = array(
		'a', 'altGlyph', 'altGlyphDef', 'altGlyphItem', 'animate',
		'animateColor', 'animateMotion', 'animateTransform', 'circle', 'clipPath',
		'color-profile', 'cursor', 'defs', 'desc', 'discard', 'ellipse',
		'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite',
		'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap',
		'feDistantLight', 'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB',
		'feFuncG', 'feFuncR', 'feGaussianBlur', 'feImage', 'feMerge',
		'feMergeNode', 'feMorphology', 'feOffset', 'fePointLight',
		'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence', 'filter',
		'flowDiv', 'flowLine', 'flowPara', 'flowRegion', 'flowRegionBreak',
		'flowRoot', 'flowSpan', 'font', 'font-face', 'font-face-format',
		'font-face-name', 'font-face-src', 'font-face-uri', 'foreignObject', 'g',
		'glyph', 'glyphRef', 'hatch', 'hatchpath', 'hkern', 'image', 'line',
		'linearGradient', 'marker', 'mask', 'mesh', 'meshgradient', 'meshpatch',
		'meshrow', 'metadata', 'missing-glyph', 'mpath', 'path', 'pattern',
		'polygon', 'polyline', 'radialGradient', 'rect', 'script', 'set',
		'solidColor', 'solidcolor', 'stop', 'style', 'svg', 'switch', 'symbol',
		'text', 'textPath', 'title', 'tref', 'tspan', 'use', 'view', 'vkern',
	);

	private const KNOWN_MATHML_TREE_ELEMENT_NAMES = array(
		'abs', 'and', 'annotation', 'annotation-xml', 'apply', 'approx',
		'arccos', 'arccosh', 'arccot', 'arccoth', 'arccsc', 'arccsch', 'arcsec',
		'arcsech', 'arcsin', 'arcsinh', 'arctan', 'arctanh', 'arg', 'bind',
		'bvar', 'card', 'cartesianproduct', 'cbytes', 'ceiling', 'cerror',
		'ci', 'cn', 'codomain', 'complexes', 'compose', 'condition',
		'conjugate', 'cos', 'cosh', 'cot', 'coth', 'cs', 'csc', 'csch',
		'csymbol', 'curl', 'declare', 'degree', 'determinant', 'diff',
		'divergence', 'divide', 'domain', 'domainofapplication', 'emptyset',
		'eq', 'equivalent', 'eulergamma', 'exists', 'exp', 'exponentiale',
		'factorial', 'factorof', 'false', 'floor', 'fn', 'forall', 'gcd', 'geq',
		'grad', 'gt', 'ident', 'image', 'imaginary', 'imaginaryi', 'implies',
		'in', 'infinity', 'int', 'integers', 'intersect', 'interval', 'inverse',
		'lambda', 'laplacian', 'lcm', 'leq', 'limit', 'list', 'ln', 'log',
		'logbase', 'lowlimit', 'lt', 'maction', 'maligngroup', 'malignmark',
		'math', 'matrix', 'matrixrow', 'max', 'mean', 'median', 'menclose',
		'merror', 'mfenced', 'mfrac', 'mglyph', 'mi', 'min', 'minus',
		'mlabeledtr', 'mlongdiv', 'mmultiscripts', 'mn', 'mo', 'mode', 'moment',
		'momentabout', 'mover', 'mpadded', 'mphantom', 'mprescripts', 'mroot',
		'mrow', 'ms', 'mscarries', 'mscarry', 'msgroup', 'msline', 'mspace',
		'msqrt', 'msrow', 'mstack', 'mstyle', 'msub', 'msubsup', 'msup',
		'mtable', 'mtd', 'mtext', 'mtr', 'munder', 'munderover',
		'naturalnumbers', 'neq', 'none', 'not', 'notanumber', 'notin',
		'notprsubset', 'notsubset', 'or', 'otherwise', 'outerproduct',
		'partialdiff', 'pi', 'piece', 'piecewise', 'plus', 'power', 'primes',
		'product', 'prsubset', 'quotient',
		'rationals', 'real', 'reals', 'reln', 'rem', 'root', 'scalarproduct',
		'sdev', 'sec', 'sech', 'selector', 'semantics', 'sep', 'set', 'setdiff',
		'share', 'sin', 'sinh', 'subset', 'sum', 'tan', 'tanh', 'tendsto',
		'times', 'transpose', 'true', 'union', 'uplimit', 'variance', 'vector',
		'vectorproduct', 'xor',
	);

	public static function normalize_tree_line( ?string $line ): ?string {
		if ( null === $line ) {
			return null;
		}
		$trimmed = trim( $line );
		if ( preg_match( '/^([^=]+)="(?:\\\\.|[^"\\\\])*+"$/s', $trimmed, $m ) ) {
			return $m[1] . '="<value>"';
		}
		/*
		 * Element lines whose names are not recognized spec names are masked:
		 * the generator mints random custom and invalid tag names, and
		 * leaving them in normalized lines spreads one root cause across
		 * many signatures and families.
		 */
		if ( preg_match( '/^<([^!>][^>]*)>$/s', $trimmed, $m ) && ! self::is_known_tree_element_name( $m[1] ) ) {
			return '<custom-element>';
		}
		$line = preg_replace( '/"(?:\\\\.|[^"\\\\])*+"/s', '"<value>"', $line );
		$line = preg_replace( '/<!--.*-->/s', '<!-- <comment> -->', $line );
		return trim( (string) $line );
	}

	private static function is_known_tree_element_name( string $display_name ): bool {
		$namespace = 'html';
		$local = $display_name;
		if ( str_starts_with( $display_name, 'svg ' ) ) {
			$namespace = 'svg';
			$local = substr( $display_name, strpos( $display_name, ' ' ) + 1 );
		} elseif ( str_starts_with( $display_name, 'math ' ) ) {
			$namespace = 'math';
			$local = substr( $display_name, strpos( $display_name, ' ' ) + 1 );
		}

		$known = self::known_tree_element_names( $namespace );
		return isset( $known[ $local ] ) || isset( $known[ strtolower( $local ) ] );
	}

	private static function known_tree_element_names( string $namespace ): array {
		static $known = array();
		if ( ! isset( $known[ $namespace ] ) ) {
			if ( 'svg' === $namespace ) {
				$names = self::KNOWN_SVG_TREE_ELEMENT_NAMES;
			} elseif ( 'math' === $namespace ) {
				$names = self::KNOWN_MATHML_TREE_ELEMENT_NAMES;
			} else {
				$names = self::KNOWN_HTML_TREE_ELEMENT_NAMES;
			}

			$known[ $namespace ] = array();
			foreach ( $names as $name ) {
				$known[ $namespace ][ $name ] = true;
				$known[ $namespace ][ strtolower( $name ) ] = true;
			}
		}

		return $known[ $namespace ];
	}

	private static function unsupported_details( \WP_HTML_Unsupported_Exception $e ): array {
		return array(
			'message'                  => $e->getMessage(),
			'tokenName'                => $e->token_name,
			'tokenAt'                  => $e->token_at,
			'tokenPreview'             => preview_bytes( $e->token, 160 ),
			'stackOfOpenElements'      => $e->stack_of_open_elements,
			'activeFormattingElements' => $e->active_formatting_elements,
		);
	}
}
