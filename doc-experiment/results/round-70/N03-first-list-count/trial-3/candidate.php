<?php

function add_first_list_item_count( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_tags = array( 'UL', 'OL' );
	$list_tag  = null;

	while ( $processor->next_tag() ) {
		if ( in_array( $processor->get_tag(), $list_tags, true ) && ! $processor->is_tag_closer() ) {
			$list_tag = $processor->get_tag();
			break;
		}
	}

	if ( null === $list_tag ) {
		if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
			return $html;
		}

		return $html;
	}

	$list_depth = $processor->get_current_depth();
	if ( ! $processor->set_bookmark( 'first-list' ) ) {
		return $html;
	}

	$item_count = 0;

	while ( $processor->next_token() ) {
		if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
			if ( 'LI' === $processor->get_tag() && $processor->get_current_depth() === $list_depth + 1 ) {
				$item_count++;
			}
		}

		if ( $processor->get_token_type() === '#tag' && $processor->is_tag_closer() && $processor->get_tag() === $list_tag && $processor->get_current_depth() === $list_depth - 1 ) {
			break;
		}
	}

	if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
		return $html;
	}

	if ( ! $processor->seek( 'first-list' ) ) {
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );
	$processor->release_bookmark( 'first-list' );

	return $processor->get_updated_html();
}
