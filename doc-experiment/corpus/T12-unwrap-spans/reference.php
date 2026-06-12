<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$output = '';
	while ( $processor->next_token() ) {
		// A single check for 'SPAN' === $processor->get_token_name() is also idiomatic here.
		if ( '#tag' === $processor->get_token_type() && 'SPAN' === $processor->get_tag() ) {
			continue;
		}
		$output .= $processor->serialize_token();
	}

	return $output;
}
