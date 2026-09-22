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
			continue;
		}

		if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
			$tag = $processor->get_tag();
			if ( 'TEXTAREA' === $tag || 'TITLE' === $tag ) {
				$text .= $processor->get_modifiable_text();
			}
		}
	}

	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
	}

	if ( preg_match_all( '/./us', $text, $matches ) ) {
		return implode( '', array_slice( $matches[0], 0, $max_codepoints ) );
	}

	return '';
}
