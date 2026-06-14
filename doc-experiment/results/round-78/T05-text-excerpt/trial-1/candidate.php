<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
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

		$token_name = $processor->get_token_name();
		if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		if ( mb_strlen( $text, 'UTF-8' ) > $max_codepoints ) {
			return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
		}

		return $text;
	}

	if ( preg_match_all( '/./us', $text, $matches ) ) {
		if ( count( $matches[0] ) > $max_codepoints ) {
			return implode( '', array_slice( $matches[0], 0, $max_codepoints ) );
		}

		return $text;
	}

	return '';
}
