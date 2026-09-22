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

	$codepoint_length = static function ( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text, 'UTF-8' );
		}

		if ( preg_match_all( '/./us', $text, $matches ) !== false ) {
			return count( $matches[0] );
		}

		return strlen( $text );
	};

	$codepoint_slice = static function ( string $text, int $length ): string {
		if ( $length <= 0 ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, $length, 'UTF-8' );
		}

		if ( preg_match_all( '/./us', $text, $matches ) !== false ) {
			return implode( '', array_slice( $matches[0], 0, $length ) );
		}

		return substr( $text, 0, $length );
	};

	$output    = '';
	$remaining = $max_codepoints;

	while ( $remaining > 0 && $processor->next_token() ) {
		$chunk = '';

		if ( '#text' === $processor->get_token_type() ) {
			$chunk = $processor->get_modifiable_text();
		} elseif ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
			$tag_name = $processor->get_tag();
			if ( 'TITLE' === $tag_name || 'TEXTAREA' === $tag_name ) {
				$chunk = $processor->get_modifiable_text();
			}
		}

		if ( '' === $chunk ) {
			continue;
		}

		$chunk_length = $codepoint_length( $chunk );
		if ( $chunk_length <= $remaining ) {
			$output .= $chunk;
			$remaining -= $chunk_length;
			continue;
		}

		$output .= $codepoint_slice( $chunk, $remaining );
		break;
	}

	return $output;
}
