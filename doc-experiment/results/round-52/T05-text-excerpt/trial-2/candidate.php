<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$codepoint_length = static function ( string $text ): int {
		if ( '' === $text ) {
			return 0;
		}

		if ( function_exists( 'mb_strlen' ) ) {
			$length = mb_strlen( $text, 'UTF-8' );
			if ( false !== $length ) {
				return $length;
			}
		}

		$count = preg_match_all( '/./us', $text, $matches );
		return false === $count ? 0 : $count;
	};

	$codepoint_substr = static function ( string $text, int $length ): string {
		if ( $length <= 0 || '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			$slice = mb_substr( $text, 0, $length, 'UTF-8' );
			if ( false !== $slice ) {
				return $slice;
			}
		}

		$count = preg_match_all( '/./us', $text, $matches );
		if ( false === $count ) {
			return '';
		}

		return implode( '', array_slice( $matches[0], 0, $length ) );
	};

	$result    = '';
	$remaining = $max_codepoints;

	while ( $remaining > 0 && $processor->next_token() ) {
		$text = '';

		$token_type = $processor->get_token_type();
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
		} elseif ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
			$tag_name = $processor->get_tag();
			if ( 'TITLE' === $tag_name || 'TEXTAREA' === $tag_name ) {
				$text = $processor->get_modifiable_text();
			}
		}

		if ( '' === $text ) {
			continue;
		}

		$text_length = $codepoint_length( $text );
		if ( $text_length <= $remaining ) {
			$result .= $text;
			$remaining -= $text_length;
			continue;
		}

		$result .= $codepoint_substr( $text, $remaining );
		break;
	}

	return $result;
}
