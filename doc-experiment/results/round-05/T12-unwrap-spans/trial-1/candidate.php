<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	$output    = '';

	while ( $processor->next_token() ) {
		if ( 'SPAN' === $processor->get_tag() ) {
			continue; // Skips both the opener and the closer.
		}
		$output .= $processor->serialize_token();
	}

	return $output;
}
