<?php

function unwrap_spans( string $html ): string {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If creation failed, return the input unchanged
	if ( null === $processor ) {
		return $html;
	}
	
	// Iterate through all tokens
	$output = '';
	while ( $processor->next_token() ) {
		// Get the current tag name (uppercase)
		$tag = $processor->get_tag();
		
		// Skip SPAN tags (both openers and closers)
		if ( 'SPAN' === $tag ) {
			continue; // Don't serialize this token
		}
		
		// For all other tokens, serialize them
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
