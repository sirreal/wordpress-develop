<?php
/**
 * HTML API: WP_CSS_ID_Selector class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since {WP_VERSION}
 */

/**
 * CSS ID selector.
 *
 * This class is used to test for matching HTML tags in a {@see WP_HTML_Tag_Processor}.
 *
 * @since {WP_VERSION}
 *
 * @access private
 */
final class WP_CSS_ID_Selector extends WP_CSS_Selector_Parser_Matcher {
	/**
	 * The ID to match.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Constructor.
	 *
	 * @param string $id The ID to match.
	 */
	private function __construct( string $id ) {
		$this->id = $id;
	}

	/**
	 * Determines if the processor's current position matches the selector.
	 *
	 * @param WP_HTML_Tag_Processor $processor The processor.
	 * @return bool True if the processor's current position matches the selector.
	 */
	public function matches( WP_HTML_Tag_Processor $processor ): bool {
		$id = $processor->get_attribute( 'id' );
		if ( ! is_string( $id ) ) {
			return false;
		}

		$case_insensitive = $processor->is_quirks_mode();

		return $case_insensitive
			? 0 === strcasecmp( $id, $this->id )
			: $id === $this->id;
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
		if (
			! $tokens->matches( WP_CSS_Token_Processor::TOKEN_HASH ) ||
			WP_CSS_Token_Processor::HASH_TOKEN_ID !== $tokens->get_token_type_flag()
		) {
			return null;
		}

		$id = $tokens->get_token_value();
		if ( null === $id ) {
			return null;
		}

		$tokens->consume( WP_CSS_Token_Processor::TOKEN_HASH );

		return new self( $id );
	}
}
