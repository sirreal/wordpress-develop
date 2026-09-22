<?php

function collect_figure_images( string $html ): array {
	$result = array();
	
	// Create a fragment processor for the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return $result;
	}
	
	// Find all IMG tags that are inside a FIGURE element
	while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		// Check if the IMG is inside a FIGURE by checking breadcrumbs
		if ( $processor->matches_breadcrumbs( array( 'FIGURE', 'IMG' ) ) 
		     || $processor->matches_breadcrumbs( array( 'FIGURE', '*', 'IMG' ) ) ) {
			
			// Get the src attribute value
			$src = $processor->get_attribute( 'src' );
			
			// Only add non-empty src values
			if ( $src && is_string( $src ) && '' !== $src ) {
				$result[] = $src;
			}
		}
	}
	
	return $result;
}
