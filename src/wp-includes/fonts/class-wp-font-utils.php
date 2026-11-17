<?php
/**
 * Font Utils class.
 *
 * Provides utility functions for working with font families.
 *
 * @package    WordPress
 * @subpackage Fonts
 * @since      6.5.0
 */

/**
 * A class of utilities for working with the Font Library.
 *
 * These utilities may change or be removed in the future and are intended for internal use only.
 *
 * @since 6.5.0
 * @access private
 */
class WP_Font_Utils {
	/**
	 * Generic font family keywords.
	 *
	 * A generic keyword is not a font name. The browser maps it to a font that
	 * the user or the system selects.
	 *
	 * @since 7.3.0
	 *
	 * @link https://www.w3.org/TR/css-fonts-4/#generic-font-families
	 *
	 * @var string[]
	 */
	private const GENERIC_FONT_FAMILIES = array(
		'serif',
		'sans-serif',
		'cursive',
		'fantasy',
		'monospace',
		'system-ui',
		'math',
		'ui-serif',
		'ui-sans-serif',
		'ui-monospace',
		'ui-rounded',
		'emoji',
		'fangsong',
	);

	/**
	 * Arguments of the `generic()` function.
	 *
	 * @since 7.3.0
	 *
	 * @link https://www.w3.org/TR/css-fonts-4/#generic-font-families
	 *
	 * @var string[]
	 */
	private const GENERIC_FUNCTION_ARGUMENTS = array(
		'kai',
		'fangsong',
		'khmer-mul',
		'nastaliq',
	);

	/**
	 * Keywords that an unquoted font name cannot use.
	 *
	 * These are the CSS-wide keywords and `default`, which CSS reserves. A
	 * value that uses one of them alone is a keyword. A quoted value with the
	 * same text is a font name.
	 *
	 * @since 7.3.0
	 *
	 * @link https://www.w3.org/TR/css-fonts-4/#family-name-syntax
	 *
	 * @var string[]
	 */
	private const RESERVED_FONT_FAMILY_KEYWORDS = array(
		'inherit',
		'initial',
		'unset',
		'revert',
		'revert-layer',
		'default',
	);

	/**
	 * Sanitizes and formats font family names.
	 *
	 * The method reads the value with the CSS `font-family` grammar and writes
	 * it back in a canonical form. It keeps explicit quotes. It writes each other
	 * named family as a quoted CSS string, except one identifier of ASCII letters
	 * and hyphens, such as `-apple-system`. It keeps each generic family as a
	 * keyword. The decoded name does not change, so a name can contain a comma,
	 * an apostrophe, a quotation mark, or a CSS escape.
	 *
	 * For compatibility, the method also accepts a plain font name that is not
	 * valid CSS, such as `O'Reilly Sans` or `Bodoni*`. It rejects a value with
	 * control characters.
	 *
	 * It follows the recommendations from the CSS Fonts Module Level 4.
	 * @link https://www.w3.org/TR/css-fonts-4/#font-family-prop
	 *
	 * @since 6.5.0
	 * @since 7.3.0 Parses the value with the CSS font family grammar to keep the font name.
	 *              An invalid value returns an empty string.
	 * @access private
	 *
	 * @see WP_Font_Utils::parse_font_family_list()
	 *
	 * @param string $font_family Font family name(s), comma-separated.
	 * @return string Sanitized and formatted font family name(s), or an empty
	 *                string if the value is invalid.
	 */
	public static function sanitize_font_family( $font_family ) {
		$entries = self::parse_font_family_list( $font_family, true );

		if ( null === $entries ) {
			return '';
		}

		return self::serialize_font_family_list( $entries );
	}

