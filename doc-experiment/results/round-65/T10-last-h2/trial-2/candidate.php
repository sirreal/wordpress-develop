<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$found     = false;

	while ( $processor->next_tag( 'h2' ) ) {
		$processor->set_bookmark( 'last-h2' );
		$found = true;
	}

	if ( ! $found || ! $processor->seek( 'last-h2' ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );

	return $processor->get_updated_html();
}
