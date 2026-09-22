<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create a fragment processor for the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If the processor failed to initialize, return empty
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	// Walk through all tokens
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the text contains the keyword (case-sensitive substring match on decoded text)
			if ( str_contains( $text, $keyword ) ) {
				// Wrap the entire text node in a mark tag
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Just serialize the token as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// For all other tokens, serialize them as-is
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
