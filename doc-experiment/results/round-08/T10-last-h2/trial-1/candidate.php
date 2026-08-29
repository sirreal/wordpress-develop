<?php
function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;

	while ( $processor->next_tag( array( 'tag_name' => 'H2' ) ) ) {
		// Set a bookmark at this H2 tag (will be overwritten on the next H2)
		if ( ! $processor->is_tag_closer() ) {
			if ( $last_h2_bookmark !== null ) {
				$processor->release_bookmark( $last_h2_bookmark );
			}
			$last_h2_bookmark = 'last-h2';
			$processor->set_bookmark( $last_h2_bookmark );
		}
	}

	// If we found at least one H2 tag, seek to the last one and add the class
	if ( $last_h2_bookmark !== null && $processor->seek( $last_h2_bookmark ) ) {
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}

	return $processor->get_updated_html();
}
