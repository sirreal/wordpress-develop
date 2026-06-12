<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;

	// Scan through the document to find all H2 tags
	while ( $processor->next_tag( 'h2' ) ) {
		// Skip if this is a tag closer, we only want openers
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		// If we already have a bookmark from a previous H2, release it
		if ( $last_h2_bookmark !== null ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}

		// Create a bookmark for this H2
		$last_h2_bookmark = 'last-h2';
		$processor->set_bookmark( $last_h2_bookmark );
	}

	// If we found at least one H2 tag, seek to it and add the class
	if ( $last_h2_bookmark !== null && $processor->seek( $last_h2_bookmark ) ) {
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}

	return $processor->get_updated_html();
}
