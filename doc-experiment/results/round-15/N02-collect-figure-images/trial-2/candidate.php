<?php
function collect_figure_images( string $html ): array {
	$result = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $result;
	}
	
	// Find all IMG tags that are inside a FIGURE element
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if FIGURE is in the breadcrumbs (at any depth)
		if ( in_array( 'FIGURE', $breadcrumbs, true ) ) {
			$src = $processor->get_attribute( 'src' );
			
			// Only include if src attribute exists and has a value
			if ( null !== $src && '' !== $src ) {
				$result[] = $src;
			}
		}
	}
	
	return $result;
}
