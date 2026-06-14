<?php

declare(strict_types=1);

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
		$list_ancestor_count = 0;

		foreach ( $breadcrumbs as $breadcrumb ) {
			if ( 'UL' === $breadcrumb || 'OL' === $breadcrumb ) {
				$list_ancestor_count++;
			}
		}

		if ( $list_ancestor_count > 1 ) {
			$processor->add_class( 'nested-list' );
		}
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
