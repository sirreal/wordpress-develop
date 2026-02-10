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

	/**
	 * The replacement values for placeholders.
	 *
	 * @since 7.0.0
	 *
	 * @var array<string, string|self>|null
	 */
	private ?array $replacements = null;

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
	 * @var null|array<array{'start': int, 'length': int, 'placeholder': string, 'context': 'text'|'attribute'}|WP_HTML_Text_Replacement>
	 */
	private ?array $edits = null;

	/**
	 * Placeholder names for O(1) validation.
	 *
	 * @since 7.0.0
	 * @var array<string, true>
	 */
	private array $placeholder_names = array();

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
		if ( null !== $this->edits ) {
			return;
		}

		$this->edits             = array();
		$this->placeholder_names = array();

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
						$this->edits[] = new WP_HTML_Text_Replacement(
							$mark->start,
							$mark->length,
							$normalized,
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

					// Valid placeholders match `/[a-z][a-z0-9_-]*/i` (must start with letter).
					if (
						'' === $placeholder ||
						! ctype_alpha( $placeholder[0] ) ||
						strlen( $placeholder ) !== strspn( $placeholder, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-' )
					) {
						break;
					}

					// New: append placeholder edit and register name.
					$this->edits[]                           = array(
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
								'#</%[ \\t\\r\\f\\n]*([a-z][a-z0-9_-]*)[ \\t\\r\\f\\n]*>#i',
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
								$escaped    = strtr(
									$decoded,
									array(
										'&' => '&amp;',
										'<' => '&lt;',
										'>' => '&gt;',
										"'" => '&apos;',
										'"' => '&quot;',
									)
								);
								// Only add edit if escaping actually changes the text.
								if ( $escaped !== $original ) {
									$this->edits[] = new WP_HTML_Text_Replacement(
										$last_offset,
										$seg_length,
										$escaped,
									);
								}
							}

							// New: append placeholder edit and register name.
							$this->edits[]                           = array(
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
							$escaped    = strtr(
								$decoded,
								array(
									'&' => '&amp;',
									'<' => '&lt;',
									'>' => '&gt;',
									"'" => '&apos;',
									'"' => '&quot;',
								)
							);
							// Only add edit if escaping actually changes the text.
							if ( $escaped !== $original ) {
								$this->edits[] = new WP_HTML_Text_Replacement(
									$last_offset,
									$seg_length,
									$escaped,
								);
							}
						}
					}
					break;
			}
		}
	}

	private function __construct( string $template_string, ?array $replacements ) {
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
	public static function from( string $template, ?array $replacements = null ): static {
		return new static( $template, $replacements );
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

		// Check for missing keys (placeholder without replacement).
		foreach ( $this->placeholder_names as $placeholder => $_ ) {
			if ( ! array_key_exists( $placeholder, $replacements ) ) {
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
			if ( ! isset( $this->placeholder_names[ $key ] ) ) {
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
		foreach ( $this->edits as $edit ) {
			if ( $edit instanceof WP_HTML_Text_Replacement || 'attribute' !== $edit['context'] ) {
				continue;
			}

			$placeholder = $edit['placeholder'];
			$value       = $replacements[ $placeholder ] ?? null;

			if ( $value instanceof self ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'Template cannot be used in attribute context: %s',
						$placeholder
					),
					'7.0.0'
				);
				break; // Only warn once per placeholder name.
			}
		}

		$new                    = new static( $this->template_string, $replacements );
		$new->edits             = $this->edits;
		$new->placeholder_names = $this->placeholder_names;
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
			// @todo check for missing names.
			return WP_HTML_Processor::normalize( $this->template_string ) ?? $this->template_string;
		}

		$escape_map = array(
			'&' => '&amp;',
			'<' => '&lt;',
			'>' => '&gt;',
			"'" => '&apos;',
			'"' => '&quot;',
		);

		$processor = ( new class( $this->template_string ) extends WP_HTML_Tag_Processor {
			public function push_update( WP_HTML_Text_Replacement $update ) {
				$this->lexical_updates[] = $update;
			}
		} );

		$used_keys = array();
		foreach ( $this->edits as $edit ) {
			if ( $edit instanceof WP_HTML_Text_Replacement ) {
				$processor->push_update( $edit );
			} else {
				// Placeholder: look up replacement value.
				$placeholder = $edit['placeholder'];
				$value       = $this->replacements[ $placeholder ] ?? null;

				if ( $value instanceof self ) {
					if ( 'attribute' === $edit['context'] ) {
						// @todo doing it wrong.
						return false;
					}

					$rendered = $value->render();
					if ( false === $rendered ) {
						return false;
					}

					$processor->push_update(
						new WP_HTML_Text_Replacement( $edit['start'], $edit['length'], $rendered ),
					);
				} elseif ( is_string( $value ) ) {
					$escaped = strtr( $value, $escape_map );
					$processor->push_update(
						new WP_HTML_Text_Replacement( $edit['start'], $edit['length'], $escaped ),
					);
				} else {
					// @todo doing it wrong.
					return false;
				}
				$used_keys[ $placeholder ] = true;
			}
		}

		// Return false if any replacement key was not used.
		if ( count( $used_keys ) !== count( $this->replacements ) ) {
			return false;
		}

		/*
		 * @todo ideally, just call `$processor->serialize()`.
		 * @todo doing it wrong?
		 */
		return WP_HTML_Processor::normalize( $processor->get_updated_html() ) ?? false;
	}
}
