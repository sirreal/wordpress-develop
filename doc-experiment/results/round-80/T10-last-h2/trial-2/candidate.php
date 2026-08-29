<?php

function mark_last_h2( string $html ): string {
	$processor = WP_HTML_Processor::create_full_parser( $html );
	if ( null === $processor ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			return $html;
		}
	}

	$found = false;

	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$processor->set_bookmark( 'last-h2' );
		$found = true;
	}

	if ( ! $found || ! $processor->seek( 'last-h2' ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );
	return $processor->get_updated_html();
}
