<?php

function mark_nested_lists( string $html ): string {
	$processor = new WP_HTML_Processor( $html );

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();

		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$ancestor_count = count( $breadcrumbs ) - 1;
		$is_nested = false;

		for ( $i = 0; $i < $ancestor_count; $i++ ) {
			if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
				$is_nested = true;
				break;
			}
		}

		if ( $is_nested ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
