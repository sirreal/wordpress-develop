<?php

function mark_last_h2( string $html ): string {
	$tags = new WP_HTML_Tag_Processor( $html );
	$found = false;

	while ( $tags->next_tag( 'h2' ) ) {
		$tags->set_bookmark( 'last-h2' );
		$found = true;
	}

	if ( ! $found ) {
		return $html;
	}

	if ( $tags->seek( 'last-h2' ) ) {
		$tags->add_class( 'final-section' );
		$tags->release_bookmark( 'last-h2' );
		return $tags->get_updated_html();
	}

	return $html;
}
