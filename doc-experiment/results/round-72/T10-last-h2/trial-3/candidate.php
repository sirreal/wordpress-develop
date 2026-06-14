<?php

function mark_last_h2( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$found     = false;

	while ( $processor->next_tag( 'h2' ) ) {
		$processor->set_bookmark( 'last-h2' );
		$found = true;
	}

	if ( ! $found ) {
		return $html;
	}

	if ( $processor->seek( 'last-h2' ) ) {
		$processor->add_class( 'final-section' );
	}

	$processor->release_bookmark( 'last-h2' );

	return $processor->get_updated_html();
}
