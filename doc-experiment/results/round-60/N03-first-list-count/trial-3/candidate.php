<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_tag = null;
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( 'UL' === $tag || 'OL' === $tag ) {
			$list_tag = $tag;
			break;
		}
	}

	if ( null === $list_tag ) {
		return $html;
	}

	if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
		return $html;
	}

	$list_depth = $processor->get_current_depth();
	$count      = 0;

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $list_depth ) {
			break;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		if ( 'LI' === $processor->get_tag() && $processor->get_current_depth() === $list_depth + 1 ) {
			$count++;
		}
	}

	$scan_finished_cleanly = ! $processor->paused_at_incomplete_token() && null === $processor->get_last_error();
	if ( ! $scan_finished_cleanly ) {
		return $html;
	}

	if ( ! $processor->seek( 'first-list-opener' ) ) {
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $count );
	$processor->release_bookmark( 'first-list-opener' );

	return $processor->get_updated_html();
}
