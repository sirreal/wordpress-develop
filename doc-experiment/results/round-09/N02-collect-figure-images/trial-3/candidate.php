<?php
/**
 * Collects the src values of IMG elements inside FIGURE elements.
 *
 * @param string $html The HTML fragment to process.
 * @return array Numeric array of decoded src values for IMG elements inside FIGUREs.
 */
function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$images = array();
	
	// Walk through all IMG tags in the document
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Check if this IMG is inside a FIGURE by examining breadcrumbs
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if FIGURE appears in the breadcrumbs (meaning IMG is nested within it)
		if ( in_array( 'FIGURE', $breadcrumbs, true ) ) {
			// Get the src attribute
			$src = $processor->get_attribute( 'src' );
			
			// Only include if src exists and is not empty
			// get_attribute returns null if not present, true for boolean attrs, or string value
			if ( null !== $src && '' !== $src && true !== $src ) {
				$images[] = $src;
			}
		}
	}
	
	return $images;
}
