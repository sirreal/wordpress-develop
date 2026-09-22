<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	// Create an HTML Processor to parse the HTML fragment.
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation fails, return empty array.
	if ( null === $processor ) {
		return array();
	}
	
	// Iterate through all tags in the document.
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		
		// Only process img tags that are in HTML namespace (not SVG).
		if ( 'IMG' === $tag && 'html' === $processor->get_namespace() ) {
			// Get the src attribute.
			$src = $processor->get_attribute( 'src' );
			
			// Only add if src exists and is not null/empty.
			if ( null !== $src && '' !== $src ) {
				// get_attribute returns decoded values, so no further decoding needed.
				$sources[] = $src;
			}
		}
	}
	
	return $sources;
}
