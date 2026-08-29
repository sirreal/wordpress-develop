<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle invalid max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	// Accumulate text from all text nodes
	$text = '';
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	// Truncate to max_codepoints using mb_substr with UTF-8 encoding
	// mb_substr counts Unicode code points when encoding is specified
	return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
}
