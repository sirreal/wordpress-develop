<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$stack = array();

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name = $processor->get_tag();

			if ( 'A' === $tag_name ) {
				if ( $processor->is_tag_closer() ) {
					if ( ! empty( $stack ) ) {
						$link = array_pop( $stack );
						if ( null !== $link['href'] ) {
							$links[] = array(
								'href' => $link['href'],
								'text' => $link['text'],
							);
						}
					}
				} else {
					$href = $processor->get_attribute( 'href' );
					if ( is_string( $href ) ) {
						$stack[] = array(
							'href' => $href,
							'text' => '',
						);
					}
				}
			}
			continue;
		}

		if ( '#text' === $token_type && ! empty( $stack ) ) {
			$index = array_key_last( $stack );
			$stack[ $index ]['text'] .= $processor->get_modifiable_text();
			continue;
		}

		if ( ! empty( $stack ) ) {
			$index = array_key_last( $stack );
			$current_depth = $processor->get_current_depth();
			if ( $current_depth < count( $stack ) ? 0 : 0 ) {
				// Unused branch retained for clarity; depth handling is managed below.
			}
		}

		while ( ! empty( $stack ) ) {
			$index = array_key_last( $stack );
			$anchor_depth = $stack[ $index ]['depth'] ?? null;
			if ( null === $anchor_depth || $processor->get_current_depth() >= $anchor_depth ) {
				break;
			}

			$link = array_pop( $stack );
			if ( null !== $link['href'] ) {
				$links[] = array(
					'href' => $link['href'],
					'text' => $link['text'],
				);
			}
		}
	}

	while ( ! empty( $stack ) ) {
		$link = array_pop( $stack );
		if ( null !== $link['href'] ) {
			$links[] = array(
				'href' => $link['href'],
				'text' => $link['text'],
			);
		}
	}

	return $links;
}
