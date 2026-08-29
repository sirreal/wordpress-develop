<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$stack = array();

	while ( $processor->next_token() ) {
		if ( '#tag' === $processor->get_token_type() ) {
			if ( ! $processor->is_tag_closer() && 'A' === $processor->get_tag() ) {
				$href = $processor->get_attribute( 'href' );

				if ( is_string( $href ) ) {
					$stack[] = array(
						'href' => $href,
						'text' => '',
					);
				}
			} elseif ( $processor->is_tag_closer() && 'A' === $processor->get_tag() ) {
				if ( ! empty( $stack ) ) {
					$links[] = array_pop( $stack );
				}
			}

			continue;
		}

		if ( '#text' !== $processor->get_token_type() || empty( $stack ) ) {
			continue;
		}

		$index = count( $stack ) - 1;
		$stack[ $index ]['text'] .= $processor->get_modifiable_text();
	}

	return $links;
}
