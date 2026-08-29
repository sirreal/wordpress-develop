<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;

	// Find all H2 tags and track the last one found.
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Set or update the bookmark to the current H2 tag.
		$processor->set_bookmark( 'last-h2' );
		$last_h2_bookmark = 'last-h2';
	}

	// If we found at least one H2, seek to it and add the class.
	if ( $last_h2_bookmark ) {
		$processor->seek( $last_h2_bookmark );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}

	return $processor->get_updated_html();
}
