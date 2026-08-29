<?php

/**
 * Collect HTML image sources, excluding SVG image elements.
 *
 * Given an HTML fragment, returns a list of decoded src values of every HTML img element
 * in document order, excluding SVG image elements (which are in a different namespace).
 *
 * @param string $html HTML fragment to process.
 * @return array Numeric array of decoded src values from HTML img elements.
 */
function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	// Create an HTML processor for the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $sources;
	}
	
	// Iterate through all tags looking for img elements
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Skip if not in HTML namespace (e.g., in SVG namespace)
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );
		
		// Include the src only if it exists and is not empty or null
		if ( null !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
