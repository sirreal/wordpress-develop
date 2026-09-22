<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create a processor to parse the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If the processor couldn't be created, return normalized input
	if ( null === $processor ) {
		return WP_HTML_Processor::normalize( $html ) ?? '';
	}
	
	// Walk through all tokens and build output
	$output = '';
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the text contains the keyword (case-sensitive)
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a mark element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Serialize text node as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// For all other tokens, serialize them as-is
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
