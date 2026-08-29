<?php
function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$ancestor_lists = 0;

		foreach ( $breadcrumbs as $index => $breadcrumb ) {
			if ( $index === count( $breadcrumbs ) - 1 ) {
				break;
			}

			if ( 'UL' === $breadcrumb || 'OL' === $breadcrumb ) {
				$ancestor_lists++;
			}
		}

		if ( $ancestor_lists > 0 ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
