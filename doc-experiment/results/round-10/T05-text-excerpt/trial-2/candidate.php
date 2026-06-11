<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Return empty string if max_codepoints is non-positive.
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a Tag Processor instance to scan through the HTML.
	$processor = new WP_HTML_Tag_Processor( $html );

	$text = '';

	// Scan through all tokens in the HTML document.
	while ( $processor->next_token() ) {
		// Only collect text from #text nodes.
		if ( '#text' === $processor->get_token_type() ) {
			// Get the modifiable text (already decoded by the API).
			$text .= $processor->get_modifiable_text();

			// Check if we've exceeded the limit.
			if ( mb_strlen( $text, 'UTF-8' ) >= $max_codepoints ) {
				// Truncate to exactly max_codepoints code points.
				$text = mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
				break;
			}
		}
	}

	return $text;
}
