<?php

class WP_CSS_Processor {
	/**
	 * The original CSS input string.
	 *
	 * @var string
	 */
	private string $css;

	/**
	 * The underlying token processor.
	 *
	 * @var WP_CSS_Token_Processor
	 */
	private WP_CSS_Token_Processor $processor;

	/**
	 * Queued byte-range replacements, applied by get_updated_css().
	 *
	 * Each entry is an array with keys: 'start', 'length', 'text'.
	 *
	 * @var array[]
	 */
	private array $lexical_updates = array();

	/**
	 * Declarations to append, each with 'name' and 'value' keys.
	 *
	 * @var array[]
	 */
	private array $appended_declarations = array();

	/**
	 * Decoded property name of the current declaration, or null.
	 *
	 * @var string|null
	 */
	private ?string $current_name = null;

	/**
	 * Byte offset of the property name start.
	 *
	 * @var int|null
	 */
	private ?int $declaration_start = null;

	/**
	 * Byte offset after the trailing `;` or after the value end.
	 *
	 * @var int|null
	 */
	private ?int $declaration_end = null;

	/**
	 * Byte offset immediately after the colon token.
	 *
	 * @var int|null
	 */
	private ?int $after_colon = null;

	/**
	 * Byte offset of the first non-whitespace value token, or null for empty values.
	 *
	 * @var int|null
	 */
	private ?int $value_start = null;

	/**
	 * Byte offset after the last non-whitespace value token, or null for empty values.
	 *
	 * @var int|null
	 */
	private ?int $value_end = null;

	/**
	 * Pending mutation for the current declaration, or null.
	 *
	 * @var array|null
	 */
	private ?array $pending_mutation = null;

	/**
	 * Private constructor. Use create_declaration_list() or parse_a_rule().
	 *
	 * @param string                 $css       The CSS input.
	 * @param WP_CSS_Token_Processor $processor The token processor.
	 */
	private function __construct( string $css, WP_CSS_Token_Processor $processor ) {
		$this->css       = $css;
		$this->processor = $processor;
	}

	/**
	 * Creates a processor for iterating and mutating declarations.
	 *
	 * @param string $css The CSS declaration list (e.g. contents of a style block).
	 * @return self|null The processor, or null on invalid encoding.
	 */
	public static function create_declaration_list( string $css ): ?self {
		$processor = WP_CSS_Token_Processor::create( $css );
		if ( null === $processor ) {
			return null;
		}
		return new self( $css, $processor );
	}

