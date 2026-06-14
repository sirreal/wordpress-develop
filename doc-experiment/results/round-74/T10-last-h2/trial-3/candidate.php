<?php

function mark_last_h2( string $html ): string {
	$processor = null;

	if ( class_exists( 'WP_HTML_Processor' ) && method_exists( 'WP_HTML_Processor', 'create_full_parser' ) ) {
		$processor = WP_HTML_Processor::create_full_parser( $html );
	}

	if ( ! $processor && class_exists( 'WP_HTML_Processor' ) && method_exists( 'WP_HTML_Processor', 'create_fragment' ) ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
	}

	if ( ! $processor ) {
		return $html;
	}

	$bookmark_name = 'last-h2';
	$found         = false;

	while ( $processor->next_tag( 'H2' ) ) {
		$processor->set_bookmark( $bookmark_name );
		$found = true;
	}

	if ( ! $found ) {
		return $html;
	}

	if ( $processor->seek( $bookmark_name ) ) {
		$processor->add_class( 'final-section' );
	}

	$processor->release_bookmark( $bookmark_name );

	return $processor->get_updated_html();
}
