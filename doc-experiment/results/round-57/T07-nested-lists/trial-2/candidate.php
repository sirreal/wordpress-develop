<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( ! $processor ) {
		return $html;
	}

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$ancestor_has_list = false;

		for ( $i = 0, $count = count( $breadcrumbs ) - 1; $i < $count; $i++ ) {
			if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
				$ancestor_has_list = true;
				break;
			}
		}

		if ( $ancestor_has_list ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
