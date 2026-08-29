<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;

	// Find all H2 tags and keep track of the last one
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Skip tag closers - we only care about opening tags
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		// Set or update the bookmark to the current H2
		$last_h2_bookmark = 'last_h2';
		$processor->set_bookmark( $last_h2_bookmark );
	}

	// If we found an H2 tag, seek to it and add the class
	if ( $last_h2_bookmark && $processor->has_bookmark( $last_h2_bookmark ) ) {
		$processor->seek( $last_h2_bookmark );
		$processor->add_class( 'final-section' );
	}

	return $processor->get_updated_html();
}
