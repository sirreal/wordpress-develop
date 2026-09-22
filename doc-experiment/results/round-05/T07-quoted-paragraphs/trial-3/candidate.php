<?php

function mark_quoted_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		// If parsing failed, return the original HTML unchanged.
		return $html;
	}
	
	// Find all P tags and check if they have a BLOCKQUOTE ancestor.
	while ( $processor->next_tag( array( 'tag_name' => 'P' ) ) ) {
		// Get the breadcrumbs (stack of open elements from root to current element).
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if BLOCKQUOTE is in the breadcrumbs.
		if ( in_array( 'BLOCKQUOTE', $breadcrumbs, true ) ) {
			// Add the "quoted" class to this P element.
			$processor->add_class( 'quoted' );
		}
	}
	
	// Return the modified HTML.
	return $processor->get_updated_html();
}
