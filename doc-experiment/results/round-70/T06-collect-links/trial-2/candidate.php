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
		$current_depth = $processor->get_current_depth();

		while ( ! empty( $stack ) && $current_depth < $stack[ count( $stack ) - 1 ]['depth'] ) {
			$link = array_pop( $stack );
			$links[] = array(
				'href' => $link['href'],
				'text' => $link['text'],
			);
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			if ( ! empty( $stack ) && '#text' === $processor->get_token_type() ) {
				$stack[ count( $stack ) - 1 ]['text'] .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		if ( 'A' !== $processor->get_tag() ) {
			continue;
		}

		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			continue;
		}

		$stack[] = array(
			'depth' => $current_depth,
			'href'  => $href,
			'text'  => '',
		);
	}

	while ( ! empty( $stack ) ) {
		$link = array_pop( $stack );
		$links[] = array(
			'href' => $link['href'],
			'text' => $link['text'],
		);
	}

	return $links;
}
