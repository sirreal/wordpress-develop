<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$current_href = null;
	$current_text = '';
	$anchor_depth = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			if ( 'A' === $processor->get_token_name() ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_href ) {
						$links[] = array(
							'href' => $current_href,
							'text' => $current_text,
						);
					}

					$current_href = null;
					$current_text = '';
					$anchor_depth = null;
				} else {
					$href = $processor->get_attribute( 'href' );
					if ( is_string( $href ) ) {
						$current_href = $href;
						$current_text = '';
						$anchor_depth = $processor->get_current_depth();
					} else {
						$current_href = null;
						$current_text = '';
						$anchor_depth = null;
					}
				}
			}

			continue;
		}

		if ( null !== $current_href && '#text' === $token_type && $processor->get_current_depth() >= $anchor_depth ) {
			$current_text .= $processor->get_modifiable_text();
		}
	}

	return $links;
}
