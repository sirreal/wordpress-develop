<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$headings = array();
	
	// Loop through all tags to find headings
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		
		// Check if the tag is a heading (H1-H6)
		if ( in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
			// Extract the level from the tag name
			$level = (int) substr( $tag, 1 );
			
			// Collect text content from inside the heading
			$text = '';
			$depth_inside_heading = $processor->get_current_depth();
			
			// Walk through tokens inside the heading to collect text
			while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_heading ) {
				if ( '#text' === $processor->get_token_type() ) {
					$text .= $processor->get_modifiable_text();
				}
			}
			
			// Add the heading to the result array
			$headings[] = array(
				'level' => $level,
				'text'  => $text,
			);
		}
	}
	
	return $headings;
}
