<?php
/**
 * Get plain-text excerpt from HTML with a maximum code point length.
 *
 * Given an HTML fragment, returns the concatenation of all text nodes in document order
 * with character references decoded. Text is truncated to a maximum number of Unicode code points.
 *
 * @param string $html HTML fragment to extract text from.
 * @param int $max_codepoints Maximum number of Unicode code points to include in output.
 * @return string Plain text excerpt, truncated to max_codepoints.
 */
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle invalid max_codepoints values.
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create an HTML processor in fragment mode.
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens in the HTML document.
	while ( $processor->next_token() ) {
		// Only collect text from text nodes (not from tags, comments, etc.).
		if ( '#text' === $processor->get_token_type() ) {
			$token_text = $processor->get_modifiable_text();

			// Determine how many code points we can still add.
			$remaining_codepoints = $max_codepoints - $codepoint_count;

			if ( $remaining_codepoints <= 0 ) {
				break;
			}

			// If this text fits entirely, add it all.
			$token_length = mb_strlen( $token_text, 'UTF-8' );
			if ( $token_length <= $remaining_codepoints ) {
				$text .= $token_text;
				$codepoint_count += $token_length;
			} else {
				// Truncate to fit exactly the remaining codepoints.
				$text .= mb_substr( $token_text, 0, $remaining_codepoints, 'UTF-8' );
				$codepoint_count = $max_codepoints;
				break;
			}
		}
	}

	return $text;
}
