<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first H1 element
	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}
	
	// Record the depth at which we found the H1
	$h1_depth = $processor->get_current_depth();
	
	// Collect all text content inside the H1
	$text = '';
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the H1 element (depth becomes less than H1 depth)
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Collect text tokens that are inside the H1
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
