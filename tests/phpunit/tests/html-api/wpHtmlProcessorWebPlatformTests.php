<?php

/**
 * Unit tests covering HTML API functionality.
 *
 * This test suite runs a set of tests on the HTML API using a third-party suite of test fixtures.
 * A third-party test suite allows the HTML API's behavior to be compared against an external
 * standard. Without a third-party, there is risk of oversight or misinterpretation of the standard
 * being implemented in application code and in tests. The Web Platform Tests tree-construction
 * fixtures are used by other projects like browsers or other HTML parsers for the same purpose
 * of validating behavior against an external reference.
 *
 * See the README file at DIR_TESTDATA / web-platform-tests for details on the third-party suite.
 *
 * @package WordPress
 * @subpackage HTML-API
 *
 * @since 6.6.0
 *
 * @group html-api
 * @group html-api-web-platform-tests
 */
class Tests_HtmlApi_WebPlatformTests extends WP_UnitTestCase {
	const TREE_INDENT = '  ';

	/**
	 * Reason to skip tests which require relocating already-visited nodes.
	 *
	 * The HTML Processor visits a document in a single pass and cannot move
	 * nodes it has already visited. When the adoption agency algorithm runs,
	 * browsers may re-parent nodes found before the misnesting was discovered;
	 * this parser reports them where they were originally visited, so the
	 * constructed tree differs even though the parser state after the
	 * algorithm matches browsers exactly for everything which follows.
	 */
	const SKIP_HTML_PARSER_REPARENTS_VISITED_NODES = 'Single-pass parser: the adoption agency algorithm cannot relocate nodes which have already been visited.';

	/**
	 * Reason to skip tests in which a FORM element is closed while other
	 * elements remain open inside of it.
	 *
	 * In this case browsers remove the FORM from the stack of open elements
	 * while its still-open descendants remain in place: the FORM remains an
	 * ancestor of following content in the DOM even though no new content
	 * can reach it. A properly-nested token stream cannot express this;
	 * this parser reports following content outside of the closed FORM,
	 * mirroring the stack of open elements a browser would maintain.
	 */
	const SKIP_HTML_PARSER_CANNOT_HOLD_FORM_OPEN = 'Single-pass parser: a FORM closed while its descendants remain open stays in the document as their ancestor, which the token stream cannot express.';

	/**
	 * Reason to skip tests in which an A element which is not in table scope
	 * is removed from the stack of open elements when another A element is
	 * found.
	 *
	 * As with a closed FORM, browsers remove the A from the stack of open
	 * elements while its still-open descendants — such as the TABLE which
	 * shields it from table scope — remain in place: the A remains an
	 * ancestor in the DOM, and content foster-parented out of that TABLE
	 * lands inside of it. A properly-nested token stream cannot express
	 * this; this parser reports following content outside of the removed A,
	 * mirroring the stack of open elements a browser would maintain.
	 */
	const SKIP_HTML_PARSER_CANNOT_HOLD_REMOVED_A_OPEN = 'Single-pass parser: an A element removed from the stack of open elements while its descendants remain open stays in the document as their ancestor, which the token stream cannot express.';

