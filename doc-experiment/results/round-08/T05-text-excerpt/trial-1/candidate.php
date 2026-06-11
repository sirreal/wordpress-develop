<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// If max_codepoints is zero or negative, return empty string.
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = \WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		// Only process text nodes, not tags, comments, etc.
		if ( '#text' === $token_type ) {
			$token_text = $processor->get_modifiable_text();

			// Check if adding this text would exceed the limit.
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );

			if ( $codepoint_count + $token_codepoints <= $max_codepoints ) {
				// We can add the entire token.
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// We need to truncate.
				$remaining = $max_codepoints - $codepoint_count;
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				break;
			}
		}
	}

	return $text;
}
