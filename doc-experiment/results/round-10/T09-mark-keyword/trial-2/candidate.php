<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create an HTML processor for the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation fails, return normalized version without marks
	if ( null === $processor ) {
		$normalized = WP_HTML_Processor::normalize( $html );
		return $normalized ?? $html;
	}
	
	$output = '';
	
	// Iterate through all tokens
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the keyword is in the decoded text (case-sensitive substring match)
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a <mark> element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// No match, serialize normally
				$output .= $processor->serialize_token();
			}
		} else {
			// Serialize all other tokens normally
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
