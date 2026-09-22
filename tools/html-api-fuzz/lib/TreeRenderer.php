<?php
namespace HtmlApiFuzz;

class TreeRenderer {
	const STATUS_OK          = 'ok';
	const STATUS_UNSUPPORTED = 'unsupported';
	const STATUS_ERROR       = 'error';
	private const DOM_TEMPLATE_CONTEXT_UNSUPPORTED = 'DOM template content does not round-trip through body-context fragment parsing.';
	private const XLINK_LOCAL_NAMES = array(
		'actuate' => true,
		'arcrole' => true,
		'href'    => true,
		'role'    => true,
		'show'    => true,
		'title'   => true,
		'type'    => true,
	);

	public static function render_wordpress( string $html, string $mode, array $limits = array(), string $fragment_context = 'body' ): array {
		HtmlApiBootstrap::load();
		$max_tokens     = $limits['maxTokens'] ?? 2000;
		$max_depth      = $limits['maxDepth'] ?? 512;
		$max_tree_bytes = $limits['maxTreeBytes'] ?? 16777216;
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
		$dom_oracle_line_tolerances = array();

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
				if ( $indent_level > $max_depth ) {
					return array(
						'status'       => self::STATUS_ERROR,
						'error'        => 'Tree depth limit exceeded.',
						'failureClass' => 'depth-limit-exceeded',
						'tokenCount'   => $tokens,
					);
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
						$output .= self::render_wp_attributes( $processor, $tag_indent + 1, $line_count, $dom_oracle_line_tolerances );

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

					case '#processing-instruction':
						$output .= str_repeat( '  ', $indent_level )
							. '<?'
							. self::escape_tree_scalar( (string) $processor->get_tag() )
							. ' '
							. self::escape_tree_scalar( $processor->get_modifiable_text() )
							. "?>\n";
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

				if ( strlen( $output ) + strlen( $text_node ) > $max_tree_bytes ) {
					return array(
						'status'       => self::STATUS_ERROR,
						'error'        => 'Rendered tree byte limit exceeded.',
						'failureClass' => 'tree-byte-limit-exceeded',
						'tokenCount'   => $tokens,
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
				'domOracleLineTolerances'  => $dom_oracle_line_tolerances,
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
		if ( strlen( $output ) + 1 > $max_tree_bytes ) {
			return array(
				'status'       => self::STATUS_ERROR,
				'error'        => 'Rendered tree byte limit exceeded.',
				'failureClass' => 'tree-byte-limit-exceeded',
				'tokenCount'   => $tokens,
			);
		}

		return array(
			'status'                   => self::STATUS_OK,
			'tree'                     => $output . "\n",
			'tokenCount'               => $tokens,
			'domOracleLineTolerances'  => $dom_oracle_line_tolerances,
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

	private static function render_wp_attributes( \WP_HTML_Processor $processor, int $indent_level, int &$line_count, array &$dom_oracle_line_tolerances ): string {
		$attribute_names = $processor->get_attribute_names_with_prefix( '' );
		if ( ! $attribute_names ) {
			return '';
		}

		$dom_oracle_dropped_attributes = self::dom_oracle_xlink_dropped_attribute_names( $processor, $attribute_names );

		$sorted = array();
		foreach ( $attribute_names as $attribute_name ) {
			$display_name = (string) $processor->get_qualified_attribute_name( $attribute_name );
			$sorted[ $attribute_name ] = self::attribute_record( $display_name );
		}
		uasort( $sorted, array( __CLASS__, 'compare_attribute_records' ) );

		$output = '';
		foreach ( $sorted as $attribute_name => $display ) {
			if ( isset( $dom_oracle_dropped_attributes[ $attribute_name ] ) ) {
				$dom_oracle_line_tolerances[] = $line_count;
			}
			$value = $processor->get_attribute( $attribute_name );
			if ( true === $value ) {
				$value = '';
			}
			$output .= str_repeat( '  ', $indent_level ) . $display['renderName'] . '="' . self::escape_tree_scalar( (string) $value ) . "\"\n";
			++$line_count;
		}
		return $output;
	}

	private static function dom_oracle_xlink_dropped_attribute_names( \WP_HTML_Processor $processor, array $attribute_names ): array {
		if ( 'html' === $processor->get_namespace() || ! self::dom_oracle_drops_bare_xlink_local_name_after_xlink() ) {
			return array();
		}

		$dropped_attribute_names = array();
		$seen_xlink_local_names  = array();
		foreach ( $attribute_names as $attribute_name ) {
			$lower_name = strtolower( $attribute_name );
			if ( str_starts_with( $lower_name, 'xlink:' ) ) {
				$local_name = substr( $lower_name, strlen( 'xlink:' ) );
				if ( isset( self::XLINK_LOCAL_NAMES[ $local_name ] ) ) {
					$seen_xlink_local_names[ $local_name ] = true;
				}
				continue;
			}

			if ( isset( $seen_xlink_local_names[ $lower_name ] ) ) {
				$dropped_attribute_names[ $attribute_name ] = true;
			}
		}

		return $dropped_attribute_names;
	}

	private static function dom_oracle_drops_bare_xlink_local_name_after_xlink(): bool {
		static $drops = null;
		if ( null !== $drops ) {
			return $drops;
		}

		if ( ! class_exists( 'Dom\\HTMLDocument' ) ) {
			$drops = false;
			return $drops;
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$document = \Dom\HTMLDocument::createFromString( '<svg xlink:href href></svg>', LIBXML_NOERROR );
			$svg      = $document->getElementsByTagName( 'svg' )->item( 0 );
			$drops    = null !== $svg && $svg->hasAttributeNS( 'http://www.w3.org/1999/xlink', 'href' ) && ! $svg->hasAttribute( 'href' );
		} catch ( \Throwable $e ) {
			$drops = false;
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $drops;
	}

	/**
	 * PHP's Lexbor-based parser fails to treat U+000C FORM FEED as ignorable
	 * whitespace in the pre-body insertion modes (initial, before html,
	 * before head, in head, after head), leaking it into body text where
	 * spec-following parsers — including WordPress — drop it. Probed at
	 * runtime so the tolerance disables itself when PHP fixes the bug.
	 */
	public static function dom_oracle_mishandles_form_feed(): bool {
		static $mishandles = null;
		if ( null !== $mishandles ) {
			return $mishandles;
		}

		if ( ! class_exists( 'Dom\\HTMLDocument' ) ) {
			$mishandles = false;
			return $mishandles;
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$document   = \Dom\HTMLDocument::createFromString( "\fa", LIBXML_NOERROR );
			$body       = $document->getElementsByTagName( 'body' )->item( 0 );
			$mishandles = null !== $body && "\fa" === $body->textContent;
		} catch ( \Throwable $e ) {
			$mishandles = false;
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $mishandles;
	}

	public static function render_dom( string $html, string $mode, array $limits = array(), string $fragment_context = 'body' ): array {
		if ( ! class_exists( 'Dom\\HTMLDocument' ) ) {
			return array(
				'status'       => self::STATUS_ERROR,
				'error'        => 'Dom\\HTMLDocument is not available. PHP 8.4+ with ext-dom is required.',
				'failureClass' => 'oracle-unavailable',
			);
		}

		$max_nodes = $limits['maxNodes'] ?? 3000;

		$node_count = 0;
		$output     = '';
		try {
			if ( Generator::MODE_FRAGMENT_BODY === $mode ) {
				try {
					$context = self::parse_dom_fragment( $html, $fragment_context );
				} catch ( \Throwable $e ) {
					return array(
						'status'       => self::STATUS_ERROR,
						'error'        => $e->getMessage(),
						'throwable'    => get_class( $e ),
						'failureClass' => 'oracle-parse-error',
					);
				}
				foreach ( $context->childNodes as $child ) {
					$output .= self::render_dom_node( $child, 0, $node_count, $max_nodes );
				}
			} else {
				$previous = libxml_use_internal_errors( true );
				try {
					$document = \Dom\HTMLDocument::createFromString( $html, LIBXML_NOERROR );
				} catch ( \Throwable $e ) {
					return array(
						'status'       => self::STATUS_ERROR,
						'error'        => $e->getMessage(),
						'throwable'    => get_class( $e ),
						'failureClass' => 'oracle-parse-error',
					);
				} finally {
					libxml_clear_errors();
					libxml_use_internal_errors( $previous );
				}
				foreach ( $document->childNodes as $child ) {
					$output .= self::render_dom_node( $child, 0, $node_count, $max_nodes );
				}
			}
		} catch ( \RuntimeException $e ) {
			if ( 'DOM node limit exceeded.' === $e->getMessage() ) {
				return array(
					'status'       => self::STATUS_ERROR,
					'error'        => $e->getMessage(),
					'failureClass' => 'node-limit-exceeded',
					'nodeCount'    => $node_count,
				);
			}
			if ( self::DOM_TEMPLATE_CONTEXT_UNSUPPORTED === $e->getMessage() ) {
				return array(
					'status'       => self::STATUS_UNSUPPORTED,
					'error'        => $e->getMessage(),
					'failureClass' => 'oracle-unsupported',
					'unsupported'  => array(
						'message' => $e->getMessage(),
					),
					'nodeCount'    => $node_count,
				);
			}
			throw $e;
		}

		return array(
			'status'    => self::STATUS_OK,
			'tree'      => $output . "\n",
			'nodeCount' => $node_count,
		);
	}

	/**
	 * Parses HTML as a fragment in the given context element using the DOM
	 * innerHTML setter, which performs context-aware fragment parsing.
	 *
	 * Returns the context element whose children are the parsed fragment.
	 */
	private static function parse_dom_fragment( string $html, string $context_tag ) {
		$document = \Dom\HTMLDocument::createEmpty();
		$lower    = strtolower( $context_tag );
		if ( 'svg' === $lower ) {
			$context = $document->createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		} elseif ( 'math' === $lower ) {
			$context = $document->createElementNS( 'http://www.w3.org/1998/Math/MathML', 'math' );
		} else {
			$context = $document->createElement( $lower );
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$context->innerHTML = $html;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		return $context;
	}

	private static function render_dom_node( $node, int $indent_level, int &$node_count, int $max_nodes ): string {
		++$node_count;
		if ( $node_count > $max_nodes ) {
			throw new \RuntimeException( 'DOM node limit exceeded.' );
		}

		switch ( $node->nodeType ) {
			case XML_DOCUMENT_TYPE_NODE:
				$name   = $node->name ?? $node->nodeName;
				$output = '<!DOCTYPE ' . self::escape_tree_scalar( (string) $name );
				$public = $node->publicId ?? '';
				$system = $node->systemId ?? '';
				if ( '' !== $public || '' !== $system ) {
					$output .= ' "' . self::escape_tree_scalar( (string) $public ) . '" "' . self::escape_tree_scalar( (string) $system ) . '"';
				}
				return $output . ">\n";

			case XML_ELEMENT_NODE:
				return self::render_dom_element( $node, $indent_level, $node_count, $max_nodes );

			case XML_TEXT_NODE:
			case XML_CDATA_SECTION_NODE:
				return '' === $node->nodeValue ? '' : str_repeat( '  ', $indent_level ) . '"' . self::escape_tree_scalar( (string) $node->nodeValue ) . "\"\n";

			case XML_COMMENT_NODE:
				return str_repeat( '  ', $indent_level ) . '<!-- ' . self::escape_tree_scalar( (string) $node->nodeValue ) . " -->\n";

			default:
				return '';
		}
	}

	private static function render_dom_element( $node, int $indent_level, int &$node_count, int $max_nodes ): string {
		$tag_name = self::dom_element_display_name( $node );
		$output   = str_repeat( '  ', $indent_level ) . '<' . self::escape_tree_scalar( $tag_name ) . ">\n";
		$output  .= self::render_dom_attributes( $node, $indent_level + 1 );

		$is_html_template = 'http://www.w3.org/1999/xhtml' === ( $node->namespaceURI ?? '' ) && 'template' === strtolower( (string) $node->localName );
		if ( $is_html_template ) {
			$output .= str_repeat( '  ', $indent_level + 1 ) . "content\n";
			$output .= self::render_dom_template_children( $node, $indent_level + 2, $node_count, $max_nodes );
			return $output;
		}

		foreach ( $node->childNodes as $child ) {
			$output .= self::render_dom_node( $child, $indent_level + 1, $node_count, $max_nodes );
		}
		return $output;
	}

	/**
	 * Renders the children of a template element.
	 *
	 * PHP's Dom\HTMLDocument hides template content (childNodes is empty and no
	 * `content` property is exposed), but the innerHTML getter serializes the
	 * true, template-context-parsed content. Re-parse that serialization in a
	 * body context and verify fidelity by re-serializing: when the round-trip
	 * reproduces the source serialization byte-for-byte, the body-context tree
	 * is the template content tree. When it does not (table parts, foreign
	 * fragments, and other template-mode-sensitive content), declare the case
	 * unsupported rather than render a wrong tree. This check is deliberately
	 * self-contained: it must not consult the WordPress HTML API, which is the
	 * system under test.
	 */
	private static function render_dom_template_children( $node, int $indent_level, int &$node_count, int $max_nodes ): string {
		$output = '';
		foreach ( $node->childNodes as $child ) {
			$output .= self::render_dom_node( $child, $indent_level, $node_count, $max_nodes );
		}
		if ( '' !== $output ) {
			return $output;
		}

		$inner_html = (string) ( $node->innerHTML ?? '' );
		if ( '' === $inner_html ) {
			return '';
		}

		try {
			$body = self::parse_dom_fragment( $inner_html, 'body' );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'Could not render DOM template innerHTML: ' . $e->getMessage(), 0, $e );
		}

		if ( (string) ( $body->innerHTML ?? '' ) !== $inner_html ) {
			// Count the nodes that were parsed so resource-stress template
			// content reports node-limit-exceeded ahead of unsupported.
			$scratch_count = $node_count;
			self::count_dom_children( $body, $scratch_count, $max_nodes );
			throw new \RuntimeException( self::DOM_TEMPLATE_CONTEXT_UNSUPPORTED );
		}

		foreach ( $body->childNodes as $child ) {
			$output .= self::render_dom_node( $child, $indent_level, $node_count, $max_nodes );
		}
		return $output;
	}

	private static function count_dom_children( $node, int &$node_count, int $max_nodes ): void {
		foreach ( $node->childNodes as $child ) {
			++$node_count;
			if ( $node_count > $max_nodes ) {
				throw new \RuntimeException( 'DOM node limit exceeded.' );
			}
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				self::count_dom_children( $child, $node_count, $max_nodes );
			}
		}
	}

	private static function dom_element_display_name( $node ): string {
		$namespace = $node->namespaceURI ?? '';
		$local     = $node->localName ?? $node->nodeName;
		if ( 'http://www.w3.org/1999/xhtml' === $namespace ) {
			return strtolower( $local );
		}
		if ( 'http://www.w3.org/2000/svg' === $namespace ) {
			return 'svg ' . $local;
		}
		if ( 'http://www.w3.org/1998/Math/MathML' === $namespace ) {
			return 'math ' . $local;
		}
		return $node->nodeName;
	}

	private static function render_dom_attributes( $node, int $indent_level ): string {
		if ( ! $node->hasAttributes() ) {
			return '';
		}

		$attrs = array();
		foreach ( $node->attributes as $attr ) {
			$record          = self::attribute_record( self::dom_attribute_display_name( $attr ) );
			$record['value'] = $attr->nodeValue;
			$attrs[]         = $record;
		}

		usort( $attrs, array( __CLASS__, 'compare_attribute_records' ) );

		$output = '';
		foreach ( $attrs as $attr ) {
			$output .= str_repeat( '  ', $indent_level ) . $attr['renderName'] . '="' . self::escape_tree_scalar( (string) $attr['value'] ) . "\"\n";
		}
		return $output;
	}

	private static function dom_attribute_display_name( $attr ): string {
		$namespace = $attr->namespaceURI ?? '';
		$local     = $attr->localName ?? $attr->nodeName;
		if ( 'http://www.w3.org/1999/xlink' === $namespace ) {
			return 'xlink ' . $local;
		}
		if ( 'http://www.w3.org/XML/1998/namespace' === $namespace ) {
			return 'xml ' . $local;
		}
		if ( 'http://www.w3.org/2000/xmlns/' === $namespace ) {
			return 'xmlns ' . $local;
		}
		return $attr->nodeName;
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
	 * normalized): WordPress preserves raw bytes that the DOM oracle
	 * substitutes, and sorting each side by its own raw rendering would put
	 * the same logical attribute at different positions in the two trees.
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

	public static function compare_trees( string $wordpress_tree, string $dom_tree, array $wordpress_line_tolerances = array() ): array {
		$adjusted = self::remove_tolerated_wordpress_lines( $wordpress_tree, $wordpress_line_tolerances );
		$adjusted_wordpress = self::apply_wrapper_tolerance( $adjusted['tree'], $dom_tree );
		if ( $adjusted_wordpress === $dom_tree ) {
			return array(
				'ok' => true,
			);
		}

		/*
		 * The WordPress HTML API deliberately preserves raw NUL and CR bytes
		 * where spec-following parsers substitute U+FFFD and normalize
		 * newlines during input preprocessing. Render both trees raw, and
		 * tolerate a differing line only when that exact substitution
		 * explains the whole difference. Tolerated lines are reported so
		 * runs account for them instead of silently scrubbing both sides.
		 */
		$wordpress_lines   = explode( "\n", $adjusted_wordpress );
		$dom_lines         = explode( "\n", $dom_tree );
		$tolerated         = array();
		$first_unexplained = null;
		$shared_line_count = min( count( $wordpress_lines ), count( $dom_lines ) );
		for ( $i = 0; $i < $shared_line_count; ++$i ) {
			if ( $wordpress_lines[ $i ] === $dom_lines[ $i ] ) {
				continue;
			}
			if (
				self::scalar_tolerance_eligible_line( $wordpress_lines[ $i ] ) &&
				self::scalar_tolerance_eligible_line( $dom_lines[ $i ] ) &&
				self::escaped_scalar_lines_match( $wordpress_lines[ $i ], $dom_lines[ $i ] )
			) {
				$tolerated[] = $adjusted['lineMap'][ $i ] ?? $i;
				continue;
			}
			$first_unexplained = $i;
			break;
		}
		if ( null === $first_unexplained ) {
			if ( count( $wordpress_lines ) === count( $dom_lines ) ) {
				return array(
					'ok'                  => true,
					'scalarToleratedLines' => $tolerated,
				);
			}
			// One tree has extra trailing lines; report from the tail.
			$first_unexplained = $shared_line_count;
		}

		// Report the first line the scalar tolerance cannot explain, not
		// merely the first line that differs.
		return array(
			'ok'              => false,
			'firstDifference' => self::first_difference( $adjusted_wordpress, $dom_tree, $adjusted['lineMap'], $first_unexplained ?? 0 ),
		);
	}

	/**
	 * Indicates whether a rendered tree line is one where WordPress
	 * deliberately preserves raw NUL/CR bytes that spec-following parsers
	 * substitute: tag lines (NUL survives in foreign tag names) and
	 * attribute lines (NUL/CR in values, NUL in names). Everywhere else —
	 * text, RCDATA, rawtext, comments, doctypes — WordPress applies the
	 * spec substitutions itself, so a scalar difference on those lines is
	 * a real divergence the tolerance must not mask.
	 *
	 * Operates on the escaped rendering, where the shapes are disjoint: a
	 * text line is exactly one quoted escaped string (interior quotes
	 * render as `\"`, so its content can never contain an unescaped `="`),
	 * comment, doctype, and tag lines end with `>` or ` -->`, and only an
	 * attribute line ends with `="…"`. The attribute shape is therefore
	 * tested before the comment prefix: the tokenizer permits `<` and `!`
	 * in attribute names, so an attribute line may begin with `<!--`.
	 */
	private static function scalar_tolerance_eligible_line( string $line ): bool {
		$trimmed = ltrim( $line, ' ' );
		if ( '' === $trimmed ) {
			return false;
		}
		// Text line: a single quoted escaped string. The escape loop is
		// possessive: its branches are disjoint, so backtracking can never
		// help, and PCRE's JIT stack gives out near 8KB when it tracks
		// backtrack frames anyway. Same for every escape loop below.
		if ( preg_match( '/^"(?:\\\\.|[^"\\\\])*+"$/', $trimmed ) ) {
			return false;
		}
		// Attribute line: name followed by a quoted escaped value.
		if ( preg_match( '/="(?:\\\\.|[^"\\\\])*+"$/', $trimmed ) ) {
			return true;
		}
		if ( str_starts_with( $trimmed, '<!--' ) || str_starts_with( $trimmed, '<!DOCTYPE' ) ) {
			return false;
		}
		// Template content marker.
		if ( 'content' === $trimmed ) {
			return false;
		}
		// Tag line.
		return '<' === $trimmed[0];
	}

	/**
	 * Indicates whether the spec-mandated scalar substitutions explain the
	 * entire difference between a WordPress tree line and a DOM tree line:
	 * NUL becomes U+FFFD and CR / CRLF become LF.
	 *
	 * The substitutions apply per occurrence, only where the DOM side holds
	 * the substituted form. A whole-line rewrite would also rewrite escapes
	 * the two sides agree on — a decoded `&#13;` renders as `\r` in both
	 * trees — and the tolerance would then fail to fire.
	 *
	 * Operates on the escaped rendering produced by escape_tree_scalar(),
	 * where `\` starts an escape sequence and a literal backslash is `\\`.
	 *
	 * One alignment is ambiguous: WordPress `\r\n` opposite DOM `\n` is
	 * either a raw CRLF the DOM collapsed to one LF, or a raw CR mapped to
	 * LF followed by a decoded LF both sides agree on (`\r&#10;` renders
	 * `\r\n` in WordPress and `\n\n` in the DOM). Both are legitimate, so
	 * that site backtracks. Every other step is deterministic. The step
	 * budget bounds pathological backtracking; exceeding it fails closed,
	 * reporting a mismatch rather than tolerating one.
	 */
	private static function escaped_scalar_lines_match( string $wordpress_line, string $dom_line ): bool {
		$failed = array();
		$steps  = 0;
		return self::escaped_scalar_match_at( $wordpress_line, $dom_line, 0, 0, $failed, $steps );
	}

	/**
	 * Matches a WordPress escaped line suffix against a DOM line suffix,
	 * branching at the ambiguous CR alignment and memoizing dead ends.
	 */
	private static function escaped_scalar_match_at( string $wordpress_line, string $dom_line, int $i, int $j, array &$failed, int &$steps ): bool {
		$wordpress_length = strlen( $wordpress_line );
		$dom_length       = strlen( $dom_line );
		while ( $i < $wordpress_length && $j < $dom_length ) {
			if ( ++$steps > 1000000 ) {
				return false;
			}

			if ( '\\' === $wordpress_line[ $i ] ) {
				if (
					0 === substr_compare( $wordpress_line, '\\0', $i, 2 ) &&
					$j + 3 <= $dom_length &&
					0 === substr_compare( $dom_line, "\xEF\xBF\xBD", $j, 3 )
				) {
					$i += 2;
					$j += 3;
					continue;
				}
				if (
					0 === substr_compare( $wordpress_line, '\\r', $i, 2 ) &&
					$j + 2 <= $dom_length &&
					0 === substr_compare( $dom_line, '\\n', $j, 2 )
				) {
					if (
						$i + 4 <= $wordpress_length &&
						0 === substr_compare( $wordpress_line, '\\r\\n', $i, 4 )
					) {
						$key = $i . ':' . $j;
						if ( isset( $failed[ $key ] ) ) {
							return false;
						}
						// CR maps to LF and the WordPress `\n` matches on its
						// own (a raw CR before a decoded LF), or the raw CRLF
						// pair collapsed to the one DOM LF. Lockstep first:
						// it resolves the common case without backtracking.
						if (
							self::escaped_scalar_match_at( $wordpress_line, $dom_line, $i + 2, $j + 2, $failed, $steps ) ||
							self::escaped_scalar_match_at( $wordpress_line, $dom_line, $i + 4, $j + 2, $failed, $steps )
						) {
							return true;
						}
						$failed[ $key ] = true;
						return false;
					}
					$i += 2;
					$j += 2;
					continue;
				}
				// Any other escape must match the DOM side byte for byte,
				// including both bytes of a literal `\\`.
				if (
					$i + 1 < $wordpress_length &&
					$j + 1 < $dom_length &&
					0 === substr_compare( $dom_line, $wordpress_line[ $i ] . $wordpress_line[ $i + 1 ], $j, 2 )
				) {
					$i += 2;
					$j += 2;
					continue;
				}
				return false;
			}
			if ( $wordpress_line[ $i ] !== $dom_line[ $j ] ) {
				return false;
			}
			++$i;
			++$j;
		}

		return $i === $wordpress_length && $j === $dom_length;
	}

	private static function remove_tolerated_wordpress_lines( string $wordpress_tree, array $line_tolerances ): array {
		if ( empty( $line_tolerances ) ) {
			return array(
				'tree'    => $wordpress_tree,
				'lineMap' => array(),
			);
		}

		$tolerated_lines = array();
		foreach ( $line_tolerances as $line ) {
			if ( is_int( $line ) || ctype_digit( (string) $line ) ) {
				$tolerated_lines[ (int) $line ] = true;
			}
		}

		if ( empty( $tolerated_lines ) ) {
			return array(
				'tree'    => $wordpress_tree,
				'lineMap' => array(),
			);
		}

		$lines = explode( "\n", $wordpress_tree );
		$line_map = array();
		foreach ( array_keys( $lines ) as $line_number ) {
			if ( isset( $tolerated_lines[ $line_number ] ) ) {
				unset( $lines[ $line_number ] );
			} else {
				$line_map[] = $line_number;
			}
		}

		return array(
			'tree'    => implode( "\n", $lines ),
			'lineMap' => $line_map,
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
