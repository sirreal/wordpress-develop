<?php
/**
 * HTML API: WP_HTML_Template class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 7.0.0
 */

class WP_HTML_Template {
	/**
	 * The template string.
	 *
	 * @since 7.0.0
	 *
	 * @var string
	 */
	private string $template_string;

	private array $replacements = array();

	/**
	 * Compiled placeholder metadata.
	 *
	 * Array of placeholder_name => array with keys:
	 * - 'offsets': array of [start, length] pairs for each occurrence
	 * - 'context': 'text' or 'attribute' (attribute takes precedence)
	 *
	 * @since 7.0.0
	 * @var array|null
	 */
	private ?array $compiled = null;

	/**
	 * Text normalizations discovered during compilation.
	 *
	 * Array of [start, length, normalized_text] tuples for #text tokens
	 * whose serialized form differs from the original HTML.
	 *
	 * @since 7.0.0
	 * @var array
	 */
	private array $text_normalizations = array();

	/**
	 * Attribute text segments needing escaping.
	 *
	 * Array of [start, length] pairs for text between/before placeholders
	 * within attribute values.
	 *
	 * @since 7.0.0
	 * @var array
	 */
	private array $attr_escapes = array();

	/**
	 * Unified edit operations list.
	 *
	 * Flat array in document order (ascending offsets). Each entry is one of:
	 *
	 * Pre-computed replacement (normalizations, escapes):
	 *   ['start' => int, 'length' => int, 'replacement' => string]
	 *
	 * Placeholder reference (render-time lookup):
	 *   ['start' => int, 'length' => int, 'placeholder' => string, 'context' => 'text'|'attribute']
	 *
	 * @since 7.0.0
	 * @var array
	 */
	private array $edits = array();

	/**
	 * Placeholder names for O(1) validation.
	 *
	 * @since 7.0.0
	 * @var array<string, true>
	 */
	private array $placeholder_names = array();

	/**
	 * Returns the compiled placeholder metadata.
	 *
	 * Triggers compilation if not already done.
	 *
	 * @since 7.0.0
	 *
	 * @return array Associative array of placeholder_name => metadata.
	 */
	public function get_placeholders(): array {
		$this->compile();
		return $this->compiled;
	}

