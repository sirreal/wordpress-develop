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

		if ( 'TITLE' === $processor->get_token_name() || 'TEXTAREA' === $processor->get_token_name() ) {
			if ( ! $processor->is_tag_closer() ) {
				$text .= $processor->get_modifiable_text();
			}
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
