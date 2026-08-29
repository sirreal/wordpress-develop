<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	$headings = array();
	
	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();
		
		// Check if this is a heading tag (H1-H6)
		if ( in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
			// Only process opening tags, not closing tags
			if ( ! $processor->is_tag_closer() ) {
				// Extract the heading level from the tag name
				$level = (int) substr( $tag, 1 );
				
				// Get the current depth so we know when we've exited this heading
				$heading_depth = $processor->get_current_depth();
				
				// Collect all text content within this heading
				$text = '';
				while ( $processor->next_token() ) {
					$current_depth = $processor->get_current_depth();
					
					// Exit the heading when we reach its closing tag (depth drops below heading depth)
					if ( $current_depth < $heading_depth ) {
						break;
					}
					
					// Collect text tokens that are inside the heading
					if ( '#text' === $processor->get_token_type() && $current_depth > $heading_depth ) {
						$text .= $processor->get_modifiable_text();
					}
				}
				
				// Add the heading to the results
				$headings[] = array(
					'level' => $level,
					'text'  => $text,
				);
			}
		}
	}
	
	return $headings;
}
