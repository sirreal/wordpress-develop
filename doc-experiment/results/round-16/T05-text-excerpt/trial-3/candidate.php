<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// If max_codepoints is zero or negative, return empty string
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a tag processor to scan through the HTML
	$processor = new WP_HTML_Tag_Processor( $html );
	
	$text = '';
	$count = 0;

	// Iterate through all tokens in the HTML
	while ( $processor->next_token() ) {
		// Only collect text from #text nodes
		// Script and style contents are not text nodes according to the documentation
		if ( '#text' === $processor->get_token_name() ) {
			$token_text = $processor->get_modifiable_text();
			
			// Get the length in code points (Unicode characters)
			$token_length = mb_strlen( $token_text, 'UTF-8' );
			
			// Calculate how many characters we can still add
			$remaining = $max_codepoints - $count;
			
			if ( $token_length <= $remaining ) {
				// We can add the entire token
				$text .= $token_text;
				$count += $token_length;
			} else {
				// We need to truncate this token
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				$count = $max_codepoints;
				break;
			}
		}
	}

	return $text;
}
