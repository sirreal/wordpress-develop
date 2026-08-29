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
	if ( ! $processor->next_tag() ) {
		return $html;
	}

	while ( ! in_array( $processor->get_tag(), $list_tags, true ) ) {
		if ( ! $processor->next_tag() ) {
			return $html;
		}
	}

	if ( $processor->is_tag_closer() ) {
		return $html;
	}

	$list_depth = $processor->get_current_depth();
	if ( ! $processor->set_bookmark( 'first-list' ) ) {
		return $html;
	}

	$item_count = 0;
	while ( $processor->next_token() && $processor->get_current_depth() >= $list_depth ) {
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
		return $html;
	}

	if ( ! $processor->seek( 'first-list' ) ) {
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );

	return $processor->get_updated_html();
}
