<?php

function mark_nested_lists( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( ! $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();

		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$is_nested   = false;

		for ( $i = 0, $limit = count( $breadcrumbs ) - 1; $i < $limit; $i++ ) {
			if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
				$is_nested = true;
				break;
			}
		}

		if ( $is_nested ) {
			$processor->add_class( 'nested-list' );
		}
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
