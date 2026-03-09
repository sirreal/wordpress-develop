<?php
/**
 * HTML API: WP_HTML_Template class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 7.1.0
 */

class WP_HTML_Template {
	/**
	 * Map of characters to their HTML entity equivalents for escaping.
	 *
	 * @since 7.1.0
	 *
	 * @var array<string, string>
	 */
	private const ESCAPE_MAP = array(
		'&' => '&amp;',
		'<' => '&lt;',
		'>' => '&gt;',
		"'" => '&apos;',
		'"' => '&quot;',
	);

	/**
	 * The template string.
	 *
	 * @since 7.1.0
	 *
	 * @var string
	 */
	private string $template_string;

	/**
	 * The replacement values for placeholders.
	 *
	 * @since 7.1.0
	 *
	 * @var array<string, string|self|true|false|null>
	 */
	private array $replacements;

	private function __construct( string $template_string, array $replacements ) {
		$this->template_string = $template_string;
		$this->replacements    = $replacements;
	}

	/**
	 * Creates a bound template for use as a replacement value in another template.
	 *
	 * This is a data container — it stores the template string and replacements
	 * without parsing or processing. Processing happens when the parent template
	 * is rendered, which provides the correct parsing context.
	 *
	 * @since 7.1.0
	 *
	 * @param string $template     The template string with placeholders.
	 * @param array  $replacements The replacement values for placeholders.
	 * @return static The bound template instance.
	 */
	public static function template( string $template, array $replacements = array() ) {
		return new static( $template, $replacements );
	}

	/**
	 * Renders a template to an HTML string.
	 *
	 * Processes the template in a single pass using WP_HTML_Processor. Placeholders
	 * (`</%name>`) in text context are replaced with escaped values. Placeholders in
	 * attribute values are found via regex scanning and replaced with escaped values.
	 *
	 * Nested templates (WP_HTML_Template values) are parsed at render time in the
	 * parent's parsing context, enabling correct handling of table elements and
	 * other context-dependent HTML.
	 *
	 * @since 7.1.0
	 *
	 * @param string $template     The template string with placeholders.
	 * @param array  $replacements The replacement values for placeholders.
	 * @return string|false The rendered HTML, or false on error.
	 */
	public static function render( string $template, array $replacements = array() ) {
		if ( empty( $replacements ) ) {
			return WP_HTML_Processor::normalize( $template ) ?? false;
		}

		$instance  = new static( $template, $replacements );
		$processor = static::create_processor( $template );
		if ( null === $processor ) {
			return false;
		}

		return static::process( $instance, $processor );
	}

	/**
	 * Creates an extended WP_HTML_Processor that exposes internals needed for template processing.
	 *
	 * @since 7.1.0
	 *
	 * @param string $html The HTML fragment to parse.
	 * @return WP_HTML_Processor|null The processor, or null on failure.
	 */
	private static function create_processor( string $html ) {
		return ( new class( '', WP_HTML_Processor::CONSTRUCTOR_UNLOCK_CODE ) extends WP_HTML_Processor {
			public function get_html(): string {
				return $this->html;
			}

			public function get_tag_attributes(): array {
				return Closure::bind( fn () => $this->attributes, $this, WP_HTML_Tag_Processor::class )();
			}

			/**
			 * Creates a fragment processor using the given element as context.
			 *
			 * For most elements, uses standard body context parsing. For table-related
			 * elements, creates a properly-nested wrapper to establish the correct
			 * insertion mode (e.g., IN_TABLE_BODY for content inside `<tbody>`).
			 *
			 * @param string $html            The HTML fragment to parse.
			 * @param string $context_element The context element tag name (e.g., 'TBODY').
			 * @return static|null The fragment processor, or null on failure.
			 */
			public function create_fragment_for_context( string $html, string $context_element ): ?static {
				/*
				 * Table-related elements need special context to parse correctly.
				 * Build a wrapper that creates the right insertion mode, then use
				 * create_fragment_at_current_node() to parse the child HTML.
				 */
				$table_contexts = array(
					'TABLE'    => '<table>',
					'THEAD'    => '<table><thead>',
					'TBODY'    => '<table><tbody>',
					'TFOOT'    => '<table><tfoot>',
					'TR'       => '<table><tbody><tr>',
					'TD'       => '<table><tbody><tr><td>',
					'TH'       => '<table><tbody><tr><th>',
					'CAPTION'  => '<table><caption>',
					'COLGROUP' => '<table><colgroup>',
				);

				$wrapper_html = $table_contexts[ $context_element ] ?? null;

				if ( null === $wrapper_html ) {
					// Non-table context: body context is correct.
					return static::create_fragment( $html );
				}

				// Parse wrapper to position at the target context element.
				$wrapper = static::create_fragment( $wrapper_html . '<span>x</span>' );
				if ( null === $wrapper ) {
					return null;
				}

				// Walk to the target context element.
				while ( $wrapper->next_token() ) {
					if ( '#tag' === $wrapper->get_token_type()
						&& ! $wrapper->is_tag_closer()
						&& $context_element === $wrapper->get_tag()
					) {
						$create_fn = Closure::bind(
							function () use ( $html ) {
								return $this->create_fragment_at_current_node( $html );
							},
							$wrapper,
							WP_HTML_Processor::class
						);
						return $create_fn();
					}
				}
				return null;
			}
		} )::create_fragment( $html );
	}

