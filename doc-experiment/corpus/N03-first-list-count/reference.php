<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		if ( in_array( $processor->get_tag(), array( 'UL', 'OL' ), true ) ) {
			break;
		}
	}

	if ( ! in_array( $processor->get_tag(), array( 'UL', 'OL' ), true ) ) {
		return $html;
	}

	if ( ! $processor->set_bookmark( 'list' ) ) {
		return $html;
	}

	$list_depth = $processor->get_current_depth();
	$count      = 0;

	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		if ( $processor->get_current_depth() < $list_depth ) {
			break;
		}

		if (
			! $processor->is_tag_closer() &&
			'LI' === $processor->get_tag() &&
			$processor->get_current_depth() === $list_depth + 1
		) {
			++$count;
		}
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( ! $processor->seek( 'list' ) ) {
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $count );
	return $processor->get_updated_html();
}
