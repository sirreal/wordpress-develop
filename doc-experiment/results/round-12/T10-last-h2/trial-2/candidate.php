<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;

	// Scan through all tags to find all H2 tags
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Only process opening tags, not closing tags
		if ( ! $processor->is_tag_closer() ) {
			// Set a bookmark at this H2 tag (will overwrite previous one)
			$last_h2_bookmark = 'last-h2';
			$processor->set_bookmark( $last_h2_bookmark );
		}
	}

	// If we found an H2, seek to it and add the class
	if ( null !== $last_h2_bookmark && $processor->seek( $last_h2_bookmark ) ) {
		$processor->add_class( 'final-section' );
	}

	return $processor->get_updated_html();
}
