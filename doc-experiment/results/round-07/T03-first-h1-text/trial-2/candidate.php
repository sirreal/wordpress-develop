<?php

function get_first_h1_text( string $html ): ?string {
	$processor = \WP_HTML_Processor::create_fragment( $html );
	
	// Find the first H1 element
	if ( ! $processor->next_tag( array( 'tag_name' => 'H1' ) ) ) {
		return null;
	}
	
	// Record the depth of the H1 element
	$h1_depth = $processor->get_current_depth();
	
	// Accumulate text content
	$text = '';
	
	// Move to the next token to start reading content inside H1
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we've exited the H1 element (depth equals H1's opening depth)
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Only collect text tokens that are inside the H1
		if ( '#text' === $processor->get_token_type() && $current_depth >= $h1_depth ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
