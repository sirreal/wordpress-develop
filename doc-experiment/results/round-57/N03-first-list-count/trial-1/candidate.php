<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$found_list   = false;
	$list_depth   = null;
	$item_count   = 0;
	$bookmark_set = false;

	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();

		if ( ! $found_list ) {
			if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
				continue;
			}

			$found_list = true;
			$list_depth = $processor->get_current_depth();

			if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
				return $html;
			}

			$bookmark_set = true;
			continue;
		}

		if ( $processor->get_current_depth() < $list_depth ) {
			break;
		}

		if (
			'LI' === $tag_name &&
			! $processor->is_tag_closer() &&
			$processor->get_current_depth() === $list_depth + 1
		) {
			++$item_count;
		}
	}

	if ( ! $found_list ) {
		return $html;
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( ! $bookmark_set || ! $processor->seek( 'first-list-opener' ) ) {
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );

	return $processor->get_updated_html();
}
