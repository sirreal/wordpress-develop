<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle zero or negative max_codepoints early
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a tag processor to parse the HTML fragment
	$processor = new WP_HTML_Tag_Processor( $html );

	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens in the HTML document
	while ( $processor->next_token() ) {
		// Only collect text from text nodes
		if ( '#text' === $processor->get_token_name() ) {
			$token_text = $processor->get_modifiable_text();

			// Count codepoints in this token and truncate if necessary
			$remaining = $max_codepoints - $codepoint_count;
			if ( $remaining <= 0 ) {
				break;
			}

			// Use mb_substr to handle multi-byte UTF-8 characters correctly
			$token_text = mb_substr( $token_text, 0, $remaining, 'UTF-8' );
			$text .= $token_text;

			// Update codepoint count
			$codepoint_count = mb_strlen( $text, 'UTF-8' );

			// Stop if we've reached the limit
			if ( $codepoint_count >= $max_codepoints ) {
				break;
			}
		}
	}

	return $text;
}
