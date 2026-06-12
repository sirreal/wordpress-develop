<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Try to find the first H1 element.
	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}
	
	// Record the depth of the H1 element.
	$h1_depth = $processor->get_current_depth();
	
	// Accumulate text content of the H1.
	$text = '';
	
	// Walk through all tokens within the H1 element.
	while ( $processor->next_token() && $processor->get_current_depth() >= $h1_depth ) {
		// Only collect text from #text nodes.
		if ( '#text' === $processor->get_token_type() ) {
			// get_modifiable_text() returns decoded text for #text nodes.
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
