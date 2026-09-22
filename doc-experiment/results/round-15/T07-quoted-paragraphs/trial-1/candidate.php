<?php

function mark_quoted_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	// Find all P tags
	while ( $processor->next_tag( array( 'tag_name' => 'P' ) ) ) {
		// Get the breadcrumbs to check if BLOCKQUOTE is an ancestor
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if BLOCKQUOTE exists anywhere in the breadcrumbs
		if ( in_array( 'BLOCKQUOTE', $breadcrumbs, true ) ) {
			$processor->add_class( 'quoted' );
		}
	}
	
	return $processor->get_updated_html();
}
