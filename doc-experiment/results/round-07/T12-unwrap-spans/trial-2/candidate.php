<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();
		
		// Skip SPAN opening and closing tags, but keep their contents
		if ( 'SPAN' === $tag ) {
			continue;
		}
		
		// Serialize all non-SPAN tokens
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
