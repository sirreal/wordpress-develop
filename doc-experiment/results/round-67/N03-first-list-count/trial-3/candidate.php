<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	if ( ! $processor->next_tag( array( 'tag_name' => 'UL' ) ) && ! $processor->next_tag( array( 'tag_name' => 'OL' ) ) ) {
		return $html;
	}

	$container_depth = $processor->get_current_depth();
	if ( ! $processor->set_bookmark( 'first-list' ) ) {
		return $html;
	}

	$item_count = 0;
	$scan_finished_cleanly = true;

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $container_depth ) {
			break;
		}

		if (
			'#tag' === $processor->get_token_type() &&
			! $processor->is_tag_closer() &&
			'LI' === $processor->get_tag() &&
			$processor->get_current_depth() === $container_depth + 1
		) {
			$item_count++;
		}
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		$scan_finished_cleanly = false;
	}

	if ( ! $scan_finished_cleanly || ! $processor->seek( 'first-list' ) ) {
		$processor->release_bookmark( 'first-list' );
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );
	$processor->release_bookmark( 'first-list' );

	return $processor->get_updated_html();
}
