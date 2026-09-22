<?php
function collect_figure_images( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$results = array();
	
	// Find all IMG tags and check if they are inside a FIGURE
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		// Get the breadcrumbs to check if FIGURE is an ancestor
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if FIGURE is in the breadcrumbs
		if ( ! in_array( 'FIGURE', $breadcrumbs, true ) ) {
			continue;
		}
		
		// Get the src attribute
		$src = $processor->get_attribute( 'src' );
		
		// Skip if src is null (not present) or boolean (empty attribute)
		if ( null === $src || true === $src ) {
			continue;
		}
		
		// Skip if src is an empty string
		if ( '' === $src ) {
			continue;
		}
		
		// Add the decoded src value to results
		$results[] = $src;
	}
	
	return $results;
}
