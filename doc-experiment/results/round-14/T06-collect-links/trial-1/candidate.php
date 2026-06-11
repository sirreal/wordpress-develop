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

		// Record the current depth to know when we exit the A tag
		$depth_inside_a = $processor->get_current_depth();

		// Collect text content inside the A tag
		$text = '';
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// Stop when we exit the A element
			if ( $current_depth < $depth_inside_a ) {
				break;
			}

			// Collect text nodes that are direct or nested inside the A tag
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}

	return $links;
}
