<?php
/**
 * HTML API: WP_CSS_Complex_Selector_List class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since TBD
 */

/**
 * Core class used by the {@see WP_HTML_Processor} to parse and match CSS selectors.
 *
 * This class is designed for internal use by the HTML processor.
 *
 * For usage, see {@see WP_HTML_Processor::select()} or {@see WP_HTML_Processor::select_all()}.
 *
 * This class is instantiated via the {@see WP_CSS_Complex_Selector_List::from_selectors()} method.
 * It takes a CSS selector string and returns an instance of itself or `null` if the selector
 * is invalid or unsupported.
 *
 * A subset of the CSS selector grammar is supported. The grammar is defined in the CSS Syntax
 * specification, which is available at {@link https://www.w3.org/TR/selectors/#grammar}.
 *
 * This class is rougly analogous to the <selector-list> in the grammar. See {@see WP_CSS_Compound_Selector_List} for more details on the grammar.
 *
 * This class supports the same selector syntax as {@see WP_CSS_Compound_Selector_List} as well as:
 * - The following combinators:
 *   - Next sibling (`el + el`)
 *   - Subsequent sibling (`el ~ el`)
 *
 * @since TBD
 *
 * @access private
 */
class WP_CSS_Complex_Selector_List extends WP_CSS_Compound_Selector_List implements WP_CSS_HTML_Processor_Matcher {
	/**
	 * Takes a CSS selector string and returns an instance of itself or `null` if the selector
	 * string is invalid or unsupported.
	 *
	 * @since TBD
	 *
	 * @param string $input CSS selectors.
	 * @return static|null
	 */
	public static function from_selectors( string $input ) {
		$input = self::normalize_selector_input( $input );

		if ( '' === $input ) {
			return null;
		}

		$offset = 0;

		$selector = self::parse_complex_selector( $input, $offset );
		if ( null === $selector ) {
			return null;
		}
		self::parse_whitespace( $input, $offset );

		$selectors = array( $selector );
		while ( $offset < strlen( $input ) ) {
			// Each loop should stop on a `,` selector list delimiter.
			if ( ',' !== $input[ $offset ] ) {
				return null;
			}
			++$offset;
			self::parse_whitespace( $input, $offset );
			$selector = self::parse_complex_selector( $input, $offset );
			if ( null === $selector ) {
				return null;
			}
			$selectors[] = $selector;
			self::parse_whitespace( $input, $offset );
		}

		return new self( $selectors );
	}

	/*
	 * ------------------------------
	 * Selector parsing functionality
	 * ------------------------------
	 */

	/**
	 * Parses a complex selector.
	 *
	 * > <complex-selector> = [ <type-selector> <combinator>? ]* <compound-selector>
	 *
	 * @return WP_CSS_Complex_Selector|null
	 */
	final protected static function parse_complex_selector( string $input, int &$offset ): ?WP_CSS_Complex_Selector {
		if ( $offset >= strlen( $input ) ) {
			return null;
		}

		$updated_offset = $offset;
		$selector       = self::parse_compound_selector( $input, $updated_offset );
		if ( null === $selector ) {
			return null;
		}

		$selectors                       = array( $selector );
		$has_preceding_subclass_selector = null !== $selector->subclass_selectors;

		$found_whitespace = self::parse_whitespace( $input, $updated_offset );
		while ( $updated_offset < strlen( $input ) ) {
			if (
				WP_CSS_Complex_Selector::COMBINATOR_CHILD === $input[ $updated_offset ] ||
				WP_CSS_Complex_Selector::COMBINATOR_NEXT_SIBLING === $input[ $updated_offset ] ||
				WP_CSS_Complex_Selector::COMBINATOR_SUBSEQUENT_SIBLING === $input[ $updated_offset ]
			) {
				$combinator = $input[ $updated_offset ];
				++$updated_offset;
				self::parse_whitespace( $input, $updated_offset );

				// Failure to find a selector here is a parse error
				$selector = self::parse_compound_selector( $input, $updated_offset );
			} elseif ( $found_whitespace ) {
				/*
				* Whitespace is ambiguous, it could be a descendant combinator or
				* insignificant whitespace.
				*/
				$selector = self::parse_compound_selector( $input, $updated_offset );
				if ( null === $selector ) {
					break;
				}
				$combinator = WP_CSS_Complex_Selector::COMBINATOR_DESCENDANT;
			} else {
				break;
			}

			if ( null === $selector ) {
				return null;
			}

			/*
			 * Subclass selectors in non-final position is not supported:
			 *   - `div > .className` is valid
			 *   - `.className > div` is not
			 */
			if ( $has_preceding_subclass_selector ) {
				return null;
			}
			$has_preceding_subclass_selector = null !== $selector->subclass_selectors;

			$selectors[] = $combinator;
			$selectors[] = $selector;

			$found_whitespace = self::parse_whitespace( $input, $updated_offset );
		}
		$offset = $updated_offset;
		return new WP_CSS_Complex_Selector( $selectors );
	}
}
