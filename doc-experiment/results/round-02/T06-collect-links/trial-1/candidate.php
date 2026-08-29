<?php

function collect_links( string $html ): array {
	$links = array();
	
	// Create a fragment processor in BODY context (the default)
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation fails, return empty array
	if ( null === $processor ) {
		return $links;
	}
	
	// Find each A tag
	while ( $processor->next_tag( 'A' ) ) {
		// Check if the tag has an href attribute
		$href = $processor->get_attribute( 'href' );
		
		// Skip A tags without href attribute
		if ( null === $href ) {
			continue;
		}
		
		// Collect text content inside the A tag
		$text = '';
		$depth_inside_a = $processor->get_current_depth();
		
		// Walk through tokens until we exit the A tag
		while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_a ) {
			// Only collect text from #text tokens
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		// Add the link to the results array
		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}
	
	return $links;
}
