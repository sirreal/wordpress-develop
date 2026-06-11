<?php

function unwrap_spans( string $html ): string {
	// Create an HTML processor in fragment mode (body context)
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		// If processor creation fails, return original HTML
		return $html;
	}
	
	$output = '';
	
	// Iterate through all tokens in the document
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Skip any SPAN tags (both opening and closing)
		if ( '#tag' === $token_type && 'SPAN' === $token_name ) {
			continue;
		}
		
		// Serialize and collect all non-SPAN tokens
		$output .= $processor->serialize_token();
	}
	
	// Normalize the collected output to canonical form
	$normalized = WP_HTML_Processor::normalize( $output );
	
	// Return normalized output, falling back to non-normalized if normalization fails
	return ( null !== $normalized ) ? $normalized : $output;
}
