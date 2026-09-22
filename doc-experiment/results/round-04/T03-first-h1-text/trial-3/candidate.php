<?php

function get_first_h1_text( string $html ): ?string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find the first H1 element
	if ( ! $processor->next_tag( 'h1' ) ) {
		return null;
	}
	
	// Collect all text content from within the H1
	$text_content = '';
	
	// Record the depth of the H1 so we know when we've exited it
	$h1_depth = 0;
	
	// Move past the opening H1 tag to its contents
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Track depth to know when we exit the H1
		if ( '#tag' === $token_type ) {
			if ( $processor->is_tag_closer() ) {
				// Check if this is the closing H1 tag
				if ( 'H1' === $processor->get_tag() ) {
					break;
				}
			}
		} elseif ( '#text' === $token_type ) {
			// Collect text from text nodes
			// get_modifiable_text() returns decoded character references
			$text_content .= $processor->get_modifiable_text();
		}
	}
	
	return $text_content;
}
