<?php

/**
 * Extract plain text from HTML with a maximum codepoint limit.
 *
 * Given an HTML fragment, returns the concatenation of every text node in document order,
 * with character references decoded. Whitespace is preserved as-is.
 *
 * If the text exceeds $max_codepoints Unicode code points, it is truncated to exactly
 * that many code points without cutting multi-byte characters.
 *
 * @param string $html          The HTML fragment to process.
 * @param int    $max_codepoints Maximum number of Unicode code points to return.
 *
 * @return string Plain text extracted from the HTML, truncated if necessary.
 */
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Return empty string if max_codepoints is zero or negative
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Use WP_HTML_Tag_Processor to scan tokens
	$processor = new WP_HTML_Tag_Processor( $html );
	$text      = '';

	// Iterate through all tokens
	while ( $processor->next_token() ) {
		// Only collect text from #text nodes
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	// Truncate to max_codepoints code points using mb_substr with UTF-8 encoding
	return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
}
