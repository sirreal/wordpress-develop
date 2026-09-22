<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return '';
	}
	
	$output = '';
	
	// Walk through all tokens
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the decoded text contains the keyword (case-sensitive substring match)
			if ( strpos( $text, $keyword ) !== false ) {
				// Wrap the entire text node in a mark element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// No match, just output the token as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// For all other tokens (tags, comments, etc.), serialize normally
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
