<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$text = '';
	$codepoint_count = 0;

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_name() ) {
			$token_text = $processor->get_modifiable_text();
			
			if ( '' === $token_text ) {
				continue;
			}

			// Count how many codepoints we can add from this token.
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );
			$remaining = $max_codepoints - $codepoint_count;

			if ( $token_codepoints <= $remaining ) {
				// The entire token fits.
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// We need to truncate the token.
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				$codepoint_count = $max_codepoints;
				break;
			}
		}
	}

	return $text;
}
