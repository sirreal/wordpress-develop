<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create an HTML processor in fragment mode
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		// Return normalized version if parsing fails
		return WP_HTML_Processor::normalize( $html ) ?? '';
	}
	
	$output = '';
	
	// Iterate through all tokens
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the keyword appears in this text node (case-sensitive)
			if ( strpos( $text, $keyword ) !== false ) {
				// Wrap the entire text node in a <mark> element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Include the text node as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// For all other token types, serialize them as-is
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
