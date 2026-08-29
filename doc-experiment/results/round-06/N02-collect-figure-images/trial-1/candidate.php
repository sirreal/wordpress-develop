<?php

function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	$images = array();

	// Find all IMG tags that are inside FIGURE elements at any depth
	while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		// Check if this IMG is inside a FIGURE element by examining breadcrumbs
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Look for 'FIGURE' anywhere in the breadcrumbs before the IMG
		if ( in_array( 'FIGURE', $breadcrumbs, true ) ) {
			// Get the src attribute
			$src = $processor->get_attribute( 'src' );
			
			// Only include if src exists and is not empty
			if ( is_string( $src ) && '' !== $src ) {
				$images[] = $src;
			}
		}
	}

	return $images;
}
