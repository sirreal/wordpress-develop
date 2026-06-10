<?php
namespace CssSelectorFuzz;

/**
 * Independent implementation of the supported CSS selector semantics,
 * operating on the document model produced by DocumentGenerator and the
 * canonical selector AST.
 *
 * Semantics follow the CSS Selectors Level 4 specification:
 *  - Tag names match ASCII case-insensitively (HTML documents).
 *  - Attribute names match ASCII case-insensitively; the first of duplicate
 *    attributes wins.
 *  - Class and ID matching is exact, except in quirks mode where it is
 *    ASCII case-insensitive.
 *  - Attribute value matching is exact (byte-wise) unless the `i` modifier
 *    requests ASCII case-insensitivity.
 *  - For `^=`, `$=`, `*=` and `~=`, an empty (or for `~=`, whitespace-
 *    containing) value matches nothing.
 *
 * Divergence between this matcher and the HTML API is a fuzzer finding.
 */
class ReferenceMatcher {

	const WHITESPACE = " \t\r\n\f";

	/**
	 * Expected match list for WP_HTML_Processor::select() over a full document.
	 *
	 * @param array $list_ast Canonical complex selector list AST.
	 * @param array $model    Root element model ( the `html` element ).
	 * @param bool  $quirks   Whether the document parses in quirks mode.
	 * @return string[] data-fid values in document order.
	 */
	public static function expected_html_processor_matches( array $list_ast, array $model, bool $quirks ): array {
		$out = array();
		foreach ( DocumentGenerator::flatten_with_ancestors( $model ) as $pair ) {
			list( $element, $ancestors ) = $pair;
			if ( self::list_matches( $list_ast, $element, $ancestors, $quirks ) ) {
				$out[] = $element['fid'];
			}
		}
		return $out;
	}

	/**
	 * Expected match list for WP_HTML_Tag_Processor::select() over the same
	 * markup. The tag processor never enters quirks mode on its own and a
	 * compound selector list never inspects ancestors.
	 *
	 * @param array $list_ast Canonical complex selector list AST ( contexts must be empty ).
	 * @param array $model    Root element model.
	 * @return string[] data-fid values in document order.
	 */
	public static function expected_tag_processor_matches( array $list_ast, array $model ): array {
		$out = array();
		foreach ( DocumentGenerator::flatten( $model ) as $element ) {
			if ( self::list_matches( $list_ast, $element, array(), false ) ) {
				$out[] = $element['fid'];
			}
		}
		return $out;
	}

	public static function list_matches( array $list_ast, array $element, array $ancestors, bool $quirks ): bool {
		foreach ( $list_ast as $complex ) {
			if ( self::complex_matches( $complex, $element, $ancestors, $quirks ) ) {
				return true;
			}
		}
		return false;
	}

	private static function complex_matches( array $complex, array $element, array $ancestors, bool $quirks ): bool {
		if ( ! self::compound_matches( $complex['self'], $element, $quirks ) ) {
			return false;
		}
		$ancestor_tags = array();
		foreach ( $ancestors as $ancestor ) {
			$ancestor_tags[] = $ancestor['tag'];
		}
		return self::explore_context( $complex['context'], $ancestor_tags );
	}

	/**
	 * @param array    $context       Right-to-left ( type, combinator ) pairs.
	 * @param string[] $ancestor_tags Nearest-ancestor-first tag names.
	 */
	private static function explore_context( array $context, array $ancestor_tags ): bool {
		if ( array() === $context ) {
			return true;
		}
		if ( array() === $ancestor_tags ) {
			return false;
		}

		list( $type, $combinator ) = $context[0];
		$rest                      = array_slice( $context, 1 );

		if ( '>' === $combinator ) {
			return self::type_matches( $type, $ancestor_tags[0] )
				&& self::explore_context( $rest, array_slice( $ancestor_tags, 1 ) );
		}

		// Descendant: try every matching ancestor.
		$count = count( $ancestor_tags );
		for ( $i = 0; $i < $count; $i++ ) {
			if (
				self::type_matches( $type, $ancestor_tags[ $i ] ) &&
				self::explore_context( $rest, array_slice( $ancestor_tags, $i + 1 ) )
			) {
				return true;
			}
		}
		return false;
	}

