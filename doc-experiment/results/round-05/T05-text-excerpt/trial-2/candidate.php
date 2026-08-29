<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle zero or negative max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Use WP_HTML_Tag_Processor to parse HTML tokens
	$processor = new WP_HTML_Tag_Processor( $html );
	
	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens in the HTML document
	while ( $processor->next_token() ) {
		// Only process text nodes (#text tokens)
		if ( '#text' === $processor->get_token_type() ) {
			$token_text = $processor->get_modifiable_text();
			
			// Count codepoints in the token text
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );
			
			// Check if we have room for this entire token
			if ( $codepoint_count + $token_codepoints <= $max_codepoints ) {
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// We need to truncate this token
				$remaining_codepoints = $max_codepoints - $codepoint_count;
				if ( $remaining_codepoints > 0 ) {
					// Safely truncate to the remaining codepoints
					$text .= mb_substr( $token_text, 0, $remaining_codepoints, 'UTF-8' );
				}
				break;
			}
		}
	}

	return $text;
}
