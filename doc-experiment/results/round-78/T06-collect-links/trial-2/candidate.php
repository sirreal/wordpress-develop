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
		if ( '#text' === $processor->get_token_type() ) {
			if ( ! empty( $stack ) ) {
				$stack[ count( $stack ) - 1 ]['text'] .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $processor->get_token_type() || 'A' !== $processor->get_tag() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( ! empty( $stack ) ) {
				$link = array_pop( $stack );
				$links[] = array(
					'href' => $link['href'],
					'text' => $link['text'],
				);
			}
			continue;
		}

		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			continue;
		}

		$stack[] = array(
			'href' => $href,
			'text' => '',
		);
	}

	return $links;
}
