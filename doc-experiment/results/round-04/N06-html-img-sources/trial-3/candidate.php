<?php

function collect_html_img_sources( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$sources = array();
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect IMG elements in HTML namespace, not SVG <image> elements
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src attribute doesn't exist or is null
		if ( null === $src || false === $src ) {
			continue;
		}
		
		// Skip if src is a boolean true (empty attribute)
		if ( true === $src ) {
			continue;
		}
		
		// Add the src value to our result
		$sources[] = $src;
	}
	
	return $sources;
}
