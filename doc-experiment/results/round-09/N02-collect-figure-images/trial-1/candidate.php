<?php

function collect_figure_images( string $html ): array {
	$images = array();
	
	// Create an HTML processor for the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $images;
	}
	
	// Loop through all IMG tags
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Get the breadcrumbs to check if this IMG is inside a FIGURE
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if FIGURE is in the breadcrumbs (at any depth)
		if ( ! in_array( 'FIGURE', $breadcrumbs, true ) ) {
			continue;
		}
		
		// Get the src attribute
		$src = $processor->get_attribute( 'src' );
		
		// Only include if src is a non-empty string (not null, not true, not empty string)
		if ( is_string( $src ) && '' !== $src ) {
			$images[] = $src;
		}
	}
	
	return $images;
}
