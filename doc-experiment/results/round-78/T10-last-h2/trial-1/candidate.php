<?php

function mark_last_h2( string $html ): string {
	$processor = null;

	if ( class_exists( 'WP_HTML_Processor' ) ) {
		$processor = WP_HTML_Processor::create_full_parser( $html );
		if ( null === $processor ) {
			$processor = WP_HTML_Processor::create_fragment( $html );
		}
	}

	if ( null === $processor ) {
		return $html;
	}

	$bookmark = 'last-h2';

	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		if ( ! $processor->is_tag_closer() ) {
			$processor->set_bookmark( $bookmark );
		}
	}

	if ( ! $processor->has_bookmark( $bookmark ) ) {
		return $html;
	}

	if ( ! $processor->seek( $bookmark ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );
	$updated = $processor->get_updated_html();
	$processor->release_bookmark( $bookmark );

	return $updated;
}
