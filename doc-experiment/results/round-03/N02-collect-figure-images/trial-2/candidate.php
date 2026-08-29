<?php

function collect_figure_images( string $html ): array {
	$images = array();
	
	// Create an HTML processor in fragment mode (body context)
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Handle creation failure
	if ( null === $processor ) {
		return $images;
	}
	
	// Iterate through all IMG tags in the document
	while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		// Get the breadcrumbs to check if FIGURE is an ancestor
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if FIGURE appears in the breadcrumbs (anywhere before the IMG)
		if ( in_array( 'FIGURE', $breadcrumbs, true ) ) {
			// Get the src attribute value
			$src = $processor->get_attribute( 'src' );
			
			// Only include if src exists and has a value
			if ( is_string( $src ) && '' !== $src ) {
				$images[] = $src;
			}
		}
	}
	
	return $images;
}