	/**
	 * Skip specific tests that may not be supported or have known issues.
	 */
	const SKIP_TESTS = array(
		'adoption01/line0001'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption01/line0014'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption01/line0083'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption01/line0030'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption01/line0062'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption01/line0108'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption01/line0124'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption01/line0141'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption01/line0241'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption01/line0281'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption02/line0001'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'adoption02/line0021'       => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'html5test-com/line0129'    => 'Unimplemented: This parser treats processing instructions as comments.',
		'html5test-com/line0252'    => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'menuitem-element/line0161' => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'noscript01/line0014'       => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'template/line1091'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'template/line1595'         => self::SKIP_HTML_PARSER_CANNOT_HOLD_REMOVED_A_OPEN,
		'tests1/line0237'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line0256'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line0355'           => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests1/line0373'           => self::SKIP_HTML_PARSER_CANNOT_HOLD_REMOVED_A_OPEN,
		'tests1/line0601'           => 'Unimplemented: This parser treats processing instructions as comments.',
		'tests1/line0602'           => 'Unimplemented: Updated Processing Instruction parsing.',
		'tests1/line0640'           => 'Unimplemented: This parser treats processing instructions as comments.',
		'tests1/line0641'           => 'Unimplemented: Updated Processing Instruction parsing.',
		'tests1/line0706'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line0784'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line0850'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line0994'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line1015'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line1037'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line1061'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line1086'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line1111'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line1149'           => self::SKIP_HTML_PARSER_CANNOT_HOLD_REMOVED_A_OPEN,
		'tests1/line1387'           => self::SKIP_HTML_PARSER_CANNOT_HOLD_REMOVED_A_OPEN,
		'tests1/line1468'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line1484'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests1/line1532'           => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests1/line1559'           => self::SKIP_HTML_PARSER_CANNOT_HOLD_REMOVED_A_OPEN,
		'tests10/line0035'          => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests10/line0046'          => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests10/line0259'          => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests10/line0284'          => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests14/line0022'          => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'tests14/line0055'          => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'tests18/line0227'          => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests18/line0240'          => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests19/line0488'          => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'tests19/line0500'          => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'tests19/line1079'          => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'tests19/line1127'          => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests19/line1169'          => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests19/line1198'          => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests19/line1258'          => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests2/line0118'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests2/line0207'           => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'tests2/line0686'           => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'tests2/line0697'           => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'tests2/line0709'           => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'tests22/line0001'          => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests22/line0023'          => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests22/line0069'          => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests22/line0117'          => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests26/line0136'          => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests6/line0012'           => self::SKIP_HTML_PARSER_CANNOT_HOLD_FORM_OPEN,
		'tests8/line0133'           => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tests9/line0048'           => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests9/line0059'           => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests9/line0299'           => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tests9/line0324'           => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'tricky01/line0001'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tricky01/line0019'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tricky01/line0078'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'tricky01/line0146'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'webkit01/line0231'         => 'Unimplemented: This parser does not add missing attributes to existing HTML or BODY tags.',
		'webkit01/line0569'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'webkit01/line0584'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'webkit01/line0601'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'webkit02/line0186'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'webkit02/line0204'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'webkit02/line0224'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'webkit02/line0242'         => self::SKIP_HTML_PARSER_REPARENTS_VISITED_NODES,
		'webkit02/line0557'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'webkit02/line0590'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'webkit02/line0611'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'webkit02/line0624'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'webkit02/line0637'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'webkit02/line0652'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'webkit02/line0666'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'webkit02/line0692'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'webkit02/line0706'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'webkit02/line0732'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
		'webkit02/line0748'         => 'Unimplemented: This parser does not support customizable SELECT element content.',
	);

	/**
	 * Skip test files that exercise parser behavior unsupported by the HTML API.
	 */
	const SKIP_TEST_PREFIXES = array(
		'processing-instructions/' => 'Unimplemented: Updated Processing Instruction parsing.',
	);

