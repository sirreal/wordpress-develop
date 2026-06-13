<?php

function mark_nested_lists( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$ancestor_lists = 0;

		// Count UL/OL ancestors, excluding the current list element itself.
		$limit = count( $breadcrumbs ) - 1;
		for ( $i = 0; $i < $limit; $i++ ) {
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
