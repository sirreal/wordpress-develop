<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$text      = '';
	$codepoints = 0;

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$token_text = $processor->get_modifiable_text();
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );

			if ( $codepoints + $token_codepoints <= $max_codepoints ) {
				// Entire token fits within limit
				$text .= $token_text;
				$codepoints += $token_codepoints;
			} else {
				// Token exceeds limit, truncate it
				$remaining = $max_codepoints - $codepoints;
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				break;
			}
		}
	}

	return $text;
}
