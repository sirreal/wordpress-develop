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
			if ( empty( $stack ) ) {
				continue;
			}

			$text = $processor->get_modifiable_text();
			foreach ( $stack as $index => $frame ) {
				$stack[ $index ]['text'] .= $text;
			}

			continue;
		}

		if ( '#tag' !== $processor->get_token_type() || 'A' !== $processor->get_tag() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( ! empty( $stack ) ) {
				$frame = array_pop( $stack );
				$links[] = array(
					'href' => $frame['href'],
					'text' => $frame['text'],
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

	while ( ! empty( $stack ) ) {
		$frame = array_pop( $stack );
		$links[] = array(
			'href' => $frame['href'],
			'text' => $frame['text'],
		);
	}

	return $links;
}
