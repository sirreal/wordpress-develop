<?php

function heading_outline( string $html ): array {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$headings = array();
	
	// Loop through all tags in the document
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		
		// Check if this is a heading tag (H1-H6)
		if ( ! $tag || ! preg_match( '/^H[1-6]$/i', $tag ) ) {
			continue;
		}
		
		// Skip if this is a closing tag
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Extract the heading level from the tag name
		$level = (int) substr( strtoupper( $tag ), 1 );
		
		// Collect the text content of the heading
		$depth_inside_heading = $processor->get_current_depth();
		$text = '';
		
		while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_heading ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		// Add this heading to the results
		$headings[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}
	
	return $headings;
}
