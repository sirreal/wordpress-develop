<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle zero or negative codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	// Truncate to max_codepoints Unicode code points
	return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
}
