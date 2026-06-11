<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;

	// Scan through all H2 tags and remember the last one.
	while ( $processor->next_tag( array( 'tag_name' => 'H2' ) ) ) {
		// Skip H2 tags inside comments
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		// Release the previous bookmark if one exists
		if ( $last_h2_bookmark !== null ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}

		// Create a bookmark for this H2 tag
		$last_h2_bookmark = 'last_h2_' . uniqid();
		$processor->set_bookmark( $last_h2_bookmark );
	}

	// If we found at least one H2 tag, seek back to it and add the class
	if ( $last_h2_bookmark !== null ) {
		$processor->seek( $last_h2_bookmark );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}

	return $processor->get_updated_html();
}
