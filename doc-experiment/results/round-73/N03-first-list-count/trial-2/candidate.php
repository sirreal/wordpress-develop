<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$found_list = false;
	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$found_list = true;
		break;
	}

	if ( ! $found_list ) {
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

		if ( 'LI' === $processor->get_token_name() && ! $processor->is_tag_closer() && $processor->get_current_depth() === $list_depth + 1 ) {
			$count++;
		}
	}

	$scan_finished_cleanly = ! $processor->paused_at_incomplete_token() && null === $processor->get_last_error();
	if ( ! $scan_finished_cleanly || ! $processor->seek( 'first-list-opener' ) ) {
		$processor->release_bookmark( 'first-list-opener' );
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $count );
	$processor->release_bookmark( 'first-list-opener' );

	return $processor->get_updated_html();
}
