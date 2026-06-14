<?php

/**
 * Remove all SPAN elements from an HTML fragment while preserving contents.
 *
 * @param string $html HTML fragment found inside <body>.
 * @return string Normalized HTML with span wrappers removed.
 */
function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	$output     = '';
	$span_depths = 0;

	while ( $processor->next_token() ) {
		if ( '#tag' === $processor->get_token_type() && 'SPAN' === $processor->get_tag() ) {
			if ( $processor->is_tag_closer() ) {
				if ( $span_depths > 0 ) {
					$span_depths--;
				}
			} else {
				$span_depths++;
			}

			continue;
		}

		if ( $span_depths > 0 ) {
			continue;
		}

		$output .= $processor->serialize_token();
	}

	if ( null !== $processor->get_last_error() ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	return $output;
}
