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

		$this->compiled = array();

		$processor = new class( $this->template_string ) extends WP_HTML_Tag_Processor {
			public function get_html(): string {
				return $this->html;
			}

			public function get_bookmark( string $name ) {
				return $this->bookmarks[ $name ] ?? null;
			}

			public function get_tag_attributes(): array {
				return $this->attributes;
			}
		};

		while ( $processor->next_token() ) {
			switch ( $processor->get_token_type() ) {
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
	 * @since 7.0.0
	 *
	 * @param array $replacements The replacement values.
	 * @return static A new template instance with the replacements bound.
	 */
	public function bind( array $replacements ): static {
		return new static( $this->template_string, $replacements );
	}

	/**
	 * Renders the template to an HTML string.
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
		if ( empty( $this->replacements ) ) {
			return WP_HTML_Processor::normalize( $this->template_string ) ?? $this->template_string;
		}

		$used_keys      = array();
		$processor      = new class( $this->template_string ) extends WP_HTML_Tag_Processor {
			/**
			 * Returns the HTML string being processed.
			 *
			 * @return string The HTML string.
			 */
			public function get_html(): string {
				return $this->html;
			}

			/**
			 * Returns a bookmark by name.
			 *
			 * @param string $name The bookmark name.
			 * @return WP_HTML_Span|null The bookmark span, or null if not found.
			 */
			public function get_bookmark( string $name ) {
				return $this->bookmarks[ $name ] ?? null;
			}

			/**
			 * Returns the tag attributes array.
			 *
			 * @return array The attributes array.
			 */
			public function get_tag_attributes(): array {
				return $this->attributes;
			}

			/**
			 * Adds a lexical update.
			 *
			 * @param WP_HTML_Text_Replacement $update The text replacement to add.
			 */
			public function add_lexical_update( WP_HTML_Text_Replacement $update ): void {
				$this->lexical_updates[] = $update;
			}
		};
		$error_occurred = false;

		while ( $processor->next_token() ) {
			switch ( $processor->get_token_type() ) {
				/*
				 * It's important that #text be normalized to prevent something like
				 * `i<</%tag-name>u` from becoming `i<a>u` after replacement and altering HTML.
				 */
				case '#text':
					$processor->set_bookmark( 'text' );
					$mark = $processor->get_bookmark( 'text' );
					assert( null !== $mark );
					$normalized = $processor->serialize_token();
					if ( 0 !== substr_compare( $processor->get_html(), $normalized, $mark->start, min( $mark->length, strlen( $normalized ) ) ) ) {
						$processor->add_lexical_update(
							new WP_HTML_Text_Replacement(
								$mark->start,
								$mark->length,
								$normalized
							)
						);
					}
					break;

				case '#funky-comment':
					// Does it look like a placeholder?
					$processor->set_bookmark( 'placeholder' );
					$mark = $processor->get_bookmark( 'placeholder' );
					assert( null !== $mark );
					// A funky comment looks at least like </%…>
					$start  = $mark->start;
					$length = $mark->length;
					$html   = $processor->get_html();
					// This is not the funky comment we're looking for.
					if ( $length < 5 || ! $html[ $start + 2 ] === '%' ) {
						break;
					}
					$placeholder = trim( \substr( $html, $start + 3, $length - 4 ), " \t\n\r\f" );

					// Valid placeholders match `/a-z0-9_-/i`.
					if ( \strlen( $placeholder ) !== \strspn( $placeholder, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-' ) ) {
						break;
					}

					$replacement = $this->get_replacement( $placeholder, $used_keys );
					if ( null === $replacement ) {
						$error_occurred = true;
						break;
					}
					if ( \is_string( $replacement ) ) {
						$processor->add_lexical_update(
							new WP_HTML_Text_Replacement(
								$start,
								$length,
								strtr(
									$replacement,
									array(
										'<' => '&lt;',
										'>' => '&gt;',
										"'" => '&apos;',
										'"' => '&quot;',
										'&' => '&amp;',
									)
								)
							)
						);
					} elseif ( $replacement instanceof WP_HTML_Template ) {
						$rendered = $replacement->render();
						if ( false === $rendered ) {
							$error_occurred = true;
							break;
						}
						$processor->add_lexical_update(
							new WP_HTML_Text_Replacement(
								$start,
								$length,
								$rendered
							)
						);
					}
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
						/**
						 * @todo preg_match does not accept length, so this will happily search
						 * beyond the attribute value.
						 */
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
							$replacement = $this->get_replacement( $matches[1][0], $used_keys );
							if ( null === $replacement ) {
								$error_occurred = true;
								break 2; // Break out of while and foreach.
							}
							if ( is_string( $replacement ) ) {
								$match_at     = $matches[0][1];
								$match_length = strlen( $matches[0][0] );

								// Capture and clean the preceding attribute text.
								$processor->add_lexical_update(
									new WP_HTML_Text_Replacement(
										$last_offset,
										$match_at - $last_offset,
										strtr(
											substr( $html, $last_offset, $match_at - $last_offset ),
											array(
												'<' => '&lt;',
												'>' => '&gt;',
												"'" => '&apos;',
												'"' => '&quot;',
												'&' => '&amp;',
											)
										)
									)
								);

								$processor->add_lexical_update(
									new WP_HTML_Text_Replacement(
										$match_at,
										strlen( $matches[0][0] ),
										strtr(
											$replacement,
											array(
												'<' => '&lt;',
												'>' => '&gt;',
												"'" => '&apos;',
												'"' => '&quot;',
												'&' => '&amp;',
											)
										)
									)
								);
								$last_offset = $match_at + $match_length;
							} elseif ( $replacement instanceof self ) {
								// Template in attribute context is an error.
								return false;
							}

							$offset = $matches[0][1] + strlen( $matches[0][0] );
						}
					}
			}
		}

		// Return false if any placeholder was missing a replacement.
		if ( $error_occurred ) {
			return false;
		}

		// Return false if any replacement key was not used.
		if ( count( $used_keys ) !== count( $this->replacements ) ) {
			return false;
		}

		$html = $processor->get_updated_html();

		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	/**
	 * Get the replacement value for a placeholder key.
	 *
	 * Handles both named keys (like 'name') and numeric keys (like 0).
	 * Tracks which keys are used for validation.
	 *
	 * @since 7.0.0
	 *
	 * @param string $key       The placeholder key.
	 * @param array  $used_keys Reference to array tracking used keys.
	 * @return self|string|null The replacement value, or null if not found.
	 */
	private function get_replacement( string $key, array &$used_keys ): self|string|null {
		// Try string key first, then numeric if the key looks numeric.
		if ( array_key_exists( $key, $this->replacements ) ) {
			$replacement = $this->replacements[ $key ];
		} elseif ( ctype_digit( $key ) && array_key_exists( (int) $key, $this->replacements ) ) {
			$key         = (int) $key;
			$replacement = $this->replacements[ $key ];
		} else {
			return null;
		}

		$used_keys[ $key ] = true;

		if ( \is_string( $replacement ) || ( $replacement instanceof WP_HTML_Template ) ) {
			return $replacement;
		}

		return null;
	}
}