	/**
	 * Compiles the template to extract placeholder metadata.
	 *
	 * Parses the template once and caches placeholder positions, lengths,
	 * and contexts. If a placeholder appears in both text and attribute
	 * contexts, the attribute context takes precedence (more restrictive).
	 *
	 * @since 7.0.0
	 */
	private function compile(): void {
		if ( null !== $this->compiled ) {
			return;
		}

		$this->compiled            = array();
		$this->text_normalizations = array();
		$this->attr_escapes        = array();
		$this->edits               = array();
		$this->placeholder_names   = array();

		$processor = ( new class( '', WP_HTML_Processor::CONSTRUCTOR_UNLOCK_CODE ) extends WP_HTML_Processor {
			public function get_html(): string {
				return $this->html;
			}

			public function get_bookmark( string $name ) {
				return $this->bookmarks[ "_{$name}" ] ?? null;
			}

			public function get_tag_attributes(): array {
				return $this->attributes;
			}
		} )::create_fragment( $this->template_string );

		if ( null === $processor ) {
			return;
		}

		while ( $processor->next_token() ) {
			switch ( $processor->get_token_type() ) {
				/*
				 * Track text normalizations to prevent something like
				 * `a<</%tag-name>u` from becoming `a<i>u` after replacement.
				 */
				case '#text':
					$processor->set_bookmark( 'text' );
					$mark = $processor->get_bookmark( 'text' );
					if ( null === $mark ) {
						break;
					}
					$normalized = $processor->serialize_token();
					if ( 0 !== substr_compare( $processor->get_html(), $normalized, $mark->start, $mark->length ) ) {
						// Legacy: keep text_normalizations for now (parallel arrays during migration).
						$this->text_normalizations[] = array( $mark->start, $mark->length, $normalized );
						// New: append pre-computed replacement to edits.
						$this->edits[] = array(
							'start'       => $mark->start,
							'length'      => $mark->length,
							'replacement' => $normalized,
						);
					}
					break;

				case '#funky-comment':
					$processor->set_bookmark( 'placeholder' );
					$mark = $processor->get_bookmark( 'placeholder' );
					if ( null === $mark ) {
						break;
					}

					$start  = $mark->start;
					$length = $mark->length;
					$html   = $processor->get_html();

					// Must be at least `</%x>` (5 chars) and start with `</%`
					if ( $length < 5 || '%' !== $html[ $start + 2 ] ) {
						break;
					}

					$placeholder = trim( substr( $html, $start + 3, $length - 4 ), " \t\n\r\f" );

					// Valid placeholders match `/[a-z0-9_-]+/i`
					if ( strlen( $placeholder ) !== strspn( $placeholder, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-' ) ) {
						break;
					}

					if ( ! isset( $this->compiled[ $placeholder ] ) ) {
						$this->compiled[ $placeholder ] = array(
							'offsets' => array(),
							'context' => 'text',
						);
					}

					$this->compiled[ $placeholder ]['offsets'][] = array( $start, $length );

					// New: append placeholder edit and register name.
					$this->edits[] = array(
						'start'       => $start,
						'length'      => $length,
						'placeholder' => $placeholder,
						'context'     => 'text',
					);
					$this->placeholder_names[ $placeholder ] = true;
					break;

				case '#tag':
					if ( $processor->is_tag_closer() ) {
						break;
					}

					$html = $processor->get_html();
					foreach ( $processor->get_tag_attributes() as $attribute ) {
						// Boolean attributes cannot contain placeholders.
						if ( $attribute->is_true ) {
							continue;
						}
						// At least `</%x>` to contain a placeholder.
						if ( $attribute->value_length < 5 ) {
							continue;
						}

						$last_offset = $attribute->value_starts_at;
						$offset      = $attribute->value_starts_at;
						$end         = $offset + $attribute->value_length;

						while (
							1 === preg_match(
								'#</%[ \\t\\r\\f\\n]*([a-z0-9_-]+)[ \\t\\r\\f\\n]*>#i',
								$html,
								$matches,
								PREG_OFFSET_CAPTURE,
								$offset
							)
							&& $matches[0][1] < $end
						) {
							$placeholder  = $matches[1][0];
							$match_start  = $matches[0][1];
							$match_length = strlen( $matches[0][0] );

							// Pre-compute escape for text segment before this placeholder.
							if ( $match_start > $last_offset ) {
								$seg_length = $match_start - $last_offset;
								$original   = substr( $html, $last_offset, $seg_length );
								$decoded    = WP_HTML_Decoder::decode_attribute( $original );
								$escaped    = strtr( $decoded, array(
									'&' => '&amp;',
									'<' => '&lt;',
									'>' => '&gt;',
									"'" => '&apos;',
									'"' => '&quot;',
								) );
								// Only add edit if escaping actually changes the text.
								if ( $escaped !== $original ) {
									$this->edits[] = array(
										'start'       => $last_offset,
										'length'      => $seg_length,
										'replacement' => $escaped,
									);
								}
								// Legacy: keep attr_escapes for now.
								$this->attr_escapes[] = array( $last_offset, $seg_length );
							}

							if ( ! isset( $this->compiled[ $placeholder ] ) ) {
								$this->compiled[ $placeholder ] = array(
									'offsets' => array(),
									'context' => 'attribute',
								);
							} else {
								// Promote text context to attribute context.
								$this->compiled[ $placeholder ]['context'] = 'attribute';
							}

							$this->compiled[ $placeholder ]['offsets'][] = array( $match_start, $match_length );

							// New: append placeholder edit and register name.
							$this->edits[] = array(
								'start'       => $match_start,
								'length'      => $match_length,
								'placeholder' => $placeholder,
								'context'     => 'attribute',
							);
							$this->placeholder_names[ $placeholder ] = true;

							$last_offset = $match_start + $match_length;
							$offset      = $last_offset;
						}

						// Pre-compute escape for trailing text segment after last placeholder.
						if ( $last_offset < $end ) {
							$seg_length = $end - $last_offset;
							$original   = substr( $html, $last_offset, $seg_length );
							$decoded    = WP_HTML_Decoder::decode_attribute( $original );
							$escaped    = strtr( $decoded, array(
								'&' => '&amp;',
								'<' => '&lt;',
								'>' => '&gt;',
								"'" => '&apos;',
								'"' => '&quot;',
							) );
							// Only add edit if escaping actually changes the text.
							if ( $escaped !== $original ) {
								$this->edits[] = array(
									'start'       => $last_offset,
									'length'      => $seg_length,
									'replacement' => $escaped,
								);
							}
							// Legacy: keep attr_escapes for now.
							$this->attr_escapes[] = array( $last_offset, $seg_length );
						}
					}
					break;
			}
		}
	}

	private function __construct( string $template_string, array $replacements ) {
		$this->template_string = $template_string;
		$this->replacements    = $replacements;
	}

	/**
	 * Creates a template from a string.
	 *
	 * @since 7.0.0
	 *
	 * @param string $template The template string with placeholders.
	 * @return static The template instance.
	 */
	public static function from( string $template ): static {
		return new static( $template, array() );
	}

	/**
	 * Returns a new immutable instance with replacements bound.
	 *
	 * Triggers compilation if not already done. Validates replacements:
	 * - Warns if a placeholder has no corresponding replacement
	 * - Warns if a replacement key has no corresponding placeholder
	 * - Warns if a template is used in attribute context
	 *
	 * @since 7.0.0
	 *
	 * @param array $replacements The replacement values.
	 * @return static A new template instance with the replacements bound.
	 */
	public function bind( array $replacements ): static {
		$this->compile();

		// Build a lookup of placeholder keys from compiled data.
		$placeholder_keys = array();
		foreach ( $this->compiled as $placeholder => $info ) {
			$placeholder = (string) $placeholder;
			$placeholder_keys[ $placeholder ] = true;
			if ( ctype_digit( $placeholder ) ) {
				$placeholder_keys[ (int) $placeholder ] = true;
			}
		}

		// Build a lookup of replacement keys.
		$replacement_keys = array();
		foreach ( $replacements as $key => $value ) {
			$replacement_keys[ (string) $key ] = true;
			if ( is_int( $key ) ) {
				$replacement_keys[ $key ] = true;
			}
		}

		// Check for missing keys (placeholder without replacement).
		foreach ( $this->compiled as $placeholder => $info ) {
			$placeholder = (string) $placeholder;
			$found       = isset( $replacement_keys[ $placeholder ] );
			if ( ! $found && ctype_digit( $placeholder ) ) {
				$found = isset( $replacement_keys[ (int) $placeholder ] ) || array_key_exists( (int) $placeholder, $replacements );
			}
			if ( ! $found ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'Missing replacement for placeholder: %s',
						$placeholder
					),
					'7.0.0'
				);
			}
		}

