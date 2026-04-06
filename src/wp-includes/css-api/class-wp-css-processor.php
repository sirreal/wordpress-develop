<?php

class WP_CSS_Processor {
	/**
	 * Implements "parse a rule" from CSS Syntax Level 3.
	 *
	 * Returns true if the input contains exactly one CSS rule
	 * (at-rule or qualified rule), false for syntax errors.
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
				self::consume_simple_block( $processor );
				return;
			}
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
		// The processor is already on the first token of the prelude.
		// Check if it's already a left brace.
		if ( WP_CSS_Token_Processor::TOKEN_LEFT_BRACE === $processor->get_token_type() ) {
			self::consume_simple_block( $processor );
			return true;
		}

		while ( $processor->next_token() ) {
			if ( WP_CSS_Token_Processor::TOKEN_LEFT_BRACE === $processor->get_token_type() ) {
				self::consume_simple_block( $processor );
				return true;
			}
		}

		// EOF without finding a block → return nothing (syntax error).
		return false;
	}

	/**
	 * Consumes a simple block from the token stream.
	 *
	 * The processor must be positioned on a left brace token.
	 * Consumes tokens until the matching right brace or EOF,
	 * tracking nested brace pairs.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-a-simple-block
	 */
	private static function consume_simple_block( WP_CSS_Token_Processor $processor ): void {
		$depth = 1;

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if ( WP_CSS_Token_Processor::TOKEN_LEFT_BRACE === $type ) {
				++$depth;
			} elseif ( WP_CSS_Token_Processor::TOKEN_RIGHT_BRACE === $type ) {
				--$depth;
				if ( 0 === $depth ) {
					return;
				}
			}
		}
		// EOF: block is still returned per spec.
	}
}
