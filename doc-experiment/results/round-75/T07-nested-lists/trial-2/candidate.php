<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag( array( 'tag_closers' => 'skip' ) ) ) {
		$tag = $processor->get_tag();

		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$ancestor_lists = 0;

		// Exclude the current list element and detect any list ancestor above it.
		for ( $i = 0, $n = count( $breadcrumbs ) - 1; $i < $n; $i++ ) {
			if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
				$ancestor_lists++;
				break;
			}
		}

		if ( $ancestor_lists > 0 ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
