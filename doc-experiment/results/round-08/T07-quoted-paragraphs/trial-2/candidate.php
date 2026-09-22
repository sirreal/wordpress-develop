<?php

function mark_quoted_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		// If processor creation fails, return original HTML unchanged
		return $html;
	}
	
	// Find all P tags
	while ( $processor->next_tag( 'P' ) ) {
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if BLOCKQUOTE is in the breadcrumbs (ancestor anywhere above)
		if ( in_array( 'BLOCKQUOTE', $breadcrumbs, true ) ) {
			$processor->add_class( 'quoted' );
		}
	}
	
	return $processor->get_updated_html();
}
