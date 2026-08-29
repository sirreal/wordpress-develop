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
		$type = $processor->get_token_type();

		if ( '#text' === $type ) {
			$text .= $processor->get_modifiable_text();
			continue;
		}

		if ( '#tag' !== $type || $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_token_name();
		if ( 'TITLE' === $tag_name || 'TEXTAREA' === $tag_name ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $processor->get_last_error() ) {
		return '';
	}

	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		if ( mb_strlen( $text, 'UTF-8' ) > $max_codepoints ) {
			return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
		}

		return $text;
	}

	// Fallback for environments without mbstring: the input is expected to be UTF-8.
	if ( strlen( $text ) > $max_codepoints ) {
		return substr( $text, 0, $max_codepoints );
	}

	return $text;
}
