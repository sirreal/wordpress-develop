<?php
/**
 * HTML API: WP_CSS_Complex_Selector_List class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since {WP_VERSION}
 */

/**
 * Core class used by the {@see WP_HTML_Processor} to parse and match CSS selectors.
 *
 * This class is designed for internal use by the HTML processor.
 *
 * For usage, see {@see WP_HTML_Processor::select()}.
 *
 * This class is instantiated via the {@see WP_CSS_Complex_Selector_List::from_selectors()} method.
 * It takes a CSS selector string and returns an instance of itself or `null` if the selector
 * is invalid or unsupported.
 *
 * A subset of the CSS selector grammar is supported. The grammar is defined in the CSS Syntax
 * specification, which is available at {@link https://www.w3.org/TR/selectors/#grammar}.
 *
 * This class is roughly analogous to the <selector-list> in the grammar.
 * See {@see WP_CSS_Compound_Selector_List} for more details on the grammar.
 *
 * This class supports the same selector syntax as {@see WP_CSS_Compound_Selector_List} as well as
 * the following combinators:
 *   - Descendant (`ancestor descendant`)
 *   - Child (`parent > child`)
 *
 * Combinators may only be used with type selectors in the non-final position, for example:
 *   - `div [type=input]` is valid because the `div` type selector appears in a non-final position.
 *   - `[disabled] option` is NOT valid, because the `[disabled]` attribute selector appears
 *     in a non-final position.
 *
 * These combinators are not supported:
 *   - Next sibling (`former-sibling + next-sibling`)
 *   - Subsequent sibling (`former-sibling ~ subsequent-sibling`)
 *
 * @since {WP_VERSION}
 *
 * @access private
 */
class WP_CSS_Complex_Selector_List extends WP_CSS_Compound_Selector_List {
	/**
	 * Parses CSS selector tokens to create a selector instance.
	 *
	 * To create an instance of this class, use the {@see WP_CSS_Compound_Selector_List::from_selectors()} method.
	 *
	 * @param WP_CSS_Selector_Token_Stream $tokens The selector token stream.
	 * @return static|null The selector instance, or null if the parse was unsuccessful.
	 */
	public static function parse( WP_CSS_Selector_Token_Stream $tokens ) {
		$bookmark = $tokens->bookmark();
		$selector = WP_CSS_Complex_Selector::parse( $tokens );
		if ( null === $selector ) {
			return null;
		}
		$tokens->consume_whitespace();

		$selectors = array( $selector );
		while ( $tokens->consume( WP_CSS_Token_Processor::TOKEN_COMMA ) ) {
			$tokens->consume_whitespace();
			$selector = WP_CSS_Complex_Selector::parse( $tokens );
			if ( null === $selector ) {
				$tokens->seek( $bookmark );
				return null;
			}
			$selectors[] = $selector;
			$tokens->consume_whitespace();
		}

		return new self( $selectors );
	}
}
