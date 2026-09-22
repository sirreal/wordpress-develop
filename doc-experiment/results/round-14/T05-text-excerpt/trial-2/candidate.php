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
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();

			if ( mb_strlen( $text, 'UTF-8' ) >= $max_codepoints ) {
				$text = mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
				break;
			}
		}
	}

	return $text;
}