	/**
	 * Advances to the next declaration in the list.
	 *
	 * Implements "consume a list of declarations" from CSS Syntax Level 3,
	 * stopping at each valid declaration. At-rules are consumed but skipped.
	 * Invalid declarations trigger error recovery and are skipped.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-a-list-of-declarations
	 *
	 * @return bool True if a declaration was found, false at EOF.
	 */
	public function next_declaration(): bool {
		$this->commit_pending_mutation();

		// Reset declaration state.
		$this->current_name      = null;
		$this->declaration_start = null;
		$this->declaration_end   = null;
		$this->after_colon       = null;
		$this->value_start       = null;
		$this->value_end         = null;

		while ( $this->processor->next_token() ) {
			$type = $this->processor->get_token_type();

			// Whitespace, comments, and semicolons: do nothing.
			if (
				WP_CSS_Token_Processor::TOKEN_WHITESPACE === $type ||
				WP_CSS_Token_Processor::TOKEN_COMMENT === $type ||
				WP_CSS_Token_Processor::TOKEN_SEMICOLON === $type
			) {
				continue;
			}

			// At-keyword: consume an at-rule, do not yield.
			if ( WP_CSS_Token_Processor::TOKEN_AT_KEYWORD === $type ) {
				self::consume_at_rule( $this->processor );
				continue;
			}

			// Ident token: attempt to consume a declaration.
			if ( WP_CSS_Token_Processor::TOKEN_IDENT === $type ) {
				$name       = $this->processor->get_token_value();
				$decl_start = $this->processor->get_token_start();

				// Skip whitespace/comments to find the colon.
				if ( ! self::next_non_whitespace_comment_token( $this->processor ) ) {
					// EOF without colon.
					return false;
				}

				if ( WP_CSS_Token_Processor::TOKEN_COLON !== $this->processor->get_token_type() ) {
					// No colon: not a valid declaration.
					if ( WP_CSS_Token_Processor::TOKEN_SEMICOLON !== $this->processor->get_token_type() ) {
						self::consume_the_remnants_of_a_bad_declaration( $this->processor );
					}
					continue;
				}

				$colon_end = $this->processor->get_token_start() + $this->processor->get_token_length();

				// Consume the value, tracking byte offsets.
				$value_start    = null;
				$value_end      = null;
				$semicolon_end  = null;

				while ( $this->processor->next_token() ) {
					$vtype = $this->processor->get_token_type();

					if ( WP_CSS_Token_Processor::TOKEN_SEMICOLON === $vtype ) {
						$semicolon_end = $this->processor->get_token_start() + $this->processor->get_token_length();
						break;
					}

					if (
						WP_CSS_Token_Processor::TOKEN_WHITESPACE === $vtype ||
						WP_CSS_Token_Processor::TOKEN_COMMENT === $vtype
					) {
						continue;
					}

					if ( null === $value_start ) {
						$value_start = $this->processor->get_token_start();
					}

					self::consume_component_value( $this->processor );

					$value_end = $this->processor->get_token_start() + $this->processor->get_token_length();
				}

				// Store declaration state.
				$this->current_name      = $name;
				$this->declaration_start = $decl_start;
				$this->after_colon       = $colon_end;
				$this->value_start       = $value_start;
				$this->value_end         = $value_end;
				$this->declaration_end   = $semicolon_end ?? $value_end ?? $colon_end;

				return true;
			}

			// Anything else: parse error. Consume until ; or EOF.
			self::consume_the_remnants_of_a_bad_declaration( $this->processor );
		}

		return false;
	}

	/**
	 * Returns the property name of the current declaration.
	 *
	 * @return string|null The decoded property name, or null.
	 */
	public function get_name(): ?string {
		return $this->current_name;
	}

	/**
	 * Returns the component values string of the current declaration.
	 *
	 * Leading and trailing whitespace/comments are trimmed. Comments
	 * between value tokens are preserved.
	 *
	 * @return string|null The value string, empty string for empty values, or null.
	 */
	public function get_value(): ?string {
		if ( null === $this->current_name ) {
			return null;
		}
		if ( null === $this->value_start ) {
			return '';
		}
		return substr( $this->css, $this->value_start, $this->value_end - $this->value_start );
	}

	/**
	 * Queues a replacement of the current declaration's value.
	 *
	 * The new value is validated for structural safety: bare semicolons,
	 * unmatched closing braces, and unbalanced blocks are rejected.
	 *
	 * If both set_value() and remove() are called on the same declaration,
	 * the last call wins.
	 *
	 * @param string $value The new CSS value text.
	 * @return bool True if accepted, false on validation failure or no current declaration.
	 */
	public function set_value( string $value ): bool {
		if ( null === $this->current_name ) {
			return false;
		}
		if ( ! self::is_valid_declaration_value( $value ) ) {
			return false;
		}
		$this->pending_mutation = array(
			'type'  => 'set_value',
			'value' => $value,
		);
		return true;
	}

	/**
	 * Queues removal of the current declaration.
	 *
	 * The declaration bytes including the trailing semicolon (if present)
	 * are removed. If both set_value() and remove() are called on the
	 * same declaration, the last call wins.
	 *
	 * @return bool True if accepted, false when not on a declaration.
	 */
	public function remove(): bool {
		if ( null === $this->current_name ) {
			return false;
		}
		$this->pending_mutation = array( 'type' => 'remove' );
		return true;
	}

