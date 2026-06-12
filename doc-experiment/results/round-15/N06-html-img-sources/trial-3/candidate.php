<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$sources = array();
	
	// Scan through all IMG tags in the document
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect IMG tags in the HTML namespace, not SVG <image> elements
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value (already decoded by the API)
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src attribute is missing (null) or is boolean true (no value)
		if ( null === $src || true === $src ) {
			continue;
		}
		
		// Skip if src is an empty string
		if ( '' === $src ) {
			continue;
		}
		
		// Add the decoded src value to the result
		$sources[] = $src;
	}
	
	return $sources;
}
