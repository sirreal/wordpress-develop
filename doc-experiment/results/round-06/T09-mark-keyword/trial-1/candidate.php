<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
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
			
			// Check if the keyword appears in this text node (case-sensitive)
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a <mark> element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// No match, serialize normally
				$output .= $processor->serialize_token();
			}
		} else {
			// Not a text node, serialize normally
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
