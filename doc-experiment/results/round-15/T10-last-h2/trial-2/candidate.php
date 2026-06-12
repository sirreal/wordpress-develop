<?php

function mark_last_h2( string $html ): string {
	if ( empty( $html ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;

	// Scan through the document and track the last H2 tag found
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Set a bookmark on this H2 tag, moving the bookmark if it already exists
		if ( $processor->set_bookmark( 'last-h2' ) ) {
			$last_h2_bookmark = 'last-h2';
		}
	}

	// If we found an H2 tag, seek to it and add the class
	if ( $last_h2_bookmark !== null && $processor->seek( $last_h2_bookmark ) ) {
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}

	return $processor->get_updated_html();
}
