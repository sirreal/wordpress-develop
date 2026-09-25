<?php
/**
 * HTML API: WP_CSS_Selector_Parser_Matcher class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 6.8.0
 */

/**
 * Base class for all CSS Selector parser/matcher classes.
 *
 * @since 6.8.0
 *
 * @access private
 */
abstract class WP_CSS_Selector_Parser_Matcher {
	/**
	 * Determines if the processor's current position matches the selector.
	 *
	 * @param WP_HTML_Tag_Processor $processor The processor.
	 * @return bool True if the processor's current position matches the selector.
	 */
	abstract public function matches( WP_HTML_Tag_Processor $processor ): bool;

	/**
	 * Parses CSS selector tokens to create a selector instance.
	 *
	 * @param WP_CSS_Selector_Token_Stream $tokens The selector token stream.
	 * @return static|null The selector instance, or null if the parse was unsuccessful.
	 */
	abstract public static function parse( WP_CSS_Selector_Token_Stream $tokens );
}
