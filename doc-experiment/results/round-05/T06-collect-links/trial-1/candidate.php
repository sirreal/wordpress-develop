<?php

function collect_links( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );
	$links = array();
	
	while ( $processor->next_tag() ) {
		// Check if this is an A tag
		if ( 'A' !== $processor->get_tag() ) {
			continue;
		}
		
		// Check if it's an opening tag (not a closing tag)
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Get href attribute
		$href = $processor->get_attribute( 'href' );
		
		// Skip if no href attribute
		if ( null === $href ) {
			continue;
		}
		
		// Collect text content inside the A tag
		$text = '';
		$depth_inside_a = $processor->get_current_depth();
		
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();
			
			// Stop when we exit the A tag
			if ( $current_depth < $depth_inside_a ) {
				break;
			}
			
			// Collect text from text nodes
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		// Add the link to results
		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}
	
	return $links;
}
