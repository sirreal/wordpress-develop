<?php
/**
 * Extract plain text excerpt from HTML fragment with length limit.
 *
 * @param string $html HTML fragment to extract text from.
 * @param int $max_codepoints Maximum number of Unicode codepoints to include.
 * @return string Plain text content, truncated to max_codepoints, decoded.
 */
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// If max_codepoints is zero or negative, return empty string.
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create an HTML processor for the fragment.
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text            = '';
	$codepoint_count = 0;

	// Iterate through all tokens in the HTML document.
	while ( $processor->next_token() ) {
		// Only process text nodes (#text).
		if ( '#text' !== $processor->get_token_type() ) {
			continue;
		}

		// Get the decoded text content.
		$token_text = $processor->get_modifiable_text();

		// Check if adding this text would exceed the limit.
		$token_codepoints = mb_strlen( $token_text, 'UTF-8' );
		$remaining_codepoints = $max_codepoints - $codepoint_count;

		if ( $token_codepoints <= $remaining_codepoints ) {
			// We can add the entire token.
			$text .= $token_text;
			$codepoint_count += $token_codepoints;
		} else {
			// We need to truncate this token to fit the remaining space.
			$truncated = mb_substr( $token_text, 0, $remaining_codepoints, 'UTF-8' );
			$text .= $truncated;
			$codepoint_count += mb_strlen( $truncated, 'UTF-8' );
			break;
		}
	}

	return $text;
}
