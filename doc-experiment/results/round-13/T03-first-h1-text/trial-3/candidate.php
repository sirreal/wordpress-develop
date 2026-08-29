<?php

function get_first_h1_text( string $html ): ?string {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return null;
	}
	
	// Find the first H1 element
	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}
	
	// Record the depth at the H1 opener
	$depth_inside_h1 = $processor->get_current_depth();
	
	// Accumulate text from all #text tokens inside the H1
	$text = '';
	while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_h1 ) {
		if ( '#text' === $processor->get_token_type() ) {
			// get_modifiable_text() returns decoded text (character references already replaced)
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
