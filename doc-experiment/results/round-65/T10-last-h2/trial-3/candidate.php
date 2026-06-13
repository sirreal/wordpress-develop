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

	$found_last_h2 = false;

	while ( $processor->next_tag( 'h2' ) ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		if ( $processor->set_bookmark( 'last-h2' ) ) {
			$found_last_h2 = true;
		}
	}

	if ( ! $found_last_h2 || ! $processor->seek( 'last-h2' ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );

	return $processor->get_updated_html();
}
