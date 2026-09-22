<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_depth      = null;
	$list_bookmark   = null;
	$item_count      = 0;
	$found_list      = false;
	$scan_finished   = false;
	$target_list_tag = null;

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();

		if ( ! $found_list ) {
			if ( 'UL' !== $tag && 'OL' !== $tag ) {
				continue;
			}

			$found_list      = true;
			$target_list_tag = $tag;
			$list_depth      = $processor->get_current_depth();
			$list_bookmark   = 'first-list-opener';

			if ( ! $processor->set_bookmark( $list_bookmark ) ) {
				return $html;
			}

			continue;
		}

		if ( $processor->get_current_depth() < $list_depth ) {
			$scan_finished = true;
			break;
		}

		if ( 'LI' === $tag && ! $processor->is_tag_closer() && $processor->get_current_depth() === $list_depth + 1 ) {
			$item_count++;
		}
	}

	if ( ! $found_list ) {
		return $html;
	}

	if ( ! $scan_finished ) {
		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			return $html;
		}

		if ( $processor->get_current_depth() >= $list_depth ) {
			return $html;
		}
	}

	if ( null === $list_bookmark || ! $processor->seek( $list_bookmark ) ) {
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );

	return $processor->get_updated_html();
}