	/**
	 * Verify the parsing results of the HTML Processor against the
	 * test cases in the Web Platform Tests tree-construction suite.
	 *
	 * @ticket 60227
	 *
	 * @dataProvider data_external_web_platform_tests
	 *
	 * @param string|null $fragment_context Context element in which to parse HTML, such as BODY or SVG.
	 * @param string      $html             Given test HTML.
	 * @param string      $expected_tree    Tree structure of parsed HTML.
	 */
	public function test_parse( ?string $fragment_context, string $html, string $expected_tree ) {
		try {
			$processed_tree = self::build_tree_representation( $fragment_context, $html );
		} catch ( WP_HTML_Unsupported_Exception $e ) {
			$this->markTestSkipped( "Unsupported markup: {$e->getMessage()}" );
			return;
		}

		if ( null === $processed_tree ) {
			$this->markTestSkipped( 'Test includes unsupported markup.' );
			return;
		}

		$fragment_detail = $fragment_context ? " in context <{$fragment_context}>" : '';

		/*
		 * The HTML processor does not produce html, head, body tags if the processor does not reach them.
		 * HTML tree construction will always produce these tags, the HTML API does not at this time.
		 */
		$auto_generated_html_head_body = "<html>\n  <head>\n  <body>\n\n";
		$auto_generated_head_body      = "  <head>\n  <body>\n\n";
		$auto_generated_body           = "  <body>\n\n";
		if ( str_ends_with( $expected_tree, $auto_generated_html_head_body ) && ! str_ends_with( $processed_tree, $auto_generated_html_head_body ) ) {
			if ( str_ends_with( $processed_tree, "<html>\n  <head>\n\n" ) ) {
				$processed_tree = substr_replace( $processed_tree, "  <body>\n\n", -1 );
			} elseif ( str_ends_with( $processed_tree, "<html>\n\n" ) ) {
				$processed_tree = substr_replace( $processed_tree, "  <head>\n  <body>\n\n", -1 );
			} else {
				$processed_tree = substr_replace( $processed_tree, $auto_generated_html_head_body, -1 );
			}
		} elseif ( str_ends_with( $expected_tree, $auto_generated_head_body ) && ! str_ends_with( $processed_tree, $auto_generated_head_body ) ) {
			if ( str_ends_with( $processed_tree, "<head>\n\n" ) ) {
				$processed_tree = substr_replace( $processed_tree, "  <body>\n\n", -1 );
			} else {
				$processed_tree = substr_replace( $processed_tree, $auto_generated_head_body, -1 );
			}
		} elseif ( str_ends_with( $expected_tree, $auto_generated_body ) && ! str_ends_with( $processed_tree, $auto_generated_body ) ) {
			$processed_tree = substr_replace( $processed_tree, $auto_generated_body, -1 );
		}

		$this->assertSame( $expected_tree, $processed_tree, "HTML was not processed correctly{$fragment_detail}:\n{$html}" );
	}

