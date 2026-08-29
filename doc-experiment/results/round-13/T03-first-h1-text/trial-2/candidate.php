<?php

function get_first_h1_text( string $html ): ?string {
	// Create an HTML processor for the fragment.
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation fails, return null.
	if ( null === $processor ) {
		return null;
	}
	
	// Find the first H1 tag.
	if ( ! $processor->next_tag( 'H1' ) ) {
		// No H1 element found.
		return null;
	}
	
	// Record the depth at the H1 opening tag so we can detect when we exit the H1.
	$h1_depth = $processor->get_current_depth();
	
	// Accumulate text content.
	$text = '';
	
	// Walk through all tokens inside the H1 element.
	while ( $processor->next_token() ) {
		// Get the current depth.
		$current_depth = $processor->get_current_depth();
		
		// If we've exited the H1 (depth decreased), stop.
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Collect text from #text tokens.
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
