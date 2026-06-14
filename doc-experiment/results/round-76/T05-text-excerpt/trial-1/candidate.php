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

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'TITLE' === $tag_name || 'TEXTAREA' === $tag_name ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
	}

	if ( function_exists( 'iconv_substr' ) ) {
		$result = iconv_substr( $text, 0, $max_codepoints, 'UTF-8' );
		return false === $result ? '' : $result;
	}

	$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
	if ( false === $chars ) {
		return '';
	}

	return implode( '', array_slice( $chars, 0, $max_codepoints ) );
}
