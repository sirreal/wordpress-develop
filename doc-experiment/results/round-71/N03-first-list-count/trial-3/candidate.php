<?php

function add_first_list_item_count( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$list_depth = $processor->get_current_depth();
		$li_count   = 0;

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
				$li_count++;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			return $html;
		}

		$processor->seek( 'section-start-not-used' );
		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			return $html;
		}

		if ( ! $processor->next_tag( array( 'tag_name' => $tag_name ) ) ) {
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $li_count );
		return $processor->get_updated_html();
	}

	return $html;
}