	public static function compound_matches( array $compound, array $element, bool $quirks ): bool {
		if ( null !== $compound['type'] && ! self::type_matches( $compound['type'], $element['tag'] ) ) {
			return false;
		}
		foreach ( (array) $compound['subs'] as $sub ) {
			if ( ! self::sub_matches( $sub, $element, $quirks ) ) {
				return false;
			}
		}
		return true;
	}

	private static function type_matches( string $type, string $tag ): bool {
		return '*' === $type || ascii_strtolower( $type ) === ascii_strtolower( $tag );
	}

	private static function sub_matches( array $sub, array $element, bool $quirks ): bool {
		switch ( $sub['kind'] ) {
			case 'class':
				return self::class_matches( $sub['name'], $element, $quirks );
			case 'id':
				return self::id_matches( $sub['name'], $element, $quirks );
			case 'attr':
				return self::attr_matches( $sub, $element );
		}
		return false;
	}

	private static function class_matches( string $wanted, array $element, bool $quirks ): bool {
		$class_value = DocumentGenerator::get_attribute_value( $element, 'class' );
		if ( ! is_string( $class_value ) ) {
			return false;
		}

		$length = strlen( $class_value );
		$at     = 0;
		while ( $at < $length ) {
			$at += strspn( $class_value, self::WHITESPACE, $at );
			if ( $at >= $length ) {
				break;
			}
			$word_length = strcspn( $class_value, self::WHITESPACE, $at );
			$word        = substr( $class_value, $at, $word_length );
			$at         += $word_length;

			if (
				$quirks
					? ascii_strtolower( $word ) === ascii_strtolower( $wanted )
					: $word === $wanted
			) {
				return true;
			}
		}
		return false;
	}

	private static function id_matches( string $wanted, array $element, bool $quirks ): bool {
		$id = DocumentGenerator::get_attribute_value( $element, 'id' );
		if ( ! is_string( $id ) ) {
			return false;
		}
		return $quirks
			? ascii_strtolower( $id ) === ascii_strtolower( $wanted )
			: $id === $wanted;
	}

	private static function attr_matches( array $sub, array $element ): bool {
		$attr_value = DocumentGenerator::get_attribute_value( $element, $sub['name'] );
		if ( null === $attr_value ) {
			return false;
		}
		if ( null === $sub['matcher'] ) {
			return true;
		}
		if ( true === $attr_value ) {
			$attr_value = '';
		}

		$wanted           = (string) $sub['value'];
		$case_insensitive = 'case-insensitive' === $sub['modifier'];
		if ( $case_insensitive ) {
			$attr_value = ascii_strtolower( $attr_value );
			$wanted     = ascii_strtolower( $wanted );
		}

		switch ( $sub['matcher'] ) {
			case 'exact':
				return $attr_value === $wanted;

			case 'one-of':
				if ( '' === $wanted || strlen( $wanted ) !== strcspn( $wanted, self::WHITESPACE ) ) {
					return false;
				}
				$length = strlen( $attr_value );
				$at     = 0;
				while ( $at < $length ) {
					$at += strspn( $attr_value, self::WHITESPACE, $at );
					if ( $at >= $length ) {
						break;
					}
					$word_length = strcspn( $attr_value, self::WHITESPACE, $at );
					if ( substr( $attr_value, $at, $word_length ) === $wanted ) {
						return true;
					}
					$at += $word_length;
				}
				return false;

			case 'exact-or-hyphen-suffixed':
				if ( $attr_value === $wanted ) {
					return true;
				}
				return 0 === strncmp( $attr_value, $wanted . '-', strlen( $wanted ) + 1 );

			case 'prefixed':
				if ( '' === $wanted ) {
					return false;
				}
				return 0 === strncmp( $attr_value, $wanted, strlen( $wanted ) );

			case 'suffixed':
				if ( '' === $wanted ) {
					return false;
				}
				return strlen( $attr_value ) >= strlen( $wanted )
					&& substr( $attr_value, -strlen( $wanted ) ) === $wanted;

			case 'contains':
				if ( '' === $wanted ) {
					return false;
				}
				return false !== strpos( $attr_value, $wanted );
		}

		return false;
	}
}
