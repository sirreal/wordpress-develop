<?php

function unwrap_spans( string $html ): string {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	// Iterate through all tokens and rebuild without SPAN tags
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Skip SPAN opening and closing tags
		if ( '#tag' === $token_type && 'SPAN' === $token_name ) {
			continue;
		}
		
		// For all other tokens, serialize them
		$output .= $processor->serialize_token();
	}
	
	// Normalize the output using the HTML Processor
	$normalized = WP_HTML_Processor::normalize( $output );
	
	return $normalized ?? $output;
}
