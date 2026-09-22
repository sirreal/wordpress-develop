<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle zero or negative max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		// Only process text nodes
		if ( '#text' === $token_type ) {
			$modifiable_text = $processor->get_modifiable_text();

			// If we're already at max codepoints, stop
			if ( $codepoint_count >= $max_codepoints ) {
				break;
			}

			// Calculate how many codepoints we can still add
			$remaining_codepoints = $max_codepoints - $codepoint_count;

			// Count codepoints in this text node
			$text_length = mb_strlen( $modifiable_text, 'UTF-8' );

			if ( $text_length <= $remaining_codepoints ) {
				// We can add the entire text node
				$text .= $modifiable_text;
				$codepoint_count += $text_length;
			} else {
				// We need to truncate this text node
				$truncated = mb_substr( $modifiable_text, 0, $remaining_codepoints, 'UTF-8' );
				$text .= $truncated;
				$codepoint_count += $remaining_codepoints;
				break;
			}
		}
	}

	return $text;
}