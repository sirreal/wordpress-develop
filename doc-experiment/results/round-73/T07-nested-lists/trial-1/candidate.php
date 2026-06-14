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

		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$has_list_ancestor = false;

		$last_index = count( $breadcrumbs ) - 1;
		for ( $i = 0; $i < $last_index; $i++ ) {
			if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
				$has_list_ancestor = true;
				break;
			}
		}

		if ( $has_list_ancestor ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
