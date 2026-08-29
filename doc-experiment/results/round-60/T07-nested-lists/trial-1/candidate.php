<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag( array( 'tag_name' => null ) ) ) {
		$tag = $processor->get_tag();

		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		if ( count( $breadcrumbs ) < 3 ) {
			continue;
		}

		$ancestors = array_slice( $breadcrumbs, 0, -1 );
		if ( in_array( 'UL', $ancestors, true ) || in_array( 'OL', $ancestors, true ) ) {
			$processor->add_class( 'nested-list' );
		}
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
