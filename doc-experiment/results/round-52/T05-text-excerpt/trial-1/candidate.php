<?php
declare(strict_types=1);

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
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
			continue;
		}

		if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
			$token_name = $processor->get_token_name();

			// TITLE and TEXTAREA carry their decoded text on the opener token.
			if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
				$text .= $processor->get_modifiable_text();
			}
		}
	}

	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
	}

	$characters = array();
	if ( preg_match_all( '/./us', $text, $matches ) ) {
		$characters = $matches[0];
	}

	return implode( '', array_slice( $characters, 0, $max_codepoints ) );
}