	/**
	 * Queues a new declaration to be appended after all existing content.
	 *
	 * Both the name and value are validated. The name must tokenize as a
	 * single CSS ident. The value must be structurally safe.
	 *
	 * @param string $name  The property name.
	 * @param string $value The CSS value text.
	 * @return bool True if accepted, false on validation failure.
	 */
	public function append_declaration( string $name, string $value ): bool {
		if ( ! self::is_valid_declaration_value( $value ) ) {
			return false;
		}

		$name_processor = WP_CSS_Token_Processor::create( $name );
		if ( null === $name_processor || ! $name_processor->next_token() ) {
			return false;
		}
		if ( WP_CSS_Token_Processor::TOKEN_IDENT !== $name_processor->get_token_type() ) {
			return false;
		}
		$decoded_name = $name_processor->get_token_value();
		if ( $name_processor->next_token() ) {
			return false;
		}

		$this->appended_declarations[] = array(
			'name'  => WP_CSS_Builder::ident( $decoded_name ),
			'value' => $value,
		);
		return true;
	}

	/**
	 * Returns the CSS with all queued mutations applied.
	 *
	 * @return string The updated CSS string.
	 */
	public function get_updated_css(): string {
		$this->commit_pending_mutation();

		if ( ! empty( $this->lexical_updates ) ) {
			usort(
				$this->lexical_updates,
				static function ( $a, $b ) {
					return $a['start'] - $b['start'];
				}
			);

			$output               = '';
			$bytes_already_copied = 0;

			foreach ( $this->lexical_updates as $update ) {
				$output              .= substr( $this->css, $bytes_already_copied, $update['start'] - $bytes_already_copied );
				$output              .= $update['text'];
				$bytes_already_copied = $update['start'] + $update['length'];
			}

			$output .= substr( $this->css, $bytes_already_copied );
		} else {
			$output = $this->css;
		}

		foreach ( $this->appended_declarations as $decl ) {
			if ( '' !== $output && ';' !== substr( $output, -1 ) ) {
				$output .= ';';
			}
			$output .= ' ' . $decl['name'] . ': ' . $decl['value'];
		}

		return $output;
	}

	/**
	 * Commits the pending mutation for the current declaration to the lexical updates list.
	 */
	private function commit_pending_mutation(): void {
		if ( null === $this->pending_mutation ) {
			return;
		}

		$mutation              = $this->pending_mutation;
		$this->pending_mutation = null;

		if ( 'remove' === $mutation['type'] ) {
			$this->lexical_updates[] = array(
				'start'  => $this->declaration_start,
				'length' => $this->declaration_end - $this->declaration_start,
				'text'   => '',
			);
			return;
		}

		if ( 'set_value' === $mutation['type'] ) {
			if ( null !== $this->value_start ) {
				$this->lexical_updates[] = array(
					'start'  => $this->value_start,
					'length' => $this->value_end - $this->value_start,
					'text'   => $mutation['value'],
				);
			} else {
				// Empty value: insert after the colon.
				$this->lexical_updates[] = array(
					'start'  => $this->after_colon,
					'length' => 0,
					'text'   => ' ' . $mutation['value'],
				);
			}
		}
	}

