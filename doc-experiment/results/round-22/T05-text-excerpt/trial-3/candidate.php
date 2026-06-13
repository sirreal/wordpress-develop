<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$excerpt   = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#text' === $token_type ) {
			$excerpt .= $processor->get_modifiable_text();
		} elseif ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
			$tag_name = $processor->get_tag();
			if ( 'TITLE' === $tag_name || 'TEXTAREA' === $tag_name ) {
				$excerpt .= $processor->get_modifiable_text();
			}
		}

		if ( mb_strlen( $excerpt, 'UTF-8' ) >= $max_codepoints ) {
			return mb_substr( $excerpt, 0, $max_codepoints, 'UTF-8' );
		}
	}

	return $excerpt;
}
