<?php

function add_first_list_item_count( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$found_list = false;
	while ( $processor->next_tag() ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$list_depth = $processor->get_current_depth();
		if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
			return $html;
		}

		$found_list = true;
		$item_count = 0;
		$scan_ok    = true;

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $list_depth ) {
				break;
			}

			if ( '#tag' !== $processor->get_token_type() ) {
				continue;
			}

			if ( $processor->is_tag_closer() ) {
				continue;
			}

			if ( 'LI' === $processor->get_tag() && $processor->get_current_depth() === $list_depth + 1 ) {
				$item_count++;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			$scan_ok = false;
		}

		if ( ! $scan_ok || ! $processor->seek( 'first-list-opener' ) ) {
			$processor->release_bookmark( 'first-list-opener' );
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $item_count );
		$processor->release_bookmark( 'first-list-opener' );
		return $processor->get_updated_html();
	}

	return $found_list ? $html : $html;
}