	/**
	 * Generates a slug from font face properties, e.g. `open sans;normal;400;100%;U+0-10FFFF`
	 *
	 * Used for comparison with other font faces in the same family, to prevent duplicates
	 * that would both match according the CSS font matching spec. Uses only simple case-insensitive
	 * matching for fontFamily and unicodeRange, so does not handle overlapping font-family lists or
	 * unicode ranges.
	 *
	 * The font family part uses the decoded font names, so two values that
	 * write the same name with different CSS escapes produce the same slug.
	 *
	 * @since 6.5.0
	 * @since 7.3.0 Compares decoded font names instead of raw CSS text.
	 * @access private
	 *
	 * @link https://drafts.csswg.org/css-fonts/#font-style-matching
	 *
	 * @param array $settings {
	 *     Font face settings.
	 *
	 *     @type string $fontFamily   Font family name.
	 *     @type string $fontStyle    Optional font style, defaults to 'normal'.
	 *     @type string $fontWeight   Optional font weight, defaults to 400.
	 *     @type string $fontStretch  Optional font stretch, defaults to '100%'.
	 *     @type string $unicodeRange Optional unicode range, defaults to 'U+0-10FFFF'.
	 * }
	 * @return string Font face slug.
	 */
	public static function get_font_face_slug( $settings ) {
		$elements    = self::get_font_face_slug_elements( $settings );
		$font_family = self::get_font_family_comparison_key( array_shift( $elements ) );
		$font_family = function_exists( 'mb_strtolower' ) ? mb_strtolower( $font_family ) : strtolower( $font_family );

		// The font family part keeps its own characters, so add it after the sanitization.
		return $font_family . ';' . sanitize_text_field( implode( ';', self::normalize_font_face_slug_elements( $elements ) ) );
	}

	/**
	 * Generates a font face slug in the format of WordPress 6.5 to 7.2.
	 *
	 * Font faces that those versions saved use this slug as the post title.
	 * The font family part is the decoded font names, separated by commas,
	 * with the normalization of those versions. A value that the parser
	 * rejects is used as it is.
	 *
	 * @since 7.3.0
	 * @access private
	 *
	 * @see WP_Font_Utils::get_font_face_slug()
	 *
	 * @param array $settings Font face settings. See WP_Font_Utils::get_font_face_slug().
	 * @return string Font face slug in the earlier format.
	 */
	public static function get_legacy_font_face_slug( $settings ) {
		$elements = self::get_font_face_slug_elements( $settings );
		$entries  = self::parse_font_family_list( $elements[0], true );

		if ( null !== $entries ) {
			$elements[0] = implode( ', ', array_column( $entries, 'value' ) );
		}

		$elements[0] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $elements[0] ) : strtolower( $elements[0] );

