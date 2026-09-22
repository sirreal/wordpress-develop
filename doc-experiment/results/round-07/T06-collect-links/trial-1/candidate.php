<?php

function collect_links( string $html ): array {
	$links = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( $processor === null ) {
		return $links;
	}
	
	// Find all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Skip if it's a closing tag
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Get the href attribute
		$href = $processor->get_attribute( 'href' );
		
		// Skip if no href attribute
		if ( $href === null ) {
			continue;
		}
		
		// Record the depth of the A tag opening
		$link_depth = $processor->get_current_depth();
		
		// Collect all text content inside the A element
		$text = '';
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();
			
			// Stop when we exit the A element (depth decreases below link_depth)
			if ( $current_depth < $link_depth ) {
				break;
			}
			
			// Collect text nodes that are direct or nested children
			if ( '#text' === $processor->get_token_type() && $current_depth > $link_depth ) {
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
