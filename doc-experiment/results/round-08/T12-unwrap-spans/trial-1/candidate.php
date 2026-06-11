<?php
/**
 * Remove all SPAN elements from an HTML fragment while preserving their contents.
 *
 * @param string $html HTML fragment to process.
 * @return string Normalized HTML with SPAN elements removed.
 */
function unwrap_spans( string $html ): string {
	$processor = \WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();
		
		// Skip opening and closing SPAN tags
		if ( 'SPAN' === $tag ) {
			continue;
		}
		
		// Serialize all other tokens
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
