<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	if ( ! class_exists( 'WP_HTML_Processor' ) || ! method_exists( 'WP_HTML_Processor', 'create_fragment' ) ) {
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

		$tag = $processor->get_token_name();
		if ( 'TITLE' === $tag || 'TEXTAREA' === $tag ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
	}

	$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
	if ( false === $chars ) {
		return $text;
	}

	return implode( '', array_slice( $chars, 0, $max_codepoints ) );
}
