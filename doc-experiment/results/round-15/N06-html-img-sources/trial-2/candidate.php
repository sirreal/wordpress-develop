<?php

/**
 * Collect HTML image sources, excluding SVG images.
 *
 * Given an HTML fragment, returns a list of decoded `src` values from every HTML
 * `img` element as a browser would understand the document, in document order.
 * SVG `<image>` elements are excluded.
 *
 * @param string $html HTML fragment to process.
 * @return array Numeric array of decoded src values from HTML img elements.
 */
function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	// Create a fragment processor for the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation failed, return empty array
	if ( null === $processor ) {
		return $sources;
	}
	
	// Iterate through all tags in the document
	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();
		
		// Only process IMG tags in the HTML namespace
		if ( 'IMG' === $tag_name && 'html' === $processor->get_namespace() ) {
			// Get the src attribute
			$src = $processor->get_attribute( 'src' );
			
			// Only add if src exists and is not empty
			// null means attribute doesn't exist, empty string means attribute exists but is empty
			if ( null !== $src && '' !== $src ) {
				$sources[] = $src;
			}
		}
	}
	
	return $sources;
}
