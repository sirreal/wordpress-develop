<?php

function collect_links( string $html ): array {
	$links = array();
	
	// Create HTML processor for the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $links;
	}
	
	// Find each A tag
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Skip tag closers
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Get the href attribute
		$href = $processor->get_attribute( 'href' );
		
		// Only include links that have an href attribute
		if ( null === $href ) {
			continue;
		}
		
		// Collect text content inside the A element
		$text = '';
		$depth_at_a = $processor->get_current_depth();
		
		// Walk through tokens inside the A element to collect text
		while ( $processor->next_token() && $processor->get_current_depth() > $depth_at_a ) {
			// Collect all text nodes
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		// Add the link to the results
		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}
	
	return $links;
}
