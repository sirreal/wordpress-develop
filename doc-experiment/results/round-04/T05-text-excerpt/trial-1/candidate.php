<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle edge case: non-positive max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a tag processor to iterate through tokens
	$processor = new WP_HTML_Tag_Processor( $html );
	
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
		
		// Count codepoints in this token's text
		$token_length = mb_strlen( $token_text, 'UTF-8' );
		$remaining_space = $max_codepoints - $codepoint_count;

		if ( $token_length <= $remaining_space ) {
			// Token fits entirely
			$text .= $token_text;
			$codepoint_count += $token_length;
		} else {
			// Token needs to be truncated
			$text .= mb_substr( $token_text, 0, $remaining_space, 'UTF-8' );
			$codepoint_count = $max_codepoints;
			break;
		}

		// Stop if we've reached the limit
		if ( $codepoint_count >= $max_codepoints ) {
			break;
		}
	}

	return $text;
}
