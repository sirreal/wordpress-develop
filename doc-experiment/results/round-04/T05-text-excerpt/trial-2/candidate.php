<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$text = '';
	$codepoint_count = 0;

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$token_text = $processor->get_modifiable_text();
			
			// Count how many codepoints we can add from this token.
			$remaining = $max_codepoints - $codepoint_count;
			
			if ( $remaining <= 0 ) {
				break;
			}

			// Count codepoints in the token text.
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );

			if ( $token_codepoints <= $remaining ) {
				// Entire token fits.
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// Need to truncate this token.
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				$codepoint_count = $max_codepoints;
				break;
			}
		}
	}

	return $text;
}
