<?php

if ( ! function_exists( 'html_text_excerpt' ) ) {
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
				( 'TITLE' === $processor->get_token_name() || 'TEXTAREA' === $processor->get_token_name() )
			) {
				$text .= $processor->get_modifiable_text();
			}
		}

		if ( function_exists( 'preg_split' ) ) {
			$codepoints = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
			if ( false !== $codepoints ) {
				if ( count( $codepoints ) > $max_codepoints ) {
					$codepoints = array_slice( $codepoints, 0, $max_codepoints );
				}

				return implode( '', $codepoints );
			}
		}

		return $text;
	}
}
