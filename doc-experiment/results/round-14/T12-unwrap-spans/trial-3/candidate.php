<?php

function unwrap_spans( string $html ): string {
	// Create a fragment processor for the HTML.
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation fails, return empty string.
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	// Walk through all tokens in the HTML.
	while ( $processor->next_token() ) {
		// Get the current tag name.
		$tag = $processor->get_tag();
		
		// Skip SPAN tags (both opening and closing).
		if ( 'SPAN' === $tag ) {
			continue;
		}
		
		// Serialize all other tokens to normalized HTML.
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
