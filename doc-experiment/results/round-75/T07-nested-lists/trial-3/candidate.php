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
		$has_list_ancestor = false;

		foreach ( $ancestor_breadcrumbs as $ancestor ) {
			if ( 'UL' === $ancestor || 'OL' === $ancestor ) {
				$has_list_ancestor = true;
				break;
			}
		}

		if ( $has_list_ancestor ) {
			$processor->add_class( 'nested-list' );
		}
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
