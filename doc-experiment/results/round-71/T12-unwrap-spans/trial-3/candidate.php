<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	$output      = '';
	$skip_spans  = 0;

	while ( $processor->next_token() ) {
		if ( 'SPAN' === $processor->get_tag() ) {
			if ( $processor->is_tag_closer() ) {
				if ( $skip_spans > 0 ) {
					$skip_spans--;
				}
				continue;
			}

			$skip_spans++;
			continue;
		}

		if ( $skip_spans > 0 ) {
			continue;
		}

		$output .= $processor->serialize_token();
	}

	if ( null !== $processor->get_last_error() ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	return $output;
}
