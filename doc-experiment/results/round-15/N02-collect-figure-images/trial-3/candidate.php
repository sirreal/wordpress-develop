<?php

function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$images = array();
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Check if the IMG is inside a FIGURE element
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// The breadcrumbs will be like ['HTML', 'BODY', 'FIGURE', 'IMG'] or similar
		// We need to check if 'FIGURE' appears before 'IMG' in the breadcrumbs
		if ( ! in_array( 'FIGURE', $breadcrumbs, true ) ) {
			continue;
		}
		
		// Get the src attribute
		$src = $processor->get_attribute( 'src' );
		
		// Only add if src is not null and not empty string
		if ( null !== $src && '' !== $src ) {
			$images[] = $src;
		}
	}
	
	return $images;
}
