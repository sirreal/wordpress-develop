<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag( array( 'tag_name' => null, 'tag_closers' => 'skip' ) ) ) {
		$tag_name = $processor->get_tag();

		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		if ( count( $breadcrumbs ) < 2 ) {
			continue;
		}

		$has_list_ancestor = false;
		for ( $i = 0, $n = count( $breadcrumbs ) - 1; $i < $n; $i++ ) {
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
