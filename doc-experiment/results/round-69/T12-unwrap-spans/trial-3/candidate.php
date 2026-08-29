<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	$output = '';
	$skip_span_depth = 0;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( 0 === $skip_span_depth ) {
				$output .= $processor->serialize_token();
			}
			continue;
		}

		if ( 'SPAN' === $processor->get_tag() ) {
			if ( $processor->is_tag_closer() ) {
				if ( $skip_span_depth > 0 ) {
					$skip_span_depth--;
				}
			} else {
				$skip_span_depth++;
			}
			continue;
		}

		if ( 0 === $skip_span_depth ) {
			$output .= $processor->serialize_token();
		}
	}

	if ( null !== $processor->get_last_error() ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	return $output;
}
