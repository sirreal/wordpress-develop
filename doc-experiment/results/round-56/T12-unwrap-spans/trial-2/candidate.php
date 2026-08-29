<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	$output     = '';
	$span_depth = 0;

	while ( $processor->next_token() ) {
		if ( '#tag' === $processor->get_token_type() && 'SPAN' === $processor->get_tag() ) {
			if ( $processor->is_tag_closer() ) {
				if ( $span_depth > 0 ) {
					$span_depth--;
				}
			} else {
				$span_depth++;
			}

			continue;
		}

		$output .= $processor->serialize_token();
	}

	if ( null !== $processor->get_last_error() ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	return $output;
}