	/**
	 * Data provider.
	 *
	 * Tests from https://github.com/web-platform-tests/wpt/tree/master/html/syntax/parsing/resources
	 *
	 * @return array[]
	 */
	public function data_external_web_platform_tests() {
		$test_dir = DIR_TESTDATA . '/web-platform-tests/html_syntax_parsing_resources/';

		$handle = opendir( $test_dir );
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( ! stripos( $entry, '.dat' ) ) {
				continue;
			}

			foreach ( self::parse_web_platform_test_file( $test_dir . $entry ) as $k => $test ) {
				// strip .dat extension from filename
				$test_suite = substr( $entry, 0, -4 );
				$line       = str_pad( strval( $test[0] ), 4, '0', STR_PAD_LEFT );
				$test_name  = "{$test_suite}/line{$line}";

				$test_context_element = $test[1];

				if ( self::should_skip_test( $test_context_element, $test_name ) ) {
					continue;
				}

				yield $test_name => array_slice( $test, 1 );
			}
		}
		closedir( $handle );
	}

	/**
	 * Determines whether a test case should be skipped.
	 *
	 * @param string|null $test_context_element Context element for fragment parsing, or null for full document parsing.
	 * @param string      $test_name            Test name.
	 *
	 * @return bool True if the test case should be skipped. False otherwise.
	 */
	private static function should_skip_test( ?string $test_context_element, string $test_name ): bool {
		if ( null !== $test_context_element && 'body' !== $test_context_element ) {
			return true;
		}

		if ( array_key_exists( $test_name, self::SKIP_TESTS ) ) {
			return true;
		}

		foreach ( array_keys( self::SKIP_TEST_PREFIXES ) as $test_prefix ) {
			if ( str_starts_with( $test_name, $test_prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Generates the tree-like structure represented in the Web Platform Tests fixtures.
	 *
	 * @param string|null $fragment_context Context element in which to parse HTML, such as BODY or SVG.
	 * @param string      $html             Given test HTML.
	 * @return string|null Tree structure of parsed HTML, if supported, else null.
	 */
	private static function build_tree_representation( ?string $fragment_context, string $html ) {
		$processor = $fragment_context
			? WP_HTML_Processor::create_fragment( $html, "<{$fragment_context}>" )
			: WP_HTML_Processor::create_full_parser( $html );
		if ( null === $processor ) {
			throw new WP_HTML_Unsupported_Exception( "Could not create a parser with the given fragment context: {$fragment_context}.", '', 0, '', array(), array() );
		}
		$processor->enable_foster_parenting();

		/*
		 * The document tree is built from nodes of this shape and serialized
		 * once the parse completes. A realized tree is required because nodes
		 * are not always visited in document order: a foster-parented node is
		 * visited where it was found in the input HTML, after the table
		 * element which follows it in the document.
		 */
		$make_node = static function ( ?string $line ) {
			return (object) array(
				// First output line for the node, e.g. "<div>"; `null` for the root and for text nodes.
				'line'        => $line,
				// Attribute lines and self-contained text, output one level deeper than the node.
				'extra_lines' => array(),
				// Text content for text nodes; `null` for everything else.
				'text'        => null,
				// Uppercase tag name and namespace, for locating TABLE and TEMPLATE ancestors.
				'tag_name'    => null,
				'namespace'   => null,
				'children'    => array(),
			);
		};

		$root = $make_node( null );

		/*
		 * Mirrors the stack of open elements as seen through the visited
		 * tokens: tag openers which expect a closer are pushed, tag closers
		 * pop. The root node stands in for the document itself.
		 *
		 * @var array<int, object> $open_nodes
		 */
		$open_nodes = array( $root );

		/*
		 * Attaches a node to the tree.
		 *
		 * Nodes are normally appended to the deepest open element. A
		 * foster-parented node is placed where a browser would place it,
		 * repeating the parser's own walk: everything above the nearest open
		 * TABLE element belongs to the enclosing table context and is
		 * bypassed; the node is inserted immediately before that TABLE.
		 * When a TEMPLATE is found first, the node is appended inside its
		 * template contents instead.
		 *
		 * Text is merged into an immediately-preceding text node at the
		 * insertion location, as character insertion into a document does.
		 */
		$attach = static function ( $node ) use ( &$open_nodes, $processor ) {
			$parent          = end( $open_nodes );
			$insertion_index = null;

			if ( $processor->is_foster_parented() ) {
				for ( $i = count( $open_nodes ) - 1; $i > 0; $i-- ) {
					$open = $open_nodes[ $i ];
					if ( 'html' !== $open->namespace ) {
						continue;
					}

					if ( 'TEMPLATE' === $open->tag_name ) {
						$parent = $open;
						break;
					}

					if ( 'TABLE' === $open->tag_name ) {
						$parent          = $open_nodes[ $i - 1 ];
						$insertion_index = array_search( $open, $parent->children, true );
						if ( false === $insertion_index ) {
							throw new Error( 'Could not find the TABLE element before which a foster-parented node must be inserted.' );
						}
						break;
					}
				}
			}

			if ( null === $insertion_index ) {
				$insertion_index = count( $parent->children );
			}

			if (
				isset( $node->text ) &&
				$insertion_index > 0 &&
				isset( $parent->children[ $insertion_index - 1 ]->text )
			) {
				$parent->children[ $insertion_index - 1 ]->text .= $node->text;
				return;
			}

			array_splice( $parent->children, $insertion_index, 0, array( $node ) );
		};

		while ( $processor->next_token() ) {
			if ( null !== $processor->get_last_error() ) {
				break;
			}

			$token_name = $processor->get_token_name();
			$token_type = $processor->get_token_type();
			$is_closer  = $processor->is_tag_closer();

			switch ( $token_type ) {
				case '#doctype':
					$doctype      = $processor->get_doctype_info();
					$doctype_line = "<!DOCTYPE {$doctype->name}";
					if ( null !== $doctype->public_identifier || null !== $doctype->system_identifier ) {
						$doctype_line .= " \"{$doctype->public_identifier}\" \"{$doctype->system_identifier}\"";
					}
					$doctype_line .= '>';
					$attach( $make_node( $doctype_line ) );
					break;

				case '#tag':
					$namespace = $processor->get_namespace();
					$tag_name  = 'html' === $namespace
						? strtolower( $processor->get_tag() )
						: "{$namespace} {$processor->get_qualified_tag_name()}";

					if ( $is_closer ) {
						array_pop( $open_nodes );
						break;
					}

					$node            = $make_node( "<{$tag_name}>" );
					$node->tag_name  = $token_name;
					$node->namespace = $namespace;

					$attribute_names = $processor->get_attribute_names_with_prefix( '' );
					if ( $attribute_names ) {
						$sorted_attributes = array();
						foreach ( $attribute_names as $attribute_name ) {
							$sorted_attributes[ $attribute_name ] = $processor->get_qualified_attribute_name( $attribute_name );
						}

						/*
						 * Sorts attributes to match Web Platform Tests tree-construction order.
						 *
						 *  - First comes normal HTML attributes.
						 *  - Then come adjusted foreign attributes; these have spaces in their names.
						 *  - Finally come non-adjusted foreign attributes; these have a colon in their names.
						 *
						 * Example:
						 *
						 *       From: <math xlink:author definitionurl xlink:title xlink:show>
						 *     Sorted: 'definitionURL', 'xlink show', 'xlink title', 'xlink:author'
						 */
						uasort(
							$sorted_attributes,
							static function ( $a, $b ) {
								$a_has_ns = str_contains( $a, ':' );
								$b_has_ns = str_contains( $b, ':' );

								// Attributes with `:` should follow all other attributes.
								if ( $a_has_ns !== $b_has_ns ) {
									return $a_has_ns ? 1 : -1;
								}

								$a_has_sp = str_contains( $a, ' ' );
								$b_has_sp = str_contains( $b, ' ' );

								// Attributes with a namespace ' ' should come after those without.
								if ( $a_has_sp !== $b_has_sp ) {
									return $a_has_sp ? 1 : -1;
								}

								return $a <=> $b;
							}
						);

						foreach ( $sorted_attributes as $attribute_name => $display_name ) {
							$val = $processor->get_attribute( $attribute_name );
							/*
							 * Attributes with no value are `true` with the HTML API,
							 * We map use the empty string value in the tree structure.
							 */
							if ( true === $val ) {
								$val = '';
							}
							$node->extra_lines[] = "{$display_name}=\"{$val}\"";
						}
					}

					// Self-contained tags contain their inner contents as modifiable text.
					$modifiable_text = $processor->get_modifiable_text();
					if ( '' !== $modifiable_text ) {
						$node->extra_lines[] = "\"{$modifiable_text}\"";
					}

					$attach( $node );

					if ( $processor->expects_closer() ) {
						$open_nodes[] = $node;
					}
					break;

				case '#cdata-section':
				case '#text':
					$text_content = $processor->get_modifiable_text();
					if ( '' === $text_content ) {
						break;
					}
					$text_node       = $make_node( null );
					$text_node->text = $text_content;
					$attach( $text_node );
					break;

				case '#funky-comment':
					// Comments must be "<" then "!-- " then the data then " -->".
					$attach( $make_node( "<!-- {$processor->get_modifiable_text()} -->" ) );
					break;

				case '#comment':
					// Comments must be "<" then "!-- " then the data then " -->".
					$attach( $make_node( "<!-- {$processor->get_full_comment_text()} -->" ) );
					break;

				default:
					$serialized_token_type = var_export( $processor->get_token_type(), true );
					throw new Error( "Unhandled token type for tree construction: {$serialized_token_type}" );
			}
		}

		if ( null !== $processor->get_unsupported_exception() ) {
			throw $processor->get_unsupported_exception();
		}

		if ( null !== $processor->get_last_error() ) {
			throw new WP_HTML_Unsupported_Exception( "Parser error: {$processor->get_last_error()}", '', 0, '', array(), array() );
		}

		if ( $processor->paused_at_incomplete_token() ) {
			throw new WP_HTML_Unsupported_Exception( 'Paused at incomplete token.', '', 0, '', array(), array() );
		}

		$render = static function ( $node, int $depth ) use ( &$render ): string {
			if ( isset( $node->text ) ) {
				return str_repeat( self::TREE_INDENT, $depth ) . "\"{$node->text}\"\n";
			}

			$output      = '';
			$child_depth = $depth;
			if ( isset( $node->line ) ) {
				$output     .= str_repeat( self::TREE_INDENT, $depth ) . "{$node->line}\n";
				$child_depth = $depth + 1;
				foreach ( $node->extra_lines as $extra_line ) {
					$output .= str_repeat( self::TREE_INDENT, $depth + 1 ) . "{$extra_line}\n";
				}
			}

			// A TEMPLATE element holds its children inside its template contents.
			if ( 'TEMPLATE' === $node->tag_name && 'html' === $node->namespace ) {
				$output .= str_repeat( self::TREE_INDENT, $child_depth ) . "content\n";
				++$child_depth;
			}

			foreach ( $node->children as $child ) {
				$output .= $render( $child, $child_depth );
			}

			return $output;
		};

		// Tests always end with a trailing newline.
		return $render( $root, 0 ) . "\n";
	}

	/**
	 * Convert a given Web Platform Tests fixture file into a series of test cases.
	 *
	 * @param string $filename Path to `.dat` file with test cases.
	 *
	 * @return Generator<int, array{
	 *     non-negative-int, // Line number.
	 *     string|null,      // HTML fragment context element.
	 *     string,           // HTML.
	 *     string,           // DOM structure it represents.
	 * }> Test cases.
	 */
	public static function parse_web_platform_test_file( $filename ) {
		$handle = fopen( $filename, 'r', false );

		/**
		 * Represents which section of the test case is being parsed.
		 *
		 * @var string|null
		 */
		$state = null;

		$line_number          = 0;
		$test_html            = '';
		$test_dom             = '';
		$test_context_element = null;
		$test_script_flag     = false;
		$test_line_number     = 0;

		while ( false !== ( $line = fgets( $handle ) ) ) {
			++$line_number;

			if ( '#' === $line[0] ) {
				// Finish section.
				if ( "#data\n" === $line ) {
					/*
					 * Yield when switching from a previous state.
					 * Do not yield tests with the scripting flag enabled. The scripting flag
					 * is always disabled in the HTML API.
					 */
					if ( $state && ! $test_script_flag ) {
						yield array(
							$test_line_number,
							$test_context_element,
							// Remove the trailing newline
							substr( $test_html, 0, -1 ),
							$test_dom,
						);
					}

					// Finish previous test.
					$test_line_number     = $line_number;
					$test_html            = '';
					$test_dom             = '';
					$test_context_element = null;
					$test_script_flag     = false;
				}
				if ( "#script-on\n" === $line ) {
					$test_script_flag = true;
				}

				$state = trim( substr( $line, 1 ) );

				continue;
			}

			switch ( $state ) {
				/*
				 * Each test must begin with a string "#data" followed by a newline (LF). All
				 * subsequent lines until a line that says "#errors" are the test data and must be
				 * passed to the system being tested unchanged, except with the final newline (on the
				 * last line) removed.
				 */
				case 'data':
					$test_html .= $line;
					break;

				/*
				 * Then there *may* be a line that says "#document-fragment", which must
				 * be followed by a newline (LF), followed by a string of characters that
				 * indicates the context element, followed by a newline (LF). If the
				 * string of characters starts with "svg ", the context element is in
				 * the SVG namespace and the substring after "svg " is the local name.
				 * If the string of characters starts with "math ", the context element
				 * is in the MathML namespace and the substring after "math " is the
				 * local name. Otherwise, the context element is in the HTML namespace
				 * and the string is the local name. If this line is present the "#data"
				 * must be parsed using the HTML fragment parsing algorithm with the
				 * context element as context.
				 */
				case 'document-fragment':
					$test_context_element = trim( $line );
					break;

				/*
				 * Then there must be a line that says "#document", which must be followed by a dump of
				 * the tree of the parsed DOM. Each node must be represented by a single line. Each line
				 * must start with "| ", followed by two spaces per parent node that the node has before
				 * the root document node.
				 *
				 * - Element nodes must be represented by a "<" then the tag name string ">", and all the attributes must be given, sorted lexicographically by UTF-16 code unit according to their attribute name string, on subsequent lines, as if they were children of the element node.
				 * - Attribute nodes must have the attribute name string, then an "=" sign, then the attribute value in double quotes (").
				 * - Text nodes must be the string, in double quotes. Newlines aren't escaped.
				 * - Comments must be "<" then "!-- " then the data then " -->".
				 * - DOCTYPEs must be "<!DOCTYPE " then the name then if either of the system id or public id is non-empty a space, public id in double-quotes, another space an the system id in double-quotes, and then in any case ">".
				 * - Processing instructions must be "<?", then the target, then a space, then the data and then ">". (The HTML parser cannot emit processing instructions, but scripts can, and the WebVTT to DOM rules can emit them.)
				 * - Template contents are represented by the string "content" with the children below it.
				 */
				case 'document':
					if ( '|' === $line[0] ) {
						$test_dom .= substr( $line, 2 );
					} else {
						// This is a text node that includes unescaped newlines.
						// Everything else should be singles lines starting with "| ".
						$test_dom .= $line;
					}
					break;
			}
		}

		fclose( $handle );

		// Return the last result when reaching the end of the file.
		return array(
			$test_line_number,
			$test_context_element,
			// Remove the trailing newline
			substr( $test_html, 0, -1 ),
			$test_dom,
		);
	}
}
