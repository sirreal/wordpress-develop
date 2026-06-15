<?php
namespace CssSelectorFuzz;

/**
 * Converts parsed WP_CSS_* selector objects into the canonical plain-array
 * AST that SelectorGenerator produces and ReferenceMatcher consumes.
 *
 * Also performs structural sanity checks: any deviation from the documented
 * object shape is reported as an invariant violation by throwing.
 */
class AstExtractor {

	/** @param \WP_CSS_Complex_Selector_List $list */
	public static function from_complex_list( $list ): array {
		$out = array();
		foreach ( self::get_private( $list, 'selectors', \WP_CSS_Compound_Selector_List::class ) as $selector ) {
			if ( ! $selector instanceof \WP_CSS_Complex_Selector ) {
				throw new \UnexpectedValueException( 'Complex selector list contains non-complex selector: ' . self::describe( $selector ) );
			}
			$out[] = self::from_complex( $selector );
		}
		return $out;
	}

	/**
	 * Extracts from a compound selector list into the same canonical shape
	 * ( complex selectors with empty context ) for direct comparison.
	 *
	 * @param \WP_CSS_Compound_Selector_List $list
	 */
	public static function from_compound_list( $list ): array {
		$out = array();
		foreach ( self::get_private( $list, 'selectors', \WP_CSS_Compound_Selector_List::class ) as $selector ) {
			if ( ! $selector instanceof \WP_CSS_Compound_Selector ) {
				throw new \UnexpectedValueException( 'Compound selector list contains non-compound selector: ' . self::describe( $selector ) );
			}
			$out[] = array(
				'context' => array(),
				'self'    => self::from_compound( $selector ),
			);
		}
		return $out;
	}

	private static function from_complex( \WP_CSS_Complex_Selector $selector ): array {
		$context = array();
		foreach ( (array) $selector->context_selectors as $pair ) {
			if ( ! is_array( $pair ) || 2 !== count( $pair ) ) {
				throw new \UnexpectedValueException( 'Context selector pair has unexpected shape.' );
			}
			if ( ! $pair[0] instanceof \WP_CSS_Type_Selector ) {
				throw new \UnexpectedValueException( 'Context selector is not a type selector: ' . self::describe( $pair[0] ) );
			}
			if ( ! in_array( $pair[1], array( ' ', '>' ), true ) ) {
				throw new \UnexpectedValueException( 'Context selector uses unsupported combinator: ' . var_export( $pair[1], true ) );
			}
			$context[] = array( $pair[0]->type, $pair[1] );
		}

		return array(
			'context' => $context,
			'self'    => self::from_compound( $selector->self_selector ),
		);
	}

	private static function from_compound( \WP_CSS_Compound_Selector $selector ): array {
		$subs = null;
		if ( null !== $selector->subclass_selectors ) {
			if ( array() === $selector->subclass_selectors ) {
				throw new \UnexpectedValueException( 'Compound selector has empty (non-null) subclass selector array.' );
			}
			$subs = array();
			foreach ( $selector->subclass_selectors as $sub ) {
				$subs[] = self::from_subclass( $sub );
			}
		}

		if ( null === $selector->type_selector && null === $subs ) {
			throw new \UnexpectedValueException( 'Compound selector has neither type nor subclass selectors.' );
		}

		return array(
			'type' => null === $selector->type_selector ? null : $selector->type_selector->type,
			'subs' => $subs,
		);
	}

	private static function from_subclass( $sub ): array {
		if ( $sub instanceof \WP_CSS_Class_Selector ) {
			return array(
				'kind' => 'class',
				'name' => $sub->class_name,
			);
		}
		if ( $sub instanceof \WP_CSS_ID_Selector ) {
			return array(
				'kind' => 'id',
				'name' => $sub->id,
			);
		}
		if ( $sub instanceof \WP_CSS_Attribute_Selector ) {
			$valid_matchers = array(
				null,
				\WP_CSS_Attribute_Selector::MATCH_EXACT,
				\WP_CSS_Attribute_Selector::MATCH_ONE_OF_EXACT,
				\WP_CSS_Attribute_Selector::MATCH_EXACT_OR_HYPHEN_SUFFIXED,
				\WP_CSS_Attribute_Selector::MATCH_PREFIXED_BY,
				\WP_CSS_Attribute_Selector::MATCH_SUFFIXED_BY,
				\WP_CSS_Attribute_Selector::MATCH_CONTAINS,
			);
			if ( ! in_array( $sub->matcher, $valid_matchers, true ) ) {
				throw new \UnexpectedValueException( 'Attribute selector has unknown matcher: ' . var_export( $sub->matcher, true ) );
			}
			$valid_modifiers = array(
				null,
				\WP_CSS_Attribute_Selector::MODIFIER_CASE_SENSITIVE,
				\WP_CSS_Attribute_Selector::MODIFIER_CASE_INSENSITIVE,
			);
			if ( ! in_array( $sub->modifier, $valid_modifiers, true ) ) {
				throw new \UnexpectedValueException( 'Attribute selector has unknown modifier: ' . var_export( $sub->modifier, true ) );
			}
			if ( ( null === $sub->matcher ) !== ( null === $sub->value ) ) {
				throw new \UnexpectedValueException( 'Attribute selector matcher/value nullness mismatch.' );
			}
			return array(
				'kind'     => 'attr',
				'name'     => $sub->name,
				'matcher'  => $sub->matcher,
				'value'    => $sub->value,
				'modifier' => $sub->modifier,
			);
		}
		throw new \UnexpectedValueException( 'Unknown subclass selector: ' . self::describe( $sub ) );
	}

	private static function get_private( $object, string $property, string $declaring_class ) {
		$reflection = new \ReflectionProperty( $declaring_class, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		$value = $reflection->getValue( $object );
		if ( ! is_array( $value ) ) {
			throw new \UnexpectedValueException( "Property {$property} is not an array." );
		}
		return $value;
	}

	private static function describe( $value ): string {
		return is_object( $value ) ? get_class( $value ) : gettype( $value );
	}
}
