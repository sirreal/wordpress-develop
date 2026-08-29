<?php

/**
 * Extract plain text from HTML with a codepoint limit.
 *
 * Given an HTML fragment, returns its text content as the concatenation of
 * every text node in document order, with character references decoded.
 * Whitespace between elements is preserved. Text from script and style
 * elements is excluded (not counted as text nodes).
 *
 * If the text exceeds max_codepoints, it is truncated to exactly that many
 * Unicode codepoints without cutting multi-byte characters.
 *
 * @param string $html           HTML fragment to extract text from.
 * @param int    $max_codepoints Maximum number of Unicode codepoints to return.
 *                               If <= 0, returns empty string.
 *
 * @return string Plain text extracted from the HTML, truncated if necessary.
 */
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle zero or negative max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create an HTML tag processor to parse the HTML
	$processor = new WP_HTML_Tag_Processor( $html );

	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens in the HTML
	while ( $processor->next_token() ) {
		// We only care about text nodes
		if ( '#text' !== $processor->get_token_type() ) {
			continue;
		}

		// Get the text content (already decoded by the API)
		$token_text = $processor->get_modifiable_text();

		if ( '' === $token_text ) {
			continue;
		}

		// Count codepoints in the token text
		$token_codepoints = mb_strlen( $token_text, 'UTF-8' );
		$remaining = $max_codepoints - $codepoint_count;

		if ( $token_codepoints <= $remaining ) {
			// Entire token fits
			$text .= $token_text;
			$codepoint_count += $token_codepoints;
		} else {
			// Truncate to fit remaining codepoints
			$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
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
