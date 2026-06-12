<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle non-positive max_codepoints
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

			// Calculate how many codepoints we can add from this token
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );
			$remaining_capacity = $max_codepoints - $codepoint_count;

			if ( $token_codepoints <= $remaining_capacity ) {
				// Can add the entire token
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// Need to truncate this token
				$text .= mb_substr( $token_text, 0, $remaining_capacity, 'UTF-8' );
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
