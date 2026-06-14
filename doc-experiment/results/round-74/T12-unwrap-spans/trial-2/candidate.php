<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$output     = '';
	$skip_depth  = 0;

	while ( $processor->next_token() ) {
		if ( 'SPAN' === $processor->get_tag() ) {
			if ( $processor->is_tag_closer() ) {
				if ( $skip_depth > 0 ) {
					$skip_depth--;
				}
				continue;
			}

			$skip_depth++;
			continue;
		}

		if ( $skip_depth > 0 ) {
			continue;
		}

		$output .= $processor->serialize_token();
	}

	return $output;
}
