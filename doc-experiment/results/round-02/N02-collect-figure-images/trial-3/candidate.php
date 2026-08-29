<?php

function collect_figure_images( string $html ): array {
	$images = array();
	
	// Create an HTML processor for the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation failed, return empty array
	if ( null === $processor ) {
		return $images;
	}
	
	// Iterate through all IMG tags
	while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		// Get the breadcrumbs for the current position
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if FIGURE is in the breadcrumbs before this IMG
		if ( in_array( 'FIGURE', $breadcrumbs, true ) ) {
			// Get the src attribute value
			$src = $processor->get_attribute( 'src' );
			
			// Only add if src exists and is not empty/boolean
			if ( is_string( $src ) && '' !== $src ) {
				$images[] = $src;
			}
		}
	}
	
	return $images;
}
