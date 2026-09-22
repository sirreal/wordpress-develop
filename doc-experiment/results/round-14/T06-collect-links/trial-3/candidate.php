<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	// Find all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Check if the A tag has an href attribute
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			// Skip A tags without href
			continue;
		}

		// Collect text content inside the A element
		$depth_inside_a = $processor->get_current_depth();
		$text           = '';

		// Walk through tokens inside the A element
		while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_a ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		// Add link to results
		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}

	return $links;
}
