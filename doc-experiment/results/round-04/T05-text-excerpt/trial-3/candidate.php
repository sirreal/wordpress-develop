<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
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
		if ( '#text' === $processor->get_token_type() ) {
			$token_text = $processor->get_modifiable_text();
			
			// Count Unicode code points in the token text
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );
			$remaining = $max_codepoints - $codepoint_count;
			
			if ( $token_codepoints <= $remaining ) {
				// Entire token fits
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// Need to truncate this token
				$truncated = mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				$text .= $truncated;
				$codepoint_count += $remaining;
				break;
			}

			if ( $codepoint_count >= $max_codepoints ) {
				break;
			}
		}
	}

	return $text;
}
