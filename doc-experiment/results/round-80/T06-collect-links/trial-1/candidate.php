<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$open_links = array();

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			if ( ! empty( $open_links ) ) {
				$text = $processor->get_modifiable_text();
				foreach ( $open_links as $index => $link ) {
					$open_links[ $index ]['text'] .= $text;
				}
			}
			continue;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( 'A' !== $processor->get_tag() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( ! empty( $open_links ) ) {
				$link = array_pop( $open_links );
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

		$open_links[] = array(
			'href' => $href,
			'text' => '',
		);
	}

	return $links;
}
