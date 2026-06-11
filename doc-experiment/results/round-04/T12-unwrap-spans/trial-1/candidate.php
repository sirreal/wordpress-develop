<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return WP_HTML_Processor::normalize( $html ) ?? '';
	}
	
	$output = '';
	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();
		
		if ( 'SPAN' === $tag ) {
			continue;
		}
		
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
