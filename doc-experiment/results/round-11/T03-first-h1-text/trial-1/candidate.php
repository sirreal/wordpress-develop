<?php

function get_first_h1_text( string $html ): ?string {
	// Create an HTML processor for the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first H1 element
	if ( ! $processor->next_tag( 'H1' ) ) {
		// No H1 element found
		return null;
	}
	
	// Record the depth at the H1 opening tag
	$h1_depth = $processor->get_current_depth();
	
	// Collect text content from inside the H1
	$text = '';
	while ( $processor->next_token() && $processor->get_current_depth() >= $h1_depth ) {
		// Check if this is a text token
		if ( '#text' === $processor->get_token_type() ) {
			// Append the decoded text content
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
