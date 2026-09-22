<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$ancestors = array_slice( $processor->get_breadcrumbs(), 0, -1 );
		if (
			in_array( 'UL', $ancestors, true ) ||
			in_array( 'OL', $ancestors, true )
		) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
