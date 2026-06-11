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
		// Only collect text from #text tokens.
		if ( '#text' === $processor->get_token_type() ) {
			$token_text = $processor->get_modifiable_text();
			$token_codepoints = mb_strlen( $token_text, 'UTF-8' );

			// If adding this token would exceed the limit, truncate it.
			if ( $codepoint_count + $token_codepoints > $max_codepoints ) {
				$remaining = $max_codepoints - $codepoint_count;
				$text .= mb_substr( $token_text, 0, $remaining, 'UTF-8' );
				$codepoint_count = $max_codepoints;
				break;
			}

			$text .= $token_text;
			$codepoint_count += $token_codepoints;
		}
	}

	return $text;
}