	/**
	 * Validates that a string is structurally safe as a declaration value.
	 *
	 * Rejects values containing bare semicolons, unmatched closing braces,
	 * or unbalanced blocks that could break out of the declaration or
	 * enclosing rule context.
	 *
	 * @param string $css The candidate value text.
	 * @return bool Whether the value is structurally safe.
	 */
	private static function is_valid_declaration_value( string $css ): bool {
		$processor = WP_CSS_Token_Processor::create( $css );
		if ( null === $processor ) {
			return false;
		}

		$depth = 0;

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if (
				WP_CSS_Token_Processor::TOKEN_LEFT_PAREN === $type ||
				WP_CSS_Token_Processor::TOKEN_LEFT_BRACKET === $type ||
				WP_CSS_Token_Processor::TOKEN_LEFT_BRACE === $type ||
				WP_CSS_Token_Processor::TOKEN_FUNCTION === $type
			) {
				++$depth;
				continue;
			}

			if (
				WP_CSS_Token_Processor::TOKEN_RIGHT_PAREN === $type ||
				WP_CSS_Token_Processor::TOKEN_RIGHT_BRACKET === $type
			) {
				--$depth;
				continue;
			}

			if ( WP_CSS_Token_Processor::TOKEN_RIGHT_BRACE === $type ) {
				if ( $depth <= 0 ) {
					// Unmatched } would escape an enclosing block.
					return false;
				}
				--$depth;
				continue;
			}

			if ( 0 === $depth && WP_CSS_Token_Processor::TOKEN_SEMICOLON === $type ) {
				// Bare semicolon would split the declaration.
				return false;
			}
		}

