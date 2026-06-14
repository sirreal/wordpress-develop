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
		if ( '#tag' === $processor->get_token_type() ) {
			if ( 'A' === $processor->get_tag() ) {
				if ( $processor->is_tag_closer() ) {
					$frame = array_pop( $stack );
					if ( is_array( $frame ) ) {
						$links[] = array(
							'href' => $frame['href'],
							'text' => $frame['text'],
						);
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
				continue;
			}

			continue;
		}

		if ( '#text' === $processor->get_token_type() && ! empty( $stack ) ) {
			$index = count( $stack ) - 1;
			$stack[ $index ]['text'] .= $processor->get_modifiable_text();
		}
	}

	return $links;
}
