<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( 'H1' ) ) {
		return null;
	}
	
	// Record the depth at the H1 opening tag
	$depth_inside_h1 = $processor->get_current_depth();
	$text = '';
	
	// Walk through all tokens inside the H1 element
	while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_h1 ) {
		// Only collect text nodes
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
