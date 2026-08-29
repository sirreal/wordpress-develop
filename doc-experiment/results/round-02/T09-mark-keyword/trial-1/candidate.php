<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	// Iterate through all tokens and build output
	$output = '';
	$found_match = false;
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		if ( '#text' === $token_type ) {
			// Get the decoded text content
			$text = $processor->get_modifiable_text();
			
			// Check if keyword is in this text node (case-sensitive substring match)
			if ( strpos( $text, $keyword ) !== false ) {
				// Wrap the entire text node with mark tags
				$output .= '<mark>' . $text . '</mark>';
				$found_match = true;
			} else {
				// No match, include text as-is
				$output .= $text;
			}
		} else {
			// For non-text tokens, use serialize_token to get proper HTML
			$output .= $processor->serialize_token();
		}
	}
	
	// Normalize the reconstructed HTML
	$result = WP_HTML_Processor::normalize( $output );
	return $result !== null ? $result : '';
}
