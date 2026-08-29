<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle edge cases for non-positive max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a Tag Processor to parse the HTML
	$processor = new WP_HTML_Tag_Processor( $html );
	
	$text = '';
	
	// Iterate through all tokens in the HTML
	while ( $processor->next_token() ) {
		// Check if the current token is a text node
		if ( '#text' === $processor->get_token_type() ) {
			// Get the decoded text content
			$text .= $processor->get_modifiable_text();
		}
	}
	
	// Truncate to the specified number of Unicode code points if needed
	if ( mb_strlen( $text, 'UTF-8' ) > $max_codepoints ) {
		$text = mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
	}
	
	return $text;
}
