<?php

/**
 * Return the text content of an HTML fragment, truncated to a maximum codepoint length.
 *
 * @param string $html           The HTML fragment to extract text from.
 * @param int    $max_codepoints Maximum number of Unicode codepoints to include.
 * @return string The plain text content, truncated to max_codepoints.
 */
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle non-positive max_codepoints.
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens in the HTML document.
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		// Only process text nodes (#text).
		if ( '#text' === $token_type ) {
			$token_text = $processor->get_modifiable_text();

			// Count codepoints in the token text.
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );
			$remaining_codepoints = $max_codepoints - $codepoint_count;

			if ( $token_codepoints <= $remaining_codepoints ) {
				// We can fit the entire token.
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// We need to truncate this token.
				$truncated = mb_substr( $token_text, 0, $remaining_codepoints, 'UTF-8' );
				$text .= $truncated;
				$codepoint_count += mb_strlen( $truncated, 'UTF-8' );
				break;
			}
		}
	}

	return $text;
}
