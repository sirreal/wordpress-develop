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

		if ( ! $processor->set_bookmark( 'first-list' ) ) {
			return $html;
		}

		$list_depth = $processor->get_current_depth();
		$count      = 0;

		while ( $processor->next_token() && $processor->get_current_depth() >= $list_depth ) {
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

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			$processor->release_bookmark( 'first-list' );
			return $html;
		}

		if ( ! $processor->seek( 'first-list' ) ) {
			$processor->release_bookmark( 'first-list' );
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $count );
		$updated_html = $processor->get_updated_html();
		$processor->release_bookmark( 'first-list' );

		return $updated_html;
	}

	return $html;
}
