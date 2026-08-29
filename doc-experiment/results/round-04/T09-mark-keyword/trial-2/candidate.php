<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create a fragment processor for the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	// Walk through all tokens in the document
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			// Get the decoded text content
			$text = $processor->get_modifiable_text();
			
			// Check if the keyword is in the decoded text (case-sensitive substring match)
			if ( str_contains( $text, $keyword ) ) {
				// Wrap the entire text node in a <mark> element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Just serialize the token as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// For non-text tokens, serialize them as-is
			$output .= $processor->serialize_token();
		}
	}
	
	// Normalize the output
	$normalized = WP_HTML_Processor::normalize( $output );
	
	return ( null === $normalized ) ? '' : $normalized;
}
