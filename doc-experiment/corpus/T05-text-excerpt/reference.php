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
		if (
			'#text' === $processor->get_token_type() ||
			(
				! $processor->is_tag_closer() &&
				in_array( $processor->get_token_name(), array( 'TEXTAREA', 'TITLE' ), true )
			)
		) {
			$text .= $processor->get_modifiable_text();
			if ( mb_strlen( $text, 'UTF-8' ) >= $max_codepoints ) {
				break;
			}
		}
	}

	return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
}
