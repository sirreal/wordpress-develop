<?php

function collect_figure_images( string $html ): array {
	$images = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return $images;
	}
	
	// Loop through all IMG tags in the document
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Check if the current IMG is inside a FIGURE element
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Look for FIGURE in the breadcrumbs (excluding the IMG tag itself at the end)
		if ( in_array( 'FIGURE', $breadcrumbs, true ) ) {
			// Get the src attribute (returns decoded value per documentation)
			$src = $processor->get_attribute( 'src' );
			
			// Skip if src is null (attribute doesn't exist) or is true (boolean attribute, which shouldn't happen for src)
			// or is empty string
			if ( $src && '' !== $src && true !== $src ) {
				$images[] = $src;
			}
		}
	}
	
	return $images;
}
