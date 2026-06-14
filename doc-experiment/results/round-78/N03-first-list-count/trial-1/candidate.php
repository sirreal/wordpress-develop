<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$list_depth = null;

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		if ( ! $processor->is_tag_closer() ) {
			$list_depth = $processor->get_current_depth();
			if ( ! $processor->set_bookmark( 'first-list' ) ) {
				return $html;
			}
			break;
		}
	}

	if ( null === $list_depth ) {
		return $html;
	}

	$count = 0;

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $list_depth ) {
			break;
		}

		if (
			'#tag' === $processor->get_token_type() &&
			! $processor->is_tag_closer() &&
			$processor->get_current_depth() === $list_depth + 1 &&
			'LI' === $processor->get_tag()
		) {
			$count++;
		}
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( ! $processor->seek( 'first-list' ) ) {
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $count );
	$updated = $processor->get_updated_html();
	$processor->release_bookmark( 'first-list' );

	return $updated;
}
