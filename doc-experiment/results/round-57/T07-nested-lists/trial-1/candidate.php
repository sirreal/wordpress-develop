<?php

declare( strict_types=1 );

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
		if ( count( $breadcrumbs ) < 2 ) {
			continue;
		}

		foreach ( array_slice( $breadcrumbs, 0, -1 ) as $ancestor ) {
			if ( 'UL' === $ancestor || 'OL' === $ancestor ) {
				$processor->add_class( 'nested-list' );
				break;
			}
		}
	}

	return $processor->get_updated_html();
}
