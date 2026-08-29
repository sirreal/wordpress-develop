<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle edge case: non-positive max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$text = '';

	// Iterate through all tokens and accumulate text from text nodes
	while ( $processor->next_token() ) {
		// Only process text nodes ('#text' token type)
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	// Truncate to max_codepoints without cutting in the middle of a multi-byte character
	// mb_substr with UTF-8 encoding handles codepoint boundaries correctly
	return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
}
