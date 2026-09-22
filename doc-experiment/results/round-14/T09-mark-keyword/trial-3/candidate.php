<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the keyword (case-sensitive) is in the decoded text
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a <mark> element
				$output .= '<mark>';
				$output .= $processor->serialize_token();
				$output .= '</mark>';
			} else {
				// No match, output as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// For non-text tokens, serialize normally
			$output .= $processor->serialize_token();
		}
	}
	
	// Return the output
	return $output;
}
