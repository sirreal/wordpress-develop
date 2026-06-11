<?php

function collect_links( string $html ): array {
	$links = array();
	
	// Create a fragment processor for the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $links;
	}
	
	// Iterate through tokens to find A tags and their text content
	while ( $processor->next_token() ) {
		$tag_name = $processor->get_token_name();
		$token_type = $processor->get_token_type();
		
		// Check if we're at an opening A tag
		if ( 'A' === $tag_name && '#tag' === $token_type && ! $processor->is_tag_closer() ) {
			// Get the href attribute
			$href = $processor->get_attribute( 'href' );
			
			// Only process A tags that have an href attribute
			if ( null !== $href ) {
				// Record the depth so we can collect text until the A tag closes
				$depth_at_a = $processor->get_current_depth();
				$text_content = '';
				
				// Collect all text nodes inside the A tag
				while ( $processor->next_token() && $processor->get_current_depth() >= $depth_at_a ) {
					if ( '#text' === $processor->get_token_type() ) {
						$text_content .= $processor->get_modifiable_text();
					}
				}
				
				// Add the link to our results
				$links[] = array(
					'href' => $href,
					'text' => $text_content,
				);
			}
		}
	}
	
	return $links;
}
