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

		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$ancestor_list_found = false;

		for ( $i = 0, $n = count( $breadcrumbs ) - 1; $i < $n; $i++ ) {
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
