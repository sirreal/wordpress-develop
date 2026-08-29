<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$results = array();
	$stack   = array();

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#text' === $token_type ) {
			if ( empty( $stack ) ) {
				continue;
			}

			$text = $processor->get_modifiable_text();
			for ( $i = 0, $n = count( $stack ); $i < $n; $i++ ) {
				$stack[ $i ]['text'] .= $text;
			}
			continue;
		}

		if ( '#tag' !== $token_type || 'A' !== $processor->get_tag() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( ! empty( $stack ) ) {
				$anchor = array_pop( $stack );
				$results[] = array(
					'href' => $anchor['href'],
					'text' => $anchor['text'],
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

	if ( null !== $processor->get_last_error() ) {
		return array();
	}

	return $results;
}
