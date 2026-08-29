<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	if ( ! $processor->next_tag() ) {
		return $html;
	}

	while ( ! in_array( $processor->get_tag(), array( 'UL', 'OL' ), true ) ) {
		if ( ! $processor->next_tag() ) {
			return $html;
		}
	}

	if ( ! $processor->set_bookmark( 'first-list' ) ) {
		return $html;
	}

	$list_depth = $processor->get_current_depth();
	$item_count = 0;

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $list_depth ) {
			break;
		}

		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		if ( $processor->get_current_depth() === $list_depth + 1 && 'LI' === $processor->get_tag() ) {
			$item_count++;
		}
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( ! $processor->seek( 'first-list' ) ) {
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );
	$processor->release_bookmark( 'first-list' );

	return $processor->get_updated_html();
}
