<?php

function mark_nested_lists( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return $html;
	}

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$has_list_ancestor = false;

		foreach ( array_slice( $breadcrumbs, 0, -1 ) as $breadcrumb ) {
			if ( 'UL' === $breadcrumb || 'OL' === $breadcrumb ) {
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
