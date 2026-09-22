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
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );
			$remaining = $max_codepoints - $codepoint_count;

			if ( $token_codepoints <= $remaining ) {
				$text .= $token_text;
				$codepoint_count += $token_codepoints;
			} else {
				// Truncate to remaining codepoints
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				$codepoint_count = $max_codepoints;
				break;
			}

			if ( $codepoint_count >= $max_codepoints ) {
				break;
			}
		}
	}

	return $text;
}
