<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle zero or negative max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a tag processor to scan through the HTML
	$processor = new WP_HTML_Tag_Processor( $html );

	// Accumulate text from all text nodes
	$text = '';

	// Iterate through all tokens
	while ( $processor->next_token() ) {
		// Only collect text from #text nodes
		if ( '#text' === $processor->get_token_type() ) {
			// get_modifiable_text() returns already-decoded text
			$text .= $processor->get_modifiable_text();
		}
	}

	// Truncate to max_codepoints using mb_substr with UTF-8 encoding
	// mb_substr counts Unicode code points when encoding is specified
	$truncated = mb_substr( $text, 0, $max_codepoints, 'UTF-8' );

	return $truncated;
}
