<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return null;
	}
	
	// Find the first H1 element
	if ( ! $processor->next_tag( 'h1' ) ) {
		return null;
	}
	
	// Record the depth at the H1 opening tag
	$h1_depth = $processor->get_current_depth();
	$text = '';
	
	// Walk through tokens inside the H1 element
	while ( $processor->next_token() && $processor->get_current_depth() >= $h1_depth ) {
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	// Return empty string if no text found (markup-only H1)
	return $text;
}
