<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();

		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$ancestor_breadcrumbs = array_slice( $breadcrumbs, 0, -1 );

		if ( in_array( 'UL', $ancestor_breadcrumbs, true ) || in_array( 'OL', $ancestor_breadcrumbs, true ) ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
