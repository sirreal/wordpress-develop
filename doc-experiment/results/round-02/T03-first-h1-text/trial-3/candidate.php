<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return null;
	}
	
	// Find the first H1 tag
	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}
	
	// Record the depth at the H1 opening tag
	$h1_depth = $processor->get_current_depth();
	
	// Accumulate all text content inside the H1
	$text = '';
	
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the H1 element (when depth drops below H1 depth)
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Only collect text from #text nodes at any depth inside the H1
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	// Return empty string if no text found, or the accumulated text
	return $text;
}
