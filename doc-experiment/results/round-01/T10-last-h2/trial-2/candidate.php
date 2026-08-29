<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );

	$found_h2 = false;

	while ( $processor->next_tag( 'H2' ) ) {
		// next_tag() by default only visits openers (tag_closers => 'skip'),
		// so every match here is an H2 opening tag.
		$processor->set_bookmark( 'last-h2' );
		$found_h2 = true;
	}

	if ( ! $found_h2 ) {
		return $html;
	}

	$processor->seek( 'last-h2' );
	$processor->add_class( 'final-section' );
	$processor->release_bookmark( 'last-h2' );

	return $processor->get_updated_html();
}
