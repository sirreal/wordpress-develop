<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		array_pop( $breadcrumbs );

		if ( in_array( 'UL', $breadcrumbs, true ) || in_array( 'OL', $breadcrumbs, true ) ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
