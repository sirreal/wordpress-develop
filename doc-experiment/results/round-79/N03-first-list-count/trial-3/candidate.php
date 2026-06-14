<?php

function add_first_list_item_count( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_depth = null;
	$found_list = false;
	$scanned_cleanly = false;
	$item_count = 0;

	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();

		if ( ! $found_list ) {
			if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
				continue;
			}

			if ( $processor->is_tag_closer() ) {
				continue;
			}

			$found_list = true;
			$list_depth = $processor->get_current_depth();
			$processor->set_bookmark( 'first-list' );
			continue;
		}

		if ( $processor->get_current_depth() < $list_depth ) {
			$scanned_cleanly = true;
			break;
		}

		if (
			'LI' === $tag_name &&
			! $processor->is_tag_closer() &&
			$processor->get_current_depth() === $list_depth + 1
		) {
			$item_count++;
		}
	}

	if ( ! $found_list ) {
		return $html;
	}

	if ( ! $scanned_cleanly ) {
		$scanned_cleanly = ! $processor->paused_at_incomplete_token() && null === $processor->get_last_error();
	}

	if ( ! $scanned_cleanly || ! $processor->seek( 'first-list' ) ) {
		$processor->release_bookmark( 'first-list' );
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );
	$processor->release_bookmark( 'first-list' );

	return $processor->get_updated_html();
}
