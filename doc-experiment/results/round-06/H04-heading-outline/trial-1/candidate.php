<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$headings = array();
	
	// Loop through all tags and find headings
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		
		// Check if this is a heading tag (H1-H6)
		if ( ! $tag || ! preg_match( '/^H[1-6]$/', $tag ) ) {
			continue;
		}
		
		// Extract heading level from tag name
		$level = (int) substr( $tag, 1 );
		
		// Collect text content inside the heading
		$text = '';
		$heading_depth = $processor->get_current_depth();
		
		// Walk through tokens inside the heading
		while ( $processor->next_token() && $processor->get_current_depth() >= $heading_depth ) {
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
