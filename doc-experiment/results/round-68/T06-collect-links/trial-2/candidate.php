<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$stack  = array();

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( '#text' === $processor->get_token_type() && ! empty( $stack ) ) {
				$top_index = count( $stack ) - 1;
				$stack[ $top_index ]['text'] .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( 'A' !== $processor->get_tag() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( empty( $stack ) ) {
				continue;
			}

			$frame = array_pop( $stack );
			if ( null !== $frame['href'] ) {
				$links[] = array(
					'href' => $frame['href'],
					'text' => $frame['text'],
				);
			}
			continue;
		}

		$href = $processor->get_attribute( 'href' );
		$stack[] = array(
			'href' => is_string( $href ) ? $href : null,
			'text' => '',
		);
	}

	return $links;
}
