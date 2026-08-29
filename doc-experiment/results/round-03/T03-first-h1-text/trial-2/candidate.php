<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first H1 tag
	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}
	
	// Get the depth of the H1 tag
	$h1_depth = $processor->get_current_depth();
	
	// Accumulate text content
	$text_content = '';
	
	// Walk through tokens inside the H1
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the H1 (depth becomes less than H1's depth)
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Collect text nodes
		if ( '#text' === $processor->get_token_type() ) {
			$text_content .= $processor->get_modifiable_text();
		}
	}
	
	return $text_content;
}
