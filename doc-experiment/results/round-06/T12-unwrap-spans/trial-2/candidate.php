<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$output = '';
	$skip_depth = 0;

	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();

		if ( 'SPAN' === $tag ) {
			if ( ! $processor->is_tag_closer() ) {
				// Opening SPAN tag - skip it and track depth.
				$skip_depth++;
				continue;
			} else {
				// Closing SPAN tag - skip it and decrease depth.
				if ( $skip_depth > 0 ) {
					$skip_depth--;
					continue;
				}
			}
		}

		// For all other tokens, serialize and output.
		$output .= $processor->serialize_token();
	}

	return $output;
}