		// Check for unused keys (replacement without placeholder).
		foreach ( $replacements as $key => $value ) {
			$str_key = (string) $key;
			$found   = isset( $placeholder_keys[ $key ] ) || isset( $placeholder_keys[ $str_key ] );
			if ( ! $found ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'Unused replacement key: %s',
						$key
					),
					'7.0.0'
				);
			}
		}

		// Check for templates in attribute context.
		foreach ( $this->compiled as $placeholder => $info ) {
			$placeholder = (string) $placeholder;
			if ( 'attribute' !== $info['context'] ) {
				continue;
			}

			$key   = ctype_digit( $placeholder ) ? (int) $placeholder : $placeholder;
			$value = $replacements[ $key ] ?? $replacements[ $placeholder ] ?? null;

			if ( $value instanceof self ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'Template cannot be used in attribute context: %s',
						$placeholder
					),
					'7.0.0'
				);
			}
		}

		$new = new static( $this->template_string, $replacements );
		$new->compiled            = $this->compiled;
		$new->text_normalizations = $this->text_normalizations;
		$new->attr_escapes        = $this->attr_escapes;
		$new->edits               = $this->edits;
		$new->placeholder_names   = $this->placeholder_names;
		return $new;
	}

	/**
	 * Renders the template to an HTML string.
	 *
	 * Uses pre-compiled placeholder metadata to perform replacements
	 * without re-parsing. Collects all updates (placeholder replacements,
	 * text normalizations, attribute text escaping) and applies them
	 * from end to start using substr_replace() to preserve positions.
	 *
	 * Returns false on any error:
	 * - Missing replacement key (placeholder without corresponding replacement)
	 * - Unused replacement key (replacement without corresponding placeholder)
	 * - Template in attribute context
	 * - HTML processing/normalization failure
	 *
	 * @since 7.0.0
	 *
	 * @return string|false The rendered HTML, or false on error.
	 */
	public function render(): string|false {
		$this->compile();

		if ( empty( $this->replacements ) ) {
			return WP_HTML_Processor::normalize( $this->template_string ) ?? $this->template_string;
		}

		$escape_map = array(
			'&' => '&amp;',
			'<' => '&lt;',
			'>' => '&gt;',
			"'" => '&apos;',
			'"' => '&quot;',
		);

		$html      = $this->template_string;
		$used_keys = array();

		// Process edits in reverse order (end to start) to preserve positions.
		foreach ( array_reverse( $this->edits ) as $edit ) {
			if ( isset( $edit['placeholder'] ) ) {
				// Placeholder: look up replacement value.
				$placeholder = $edit['placeholder'];

				if ( array_key_exists( $placeholder, $this->replacements ) ) {
					$key = $placeholder;
				} elseif ( ctype_digit( $placeholder ) && array_key_exists( (int) $placeholder, $this->replacements ) ) {
					$key = (int) $placeholder;
				} else {
					return false;
				}

				$used_keys[ $key ] = true;
				$value             = $this->replacements[ $key ];

				if ( $value instanceof self ) {
					if ( 'attribute' === $edit['context'] ) {
						return false;
					}

					$rendered = $value->render();
					if ( false === $rendered ) {
						return false;
					}

					$html = substr_replace( $html, $rendered, $edit['start'], $edit['length'] );
				} elseif ( is_string( $value ) ) {
					$escaped = strtr( $value, $escape_map );
					$html    = substr_replace( $html, $escaped, $edit['start'], $edit['length'] );
				} else {
					return false;
				}
			} else {
				// Pre-computed replacement: apply directly.
				$html = substr_replace( $html, $edit['replacement'], $edit['start'], $edit['length'] );
			}
		}

		// Return false if any replacement key was not used.
		if ( count( $used_keys ) !== count( $this->replacements ) ) {
			return false;
		}

		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}
}
