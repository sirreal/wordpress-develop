<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first H1 tag
	if ( ! $processor->next_tag( array( 'tag_name' => 'h1' ) ) ) {
		return null;
	}
	
	// Record the depth when we're at the H1 opening tag
	$h1_depth = $processor->get_current_depth();
	
	// Collect all text content inside the H1 element
	$text = '';
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we've exited the H1 element (depth drops below H1's depth)
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Collect text from #text nodes at any depth inside the H1
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
