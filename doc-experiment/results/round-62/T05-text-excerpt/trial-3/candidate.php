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
		$token_type = $processor->get_token_type();

		if ( '#text' === $token_type ) {
			$text .= $processor->get_modifiable_text();
			continue;
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'TITLE' !== $tag_name && 'TEXTAREA' !== $tag_name ) {
			continue;
		}

		$text .= $processor->get_modifiable_text();
	}

	if ( '' === $text ) {
		return '';
	}

	$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
	if ( false === $chars ) {
		return '';
	}

	if ( count( $chars ) <= $max_codepoints ) {
		return $text;
	}

	return implode( '', array_slice( $chars, 0, $max_codepoints ) );
}
