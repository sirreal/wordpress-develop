<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$current = null;

	while ( $processor->next_token() ) {
		if ( null !== $current ) {
			if ( '#text' === $processor->get_token_type() ) {
				$current['text'] .= $processor->get_modifiable_text();
				continue;
			}

			if ( $processor->get_current_depth() < $current['depth'] ) {
				$links[] = array(
					'href' => $current['href'],
					'text' => $current['text'],
				);
				$current = null;
			}
		}

		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		if ( 'A' !== $processor->get_tag() ) {
			continue;
		}

		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			continue;
		}

		$current = array(
			'href'  => $href,
			'text'  => '',
			'depth' => $processor->get_current_depth(),
		);
	}

	if ( null !== $current ) {
		$links[] = array(
			'href' => $current['href'],
			'text' => $current['text'],
		);
	}

	return $links;
}
