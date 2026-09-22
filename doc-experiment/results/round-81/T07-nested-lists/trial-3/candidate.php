<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag( array( 'tag_name' => null ) ) ) {
		$tag_name = $processor->get_tag();

		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		if ( count( $breadcrumbs ) < 2 ) {
			continue;
		}

		$ancestor_breadcrumbs = array_slice( $breadcrumbs, 0, -1 );
		if ( in_array( 'UL', $ancestor_breadcrumbs, true ) || in_array( 'OL', $ancestor_breadcrumbs, true ) ) {
			$processor->add_class( 'nested-list' );
		}
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
