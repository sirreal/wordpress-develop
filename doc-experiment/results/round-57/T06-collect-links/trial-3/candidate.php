<?php

function collect_links( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$stack = array();

	while ( $processor->next_token() ) {
		if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
			if ( ! $processor->is_tag_closer() ) {
				$href = $processor->get_attribute( 'href' );
				if ( is_string( $href ) ) {
					$stack[] = array(
						'href' => $href,
						'text' => '',
						'depth' => $processor->get_current_depth(),
					);
				}
			} else {
				$depth = $processor->get_current_depth();
				$index = count( $stack ) - 1;

				while ( $index >= 0 ) {
					if ( $stack[ $index ]['depth'] === $depth + 1 ) {
						$links[] = array(
							'href' => $stack[ $index ]['href'],
							'text' => $stack[ $index ]['text'],
						);
						array_splice( $stack, $index, 1 );
						break;
					}

					$index--;
				}
			}

			continue;
		}

		if ( ! empty( $stack ) && '#text' === $processor->get_token_type() ) {
			$stack[ count( $stack ) - 1 ]['text'] .= $processor->get_modifiable_text();
		}
	}

	if ( ! empty( $stack ) ) {
		foreach ( $stack as $entry ) {
			$links[] = array(
				'href' => $entry['href'],
				'text' => $entry['text'],
			);
		}
	}

	return $links;
}
