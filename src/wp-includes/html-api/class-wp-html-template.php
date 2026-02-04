<?php
/**
 * HTML API: WP_HTML_Template class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 7.0.0
 */

class WP_HTML_Template extends WP_HTML_Tag_Processor {
	/**
	 * The template string.
	 *
	 * @since 7.0.0
	 *
	 * @var string
	 */
	private string $template_string;

	private array $replacements = array();

	private function __construct( string $template_string, array $replacements ) {
		$this->template_string = $template_string;
		$this->replacements    = $replacements;
	}

	/**
	 * Render a template string with the given replacements.
	 *
	 * @since 7.0.0
	 *
	 * @param string $template_string The template string with placeholders.
	 * @param array  $replacements    The replacement values.
	 * @return string|false The rendered HTML, or false on error.
	 */
	public static function sprintf( string $template_string, array $replacements = array() ) {
		return self::from( $template_string, $replacements )->render();
	}

	/**
	 * @param string $template_string The template string with placeholders.
	 * @param array  $replacements    The replacement values.
	 */
	public static function from( string $template_string, array $replacements = array() ): static {
		if ( ! is_string( $template_string ) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'The template string must be a string.' ),
				'7.0.0'
			);
			$template_string = '';
		}
		return new static( $template_string, $replacements );
	}

	/**
	 * Render the template with the given replacements.
	 *
	 * @since 7.0.0
	 *
	 * @param array $replacements Optional. The replacement values. They may be provided at template creation time.
	 * @return string|false The rendered HTML, or false on error.
	 */
	public function render( ?array $replacements = null ) {
		if ( \is_array( $replacements ) ) {
			$this->replacements = $replacements;
		} elseif ( null !== $replacements ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'The replacements must be an array.' ),
				'7.0.0'
			);
		}

		if ( empty( $this->replacements ) ) {
			return WP_HTML_Processor::normalize( $this->template_string ) ?? $this->template_string;
		}

		$processor      = new WP_HTML_Tag_Processor( $this->template_string );
		$error_occurred = false;

		while ( $processor->next_token() ) {
			switch ( $processor->get_token_type() ) {
				/*
				 * It's important that #text be normalized to prevent something like
				 * `i<</%tag-name>u` from becoming `i<a>u` after replacement and altering HTML.
				 */
				case '#text':
					$processor->set_bookmark( 'text' );
					$mark = $processor->bookmarks['text'] ?? null;
					assert( null !== $mark );
					$normalized = $processor->serialize_token();
					if ( 0 !== substr_compare( $processor->html, $normalized, $mark->start, min( $mark->length, strlen( $normalized ) ) ) ) {
						$processor->lexical_updates[] = new WP_HTML_Text_Replacement(
							$mark->start,
							$mark->length,
							$normalized
						);
					}
					break;

				case '#funky-comment':
					// Does it look like a placeholder?
					$processor->set_bookmark( 'placeholder' );
					$mark = $processor->bookmarks['placeholder'] ?? null;
					assert( null !== $mark );
					// A funky comment looks at least like </%…>
					$start  = $mark->start;
					$length = $mark->length;
					// This is not the funky comment we're looking for.
					if ( $length < 5 || ! $processor->html[ $start + 2 ] === '%' ) {
						break;
					}
					$placeholder = trim( \substr( $processor->html, $start + 3, $length - 4 ), " \t\n\r\f" );

					// Valid placeholders match `/a-z0-9_-/i`.
					if ( \strlen( $placeholder ) !== \strspn( $placeholder, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-' ) ) {
						break;
					}

					$replacement = $this->get_replacement( $placeholder );
					if ( \is_string( $replacement ) ) {
						$processor->lexical_updates[] = new WP_HTML_Text_Replacement(
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
						);
					} elseif ( $replacement instanceof WP_HTML_Template ) {
						$processor->lexical_updates[] = new WP_HTML_Text_Replacement(
							$start,
							$length,
							$replacement->render()
						);
					}
					break;

				case '#tag':
					if ( $processor->is_tag_closer() ) {
						break;
					}

					foreach ( $processor->attributes as $attribute ) {
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
								$processor->html,
								$matches,
								PREG_OFFSET_CAPTURE,
								$offset
							)
							&& $matches[0][1] < $end
						) {
							$replacement = $this->get_replacement( $matches[1][0] );
							if ( is_string( $replacement ) ) {
								$match_at     = $matches[0][1];
								$match_length = strlen( $matches[0][0] );

								// Capture and clean the preceding attribute text.
								$processor->lexical_updates[] = new WP_HTML_Text_Replacement(
									$last_offset,
									$match_at - $last_offset,
									strtr(
										substr( $processor->html, $last_offset, $match_at - $last_offset ),
										array(
											'<' => '&lt;',
											'>' => '&gt;',
											"'" => '&apos;',
											'"' => '&quot;',
											'&' => '&amp;',
										)
									)
								);

								$processor->lexical_updates[] = new WP_HTML_Text_Replacement(
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
								);
								$last_offset                  = $match_at + $match_length;
							} elseif ( $replacement instanceof self ) {
								_doing_it_wrong(
									__METHOD__,
									// @todo improve this message, include the placeholder in the string.
									__( 'Attribute values cannot contain HTML. Use a plain string.' ),
									'7.0.0'
								);
								return false;
							}

							$offset = $matches[0][1] + strlen( $matches[0][0] );
						}
					}
			}
		}

		$html = $processor->get_updated_html();

		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	private function preg_attribute_replace_callback( $matches ) {
		$key = $matches[1];

		$replacement = $this->get_replacement( $key );

		// Keep placeholder if no replacement found.
		if ( null === $replacement ) {
			return $matches[0];
		}

		// HTML cannot be embedded in attribute values.
		if ( $replacement instanceof self ) {
			_doing_it_wrong(
				__METHOD__,
				// @todo improve this message, include the placeholder in the string.
				__( 'Attribute values cannot contain HTML. Use a plain string.' ),
				'7.0.0'
			);
			return '';
		}

		return $replacement;
	}

	/**
	 * Get the replacement value for a placeholder key.
	 *
	 * Handles both named keys (like 'name') and numeric keys (like 0).
	 *
	 * @since 7.0.0
	 *
	 * @param string $key          The placeholder key.
	 * @return mixed|null The replacement value, or null if not found.
	 */
	private function get_replacement( string $key ): self|string|null {
		$replacement = $this->replacements[ $key ] ?? null;
		if ( \is_string( $replacement ) || ( $replacement instanceof WP_HTML_Template ) ) {
			return $replacement;
		}

		_doing_it_wrong(
			__METHOD__,
			sprintf(
				__( 'Invalid replacement for %1$s of type `%2$s`. Must be a string or template.' ),
				esc_html( $key ),
				esc_html( gettype( $replacement ) )
			),
			'7.0.0'
		);

		return null;
	}
}
