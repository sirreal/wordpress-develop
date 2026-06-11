<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create an HTML processor to parse the input
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		// If parsing failed, return normalized empty
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}
	
	$output = '';
	
	// Iterate through all tokens in the document
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text token
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the decoded text contains the keyword (case-sensitive substring match)
			if ( str_contains( $text, $keyword ) ) {
				// Wrap the entire text node in a mark element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Output the text token as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// Output non-text tokens as-is
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
