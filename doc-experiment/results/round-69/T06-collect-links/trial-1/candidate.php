<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$current = null;
	$anchor_depth = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		if ( '#tag' === $token_type && 'A' === $token_name ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $current ) {
					$links[] = array(
						'href' => $current['href'],
						'text' => $current['text'],
					);
					$current = null;
					$anchor_depth = null;
				}
				continue;
			}

			$href = $processor->get_attribute( 'href' );
			if ( is_string( $href ) ) {
				$current = array(
					'href' => $href,
					'text' => '',
				);
				$anchor_depth = $processor->get_current_depth();
			} else {
				$current = null;
				$anchor_depth = null;
			}

			continue;
		}

		if ( null !== $current && '#text' === $token_type ) {
			if ( $processor->get_current_depth() >= $anchor_depth ) {
				$current['text'] .= $processor->get_modifiable_text();
			}
		}
	}

	return $links;
}
