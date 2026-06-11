<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first H1 tag
	if ( ! $processor->next_tag( 'h1' ) ) {
		return null;
	}
	
	// Record the depth of the H1 element
	$h1_depth = $processor->get_current_depth();
	
	// Collect text content
	$text = '';
	
	// Walk through all tokens inside the H1
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we've exited the H1 element
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Only collect text that's inside the H1 (not from deeper nested closers at the same level)
		if ( $current_depth >= $h1_depth ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
	}
	
	return $text;
}
