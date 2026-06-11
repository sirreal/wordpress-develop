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

	while ( $processor->next_token() ) {
		if ( '#text' !== $processor->get_token_type() ) {
			continue;
		}

		$text .= $processor->get_modifiable_text();
	}

	// Count Unicode code points and truncate if needed.
	$codepoint_count = mb_strlen( $text, 'UTF-8' );
	if ( $codepoint_count > $max_codepoints ) {
		$text = mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
	}

	return $text;
}
