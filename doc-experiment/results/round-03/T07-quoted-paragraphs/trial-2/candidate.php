<?php

function mark_quoted_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	while ( $processor->next_tag() ) {
		// Check if current tag is a P element
		if ( 'P' === $processor->get_tag() ) {
			// Get the breadcrumbs (ancestor path)
			$breadcrumbs = $processor->get_breadcrumbs();
			
			// Check if BLOCKQUOTE is anywhere in the breadcrumbs
			if ( in_array( 'BLOCKQUOTE', $breadcrumbs, true ) ) {
				// Add the "quoted" class to this P element
				$processor->add_class( 'quoted' );
			}
		}
	}
	
	return $processor->get_updated_html();
}
