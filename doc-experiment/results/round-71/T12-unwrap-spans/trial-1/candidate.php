<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	$output     = '';
	$skip_depth = 0;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( 0 === $skip_depth ) {
				$output .= $processor->serialize_token();
			}
			continue;
		}

		if ( 'SPAN' !== $processor->get_tag() ) {
			if ( 0 === $skip_depth ) {
				$output .= $processor->serialize_token();
			}
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( $skip_depth > 0 ) {
				$skip_depth--;
			}
			continue;
		}

		$skip_depth++;
	}

	if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	return $output;
}
