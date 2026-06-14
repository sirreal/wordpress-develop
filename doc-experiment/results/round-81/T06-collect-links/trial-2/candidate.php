<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$stack = array();

	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();

		while ( ! empty( $stack ) && $current_depth < $stack[ count( $stack ) - 1 ]['depth'] ) {
			$entry = array_pop( $stack );
			$links[] = array(
				'href' => $entry['href'],
				'text' => $entry['text'],
			);
		}

		if ( '#tag' === $processor->get_token_type() ) {
			if ( ! $processor->is_tag_closer() && 'A' === $processor->get_tag() ) {
				$href = $processor->get_attribute( 'href' );
				if ( is_string( $href ) ) {
					$stack[] = array(
						'depth' => $current_depth,
						'href'  => $href,
						'text'  => '',
					);
				}
			}

			continue;
		}

		if ( '#text' !== $processor->get_token_type() ) {
			continue;
		}

		if ( empty( $stack ) ) {
			continue;
		}

		$text = $processor->get_modifiable_text();
		for ( $i = 0, $n = count( $stack ); $i < $n; $i++ ) {
			$stack[ $i ]['text'] .= $text;
		}
	}

	while ( ! empty( $stack ) ) {
		$entry = array_pop( $stack );
		$links[] = array(
			'href' => $entry['href'],
			'text' => $entry['text'],
		);
	}

	return $links;
}
