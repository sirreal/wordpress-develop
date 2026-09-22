<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Return empty string if max_codepoints is zero or negative
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a fragment processor for the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens
	while ( $processor->next_token() ) {
		// Check if this is a text node
		if ( '#text' === $processor->get_token_type() ) {
			$token_text = $processor->get_modifiable_text();
			
			// Count codepoints in this token
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );
			
			// If we have room for the entire token, add it
			if ( $codepoint_count + $token_codepoints <= $max_codepoints ) {
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// We need to truncate - add only what fits
				$remaining = $max_codepoints - $codepoint_count;
				if ( $remaining > 0 ) {
					$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				}
				break;
			}
		}
	}

	return $text;
}
