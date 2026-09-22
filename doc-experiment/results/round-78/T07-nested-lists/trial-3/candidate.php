<?php

function mark_nested_lists( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
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

	if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
