<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle edge cases
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a processor from the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens
	while ( $processor->next_token() ) {
		// Only process text nodes
		if ( '#text' !== $processor->get_token_type() ) {
			continue;
		}

		// Get the decoded text content
		$token_text = $processor->get_modifiable_text();

		// Count codepoints and accumulate text
		$token_codepoints = mb_strlen( $token_text, 'UTF-8' );

		if ( $codepoint_count + $token_codepoints <= $max_codepoints ) {
			// Entire token fits
			$text .= $token_text;
			$codepoint_count += $token_codepoints;
		} else {
			// Need to truncate this token
			$remaining = $max_codepoints - $codepoint_count;
			if ( $remaining > 0 ) {
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
			}
			break;
		}
	}

	return $text;
}
