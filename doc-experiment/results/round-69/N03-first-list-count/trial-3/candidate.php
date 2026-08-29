<?php

declare( strict_types = 1 );

function add_first_list_item_count( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		if ( ! $processor->set_bookmark( 'first-list' ) ) {
			return $html;
		}

		$list_depth = $processor->get_current_depth();
		$item_count  = 0;

		while ( $processor->next_token() && $processor->get_current_depth() >= $list_depth ) {
			if (
				'#tag' === $processor->get_token_type() &&
				! $processor->is_tag_closer() &&
				'LI' === $processor->get_tag() &&
				$processor->get_current_depth() === $list_depth + 1
			) {
				$item_count++;
			}
		}

		$scan_finished_cleanly =
			! $processor->paused_at_incomplete_token() &&
			null === $processor->get_last_error();

		if ( ! $scan_finished_cleanly ) {
			return $html;
		}

		if ( ! $processor->seek( 'first-list' ) ) {
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $item_count );
		return $processor->get_updated_html();
	}

	return $html;
}
