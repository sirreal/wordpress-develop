<?php

declare(strict_types=1);

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$open_links = array();

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name = $processor->get_tag();
			if ( null === $tag_name ) {
				continue;
			}

			if ( 'A' === $tag_name ) {
				$current_depth = $processor->get_current_depth();

				if ( $processor->is_tag_closer() ) {
					while ( ! empty( $open_links ) ) {
						$top_index = count( $open_links ) - 1;
						if ( $open_links[ $top_index ]['depth'] < $current_depth + 1 ) {
							break;
						}

						$entry = array_pop( $open_links );
						$links[] = array(
							'href' => $entry['href'],
							'text' => $entry['text'],
						);
					}
				} else {
					$href = $processor->get_attribute( 'href' );
					if ( is_string( $href ) ) {
						$open_links[] = array(
							'href' => $href,
							'text' => '',
							'depth' => $current_depth,
						);
					}
				}
			}

			continue;
		}

		if ( '#text' === $token_type && ! empty( $open_links ) ) {
			$text = $processor->get_modifiable_text();
			$last_index = count( $open_links ) - 1;
			$open_links[ $last_index ]['text'] .= $text;
		}
	}

	while ( ! empty( $open_links ) ) {
		$entry = array_pop( $open_links );
		$links[] = array(
			'href' => $entry['href'],
			'text' => $entry['text'],
		);
	}

	return $links;
}