	/**
	 * Processes a template with a given processor, building the output string.
	 *
	 * Walks all tokens in the processor, serializing each one. Placeholders in
	 * text context (funky comments) and attribute values are detected and replaced.
	 *
	 * @since 7.1.0
	 *
	 * @param self              $template  The template with replacements.
	 * @param WP_HTML_Processor $processor The processor to walk.
	 * @return string|false The rendered HTML, or false on error.
	 */
	private static function process( self $template, WP_HTML_Processor $processor ) {
		$output    = '';
		$used_keys = array();

		while ( $processor->next_token() ) {
			$token_type = $processor->get_token_type();

			switch ( $token_type ) {
				case '#funky-comment':
					$result = static::process_placeholder( $processor, $template, $used_keys );
					if ( false === $result ) {
						return false;
					}
					if ( null !== $result ) {
						$output .= $result;
					} else {
						// Not a placeholder — serialize normally.
						$output .= $processor->serialize_token();
					}
					break;

				case '#tag':
					if ( $processor->is_tag_closer() ) {
						$output .= $processor->serialize_token();
						break;
					}
					$result = static::process_tag( $processor, $template, $used_keys );
					if ( false === $result ) {
						return false;
					}
					$output .= $result;
					break;

				default:
					$output .= $processor->serialize_token();
					break;
			}
		}

		// Validate: all replacement keys were used.
		if ( count( $used_keys ) !== count( $template->replacements ) ) {
			foreach ( $template->replacements as $key => $_ ) {
				if ( ! isset( $used_keys[ $key ] ) ) {
					_doing_it_wrong(
						__CLASS__ . '::render',
						sprintf(
							/* translators: %s: The unused replacement key name. */
							__( 'Unused replacement key: %s.' ),
							$key
						),
						'7.1.0'
					);
				}
			}
			return false;
		}

		return $output;
	}