		return 0 === $depth;
	}

	/**
	 * Implements "parse a rule" from CSS Syntax Level 3.
	 *
	 * Returns true if the input contains exactly one CSS rule
	 * (at-rule or qualified rule), false for syntax errors.
	 *
	 * > 5.3.5. Parse a rule
	 * > To parse a rule from input:
	 * > 1. Normalize input, and set input to the result.
	 * > 2. While the next input token from input is a <whitespace-token>, consume the next input token from input.
	 * > 3. If the next input token from input is an <EOF-token>, return a syntax error.
	 * >    Otherwise, if the next input token from input is an <at-keyword-token>, consume an at-rule from input, and let rule be the return value.
	 * >    Otherwise, consume a qualified rule from input and let rule be the return value. If nothing was returned, return a syntax error.
	 * > 4. While the next input token from input is a <whitespace-token>, consume the next input token from input.
	 * > 5. If the next input token from input is an <EOF-token>, return rule. Otherwise, return a syntax error.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#parse-a-rule
	 *
	 * @param string $css The CSS input.
	 * @return bool Whether the input is a single valid CSS rule.
	 */
	public static function parse_a_rule( string $css ): bool {
		$processor = WP_CSS_Token_Processor::create( $css );
		if ( null === $processor ) {
			return false;
		}

		// Step 2: Discard whitespace and comments.
		if ( ! self::next_non_whitespace_comment_token( $processor ) ) {
			// Step 3: EOF → syntax error.
			return false;
		}

		if ( WP_CSS_Token_Processor::TOKEN_AT_KEYWORD === $processor->get_token_type() ) {
			// Step 4: Consume an at-rule.
			self::consume_at_rule( $processor );
		} else {
			// Step 5: Consume a qualified rule.
			if ( ! self::consume_qualified_rule( $processor ) ) {
				return false;
			}
		}

		// Steps 6–7: Discard whitespace/comments, then expect EOF.
		if ( self::next_non_whitespace_comment_token( $processor ) ) {
			// Non-EOF after the rule → syntax error.
			return false;
		}

		return true;
	}

	/**
	 * Advances past whitespace and comment tokens.
	 *
	 * Returns true if a non-whitespace/non-comment token was found,
	 * false if EOF was reached.
	 */
	private static function next_non_whitespace_comment_token( WP_CSS_Token_Processor $processor ): bool {
		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();
			if (
				WP_CSS_Token_Processor::TOKEN_WHITESPACE !== $type &&
				WP_CSS_Token_Processor::TOKEN_COMMENT !== $type
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Consumes an at-rule from the token stream.
	 *
	 * The processor must be positioned on an at-keyword token.
	 * Per spec, at-rules are always returned even on EOF (parse error noted).
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-an-at-rule
	 */
	private static function consume_at_rule( WP_CSS_Token_Processor $processor ): void {
		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if ( WP_CSS_Token_Processor::TOKEN_SEMICOLON === $type ) {
				return;
			}

			if ( WP_CSS_Token_Processor::TOKEN_LEFT_BRACE === $type ) {
				self::consume_simple_block( $processor, WP_CSS_Token_Processor::TOKEN_RIGHT_BRACE );
				return;
			}

			// Consume component values so that `;` and `{` inside
			// paired tokens ((), [], functions) are not misidentified.
			self::consume_component_value( $processor );
		}
		// EOF: at-rule is still returned per spec.
	}

	/**
	 * Consumes a qualified rule from the token stream.
	 *
	 * The processor must be positioned on the first prelude token.
	 * Returns true if a qualified rule was found (a block was consumed),
	 * false if EOF was reached without finding a block.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-a-qualified-rule
	 */
	private static function consume_qualified_rule( WP_CSS_Token_Processor $processor ): bool {
		/*
		 * The processor is already positioned on the first token.
		 * Loop starting from the current token, then continue with next_token().
		 */
		do {
			if ( WP_CSS_Token_Processor::TOKEN_LEFT_BRACE === $processor->get_token_type() ) {
				self::consume_simple_block( $processor, WP_CSS_Token_Processor::TOKEN_RIGHT_BRACE );
				return true;
			}

			// Consume component values so that `{` inside
			// paired tokens ((), [], functions) is not misidentified.
			self::consume_component_value( $processor );
		} while ( $processor->next_token() );

		// EOF without finding a block → return nothing (syntax error).
		return false;
	}

	/**
	 * Consumes a component value from the token stream.
	 *
	 * The processor must be positioned on the current token. If the token
	 * opens a paired block — `(`, `[`, or a function token — the entire
	 * block is consumed up to the matching close token or EOF.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-a-component-value
	 */
	private static function consume_component_value( WP_CSS_Token_Processor $processor ): void {
		$type = $processor->get_token_type();

		if ( WP_CSS_Token_Processor::TOKEN_LEFT_PAREN === $type || WP_CSS_Token_Processor::TOKEN_FUNCTION === $type ) {
			self::consume_simple_block( $processor, WP_CSS_Token_Processor::TOKEN_RIGHT_PAREN );
		} elseif ( WP_CSS_Token_Processor::TOKEN_LEFT_BRACKET === $type ) {
			self::consume_simple_block( $processor, WP_CSS_Token_Processor::TOKEN_RIGHT_BRACKET );
		} elseif ( WP_CSS_Token_Processor::TOKEN_LEFT_BRACE === $type ) {
			self::consume_simple_block( $processor, WP_CSS_Token_Processor::TOKEN_RIGHT_BRACE );
		}
	}

	/**
	 * Consumes the remnants of a bad declaration from the token stream.
	 *
	 * The processor must be positioned on the current token.
	 * Consumes it as a component value, then advances consuming
	 * component values until a semicolon or EOF is reached.
	 *
	 * @param WP_CSS_Token_Processor $processor The token processor.
	 */
	private static function consume_the_remnants_of_a_bad_declaration( WP_CSS_Token_Processor $processor ): void {
		self::consume_component_value( $processor );
		while ( $processor->next_token() ) {
			if ( WP_CSS_Token_Processor::TOKEN_SEMICOLON === $processor->get_token_type() ) {
				return;
			}
			self::consume_component_value( $processor );
		}
	}

	/**
	 * Consumes a simple block from the token stream.
	 *
	 * The processor must be positioned on the opening token.
	 * Consumes tokens until the matching ending token or EOF.
	 * Nested component values (paired tokens) are consumed recursively.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-a-simple-block
	 *
	 * @param WP_CSS_Token_Processor $processor    The token processor.
	 * @param string                 $ending_token The token type that closes this block.
	 */
	private static function consume_simple_block( WP_CSS_Token_Processor $processor, string $ending_token ): void {
		while ( $processor->next_token() ) {
			if ( $ending_token === $processor->get_token_type() ) {
				return;
			}

			self::consume_component_value( $processor );
		}
		// EOF: block is still returned per spec.
	}
}
