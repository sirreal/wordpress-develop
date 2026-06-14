<?php

function mark_nested_lists( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$list_ancestors = 0;

		foreach ( $breadcrumbs as $breadcrumb ) {
			if ( 'UL' === $breadcrumb || 'OL' === $breadcrumb ) {
				$list_ancestors++;
			}
		}

		if ( $list_ancestors > 1 ) {
			$processor->add_class( 'nested-list' );
		}
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
