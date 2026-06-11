<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}
	
	while ( $processor->next_tag( 'img' ) ) {
		// Only process IMG elements in HTML namespace, not SVG namespace
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is null (attribute not present) or true (boolean attribute with no value)
		if ( null === $src || true === $src ) {
			continue;
		}
		
		// Skip if src is empty string (has attribute but no value)
		if ( '' === $src ) {
			continue;
		}
		
		// Add the decoded src value to the results
		$sources[] = $src;
	}
	
	return $sources;
}
