<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		if ( 'SPAN' === $processor->get_tag() ) {
			continue; // Skip both the opener and closer of SPAN elements.
		}
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
