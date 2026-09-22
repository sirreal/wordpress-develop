<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$outline = array();
	
	// Find each heading tag
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		
		// Check if this is a heading tag (H1-H6)
		if ( ! in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
			continue;
		}
		
		// Extract the level (1-6) from the tag name
		$level = (int) substr( $tag, 1 );
		
		// Collect all text content inside this heading
		$text = '';
		$heading_depth = $processor->get_current_depth();
		
		// Process tokens inside the heading until we encounter the closing tag
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();
			
			// Stop when we exit the heading element
			if ( $current_depth < $heading_depth ) {
				break;
			}
			
			// Skip tokens at deeper nesting levels (from child elements)
			if ( $current_depth > $heading_depth ) {
				continue;
			}
			
			// Collect text from text nodes at the heading's direct level
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		// Add the heading to the outline
		$outline[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}
	
	return $outline;
}
