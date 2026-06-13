<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag( array( 'tag_name' => null ) ) ) {
		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$ancestor_list_found = false;

		$limit = count( $breadcrumbs ) - 1;
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
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
