<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first H1 element
	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}
	
	// Record the depth at the H1 opening tag
	$depth_inside_h1 = $processor->get_current_depth();
	
	// Collect all text content inside the H1
	$text = '';
	while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_h1 ) {
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
