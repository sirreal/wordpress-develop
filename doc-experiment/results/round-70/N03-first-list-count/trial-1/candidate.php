<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_tag = null;
	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( 'UL' === $tag || 'OL' === $tag ) {
			$list_tag = $tag;
			break;
		}
	}

	if ( null === $list_tag ) {
		return $html;
	}

	$list_depth = $processor->get_current_depth();
	if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
		return $html;
	}

	$item_count = 0;
	$scan_finished_cleanly = true;

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
