<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle non-positive max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a fragment processor to parse the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation fails, return empty string
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens in the document
	while ( $processor->next_token() ) {
		// Only process text nodes (#text tokens)
		if ( '#text' === $processor->get_token_type() ) {
			$token_text = $processor->get_modifiable_text();
			
			// Count how many code points are in the token text
			// mb_strlen with 'UTF-32BE' counts code points (not bytes)
			$token_codepoints = mb_strlen( $token_text, 'UTF-32BE' );
			
			// Calculate how many code points we can still add
			$remaining = $max_codepoints - $codepoint_count;
			
			if ( $token_codepoints <= $remaining ) {
				// Add the entire token
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// We need to truncate this token
				// Use mb_substr with UTF-32BE to truncate at code point boundaries
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-32BE' );
				$codepoint_count = $max_codepoints;
				break;
			}
		}
	}

	return $text;
}
