<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Return empty string for non-positive max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a fragment processor
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';

	// Iterate through all tokens
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		// Collect text from #text nodes
		if ( '#text' === $token_type ) {
			$token_text = $processor->get_modifiable_text();
			$text .= $token_text;

			// Check if we've reached the max codepoints
			$text_codepoints = mb_strlen( $text, 'UTF-8' );
			if ( $text_codepoints >= $max_codepoints ) {
				// Truncate to exactly max_codepoints
				$text = mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
				break;
			}
		}
	}

	return $text;
}
