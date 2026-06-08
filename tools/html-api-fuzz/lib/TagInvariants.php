<?php
namespace HtmlApiFuzz;

class TagInvariants {
	public static function check( string $html, array $limits = array() ): array {
		HtmlApiBootstrap::load();
		$max_tokens = $limits['maxTokens'] ?? 2000;
		$failures   = array();
		$tokens     = 0;

		try {
			$processor = new \WP_HTML_Tag_Processor( $html );
			while ( $processor->next_token() ) {
				++$tokens;
				if ( $tokens > $max_tokens ) {
					$failures[] = array(
						'name'    => 'tag-token-limit-exceeded',
						'message' => 'WP_HTML_Tag_Processor exceeded token limit.',
					);
					break;
				}

				$type = $processor->get_token_type();
				$name = $processor->get_token_name();
				if ( null === $type ) {
					$failures[] = array(
						'name'    => 'null-token-type-after-next-token',
						'message' => 'get_token_type() returned null after next_token().',
					);
					break;
				}
				if ( null === $name ) {
					$failures[] = array(
						'name'    => 'null-token-name-after-next-token',
						'message' => 'get_token_name() returned null after next_token().',
					);
					break;
				}

				if ( '#tag' === $type && ! $processor->is_tag_closer() ) {
					$tag = $processor->get_tag();
					if ( null === $tag ) {
						$failures[] = array(
							'name'    => 'null-tag-for-tag-token',
							'message' => 'get_tag() returned null for a tag token.',
						);
						break;
					}
					$attrs = $processor->get_attribute_names_with_prefix( '' );
					if ( is_array( $attrs ) ) {
						foreach ( $attrs as $attr ) {
							$processor->get_attribute( $attr );
							$processor->get_qualified_attribute_name( $attr );
						}
					}
					if ( null !== $processor->get_attribute( 'class' ) ) {
						foreach ( $processor->class_list() as $_class_name ) {
							// Iteration itself is the invariant check.
						}
					}
				}

				$processor->get_modifiable_text();
			}

			if ( $processor->get_updated_html() !== $html ) {
				$failures[] = array(
					'name'    => 'updated-html-changed-without-edits',
					'message' => 'get_updated_html() changed HTML even though no edits were queued.',
				);
			}

			$mutation = self::check_simple_mutation( $html, $max_tokens );
			if ( ! $mutation['ok'] ) {
				$failures[] = $mutation['failure'];
			}
		} catch ( \Throwable $e ) {
			$failures[] = array(
				'name'      => 'tag-processor-throwable',
				'message'   => $e->getMessage(),
				'throwable' => get_class( $e ),
			);
		}

		return array(
			'ok'         => empty( $failures ),
			'failures'   => $failures,
			'tokenCount' => $tokens,
		);
	}

	private static function check_simple_mutation( string $html, int $max_tokens ): array {
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() ) {
			return array( 'ok' => true );
		}

		if ( ! $processor->set_attribute( 'data-fuzz', '1' ) ) {
			return array( 'ok' => true );
		}

		$updated = $processor->get_updated_html();
		$scan    = new \WP_HTML_Tag_Processor( $updated );
		$tokens  = 0;
		$checked_first_tag = false;
		while ( $scan->next_token() ) {
			++$tokens;
			if ( $tokens > $max_tokens ) {
				return array(
					'ok'      => false,
					'failure' => array(
						'name'    => 'mutation-token-limit-exceeded',
						'message' => 'A simple set_attribute() mutation produced HTML that exceeded the token limit.',
					),
				);
			}

			if ( ! $checked_first_tag && '#tag' === $scan->get_token_type() && ! $scan->is_tag_closer() ) {
				$checked_first_tag = true;
				if ( '1' !== $scan->get_attribute( 'data-fuzz' ) ) {
					return array(
						'ok'      => false,
						'failure' => array(
							'name'    => 'mutation-attribute-missing',
							'message' => 'set_attribute() reported success, but the updated first tag does not contain data-fuzz="1".',
						),
					);
				}
			}
		}

		if ( ! $checked_first_tag ) {
			return array(
				'ok'      => false,
				'failure' => array(
					'name'    => 'mutation-removed-first-tag',
					'message' => 'A simple set_attribute() mutation removed the first tag from the updated HTML.',
				),
			);
		}

		return array( 'ok' => true );
	}
}
