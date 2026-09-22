<?php

declare(strict_types=1);

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

		if (
			'#tag' === $processor->get_token_type() &&
			! $processor->is_tag_closer() &&
			( 'TITLE' === $processor->get_tag() || 'TEXTAREA' === $processor->get_tag() )
		) {
			$text .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
		return '';
	}

	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		if ( mb_strlen( $text, 'UTF-8' ) > $max_codepoints ) {
			return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
		}

		return $text;
	}

	if ( strlen( $text ) <= $max_codepoints ) {
		return $text;
	}

	return substr( $text, 0, $max_codepoints );
}
