<?php

function mark_last_h2( string $html ): string {
	$processor   = new WP_HTML_Tag_Processor( $html );
	$found_last   = false;

	while ( $processor->next_tag( 'H2' ) ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		$processor->set_bookmark( 'last-h2' );
		$found_last = true;
	}

	if ( ! $found_last ) {
		return $html;
	}

	$processor->seek( 'last-h2' );
	$processor->add_class( 'final-section' );
	$processor->release_bookmark( 'last-h2' );

	return $processor->get_updated_html();
}
