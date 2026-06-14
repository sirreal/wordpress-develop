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

	$found_h2 = false;

	while ( $processor->next_tag( 'h2' ) ) {
		$processor->set_bookmark( 'last-h2' );
		$found_h2 = true;
	}

	if ( ! $found_h2 ) {
		return $processor->get_updated_html();
	}

	if ( $processor->seek( 'last-h2' ) ) {
		$processor->add_class( 'final-section' );
	}

	$processor->release_bookmark( 'last-h2' );

	return $processor->get_updated_html();
}