	/**
	 * Attempts to process a funky comment as a placeholder.
	 *
	 * @since 7.1.0
	 *
	 * @param WP_HTML_Processor $processor  The processor positioned at a funky comment.
	 * @param self              $template   The template with replacements.
	 * @param array             &$used_keys Tracks which replacement keys have been used.
	 * @return string|false|null The replacement string, false on error, or null if not a placeholder.
	 */
	private static function process_placeholder(
		WP_HTML_Processor $processor,
		self $template,
		array &$used_keys
	) {
		$text = $processor->get_modifiable_text();

		// Must start with `%`.
		if ( '' === $text || '%' !== $text[0] ) {
			return null;
		}

		$placeholder = trim( substr( $text, 1 ), " \t\n\r\f" );

		// Valid placeholders match `/[a-z][a-z0-9_-]*/i`.
		if (
			'' === $placeholder ||
			! ctype_alpha( $placeholder[0] ) ||
			strlen( $placeholder ) !== strspn( $placeholder, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-' )
		) {
			return null;
		}

		if ( ! array_key_exists( $placeholder, $template->replacements ) ) {
			_doing_it_wrong(
				__CLASS__ . '::render',
				sprintf(
					/* translators: %s: The placeholder name. */
					__( 'Missing replacement for placeholder: %s.' ),
					$placeholder
				),
				'7.1.0'
			);
			return false;
		}

		$value                     = $template->replacements[ $placeholder ];
		$used_keys[ $placeholder ] = true;

		if ( is_string( $value ) ) {
			return strtr( $value, self::ESCAPE_MAP );
		}

		if ( $value instanceof self ) {
			// Get the parent context element from breadcrumbs.
			// The last breadcrumb is the current token (the funky comment);
			// the parent element is the second-to-last entry.
			$breadcrumbs     = $processor->get_breadcrumbs();
			$context_element = $breadcrumbs[ count( $breadcrumbs ) - 2 ] ?? 'BODY';

			// Create a child processor in the correct context.
			$child_processor = $processor->create_fragment_for_context(
				$value->template_string,
				$context_element
			);

			if ( null === $child_processor ) {
				return false;
			}

			if ( empty( $value->replacements ) ) {
				return static::serialize_all( $child_processor );
			}

			return static::process( $value, $child_processor );
		}

		// Boolean and null are invalid in text context.
		_doing_it_wrong(
			__CLASS__ . '::render',
			sprintf(
				/* translators: %s: The placeholder name. */
				__( 'Invalid replacement type for text placeholder: %s.' ),
				$placeholder
			),
			'7.1.0'
		);
		return false;
	}

	/**
	 * Processes an opening tag, handling attribute placeholders.
	 *
	 * If no attribute contains a placeholder, delegates to serialize_token().
	 * Otherwise, builds the tag manually with placeholder replacements.
	 *
	 * @since 7.1.0
	 *
	 * @param WP_HTML_Processor $processor  The processor positioned at an opening tag.
	 * @param self              $template   The template with replacements.
	 * @param array             &$used_keys Tracks which replacement keys have been used.
	 * @return string|false The serialized tag HTML, or false on error.
	 */
	private static function process_tag(
		WP_HTML_Processor $processor,
		self $template,
		array &$used_keys
	): string|false {
		$attributes = $processor->get_tag_attributes();
		$raw_html   = $processor->get_html();

		// Quick check: does any attribute value contain a placeholder pattern?
		$has_placeholder = false;
		foreach ( $attributes as $attribute ) {
			if ( $attribute->is_true ) {
				continue;
			}
			if ( $attribute->value_length >= 5 ) {
				$raw_value = substr( $raw_html, $attribute->value_starts_at, $attribute->value_length );
				if ( str_contains( $raw_value, '</%' ) ) {
					$has_placeholder = true;
					break;
				}
			}
		}

		if ( ! $has_placeholder ) {
			return $processor->serialize_token();
		}

		// Build the tag manually to handle attribute placeholders.
		$tag_name       = str_replace( "\x00", "\u{FFFD}", $processor->get_tag() );
		$in_html        = 'html' === $processor->get_namespace();
		$qualified_name = $in_html ? strtolower( $tag_name ) : $processor->get_qualified_tag_name();

		$html = "<{$qualified_name}";

		// Track attributes to skip (removed by false/null).
		$skip_attributes = array();

		foreach ( $attributes as $attribute ) {
			if ( isset( $skip_attributes[ $attribute->name ] ) ) {
				continue;
			}

			if ( $attribute->is_true ) {
				$html .= " {$attribute->name}";
				continue;
			}

			// Check for placeholders in the raw attribute value.
			$raw_value = substr( $raw_html, $attribute->value_starts_at, $attribute->value_length );
			if ( ! str_contains( $raw_value, '</%' ) ) {
				// No placeholder — use standard serialization.
				$decoded = $processor->get_attribute( $attribute->name );
				if ( is_string( $decoded ) ) {
					$html .= ' ' . $attribute->name . '="' . htmlspecialchars( $decoded, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ) . '"';
				} else {
					$html .= " {$attribute->name}";
				}
				continue;
			}

			// Scan for placeholders in the attribute value.
			$result = static::process_attribute_value(
				$raw_value,
				$attribute,
				$template,
				$used_keys,
				$skip_attributes
			);

			if ( false === $result ) {
				return false;
			}

			if ( null === $result ) {
				// Attribute was removed (false/null replacement).
				continue;
			}

			$html .= $result;
		}

		if ( ! $in_html && $processor->has_self_closing_flag() ) {
			$html .= ' /';
		}

		$html .= '>';

		// Handle PRE/TEXTAREA/LISTING leading newline.
		if ( 'TEXTAREA' === $tag_name || 'PRE' === $tag_name || 'LISTING' === $tag_name ) {
			$html .= "\n";
		}

		// Handle self-contained elements (their content + closing tag is part of this token).
		if ( $in_html && in_array( $tag_name, array( 'IFRAME', 'NOEMBED', 'NOFRAMES', 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'XMP' ), true ) ) {
			$text = $processor->get_modifiable_text();

			switch ( $tag_name ) {
				case 'IFRAME':
				case 'NOEMBED':
				case 'NOFRAMES':
					$text = '';
					break;

				case 'SCRIPT':
				case 'STYLE':
					break;

				default:
					$text = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
			}

			$html .= "{$text}</{$qualified_name}>";
		}

		return $html;
	}

	/**
	 * Processes an attribute value that contains placeholder(s).
	 *
	 * @since 7.1.0
	 *
	 * @param string $raw_value       The raw attribute value from the HTML.
	 * @param object $attribute       The attribute token object.
	 * @param self   $template        The template with replacements.
	 * @param array  &$used_keys      Tracks which replacement keys have been used.
	 * @param array  &$skip_attributes Attributes to skip in serialization.
	 * @return string|false|null The serialized attribute, false on error, or null if removed.
	 */
	private static function process_attribute_value(
		string $raw_value,
		$attribute,
		self $template,
		array &$used_keys,
		array &$skip_attributes
	): string|false|null {
		$offset     = 0;
		$end        = strlen( $raw_value );
		$value_html = '';

		// Is this a single placeholder that covers the entire attribute value?
		$is_whole_attribute = (bool) preg_match(
			'#^</%[ \\t\\r\\f\\n]*[a-z][a-z0-9_-]*[ \\t\\r\\f\\n]*>$#i',
			$raw_value
		);

		while (
			1 === preg_match(
				'#</%[ \\t\\r\\f\\n]*([a-z][a-z0-9_-]*)[ \\t\\r\\f\\n]*>#i',
				$raw_value,
				$matches,
				PREG_OFFSET_CAPTURE,
				$offset
			)
			&& $matches[0][1] < $end
		) {
			$placeholder  = $matches[1][0];
			$match_start  = $matches[0][1];
			$match_length = strlen( $matches[0][0] );

			if ( ! array_key_exists( $placeholder, $template->replacements ) ) {
				_doing_it_wrong(
					__CLASS__ . '::render',
					sprintf(
						/* translators: %s: The placeholder name. */
						__( 'Missing replacement for placeholder: %s.' ),
						$placeholder
					),
					'7.1.0'
				);
				return false;
			}

			$value                     = $template->replacements[ $placeholder ];
			$used_keys[ $placeholder ] = true;

			// Template in attribute context is invalid.
			if ( $value instanceof self ) {
				_doing_it_wrong(
					__CLASS__ . '::render',
					sprintf(
						/* translators: %s: The placeholder name. */
						__( 'Template cannot be used in attribute context: %s.' ),
						$placeholder
					),
					'7.1.0'
				);
				return false;
			}

			// Boolean handling — only valid for whole-attribute placeholders.
			if ( true === $value ) {
				if ( ! $is_whole_attribute ) {
					return false;
				}
				// Convert to boolean attribute (just the name, no value).
				return " {$attribute->name}";
			}

			if ( false === $value || null === $value ) {
				if ( ! $is_whole_attribute ) {
					return false;
				}
				// Remove the attribute entirely.
				$skip_attributes[ $attribute->name ] = true;
				return null;
			}

			if ( ! is_string( $value ) ) {
				_doing_it_wrong(
					__CLASS__ . '::render',
					sprintf(
						/* translators: %s: The placeholder name. */
						__( 'Invalid replacement type for attribute placeholder: %s.' ),
						$placeholder
					),
					'7.1.0'
				);
				return false;
			}

			// Static text before placeholder.
			if ( $match_start > $offset ) {
				$segment     = substr( $raw_value, $offset, $match_start - $offset );
				$decoded     = WP_HTML_Decoder::decode_attribute( $segment );
				$value_html .= strtr( $decoded, self::ESCAPE_MAP );
			}

			// Escaped replacement value.
			$value_html .= strtr( $value, self::ESCAPE_MAP );

			$offset = $match_start + $match_length;
		}

		// Trailing static text after last placeholder.
		if ( $offset < $end ) {
			$segment     = substr( $raw_value, $offset );
			$decoded     = WP_HTML_Decoder::decode_attribute( $segment );
			$value_html .= strtr( $decoded, self::ESCAPE_MAP );
		}

		return ' ' . $attribute->name . '="' . $value_html . '"';
	}

	/**
	 * Serializes all tokens from a processor into a string.
	 *
	 * @since 7.1.0
	 *
	 * @param WP_HTML_Processor $processor The processor to serialize.
	 * @return string The serialized HTML.
	 */
	private static function serialize_all( WP_HTML_Processor $processor ): string {
		$html = '';
		while ( $processor->next_token() ) {
			$html .= $processor->serialize_token();
		}
		return $html;
	}
}
