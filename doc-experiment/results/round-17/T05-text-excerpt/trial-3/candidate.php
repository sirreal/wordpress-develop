<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle zero or negative max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';

	// Iterate through all tokens
	while ( $processor->next_token() ) {
		// Only collect text nodes
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	// Truncate to max_codepoints by code point count (not bytes)
	// Use mb_substr to properly handle multi-byte UTF-8 characters
	$text = mb_substr( $text, 0, $max_codepoints, 'UTF-8' );

	return $text;
}
