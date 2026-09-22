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
			$token_length = mb_strlen( $token_text, 'UTF-8' );
			
			if ( $codepoint_count + $token_length <= $max_codepoints ) {
				// Entire token fits within limit
				$text .= $token_text;
				$codepoint_count += $token_length;
			} else {
				// Token exceeds limit, truncate it
				$remaining = $max_codepoints - $codepoint_count;
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				break;
			}
		}
	}

	return $text;
}
