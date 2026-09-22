<?php
function mark_quoted_paragraphs( string $html ): string {
	// Create a processor for the HTML fragment.
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	// Loop through all P tags in the document.
	while ( $processor->next_tag( array( 'tag_name' => 'P' ) ) ) {
		// Get the breadcrumbs (ancestor chain) for the current P tag.
		$breadcrumbs = $processor->get_breadcrumbs();
		
		// Check if BLOCKQUOTE is anywhere in the ancestor chain.
		if ( in_array( 'BLOCKQUOTE', $breadcrumbs, true ) ) {
			// Add the "quoted" class to this P element.
			$processor->add_class( 'quoted' );
		}
	}
	
	// Serialize and return the modified HTML.
	$result = $processor->serialize();
	
	// If serialization failed, return the original HTML.
	if ( null === $result ) {
		return $html;
	}
	
	return $result;
}
