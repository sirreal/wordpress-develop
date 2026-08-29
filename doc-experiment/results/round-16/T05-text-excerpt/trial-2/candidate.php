<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle zero or negative codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create an HTML processor for the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return '';
	}

	$text = '';

	// Iterate through all tokens in the HTML
	while ( $processor->next_token() ) {
		// Only collect text from #text nodes
		if ( '#text' === $processor->get_token_type() ) {
			// Get the decoded text content
			$token_text = $processor->get_modifiable_text();
			
			// Check if adding this text would exceed the limit
			$current_length = mb_strlen( $text, 'UTF-8' );
			$token_length = mb_strlen( $token_text, 'UTF-8' );
			
			if ( $current_length >= $max_codepoints ) {
				// Already at or over the limit, stop collecting
				break;
			}
			
			$remaining = $max_codepoints - $current_length;
			
			if ( $token_length > $remaining ) {
				// This token would exceed the limit, truncate it
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				break;
			} else {
				// Add the entire token
				$text .= $token_text;
			}
		}
	}

	return $text;
}
