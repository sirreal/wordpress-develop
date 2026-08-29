<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	// Use the HTML Processor to properly handle SVG namespaces
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $sources;
	}
	
	// Scan through all tags
	while ( $processor->next_tag() ) {
		// Only process IMG tags in the HTML namespace
		if ( 'IMG' === $processor->get_tag() && 'html' === $processor->get_namespace() ) {
			// Get the src attribute
			$src = $processor->get_attribute( 'src' );
			
			// Only include if src exists and is not empty
			if ( null !== $src && '' !== $src && true !== $src ) {
				$sources[] = $src;
			}
		}
	}
	
	return $sources;
}
