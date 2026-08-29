<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	// Use WP_HTML_Processor to parse the HTML properly with namespace awareness.
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return array();
	}
	
	// Iterate through all tags in the HTML document.
	while ( $processor->next_tag() ) {
		// Get the current tag name.
		$tag_name = $processor->get_tag();
		
		// Get the namespace of the current tag.
		$namespace = $processor->get_namespace();
		
		// Only process IMG tags in the HTML namespace.
		if ( 'IMG' === $tag_name && 'html' === $namespace ) {
			// Get the src attribute value.
			$src = $processor->get_attribute( 'src' );
			
			// Include the src only if it exists and is not empty.
			if ( $src !== null && $src !== '' && $src !== true ) {
				// Decode HTML entities in the src attribute value.
				$decoded_src = html_entity_decode( $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$sources[] = $decoded_src;
			}
		}
	}
	
	return $sources;
}
