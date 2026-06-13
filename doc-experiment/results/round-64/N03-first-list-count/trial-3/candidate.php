<?php

function add_first_list_item_count( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

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

	if ( $processor->is_tag_closer() ) {
		return $html;
	}

	$list_depth = $processor->get_current_depth();
	$count      = 0;

	while ( $processor->next_token() && $processor->get_current_depth() >= $list_depth ) {
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

	if ( ! $processor->seek( 'first-list' ) ) {
		// Create a stable bookmark only after we know the scan finished cleanly.
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

		if ( $processor->is_tag_closer() ) {
			return $html;
		}

		$processor->set_bookmark( 'first-list' );
		if ( ! $processor->seek( 'first-list' ) ) {
			return $html;
		}
	}

	$processor->set_attribute( 'data-item-count', (string) $count );
	return $processor->get_updated_html();
}
