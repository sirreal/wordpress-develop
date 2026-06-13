<?php
function mark_nested_lists( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		if ( null === $breadcrumbs || count( $breadcrumbs ) < 2 ) {
			continue;
		}

		$ancestor_names = array_slice( $breadcrumbs, 0, -1 );
		if ( in_array( 'UL', $ancestor_names, true ) || in_array( 'OL', $ancestor_names, true ) ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
