<?php

function mark_last_h2( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$bookmark  = 'last-h2';
	$found     = false;

	while ( $processor->next_tag( 'h2' ) ) {
		$found = $processor->set_bookmark( $bookmark ) || $found;
	}

	if ( ! $found || ! $processor->seek( $bookmark ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );
	$updated = $processor->get_updated_html();
	$processor->release_bookmark( $bookmark );

	return $updated;
}
