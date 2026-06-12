<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// If max_codepoints is zero or negative, return empty string
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a fragment processor for the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation failed, return empty string
	if ( null === $processor ) {
		return '';
	}

	// Accumulate all text from text nodes
	$text = '';
	while ( $processor->next_token() ) {
		// Check if the current token is a text node
		if ( '#text' === $processor->get_token_type() ) {
			// Get the modifiable text (already decoded per the docs)
			$text .= $processor->get_modifiable_text();
		}
	}

	// Count the number of Unicode code points
	$text_length = mb_strlen( $text, 'UTF-8' );

	// If text length exceeds max_codepoints, truncate to exactly max_codepoints
	if ( $text_length > $max_codepoints ) {
		$text = mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
	}

	return $text;
}
