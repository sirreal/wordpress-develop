<?php
/**
 * Collect HTML image sources, not SVG ones
 *
 * @param string $html HTML fragment to process
 * @return array Numeric array of decoded src values for HTML img elements in document order
 */
function collect_html_img_sources( string $html ): array {
	$sources = array();
	
	// Create a fragment processor to properly parse HTML according to HTML5 rules
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Check if processor was created successfully
	if ( null === $processor ) {
		return $sources;
	}
	
	// Iterate through all img tags
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Only collect IMG elements in the HTML namespace, not SVG
		if ( 'html' !== $processor->get_namespace() ) {
			continue;
		}
		
		// Get the src attribute value (already decoded by get_attribute)
		$src = $processor->get_attribute( 'src' );
		
		// Only include if src exists and has a non-empty value
		if ( null !== $src && true !== $src && '' !== $src ) {
			$sources[] = $src;
		}
	}
	
	return $sources;
}
