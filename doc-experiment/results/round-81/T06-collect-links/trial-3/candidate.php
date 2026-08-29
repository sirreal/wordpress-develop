<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$stack = array();

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			if ( ! empty( $stack ) ) {
				$text = $processor->get_modifiable_text();
				foreach ( $stack as $index => $link ) {
					$stack[ $index ]['text'] .= $text;
				}
			}

			continue;
		}

		if ( '#tag' !== $processor->get_token_type() || 'A' !== $processor->get_tag() ) {
			continue;
		}

		if ( ! $processor->is_tag_closer() ) {
			$href = $processor->get_attribute( 'href' );
			if ( is_string( $href ) ) {
				$stack[] = array(
					'href' => $href,
					'text' => '',
				);
			}

			continue;
		}

		if ( ! empty( $stack ) ) {
			$link = array_pop( $stack );
			$links[] = array(
				'href' => $link['href'],
				'text' => $link['text'],
			);
		}
	}

	return $links;
}
