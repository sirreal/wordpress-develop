<?php
/**
 * HTML API: WP_CSS_Class_Selector class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since {WP_VERSION}
 */

/**
 * CSS class selector.
 *
 * This class is used to test for matching HTML tags in a {@see WP_HTML_Tag_Processor}.
 *
 * @since {WP_VERSION}
 *
 * @access private
 */
final class WP_CSS_Class_Selector extends WP_CSS_Selector_Parser_Matcher {
	/**
	 * The class name to match.
	 *
	 * @var string
	 */
	public $class_name;

	/**
	 * Constructor.
	 *
	 * @param string $class_name The class name to match.
	 */
	private function __construct( string $class_name ) {
		$this->class_name = $class_name;
	}

	/**
	 * Determines if the processor's current position matches the selector.
	 *
	 * @param WP_HTML_Tag_Processor $processor The processor.
	 * @return bool True if the processor's current position matches the selector.
	 */
	public function matches( WP_HTML_Tag_Processor $processor ): bool {
		return (bool) $processor->has_class( $this->class_name );
	}

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

		if ( ! $tokens->consume_delim( '.' ) ) {
			return null;
		}

		$result = $tokens->consume_ident();
		if ( null === $result ) {
			$tokens->seek( $bookmark );
			return null;
		}

		return new self( $result );
	}
}
