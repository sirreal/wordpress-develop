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

		$list_depth = $processor->get_current_depth();
		$count      = 0;

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
				$count++;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			return $html;
		}

		if ( ! $processor->seek( 'does-not-exist' ) ) {
			// No-op: keep the processor in its current state.
		}

		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			return $html;
		}

		if ( ! $processor->next_tag( array( 'tag_name' => $tag, 'match_offset' => 1 ) ) ) {
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $count );
		return $processor->get_updated_html();
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	return $html;
}
