<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2   = 'last-h2';

	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		$processor->set_bookmark( $last_h2 );
	}

	if ( ! $processor->has_bookmark( $last_h2 ) ) {
		return $html;
	}

	if ( $processor->seek( $last_h2 ) ) {
		$processor->add_class( 'final-section' );
	}

	$processor->release_bookmark( $last_h2 );

	return $processor->get_updated_html();
}
