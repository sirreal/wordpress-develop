<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$excerpt   = '';
	$remaining = $max_codepoints;

	while ( $processor->next_token() ) {
		$text = '';

		if ( '#text' === $processor->get_token_type() ) {
			$text = $processor->get_modifiable_text();
		} elseif ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
			$token_name = $processor->get_token_name();
			if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
				$text = $processor->get_modifiable_text();
			}
		}

		if ( '' === $text ) {
			continue;
		}

		$matched = preg_match_all( '/./us', $text, $codepoints );
		if ( false === $matched || 0 === $matched ) {
			continue;
		}

		if ( $matched <= $remaining ) {
			$excerpt   .= $text;
			$remaining -= $matched;
			continue;
		}

		$excerpt .= implode( '', array_slice( $codepoints[0], 0, $remaining ) );
		break;
	}

	return $excerpt;
}
