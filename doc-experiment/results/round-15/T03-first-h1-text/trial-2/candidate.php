<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first H1 element
	if ( ! $processor->next_tag( array( 'tag_name' => 'H1' ) ) ) {
		// No H1 element found
		return null;
	}
	
	// Record the depth at which we found the H1
	$h1_depth = $processor->get_current_depth();
	$text = '';
	
	// Iterate through tokens inside the H1 element
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we reach the closing tag of the H1 or go outside it
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Collect text from #text tokens
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
