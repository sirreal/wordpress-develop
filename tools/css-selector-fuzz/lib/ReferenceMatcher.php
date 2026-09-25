<?php
namespace CssSelectorFuzz;

/**
 * Independent implementation of the supported CSS selector semantics.
 *
 * Operates on flat element "rows" in visit order — either derived from the
 * generated document model or captured from the processor itself
 * ( TreeCapture ). Each row carries the element's tag, attributes, and
 * ( for the html processor view ) its nearest-first ancestor tag list,
 * which is all the supported grammar can observe: context selectors are
 * type-only.
 *
 * Semantics follow the CSS Selectors Level 4 specification:
 *  - Tag names match ASCII case-insensitively (HTML documents).
 *  - Attribute names match ASCII case-insensitively; the first of duplicate
 *    attributes wins.
 *  - Class and ID matching is exact, except in quirks mode where it is
 *    ASCII case-insensitive.
 *  - Attribute value matching is exact (byte-wise) unless the `i` modifier
 *    requests ASCII case-insensitivity, or the attribute is in HTML's
 *    case-insensitive list, the selector has no modifier, and the element
 *    is in the html namespace (rows without a namespace field are html).
 *  - For `^=`, `$=`, `*=` and `~=`, an empty (or for `~=`, whitespace-
 *    containing) value matches nothing.
 *
 * Divergence between this matcher and the HTML API is a fuzzer finding.
 */
class ReferenceMatcher {

	const WHITESPACE = " \t\r\n\f";

	/**
	 * HTML's case-insensitive attribute value list: with no `i`/`s`
	 * modifier, these attributes' values match ASCII case-insensitively on
	 * HTML elements. Independent copy — the matcher must not share a
	 * possible misreading with the implementation under test.
	 *
	 * https://html.spec.whatwg.org/multipage/semantics-other.html#case-sensitivity-of-selectors
	 */
	const HTML_CASE_INSENSITIVE_ATTRIBUTES = array(
		'accept'         => true,
		'accept-charset' => true,
		'align'          => true,
		'alink'          => true,
		'axis'           => true,
		'bgcolor'        => true,
		'charset'        => true,
		'checked'        => true,
		'clear'          => true,
		'codetype'       => true,
		'color'          => true,
		'compact'        => true,
		'declare'        => true,
		'defer'          => true,
		'dir'            => true,
		'direction'      => true,
		'disabled'       => true,
		'enctype'        => true,
		'face'           => true,
		'frame'          => true,
		'hreflang'       => true,
		'http-equiv'     => true,
		'lang'           => true,
		'language'       => true,
		'link'           => true,
		'media'          => true,
		'method'         => true,
		'multiple'       => true,
		'nohref'         => true,
		'noresize'       => true,
		'noshade'        => true,
		'nowrap'         => true,
		'readonly'       => true,
		'rel'            => true,
		'rev'            => true,
		'rules'          => true,
		'scope'          => true,
		'scrolling'      => true,
		'selected'       => true,
		'shape'          => true,
		'target'         => true,
		'text'           => true,
		'type'           => true,
		'valign'         => true,
		'valuetype'      => true,
		'vlink'          => true,
	);

