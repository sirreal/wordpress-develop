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
		$ancestor_list_found = false;

		foreach ( $breadcrumbs as $index => $breadcrumb ) {
			if ( $index === count( $breadcrumbs ) - 1 ) {
				break;
			}

			if ( 'UL' === $breadcrumb || 'OL' === $breadcrumb ) {
				$ancestor_list_found = true;
				break;
			}
		}

		if ( $ancestor_list_found ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
