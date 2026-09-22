<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first H1 element
	if ( ! $processor->next_tag( array( 'tag_name' => 'H1' ) ) ) {
		return null;
	}
	
	// Record the depth of the H1 element to know when we've exited it
	$h1_depth = $processor->get_current_depth();
	$text = '';
	
	// Iterate through tokens inside the H1 element
	while ( $processor->next_token() && $processor->get_current_depth() >= $h1_depth ) {
		// Collect all text nodes within the H1
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
