<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$tag_name = $processor->get_tag();
		
		// Skip both opening and closing SPAN tags
		if ( 'SPAN' === $tag_name ) {
			continue;
		}
		
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
