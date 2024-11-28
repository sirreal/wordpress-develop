<?php
/**
 * HTML API: WP_CSS_Attribute_Selector class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since TBD
 */

/**
 * CSS attribute selector.
 *
 * This class implements a CSS attribute selector and is used to test for matching HTML tags
 * in a {@see WP_HTML_Tag_Processor}.
 *
 * @since TBD
 *
 * @access private
 */
final class WP_CSS_Attribute_Selector implements WP_CSS_HTML_Tag_Processor_Matcher {
	/**
	 * [att=val]
	 * Represents an element with the att attribute whose value is exactly "val".
	 */
	const MATCH_EXACT = 'MATCH_EXACT';

	/**
	 * [attr~=value]
	 * Represents elements with an attribute name of attr whose value is a
	 * whitespace-separated list of words, one of which is exactly value.
	 */
	const MATCH_ONE_OF_EXACT = 'MATCH_ONE_OF_EXACT';

	/**
	 * [attr|=value]
	 * Represents elements with an attribute name of attr whose value can be exactly value or
	 * can begin with value immediately followed by a hyphen, - (U+002D). It is often used for
	 * language subcode matches.
	 */
	const MATCH_EXACT_OR_EXACT_WITH_HYPHEN = 'MATCH_EXACT_OR_EXACT_WITH_HYPHEN';

	/**
	 * [attr^=value]
	 * Represents elements with an attribute name of attr whose value is prefixed (preceded)
	 * by value.
	 */
	const MATCH_PREFIXED_BY = 'MATCH_PREFIXED_BY';

	/**
	 * [attr$=value]
	 * Represents elements with an attribute name of attr whose value is suffixed (followed)
	 * by value.
	 */
	const MATCH_SUFFIXED_BY = 'MATCH_SUFFIXED_BY';

	/**
	 * [attr*=value]
	 * Represents elements with an attribute name of attr whose value contains at least one
	 * occurrence of value within the string.
	 */
	const MATCH_CONTAINS = 'MATCH_CONTAINS';

	/**
	 * Modifier for case sensitive matching
	 * [attr=value s]
	 */
	const MODIFIER_CASE_SENSITIVE = 'case-sensitive';

	/**
	 * Modifier for case insensitive matching
	 * [attr=value i]
	 */
	const MODIFIER_CASE_INSENSITIVE = 'case-insensitive';

	/**
	 * The attribute name.
	 *
	 * @var string
	 * @readonly
	 */
	public $name;

	/**
	 * The attribute matcher.
	 *
	 * @var null|self::MATCH_*
	 * @readonly
	 */
	public $matcher;

	/**
	 * The attribute value.
	 *
	 * @var string|null
	 * @readonly
	 */
	public $value;

	/**
	 * The attribute modifier.
	 *
	 * @var null|self::MODIFIER_*
	 * @readonly
	 */
	public $modifier;

	/**
	 * Constructor.
	 *
	 * @param string $name
	 * @param null|self::MATCH_* $matcher
	 * @param null|string $value
	 * @param null|self::MODIFIER_* $modifier
	 */
	public function __construct( string $name, ?string $matcher = null, ?string $value = null, ?string $modifier = null ) {
		$this->name     = $name;
		$this->matcher  = $matcher;
		$this->value    = $value;
		$this->modifier = $modifier;
	}

	/**
	 * Determines if the processor's current position matches the selector.
	 *
	 * @param WP_HTML_Tag_Processor $processor
	 * @return bool True if the processor's current position matches the selector.
	 */
	public function matches( WP_HTML_Tag_Processor $processor ): bool {
		$att_value = $processor->get_attribute( $this->name );
		if ( null === $att_value ) {
			return false;
		}

		if ( null === $this->value ) {
			return true;
		}

		if ( true === $att_value ) {
			$att_value = '';
		}

		$case_insensitive = self::MODIFIER_CASE_INSENSITIVE === $this->modifier;

		switch ( $this->matcher ) {
			case self::MATCH_EXACT:
				return $case_insensitive
					? 0 === strcasecmp( $att_value, $this->value )
					: $att_value === $this->value;

			case self::MATCH_ONE_OF_EXACT:
				foreach ( $this->whitespace_delimited_list( $att_value ) as $val ) {
					if (
						$case_insensitive
							? 0 === strcasecmp( $val, $this->value )
							: $val === $this->value
					) {
						return true;
					}
				}
				return false;

			case self::MATCH_EXACT_OR_EXACT_WITH_HYPHEN:
				// Attempt the full match first
				if (
					$case_insensitive
						? 0 === strcasecmp( $att_value, $this->value )
						: $att_value === $this->value
				) {
					return true;
				}

				// Partial match
				if ( strlen( $att_value ) < strlen( $this->value ) + 1 ) {
					return false;
				}

				$starts_with = "{$this->value}-";
				return 0 === substr_compare( $att_value, $starts_with, 0, strlen( $starts_with ), $case_insensitive );

			case self::MATCH_PREFIXED_BY:
				return 0 === substr_compare( $att_value, $this->value, 0, strlen( $this->value ), $case_insensitive );

			case self::MATCH_SUFFIXED_BY:
				return 0 === substr_compare( $att_value, $this->value, -strlen( $this->value ), null, $case_insensitive );

			case self::MATCH_CONTAINS:
				return false !== (
					$case_insensitive
						? stripos( $att_value, $this->value )
						: strpos( $att_value, $this->value )
				);
		}
	}

	/**
	 * Splits a string into a list of whitespace delimited values.
	 *
	 * This is useful for the {@see WP_CSS_Attribute_Selector::MATCH_ONE_OF_EXACT} matcher.
	 *
	 * @param string $input
	 *
	 * @return Generator<string>
	 */
	private function whitespace_delimited_list( string $input ): Generator {
		// Start by skipping whitespace.
		$offset = strspn( $input, " \t\r\n\f" );

		while ( $offset < strlen( $input ) ) {
			// Find the byte length until the next boundary.
			$length = strcspn( $input, " \t\r\n\f", $offset );
			$value  = substr( $input, $offset, $length );

			// Move past trailing whitespace.
			$offset += $length + strspn( $input, " \t\r\n\f", $offset + $length );

			yield $value;
		}
	}
}
