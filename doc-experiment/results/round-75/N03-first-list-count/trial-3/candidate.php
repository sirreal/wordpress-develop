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
		$list_count  = 0;
		$scan_ok     = true;

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
				$list_count++;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			return $html;
		}

		if ( ! $processor->seek( 'nonexistent-bookmark' ) ) {
			// No-op: the processor is already positioned at the first list's closer or beyond.
		}

		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			return $html;
		}

		if ( ! $processor->next_tag( array( 'tag_name' => $tag ) ) || $processor->is_tag_closer() ) {
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $list_count );
		return $processor->get_updated_html();
	}

	return $html;
}
