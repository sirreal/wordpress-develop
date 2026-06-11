<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	$headings = array();
	
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		
		// Check if current tag is a heading (H1-H6)
		if ( ! preg_match( '/^H[1-6]$/', $tag ) ) {
			continue;
		}
		
		// Extract level from tag name (H1 -> 1, H2 -> 2, etc.)
		$level = (int) substr( $tag, 1 );
		
		// Collect text content of the heading
		$text = '';
		$depth_inside_heading = $processor->get_current_depth();
		
		while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_heading ) {
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
