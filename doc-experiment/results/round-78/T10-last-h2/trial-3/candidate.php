<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2   = null;

	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		$processor->set_bookmark( 'last-h2' );
		$last_h2 = true;
	}

	if ( ! $last_h2 || ! $processor->seek( 'last-h2' ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );
	$processor->release_bookmark( 'last-h2' );

	return $processor->get_updated_html();
}
