<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	// Use WP_HTML_Processor to properly parse HTML with namespace awareness
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Return early if processor creation failed
	if ( $processor === null ) {
		return $sources;
	}
	
	// Find all IMG tags at the root level (in HTML namespace)
	while ( $processor->next_tag( 'IMG' ) ) {
		// Only process IMG tags that are in the HTML namespace
		// This excludes SVG <image> elements which are in the SVG namespace
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is null (attribute not present) or empty string
		if ( $src === null || $src === '' || $src === false ) {
			continue;
		}
		
		// Handle boolean attributes (should not happen with src, but be safe)
		if ( $src === true ) {
			continue;
		}
		
		// Decode HTML entities to get the value as a browser would understand it
		// html_entity_decode converts HTML entities like &amp; to &
		$decoded_src = html_entity_decode( $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		
		// Add to results
		$sources[] = $decoded_src;
	}
	
	return $sources;
}
