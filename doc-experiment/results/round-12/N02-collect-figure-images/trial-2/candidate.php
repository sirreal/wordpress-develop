<?php

function collect_figure_images( string $html ): array {
	$images = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $images;
	}
	
	while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if FIGURE is anywhere in the breadcrumbs
		if ( ! in_array( 'FIGURE', $breadcrumbs, true ) ) {
			continue;
		}
		
		// Get the src attribute value
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is null or empty string
		if ( null === $src || '' === $src ) {
			continue;
		}
		
		$images[] = $src;
	}
	
	return $images;
}
