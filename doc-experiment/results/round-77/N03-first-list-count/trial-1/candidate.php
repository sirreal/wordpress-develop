<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_tags = array( 'UL', 'OL' );
	while ( $processor->next_tag() ) {
		if ( ! in_array( $processor->get_tag(), $list_tags, true ) ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
			return $html;
		}

		$list_depth = $processor->get_current_depth();
		$count      = 0;

		while ( $processor->next_token() && $processor->get_current_depth() >= $list_depth ) {
			if (
				'#tag' === $processor->get_token_type() &&
				! $processor->is_tag_closer() &&
				'LI' === $processor->get_tag() &&
				$processor->get_current_depth() === $list_depth + 1
			) {
				$count++;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			$processor->release_bookmark( 'first-list-opener' );
			return $html;
		}

		if ( ! $processor->seek( 'first-list-opener' ) ) {
			$processor->release_bookmark( 'first-list-opener' );
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $count );
		$updated_html = $processor->get_updated_html();
		$processor->release_bookmark( 'first-list-opener' );

		return $updated_html;
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	return $html;
}
