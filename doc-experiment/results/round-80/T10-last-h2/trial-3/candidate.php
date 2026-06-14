<?php

function mark_last_h2( string $html ): string {
	$processor = WP_HTML_Processor::create_full_parser( $html );

	if ( null === $processor ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
	}

	if ( null === $processor ) {
		return $html;
	}

	$bookmark_name = 'last-h2';

	while ( $processor->next_tag( 'H2' ) ) {
		$processor->set_bookmark( $bookmark_name );
	}

	if ( ! $processor->has_bookmark( $bookmark_name ) ) {
		return $html;
	}

	if ( $processor->seek( $bookmark_name ) ) {
		$processor->add_class( 'final-section' );
	}

	$processor->release_bookmark( $bookmark_name );

	return $processor->get_updated_html();
}
