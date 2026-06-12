<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();
		
		// Skip both opening and closing SPAN tags, but keep their contents
		if ( 'SPAN' === $tag ) {
			continue;
		}
		
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