	/**
	 * Expected match list for WP_HTML_Processor::select().
	 *
	 * @param array $list_ast     Canonical complex selector list AST.
	 * @param array $rows         Element rows in visit order, with ancestorTags.
	 * @param bool  $quirks       Whether the document parses in quirks mode.
	 * @param bool  $html_attr_ci Whether HTML's case-insensitive attribute value
	 *                            list applies. True models WP/browsers; false
	 *                            models an engine without the rule ( lexbor ).
	 * @return string[] data-fid values in visit order.
	 */
	public static function expected_html_matches_rows( array $list_ast, array $rows, bool $quirks, bool $html_attr_ci = true ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( self::list_matches_row( $list_ast, $row, $quirks, $html_attr_ci ) ) {
				$out[] = $row['fid'];
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
	 * @param array $rows     Tag-view element rows in token order.
	 * @return string[] data-fid values in token order.
	 */
	public static function expected_tag_matches_rows( array $list_ast, array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$matched = false;
			foreach ( $list_ast as $complex ) {
				if ( self::compound_matches( $complex['self'], $row, false, true ) ) {
					$matched = true;
					break;
				}
			}
			if ( $matched ) {
				$out[] = $row['fid'];
			}
		}
		return $out;
	}

	/** Back-compat: expected html-processor matches from a generated model. */
	public static function expected_html_processor_matches( array $list_ast, array $model, bool $quirks ): array {
		return self::expected_html_matches_rows( $list_ast, DocumentGenerator::rows_from_model( $model ), $quirks );
	}

	/** Back-compat: expected tag-processor matches from a generated model. */
	public static function expected_tag_processor_matches( array $list_ast, array $model ): array {
		return self::expected_tag_matches_rows( $list_ast, DocumentGenerator::rows_from_model( $model ) );
	}

	public static function list_matches_row( array $list_ast, array $row, bool $quirks, bool $html_attr_ci = true ): bool {
		foreach ( $list_ast as $complex ) {
			if (
				self::compound_matches( $complex['self'], $row, $quirks, $html_attr_ci ) &&
				self::explore_context( $complex['context'], $row['ancestorTags'] )
			) {
				return true;
			}
		}
		return false;
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

		$pair         = $context[0];
		list( $type, $combinator ) = $pair;
		$is_universal = array_key_exists( 2, $pair ) ? (bool) $pair[2] : null;
		$rest         = array_slice( $context, 1 );

		if ( '>' === $combinator ) {
			return self::type_matches( $type, $ancestor_tags[0], $is_universal )
				&& self::explore_context( $rest, array_slice( $ancestor_tags, 1 ) );
		}

		// Descendant: try every matching ancestor.
		$count = count( $ancestor_tags );
		for ( $i = 0; $i < $count; $i++ ) {
			if (
				self::type_matches( $type, $ancestor_tags[ $i ], $is_universal ) &&
				self::explore_context( $rest, array_slice( $ancestor_tags, $i + 1 ) )
			) {
				return true;
			}
		}
		return false;
	}

	public static function compound_matches( array $compound, array $row, bool $quirks, bool $html_attr_ci = true ): bool {
		$is_universal = array_key_exists( 'typeIsUniversal', $compound ) ? (bool) $compound['typeIsUniversal'] : null;
		if ( null !== $compound['type'] && ! self::type_matches( $compound['type'], $row['tag'], $is_universal ) ) {
			return false;
		}
		foreach ( (array) $compound['subs'] as $sub ) {
			if ( ! self::sub_matches( $sub, $row, $quirks, $html_attr_ci ) ) {
				return false;
			}
		}
		return true;
	}

	private static function type_matches( string $type, string $tag, ?bool $is_universal = null ): bool {
		if ( null === $is_universal ) {
			$is_universal = '*' === $type;
		}
		return $is_universal || ascii_strtolower( $type ) === ascii_strtolower( $tag );
	}

	private static function sub_matches( array $sub, array $row, bool $quirks, bool $html_attr_ci ): bool {
		switch ( $sub['kind'] ) {
			case 'class':
				return self::class_matches( $sub['name'], $row, $quirks );
			case 'id':
				return self::id_matches( $sub['name'], $row, $quirks );
			case 'attr':
				return self::attr_matches( $sub, $row, $html_attr_ci );
		}
		return false;
	}

	private static function class_matches( string $wanted, array $row, bool $quirks ): bool {
		$class_value = DocumentGenerator::get_attribute_value( $row, 'class' );
		if ( ! is_string( $class_value ) ) {
			return false;
		}

		foreach ( DocumentGenerator::class_tokens( $class_value ) as $word ) {
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

	private static function id_matches( string $wanted, array $row, bool $quirks ): bool {
		$id = DocumentGenerator::get_attribute_value( $row, 'id' );
		if ( ! is_string( $id ) ) {
			return false;
		}
		return $quirks
			? ascii_strtolower( $id ) === ascii_strtolower( $wanted )
			: $id === $wanted;
	}

	private static function attr_matches( array $sub, array $row, bool $html_attr_ci ): bool {
		$attr_value = DocumentGenerator::get_attribute_value( $row, $sub['name'] );
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
		$case_insensitive = 'case-insensitive' === $sub['modifier'] || (
			$html_attr_ci &&
			null === $sub['modifier'] &&
			'html' === ( $row['namespace'] ?? 'html' ) &&
			isset( self::HTML_CASE_INSENSITIVE_ATTRIBUTES[ ascii_strtolower( $sub['name'] ) ] )
		);
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
