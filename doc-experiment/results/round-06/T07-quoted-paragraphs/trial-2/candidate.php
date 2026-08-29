<?php

function mark_quoted_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		// If processor creation fails, return original HTML
		return $html;
	}
	
	// Find all P tags and check if they have a BLOCKQUOTE ancestor
	while ( $processor->next_tag( array( 'tag_name' => 'P' ) ) ) {
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if BLOCKQUOTE appears in the breadcrumbs (ancestors)
		if ( in_array( 'BLOCKQUOTE', $breadcrumbs, true ) ) {
			$processor->add_class( 'quoted' );
		}
	}
	
	return $processor->get_updated_html();
}
