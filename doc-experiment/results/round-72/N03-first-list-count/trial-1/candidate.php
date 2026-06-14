<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$list_depth = $processor->get_current_depth();
		if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
			return $html;
		}

		$item_count = 0;
		while ( $processor->next_token() && $processor->get_current_depth() >= $list_depth ) {
			if (
				'#tag' === $processor->get_token_type() &&
				'LI' === $processor->get_tag() &&
				! $processor->is_tag_closer() &&
				$processor->get_current_depth() === $list_depth + 1
			) {
				$item_count++;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			return $html;
		}

		if ( ! $processor->seek( 'first-list-opener' ) ) {
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $item_count );
		$processor->release_bookmark( 'first-list-opener' );

		return $processor->get_updated_html();
	}

	return $html;
}
