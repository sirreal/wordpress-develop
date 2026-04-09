<?php

class WP_CSS_Processor {
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
