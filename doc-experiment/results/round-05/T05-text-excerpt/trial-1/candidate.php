<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Return empty string if max_codepoints is zero or negative
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a tag processor to parse the HTML
	$processor = new WP_HTML_Tag_Processor( $html );
	
	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens
	while ( $processor->next_token() ) {
		// Only process text nodes
		if ( '#text' === $processor->get_token_type() ) {
			// Get the decoded text content
			$token_text = $processor->get_modifiable_text();
			
			// Count codepoints in this token to determine how much we can use
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );
			
			// Calculate how many codepoints we still need
			$remaining = $max_codepoints - $codepoint_count;
			
			if ( $token_codepoints <= $remaining ) {
				// We can use the entire token
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// We need to truncate this token
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				$codepoint_count = $max_codepoints;
				break;
			}
			
			// Stop if we've reached the limit
			if ( $codepoint_count >= $max_codepoints ) {
				break;
			}
		}
	}

	return $text;
}
