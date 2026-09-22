<?php

declare( strict_types=1 );

/**
 * Remove empty paragraph elements from an HTML fragment.
 *
 * A paragraph is considered empty only when it contains no content at all.
 * Whitespace and child elements count as content.
 *
 * If the fragment cannot be fully processed, the original HTML is returned
 * unchanged.
 */
function remove_empty_paragraphs( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$output = '';
	$stack  = array();

	while ( $processor->next_token() ) {
		$token_serialization = $processor->serialize_token();
		$token_type          = $processor->get_token_type();
		$tag_name            = $processor->get_tag();
		$is_tag              = '#tag' === $token_type;
		$is_p_open           = $is_tag && 'P' === $tag_name && ! $processor->is_tag_closer();
		$is_p_close          = $is_tag && 'P' === $tag_name && $processor->is_tag_closer();

		if ( $is_p_open ) {
			$stack[] = array(
				'html'        => $token_serialization,
				'is_paragraph' => true,
				'saw_content' => false,
			);
			continue;
		}

		if ( ! empty( $stack ) ) {
			$top_index = count( $stack ) - 1;
			$stack[ $top_index ]['html'] .= $token_serialization;

			if ( ! $is_p_close ) {
				$stack[ $top_index ]['saw_content'] = true;
			}

			if ( $is_p_close ) {
				$top = array_pop( $stack );

				if ( ! $top['saw_content'] ) {
					continue;
				}

				if ( ! empty( $stack ) ) {
					$parent_index = count( $stack ) - 1;
					$stack[ $parent_index ]['html'] .= $top['html'];
					$stack[ $parent_index ]['saw_content'] = true;
				} else {
					$output .= $top['html'];
				}
			}

			continue;
		}

		$output .= $token_serialization;
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( ! empty( $stack ) ) {
		return $html;
	}

	return $output;
}
