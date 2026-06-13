<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2    = false;

	while ( $processor->next_tag( 'h2' ) ) {
		$processor->set_bookmark( 'last-h2' );
		$last_h2 = true;
	}

	if ( ! $last_h2 ) {
		return $html;
	}

	if ( $processor->seek( 'last-h2' ) ) {
		$processor->add_class( 'final-section' );
	}

	return $processor->get_updated_html();
}
