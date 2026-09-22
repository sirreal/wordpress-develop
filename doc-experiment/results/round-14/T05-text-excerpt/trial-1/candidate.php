<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle non-positive max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a processor to parse the HTML fragment
	$processor = WP_HTML_Tag_Processor::create_fragment( $html );
	if ( null === $processor ) {
		// If fragment parsing fails, return empty
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		// Only collect text from #text nodes
		if ( '#text' === $token_type ) {
			$modifiable_text = $processor->get_modifiable_text();
			
			// Count the codepoints in this text
			$text_codepoint_count = mb_strlen( $modifiable_text, 'UTF-8' );

			// Determine how many codepoints we can still add
			$remaining_codepoints = $max_codepoints - $codepoint_count;

			if ( $text_codepoint_count <= $remaining_codepoints ) {
				// We can add all of this text
				$text .= $modifiable_text;
				$codepoint_count += $text_codepoint_count;
			} else {
				// We need to truncate this text and then stop
				$text .= mb_substr( $modifiable_text, 0, $remaining_codepoints, 'UTF-8' );
				$codepoint_count = $max_codepoints;
				break;
			}

			// If we've reached the limit, stop collecting
			if ( $codepoint_count >= $max_codepoints ) {
				break;
			}
		}
	}

	return $text;
}