		return sanitize_text_field( implode( ';', self::normalize_font_face_slug_elements( $elements ) ) );
	}

	/**
	 * Returns the font face settings that a slug compares, with defaults.
	 *
	 * @since 7.3.0
	 *
	 * @param array $settings Font face settings. See WP_Font_Utils::get_font_face_slug().
	 * @return string[] The font family, style, weight, stretch, and unicode range.
	 */
	private static function get_font_face_slug_elements( $settings ) {
		$defaults = array(
			'fontFamily'   => '',
			'fontStyle'    => 'normal',
			'fontWeight'   => '400',
			'fontStretch'  => '100%',
			'unicodeRange' => 'U+0-10FFFF',
		);
		$settings = wp_parse_args( $settings, $defaults );

		$font_style    = strtolower( $settings['fontStyle'] );
		$font_weight   = strtolower( $settings['fontWeight'] );
		$font_stretch  = strtolower( $settings['fontStretch'] );
		$unicode_range = strtoupper( $settings['unicodeRange'] );

		// Convert weight keywords to numeric strings.
		$font_weight = str_replace( array( 'normal', 'bold' ), array( '400', '700' ), $font_weight );

		// Convert stretch keywords to numeric strings.
		$font_stretch_map = array(
			'ultra-condensed' => '50%',
			'extra-condensed' => '62.5%',
			'condensed'       => '75%',
			'semi-condensed'  => '87.5%',
			'normal'          => '100%',
			'semi-expanded'   => '112.5%',
			'expanded'        => '125%',
			'extra-expanded'  => '150%',
			'ultra-expanded'  => '200%',
		);
		$font_stretch     = str_replace( array_keys( $font_stretch_map ), array_values( $font_stretch_map ), $font_stretch );

		return array( (string) $settings['fontFamily'], $font_style, $font_weight, $font_stretch, $unicode_range );
	}

	/**
	 * Normalizes the text of font face slug elements.
	 *
	 * @since 7.3.0
	 *
	 * @param string[] $elements Slug elements.
	 * @return string[] Normalized slug elements.
	 */
	private static function normalize_font_face_slug_elements( $elements ) {
		return array_map(
			static function ( $elem ) {
				// Remove quotes to normalize font-family names, and ';' to use as a separator.
				$elem = trim( str_replace( array( '"', "'", ';' ), '', $elem ) );

				// Normalize comma separated lists by removing whitespace in between items,
				// but keep whitespace within items (e.g. "Open Sans" and "OpenSans" are different fonts).
				// CSS spec for whitespace includes: U+000A LINE FEED, U+0009 CHARACTER TABULATION, or U+0020 SPACE,
				// which by default are all matched by \s in PHP.
				return preg_replace( '/,\s+/', ',', $elem );
			},
			$elements
		);
	}

	/**
	 * Builds the font family part of a font face slug.
	 *
	 * The method returns the decoded font names, separated by commas. As in
	 * WordPress 6.5.0, it removes the quotation marks and the apostrophes from
	 * each name to keep the earlier comparison rule for those characters.
	 * Each name then replaces a small set of characters with a percent sequence:
	 *
	 * - `;` and `,` cannot change the field boundaries of the slug.
	 * - `&`, `<`, and `>` cannot change when KSES filters the `post_title` of
	 *   the font face post for a user without the `unfiltered_html` capability.
	 * - `\` cannot disappear when {@see WP_Query} removes slashes from its
	 *   `title` query parameter.
	 * - `%` keeps the replacement reversible.
	 *
	 * If the value is not a font family value that the parser accepts, the
	 * method uses the text normalization of WordPress 6.5.0.
	 *
	 * @since 7.3.0
	 *
	 * @param string $font_family Font family value.
	 * @return string The font family comparison key.
	 */
	private static function get_font_family_comparison_key( $font_family ) {
		$entries = self::parse_font_family_list( $font_family, true );

		if ( null === $entries ) {
			// Keep the WordPress 6.5.0 behavior for a value that the parser rejects.
			$key = trim( str_replace( array( '"', "'", ';' ), '', $font_family ) );
			return sanitize_text_field( preg_replace( '/,\s+/', ',', $key ) );
		}

		// Remove the quotes first. Replace '%' next, so that the replacement stays reversible.
		$search  = array( '"', "'", '%', '\\', ';', ',', '&', '<', '>' );
		$replace = array( '', '', '%25', '%5c', '%3b', '%2c', '%26', '%3c', '%3e' );

		$keys = array();
		foreach ( $entries as $entry ) {
			$keys[] = str_replace( $search, $replace, $entry['value'] );
		}

		return implode( ',', $keys );
	}

	/**
	 * Sanitizes a tree of data using a schema.
	 *
	 * The schema structure should mirror the data tree. Each value provided in the
	 * schema should be a callable that will be applied to sanitize the corresponding
	 * value in the data tree. Keys that are in the data tree, but not present in the
	 * schema, will be removed in the sanitized data. Nested arrays are traversed recursively.
	 *
	 * @since 6.5.0
	 *
	 * @access private
	 *
	 * @param array $tree   The data to sanitize.
	 * @param array $schema The schema used for sanitization.
	 * @return array The sanitized data.
	 */
	public static function sanitize_from_schema( $tree, $schema ) {
		if ( ! is_array( $tree ) || ! is_array( $schema ) ) {
			return array();
		}

		foreach ( $tree as $key => $value ) {
			// Remove keys not in the schema or with null/empty values.
			if ( ! array_key_exists( $key, $schema ) ) {
				unset( $tree[ $key ] );
				continue;
			}

			$is_value_array  = is_array( $value );
			$is_schema_array = is_array( $schema[ $key ] ) && ! is_callable( $schema[ $key ] );

			if ( $is_value_array && $is_schema_array ) {
				if ( wp_is_numeric_array( $value ) ) {
					// If indexed, process each item in the array.
					foreach ( $value as $item_key => $item_value ) {
						$tree[ $key ][ $item_key ] = isset( $schema[ $key ][0] ) && is_array( $schema[ $key ][0] )
							? self::sanitize_from_schema( $item_value, $schema[ $key ][0] )
							: self::apply_sanitizer( $item_value, $schema[ $key ][0] );
					}
				} else {
					// If it is an associative or indexed array, process as a single object.
					$tree[ $key ] = self::sanitize_from_schema( $value, $schema[ $key ] );
				}
			} elseif ( ! $is_value_array && $is_schema_array ) {
				// If the value is not an array but the schema is, remove the key.
				unset( $tree[ $key ] );
			} elseif ( ! $is_schema_array ) {
				// If the schema is not an array, apply the sanitizer to the value.
				$tree[ $key ] = self::apply_sanitizer( $value, $schema[ $key ] );
			}

			// Remove keys with null/empty values.
			if ( empty( $tree[ $key ] ) ) {
				unset( $tree[ $key ] );
			}
		}

		return $tree;
	}

	/**
	 * Applies a sanitizer function to a value.
	 *
	 * @since 6.5.0
	 *
	 * @param mixed    $value     The value to sanitize.
	 * @param callable $sanitizer The sanitizer function to apply.
	 * @return mixed The sanitized value.
	 */
	private static function apply_sanitizer( $value, $sanitizer ) {
		if ( null === $sanitizer ) {
			return $value;

		}
		return call_user_func( $sanitizer, $value );
	}

	/**
	 * Returns the expected mime-type values for font files, depending on PHP version.
	 *
	 * This is needed because the mime types detected for WOFF and WOFF2 files vary by PHP version,
	 * so checking the PHP version is necessary until a list of valid mime-types for each file
	 * extension can be provided to the 'upload_mimes' filter.
	 *
	 * TrueType fonts can be detected as either font/ttf or font/sfnt depending on the libmagic
	 * version, and both are accepted by wp_check_filetype_and_ext(). The canonical font/ttf
	 * is returned for them on all PHP versions.
	 *
	 * @since 6.5.0
	 * @since 7.2.0 TrueType fonts now use the font/ttf mime type on all PHP versions.
	 *
	 * @access private
	 *
	 * @return string[] A collection of mime types keyed by file extension.
	 */
	public static function get_allowed_font_mime_types() {
		return array(
			'otf'   => 'application/vnd.ms-opentype',
			'ttf'   => 'font/ttf',
			'woff'  => PHP_VERSION_ID >= 80112 ? 'font/woff' : 'application/font-woff',
			'woff2' => PHP_VERSION_ID >= 80112 ? 'font/woff2' : 'application/font-woff2',
		);
	}

	/**
	 * Returns the `font-family` descriptor for an `@font-face` rule.
	 *
	 * The descriptor names one font family. It cannot hold a fallback list.
	 * For compatibility with existing data, the method selects the first entry
	 * of a list. The value can be CSS, such as `"ACME, Sans", serif`, or a plain
	 * name, such as `O'Reilly Sans`. The method always returns a quoted CSS string.
	 *
	 * @since 7.3.0
	 * @access private
	 *
	 * @param string $value CSS `font-family` value, or a plain font name.
	 * @return string The font name as a quoted CSS string, or an empty string if
	 *                the value names no font.
	 */
	public static function get_font_face_family( $value ) {
		$entries = self::parse_font_family_list( $value, true );

		if ( null === $entries || 'keyword' === $entries[0]['type'] || '' === $entries[0]['value'] ) {
			return '';
		}

		return WP_CSS_Builder::string( $entries[0]['value'] );
	}

	/**
	 * Parses a CSS `font-family` value into a list of entries.
	 *
	 * Each entry of the comma-separated list is a quoted name, a sequence of
	 * identifiers that names a family, a generic family keyword, or `generic()`
	 * with one of the defined arguments. A CSS-wide keyword, such as `inherit`,
	 * is valid only as the whole value. Whitespace and comments can appear
	 * around and between the parts of an entry.
	 *
	 * The strict mode requires valid CSS. The plain name mode reads each entry
	 * that is not valid CSS as a plain name, which earlier WordPress versions
	 * accepted, such as `O'Reilly Sans` or `Bodoni*`:
	 *
	 * - The text of the entry up to its first comma is one name.
	 * - The text after that comma is read again as a list. In that second
	 *   reading, an entry that is not valid CSS splits at each comma, and
	 *   each part is read on its own.
	 * - An empty entry is skipped. A name with a control character makes the
	 *   whole value invalid.
	 *
	 * No byte is read more than three times, so the time is linear in the
	 * length of the value.
	 *
	 * Each entry is an array with these keys:
	 *
	 *     @type string $type   One of 'name', 'generic', or 'keyword'.
	 *     @type string $value  For 'name', the decoded font name. For 'generic' and
	 *                          'keyword', the canonical CSS text.
	 *     @type bool   $quoted Optional. True if a CSS string quotes the name.
	 *
	 * Example:
	 *
	 *     WP_Font_Utils::parse_font_family_list( "Arial, O'Reilly Sans, serif", true );
	 *     // array(
	 *     //     array( 'type' => 'name', 'value' => 'Arial' ),
	 *     //     array( 'type' => 'name', 'value' => "O'Reilly Sans" ),
	 *     //     array( 'type' => 'generic', 'value' => 'serif' ),
	 *     // )
	 *
	 * @since 7.3.0
	 * @access private
	 *
	 * @link https://www.w3.org/TR/css-fonts-4/#font-family-prop
	 *
	 * @param string $value             CSS `font-family` value, or a plain font name.
	 * @param bool   $allow_plain_names Optional. Whether to read an entry that is not valid
	 *                                  CSS as a plain name. Default false.
	 * @return array[]|null List of parsed entries, or null if the value is invalid.
	 */
	public static function parse_font_family_list( $value, $allow_plain_names = false ) {
		// Reject invalid UTF-8 rather than replace characters in a name.
		if ( ! is_string( $value ) || ! wp_is_valid_utf8( $value ) ) {
			return null;
		}

		$entries = array();
		if ( ! self::read_font_family_list( $value, $allow_plain_names, 0, $entries ) || array() === $entries ) {
			return null;
		}

		return $entries;
	}

	/**
	 * Reads a comma-separated list of font families.
	 *
	 * @since 7.3.0
	 *
	 * @see WP_Font_Utils::parse_font_family_list()
	 *
	 * @param string  $css               CSS text of the list.
	 * @param bool    $allow_plain_names Whether to read an entry that is not valid CSS as a plain name.
	 * @param int     $depth             0 for the value, 1 for a second reading, 2 for a part of an entry.
	 * @param array[] $entries           Parsed entries. Passed by reference.
	 * @return bool False if the value is invalid.
	 */
	private static function read_font_family_list( $css, $allow_plain_names, $depth, &$entries ) {
		$groups = WP_CSS_Token_Processor::parse_comma_separated_list( $css );
		$last   = count( $groups ) - 1;
		$start  = 0;

		foreach ( $groups as $i => $tokens ) {
			$last_token = end( $tokens );
			$end        = false === $last_token ? $start : $last_token->get_start() + $last_token->get_length();
			$entry      = self::read_font_family_entry( $tokens );

			// A CSS-wide keyword is valid only as the whole value.
			if ( null !== $entry && 'keyword' === $entry['type'] && ( array() !== $entries || 0 !== $depth || $i !== $last ) ) {
				$entry = null;
			}

			if ( null !== $entry ) {
				$entries[] = $entry;
			} elseif ( ! $allow_plain_names ) {
				return false;
			} else {
				$text  = substr( $css, $start, $end - $start );
				$comma = strpos( $text, ',' );

				if ( ! self::add_plain_font_family_name( false === $comma ? $text : substr( $text, 0, $comma ), $entries ) ) {
					return false;
				}

				if ( false !== $comma ) {
					$rest  = substr( $text, $comma + 1 );
					$parts = 0 === $depth ? array( $rest ) : explode( ',', $rest );
					foreach ( $parts as $part ) {
						if ( ! self::read_font_family_list( $part, true, $depth + 1, $entries ) ) {
							return false;
						}
					}
				}
			}

			$start = $end + 1;
		}

		return true;
	}

	/**
	 * Reads one entry of a font family list.
	 *
	 * @since 7.3.0
	 *
	 * @param WP_CSS_Token[] $tokens Tokens of the entry, without the commas around it.
	 * @return array|null The parsed entry, or null if the tokens are not one valid entry.
	 */
	private static function read_font_family_entry( array $tokens ) {
		$state       = 'start';
		$entry       = null;
		$identifiers = array();
		$argument    = '';

		foreach ( $tokens as $token ) {
			// Every part of a valid entry ends before the end of the input cuts it short.
			if ( ! $token->is_terminated() || $token->ends_with_eof_escape() ) {
				return null;
			}

			if ( $token->is_whitespace_or_comment() ) {
				continue;
			}

			$type = $token->get_type();

			if ( 'start' === $state && WP_CSS_Token_Processor::TOKEN_STRING === $type ) {
				$entry = array(
					'type'   => 'name',
					'value'  => $token->get_value(),
					'quoted' => true,
				);
				$state = 'end';
			} elseif ( ( 'start' === $state || 'identifiers' === $state ) && WP_CSS_Token_Processor::TOKEN_IDENT === $type ) {
				$identifiers[] = $token->get_value();
				$state         = 'identifiers';
			} elseif ( 'start' === $state && WP_CSS_Token_Processor::TOKEN_FUNCTION === $type && 'generic' === strtolower( $token->get_value() ) ) {
				$state = 'generic-argument';
			} elseif ( 'generic-argument' === $state && WP_CSS_Token_Processor::TOKEN_IDENT === $type && in_array( strtolower( $token->get_value() ), self::GENERIC_FUNCTION_ARGUMENTS, true ) ) {
				$argument = strtolower( $token->get_value() );
				$state    = 'generic-close';
			} elseif ( 'generic-close' === $state && WP_CSS_Token_Processor::TOKEN_RIGHT_PAREN === $type ) {
				$entry = array(
					'type'  => 'generic',
					'value' => 'generic(' . $argument . ')',
				);
				$state = 'end';
			} else {
				return null;
			}
		}

		if ( 'identifiers' !== $state ) {
			return 'end' === $state ? $entry : null;
		}

		$name = implode( ' ', $identifiers );

		if ( 1 === count( $identifiers ) ) {
			$lowercase = strtolower( $name );

			if ( in_array( $lowercase, self::GENERIC_FONT_FAMILIES, true ) ) {
				return array(
					'type'  => 'generic',
					'value' => $lowercase,
				);
			}

			if ( in_array( $lowercase, self::RESERVED_FONT_FAMILY_KEYWORDS, true ) ) {
				return array(
					'type'  => 'keyword',
					'value' => $lowercase,
				);
			}
		}

		return array(
			'type'  => 'name',
			'value' => $name,
		);
	}

	/**
	 * Adds raw text as a plain font name.
	 *
	 * A font file can name its family with any text, such as `Bodoni*` or
	 * `Font (Display)`, and an upload client can send that text as it is.
	 * The serializer escapes every character that CSS or HTML reads, so the
	 * text stays one inert font name. Only control characters are rejected.
	 *
	 * @since 7.3.0
	 *
	 * @param string  $text    Raw text of the name.
	 * @param array[] $entries Parsed entries. Passed by reference.
	 * @return bool False if the text holds a control character.
	 */
	private static function add_plain_font_family_name( $text, &$entries ) {
		$name = trim( $text, " \t\n\r\f" );

		if ( '' === $name ) {
			return true;
		}

		// CSS input preprocessing replaces NULL with U+FFFD.
		$name = str_replace( "\0", "\u{FFFD}", $name );

		if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $name ) ) {
			return false;
		}

		$entries[] = array(
			'type'  => 'name',
			'value' => $name,
		);

		return true;
	}

	/**
	 * Serializes a list of parsed entries as a CSS `font-family` value.
	 *
	 * An explicitly quoted name keeps its quotes. Each other name that is one
	 * identifier of ASCII letters and hyphens stays unquoted, such as `Arial`
	 * or `-apple-system`. Some browsers read a system font keyword, such as
	 * `-apple-system`, only when it has no quotes. Each other name is a quoted
	 * CSS string.
	 *
	 * @since 7.3.0
	 *
	 * @param array[] $entries List of parsed entries.
	 * @return string The CSS `font-family` value.
	 */
	private static function serialize_font_family_list( $entries ) {
		$parts = array();

		foreach ( $entries as $entry ) {
			if ( 'name' !== $entry['type'] || ( empty( $entry['quoted'] ) && self::is_unquoted_font_family_name( $entry['value'] ) ) ) {
				$parts[] = $entry['value'];
			} else {
				$parts[] = WP_CSS_Builder::string( $entry['value'] );
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Checks whether a font name can be written as an unquoted identifier.
	 *
	 * The name must be one identifier of ASCII letters and hyphens. It must not
	 * be a generic family or a reserved keyword, because without quotes the
	 * name would have a different meaning.
	 *
	 * @since 7.3.0
	 *
	 * @param string $name Decoded font name.
	 * @return bool True if the name can be written without quotes.
	 */
	private static function is_unquoted_font_family_name( $name ) {
		if ( 1 !== preg_match( '/\A-?[a-zA-Z][a-zA-Z-]*\z/', $name ) ) {
			return false;
		}

		$lowercase = strtolower( $name );

		return ! in_array( $lowercase, self::GENERIC_FONT_FAMILIES, true )
			&& ! in_array( $lowercase, self::RESERVED_FONT_FAMILY_KEYWORDS, true );
	}
}
