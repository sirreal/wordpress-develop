<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	$outline = array();
	
	// Loop through all tags in the document
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		
		// Check if it's a heading tag (H1-H6)
		if ( ! preg_match( '/^H[1-6]$/', $tag ) ) {
			continue;
		}
		
		// Extract the level from the tag name (H1 -> 1, H2 -> 2, etc.)
		$level = (int) substr( $tag, 1 );
		
		// Collect all text inside the heading
		$text = '';
		$heading_depth = $processor->get_current_depth();
		
		// Loop through tokens until we exit the heading
		while ( $processor->next_token() && $processor->get_current_depth() >= $heading_depth ) {
			// Only collect text nodes
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		// Add heading to outline
		$outline[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}
	
	return $outline;
}
