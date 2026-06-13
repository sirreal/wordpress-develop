<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_tag_name = null;
	$list_depth    = null;
	$item_count    = 0;
	$found_list    = false;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( ! $found_list ) {
			if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
				continue;
			}

			$found_list    = true;
			$list_tag_name = $tag_name;
			$list_depth    = $processor->get_current_depth();
			$processor->set_bookmark( 'first-list-opener' );
			continue;
		}

		if ( $processor->get_current_depth() < $list_depth ) {
			break;
		}

		if ( 'LI' === $tag_name && $processor->get_current_depth() === $list_depth + 1 ) {
			++$item_count;
		}
	}

	if ( ! $found_list ) {
		return $html;
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		$processor->release_bookmark( 'first-list-opener' );
		return $html;
	}

	if ( ! $processor->seek( 'first-list-opener' ) ) {
		$processor->release_bookmark( 'first-list-opener' );
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );
	$processor->release_bookmark( 'first-list-opener' );

	return $processor->get_updated_html();
}
