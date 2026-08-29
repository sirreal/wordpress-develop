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
	
	// Record the depth of the H1 opening tag
	$h1_depth = $processor->get_current_depth();
	
	// Collect all text nodes inside the H1
	$text = '';
	
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop if we've exited the H1 element (depth dropped below H1's depth)
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Only collect text nodes that are directly or indirectly inside H1
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
