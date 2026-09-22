<?php

function mark_last_h2( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_full_parser( $html );
	if ( null === $processor ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
	}

	if ( null === $processor ) {
		return $html;
	}

	$bookmark_name = 'last-h2';
	$found_h2      = false;

	while ( $processor->next_token() ) {
		if ( 'H2' !== $processor->get_tag() || $processor->is_tag_closer() ) {
			continue;
		}

		$processor->set_bookmark( $bookmark_name );
		$found_h2 = true;
	}

	if ( ! $found_h2 ) {
		return $html;
	}

	if ( $processor->seek( $bookmark_name ) ) {
		$processor->add_class( 'final-section' );
	}

	$processor->release_bookmark( $bookmark_name );

	return $processor->get_updated_html();
}
