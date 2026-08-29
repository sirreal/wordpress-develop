<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$headings = array();
	
	// Find each heading tag (H1 through H6)
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		
		// Check if this is a heading tag
		if ( ! preg_match( '/^H[1-6]$/', $tag ) ) {
			continue;
		}
		
		$level = (int) substr( $tag, 1 ); // Extract level from H1, H2, etc.
		
		// Get text content of the heading
		$text = '';
		$depth_at_heading = $processor->get_current_depth();
		
		// Walk through tokens inside the heading until we exit it
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();
			
			// Stop when we exit the heading
			if ( $current_depth <= $depth_at_heading ) {
				break;
			}
			
			// Collect text from #text tokens
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		$headings[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}
	
	return $headings;
}
