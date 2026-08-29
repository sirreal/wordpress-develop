<?php

function mark_quoted_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	while ( $processor->next_tag( 'P' ) ) {
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if any ancestor in the breadcrumbs is a BLOCKQUOTE
		if ( in_array( 'BLOCKQUOTE', $breadcrumbs, true ) ) {
			$processor->add_class( 'quoted' );
		}
	}
	
	return $processor->get_updated_html();
}
