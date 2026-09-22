<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
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
		if ( in_array( 'UL', array_slice( $breadcrumbs, 0, -1 ), true ) || in_array( 'OL', array_slice( $breadcrumbs, 0, -1 ), true ) ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
