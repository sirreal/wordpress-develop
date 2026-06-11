<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_token() ) {
		// Check if this is an A tag opener
		if ( 'A' === $processor->get_token_name() && ! $processor->is_tag_closer() ) {
			// Check if the A tag has an href attribute
			$href = $processor->get_attribute( 'href' );
			if ( null === $href ) {
				continue; // Skip A tags without href
			}

			// Record the depth inside this A element
			$depth_inside_a = $processor->get_current_depth();

			// Collect text content inside the A element
			$text = '';
			while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_a ) {
				if ( '#text' === $processor->get_token_type() ) {
					$text .= $processor->get_modifiable_text();
				}
			}

			// Add to results
			$links[] = array(
				'href' => $href,
				'text' => $text,
			);
		}
	}

	return $links;
}
