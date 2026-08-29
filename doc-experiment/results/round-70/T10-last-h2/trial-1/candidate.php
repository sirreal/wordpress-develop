<?php

function mark_last_h2( string $html ): string {
	$processor = null;

	if ( class_exists( 'WP_HTML_Processor' ) ) {
		$processor = WP_HTML_Processor::create_full_parser( $html );

		if ( null === $processor ) {
			$processor = WP_HTML_Processor::create_fragment( $html );
		}
	}

	if ( ! $processor ) {
		return $html;
	}

	$bookmark = 'last-h2';

	while ( $processor->next_tag( 'h2' ) ) {
		$processor->set_bookmark( $bookmark );
	}

	if ( ! $processor->has_bookmark( $bookmark ) ) {
		return $html;
	}

	if ( $processor->seek( $bookmark ) ) {
		$processor->add_class( 'final-section' );
	}

	$processor->release_bookmark( $bookmark );

	return $processor->get_updated_html();
}
