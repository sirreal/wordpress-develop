<?php

function mark_last_h2( string $html ): string {
	$tags = new WP_HTML_Tag_Processor( $html );
	$last_h2_found = false;

	while ( $tags->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		$tags->set_bookmark( 'last-h2' );
		$last_h2_found = true;
	}

	if ( ! $last_h2_found ) {
		return $html;
	}

	if ( $tags->seek( 'last-h2' ) ) {
		$tags->add_class( 'final-section' );
	}

	$tags->release_bookmark( 'last-h2' );

	return $tags->get_updated_html();
}
