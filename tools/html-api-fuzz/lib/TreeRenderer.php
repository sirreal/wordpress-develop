<?php
namespace HtmlApiFuzz;

class TreeRenderer {
	const STATUS_OK          = 'ok';
	const STATUS_UNSUPPORTED = 'unsupported';
	const STATUS_ERROR       = 'error';

	public static function render_wordpress( string $html, string $mode, array $limits = array() ): array {
		HtmlApiBootstrap::load();
		$max_tokens = $limits['maxTokens'] ?? 2000;
		$processor  = Generator::MODE_FULL_DOCUMENT === $mode
			? \WP_HTML_Processor::create_full_parser( $html )
			: \WP_HTML_Processor::create_fragment( $html );

		if ( null === $processor ) {
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

				if ( $was_text && '#text' !== $token_name ) {
					if ( '' !== $text_node ) {
						$output .= "{$text_node}\"\n";
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
						break;

					case '#tag':
						$namespace = $processor->get_namespace();
						$tag_name  = 'html' === $namespace
							? strtolower( (string) $processor->get_tag() )
							: "{$namespace} {$processor->get_qualified_tag_name()}";

						if ( $is_closer ) {
							--$indent_level;
							if ( 'html' === $namespace && 'TEMPLATE' === $token_name ) {
								--$indent_level;
							}
							break;
						}

						$tag_indent = $indent_level;
						if ( $processor->expects_closer() ) {
							++$indent_level;
						}

						$output .= str_repeat( '  ', $tag_indent ) . "<{$tag_name}>\n";
						$output .= self::render_wp_attributes( $processor, $tag_indent + 1 );

						$modifiable_text = $processor->get_modifiable_text();
						if ( '' !== $modifiable_text ) {
							$output .= str_repeat( '  ', $tag_indent + 1 ) . '"' . self::escape_tree_scalar( $modifiable_text ) . "\"\n";
						}

						if ( 'html' === $namespace && 'TEMPLATE' === $token_name ) {
							$output .= str_repeat( '  ', $indent_level ) . "content\n";
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
						break;

					case '#comment':
						$output .= str_repeat( '  ', $indent_level ) . '<!-- ' . self::escape_tree_scalar( $processor->get_full_comment_text() ) . " -->\n";
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

		if ( null !== $processor->get_unsupported_exception() ) {
			return array(
				'status'      => self::STATUS_UNSUPPORTED,
				'tree'        => $output,
				'tokenCount'  => $tokens,
				'unsupported' => self::unsupported_details( $processor->get_unsupported_exception() ),
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
		}

		return array(
			'status'     => self::STATUS_OK,
			'tree'       => $output . "\n",
			'tokenCount' => $tokens,
		);
	}

	private static function render_wp_attributes( \WP_HTML_Processor $processor, int $indent_level ): string {
		$attribute_names = $processor->get_attribute_names_with_prefix( '' );
		if ( ! $attribute_names ) {
			return '';
		}

		$sorted = array();
		foreach ( $attribute_names as $attribute_name ) {
			$sorted[ $attribute_name ] = $processor->get_qualified_attribute_name( $attribute_name );
		}
		uasort( $sorted, array( __CLASS__, 'compare_attribute_display_names' ) );

		$output = '';
		foreach ( $sorted as $attribute_name => $display_name ) {
			$value = $processor->get_attribute( $attribute_name );
			if ( true === $value ) {
				$value = '';
			}
			$output .= str_repeat( '  ', $indent_level ) . "{$display_name}=\"" . self::escape_tree_scalar( (string) $value ) . "\"\n";
		}
		return $output;
	}

	public static function render_dom( string $html, string $mode, array $limits = array() ): array {
		if ( ! class_exists( 'Dom\\HTMLDocument' ) ) {
			return array(
				'status'       => self::STATUS_ERROR,
				'error'        => 'Dom\\HTMLDocument is not available. PHP 8.4+ with ext-dom is required.',
				'failureClass' => 'oracle-unavailable',
			);
		}

		$max_nodes = $limits['maxNodes'] ?? 3000;
		$parse_html = $html;
		if ( Generator::MODE_FRAGMENT_BODY === $mode ) {
			$parse_html = '<!DOCTYPE html><html><head></head><body>' . $html . '</body></html>';
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$document = \Dom\HTMLDocument::createFromString( $parse_html, LIBXML_NOERROR );
		} catch ( \Throwable $e ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return array(
				'status'       => self::STATUS_ERROR,
				'error'        => $e->getMessage(),
				'throwable'    => get_class( $e ),
				'failureClass' => 'oracle-parse-error',
			);
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$node_count = 0;
		$output     = '';
		try {
			if ( Generator::MODE_FRAGMENT_BODY === $mode ) {
				$body = $document->getElementsByTagName( 'body' )->item( 0 );
				if ( null === $body ) {
					return array(
						'status'       => self::STATUS_ERROR,
						'error'        => 'Dom\\HTMLDocument did not produce a body element for fragment wrapper.',
						'failureClass' => 'oracle-renderer-error',
					);
				}
				foreach ( $body->childNodes as $child ) {
					$output .= self::render_dom_node( $child, 0, $node_count, $max_nodes );
				}
			} else {
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
			throw $e;
		}

		return array(
			'status'    => self::STATUS_OK,
			'tree'      => $output . "\n",
			'nodeCount' => $node_count,
		);
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
		$output   = str_repeat( '  ', $indent_level ) . "<{$tag_name}>\n";
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

	private static function render_dom_template_children( $node, int $indent_level, int &$node_count, int $max_nodes ): string {
		$output = '';
		foreach ( $node->childNodes as $child ) {
			$output .= self::render_dom_node( $child, $indent_level, $node_count, $max_nodes );
		}
		if ( '' !== $output ) {
			return $output;
		}

		$inner_html = $node->innerHTML ?? '';
		if ( '' === $inner_html ) {
			return '';
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$template_document = \Dom\HTMLDocument::createFromString( '<!DOCTYPE html><html><head></head><body>' . $inner_html . '</body></html>', LIBXML_NOERROR );
		} catch ( \Throwable $e ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			throw new \RuntimeException( 'Could not render DOM template innerHTML: ' . $e->getMessage(), 0, $e );
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $template_document->getElementsByTagName( 'body' )->item( 0 );
		if ( null === $body ) {
			return '';
		}

		$output = '';
		foreach ( $body->childNodes as $child ) {
			$output .= self::render_dom_node( $child, $indent_level, $node_count, $max_nodes );
		}
		return $output;
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
			$attrs[] = array(
				'name'  => self::dom_attribute_display_name( $attr ),
				'value' => $attr->nodeValue,
			);
		}

		usort(
			$attrs,
			static function ( $a, $b ) {
				return TreeRenderer::compare_attribute_display_names( $a['name'], $b['name'] );
			}
		);

		$output = '';
		foreach ( $attrs as $attr ) {
			$output .= str_repeat( '  ', $indent_level ) . $attr['name'] . '="' . self::escape_tree_scalar( (string) $attr['value'] ) . "\"\n";
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

	public static function compare_trees( string $wordpress_tree, string $dom_tree ): array {
		$adjusted_wordpress = self::apply_wrapper_tolerance( $wordpress_tree, $dom_tree );
		if ( $adjusted_wordpress === $dom_tree ) {
			return array(
				'ok' => true,
			);
		}

		return array(
			'ok'              => false,
			'firstDifference' => self::first_difference( $adjusted_wordpress, $dom_tree ),
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

	private static function first_difference( string $left, string $right ): array {
		$left_lines  = explode( "\n", $left );
		$right_lines = explode( "\n", $right );
		$max         = max( count( $left_lines ), count( $right_lines ) );
		$left_paths  = self::line_paths( $left_lines );
		$right_paths = self::line_paths( $right_lines );

		for ( $i = 0; $i < $max; ++$i ) {
			$l = $left_lines[ $i ] ?? null;
			$r = $right_lines[ $i ] ?? null;
			if ( $l !== $r ) {
				$first_byte_offset = self::first_different_byte_offset( $l, $r );
				return array(
					'line'                                  => $i + 1,
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

	public static function normalize_tree_line( ?string $line ): ?string {
		if ( null === $line ) {
			return null;
		}
		$trimmed = trim( $line );
		if ( preg_match( '/^([^=]+)="(?:\\\\.|[^"\\\\])*"$/s', $trimmed, $m ) ) {
			return $m[1] . '="<value>"';
		}
		$line = preg_replace( '/"(?:\\\\.|[^"\\\\])*"/s', '"<value>"', $line );
		$line = preg_replace( '/<!--.*-->/s', '<!-- <comment> -->', $line );
		return trim( (string) $line );
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
