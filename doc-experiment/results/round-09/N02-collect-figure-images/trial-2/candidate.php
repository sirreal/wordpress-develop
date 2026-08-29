<?php

function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$images = array();
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Get the breadcrumbs to check if FIGURE is an ancestor
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if FIGURE appears in the breadcrumb path (case-insensitive comparison)
		$has_figure_ancestor = false;
		foreach ( $breadcrumbs as $tag ) {
			if ( 'FIGURE' === strtoupper( $tag ) ) {
				$has_figure_ancestor = true;
				break;
			}
		}
		
		if ( ! $has_figure_ancestor ) {
			continue;
		}
		
		// Get the src attribute
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is null (not present) or not a string (boolean)
		if ( null === $src || true === $src ) {
			continue;
		}
		
		// Skip if src is empty string
		if ( '' === $src ) {
			continue;
		}
		
		// Add the decoded src value to results
		$images[] = $src;
	}
	
	return $images;
}
