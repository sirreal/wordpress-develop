<?php

function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	// Use WP_HTML_Processor to properly parse with namespace awareness
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	// Iterate through all tags
	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();
		$namespace = $processor->get_namespace();
		
		// We only want HTML img elements, not SVG image elements
		// HTML img elements are in the 'html' namespace
		if ( 'IMG' === $tag_name && 'html' === $namespace ) {
			$src = $processor->get_attribute( 'src' );
			
			// Only include if src attribute exists and has a non-empty value
			// get_attribute returns null if attribute doesn't exist
			// or the actual string value (which could be empty string)
			if ( null !== $src && '' !== $src ) {
				$sources[] = $src;
			}
		}
	}
	
	return $sources;
}
