<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
			return $html;
		}

		$list_depth  = $processor->get_current_depth();
		$item_count  = 0;
		$scan_failed = false;

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $list_depth ) {
				break;
			}

			if (
				'#tag' === $processor->get_token_type() &&
				! $processor->is_tag_closer() &&
				'LI' === $processor->get_tag() &&
				$processor->get_current_depth() === $list_depth + 1
			) {
				$item_count++;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			$scan_failed = true;
		}

		if ( $scan_failed ) {
			$processor->release_bookmark( 'first-list-opener' );
			return $html;
		}

		if ( ! $processor->seek( 'first-list-opener' ) ) {
			$processor->release_bookmark( 'first-list-opener' );
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $item_count );
		$updated_html = $processor->get_updated_html();
		$processor->release_bookmark( 'first-list-opener' );

		return $updated_html;
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	return $html;
}
