<?php

declare(strict_types=1);

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links   = array();
	$stack   = array();

	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();

		while ( ! empty( $stack ) && $stack[ count( $stack ) - 1 ]['depth'] > $current_depth ) {
			$entry = array_pop( $stack );
			$links[ $entry['index'] ]['text'] = $entry['text'];
		}

		if ( '#tag' === $processor->get_token_type() ) {
			if ( 'A' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
				$href = $processor->get_attribute( 'href' );
				if ( is_string( $href ) ) {
					$links[] = array(
						'href' => $href,
						'text' => '',
					);

					$stack[] = array(
						'index' => count( $links ) - 1,
						'depth' => $current_depth,
						'text'  => '',
					);
				}
				continue;
			}
		}

		if ( '#text' === $processor->get_token_type() && ! empty( $stack ) ) {
			$chunk = $processor->get_modifiable_text();
			$stack[ count( $stack ) - 1 ]['text'] .= $chunk;
		}
	}

	while ( ! empty( $stack ) ) {
		$entry = array_pop( $stack );
		$links[ $entry['index'] ]['text'] = $entry['text'];
	}

	return $links;
}
