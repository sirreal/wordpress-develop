<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$text      = '';

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	// Truncate to max_codepoints, respecting UTF-8 code point boundaries.
	if ( mb_strlen( $text, 'UTF-8' ) > $max_codepoints ) {
		$text = mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
	}

	return $text;
}
