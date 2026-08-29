<?php

function mark_quoted_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return $html;
	}
	
	// Find all P tags
	while ( $processor->next_tag( array( 'tag_name' => 'P' ) ) ) {
		// Get the breadcrumbs for the current P element
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if BLOCKQUOTE is in the breadcrumbs (an ancestor)
		if ( in_array( 'BLOCKQUOTE', $breadcrumbs, true ) ) {
			// Add the "quoted" class
			$processor->add_class( 'quoted' );
		}
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
