<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$output = '';
	while ( $processor->next_token() ) {
		// Skip both opening and closing SPAN tags
		if ( 'SPAN' === $processor->get_tag() ) {
			continue;
		}
		$output .= $processor->serialize_token();
	}

	return $output;
}
