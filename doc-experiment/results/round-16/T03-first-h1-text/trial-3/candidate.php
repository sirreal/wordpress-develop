<?php

function get_first_h1_text( string $html ): ?string {
	$processor = \WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return null;
	}
	
	// Find the first H1 element
	if ( ! $processor->next_tag( array( 'tag_name' => 'h1' ) ) ) {
		return null;
	}
	
	// Record the depth of the H1 element
	$h1_depth = $processor->get_current_depth();
	
	// Collect all text content within the H1
	$text_content = '';
	
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the H1 element (depth goes below h1_depth)
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Only collect text nodes that are direct or nested content of the H1
		if ( '#text' === $processor->get_token_type() && $current_depth > $h1_depth ) {
			$text_content .= $processor->get_modifiable_text();
		}
	}
	
	return $text_content;
}
