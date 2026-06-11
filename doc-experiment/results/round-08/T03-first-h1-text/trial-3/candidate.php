<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return null;
	}
	
	// Find the first H1 tag
	if ( ! $processor->next_tag( array( 'tag_name' => 'h1' ) ) ) {
		return null;
	}
	
	// Record the depth of the H1 element
	$h1_depth = $processor->get_current_depth();
	
	// Collect all text content inside the H1
	$text_content = '';
	
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop if we've closed the H1 element (depth is less than H1's depth)
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Collect text nodes that are inside or equal to the H1 depth
		if ( '#text' === $processor->get_token_type() ) {
			$text_content .= $processor->get_modifiable_text();
		}
	}
	
	return $text_content;
}
