<?php

function get_first_h1_text( string $html ): ?string {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first H1 tag
	if ( ! $processor->next_tag( 'H1' ) ) {
		// No H1 found
		return null;
	}
	
	// Record the depth at which we found the H1
	$h1_depth = $processor->get_current_depth();
	
	// Collect all text inside this H1 element
	$text = '';
	
	// Walk through tokens while we're inside the H1
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the H1 (depth becomes less than h1_depth)
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Collect text nodes (not closers or other tokens)
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
