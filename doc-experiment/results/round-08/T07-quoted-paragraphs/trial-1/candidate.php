<?php

/**
 * Mark paragraphs inside blockquotes with a "quoted" class.
 *
 * @param string $html HTML fragment to process.
 * @return string The modified HTML with "quoted" class added to P elements inside BLOCKQUOTEs.
 */
function mark_quoted_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	while ( $processor->next_tag( array( 'tag_name' => 'p' ) ) ) {
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if BLOCKQUOTE appears anywhere in the breadcrumbs (ancestor check)
		if ( in_array( 'BLOCKQUOTE', $breadcrumbs, true ) ) {
			$processor->add_class( 'quoted' );
		}
	}
	
	return $processor->get_updated_html();
}
