<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create a processor to parse the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If parsing failed, return empty or handle gracefully
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	// Walk through every token in the HTML
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			// Get the decoded text content
			$text = $processor->get_modifiable_text();
			
			// Check if the keyword is in the text (case-sensitive substring match)
			if ( strpos( $text, $keyword ) !== false ) {
				// Wrap the entire text node in a <mark> element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// No match, just serialize the token as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// For non-text tokens, just serialize as-is
			$output .= $processor->serialize_token();
		}
	}
	
	// Check if there were any parsing errors
	if ( null !== $processor->get_last_error() ) {
		return '';
	}
	
	return $output;
}
