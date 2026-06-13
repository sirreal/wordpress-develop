<?php

function add_first_list_item_count( string $html ): string {
	try {
		$processor = \WP_HTML_Processor::create_fragment( $html );

		if ( null === $processor ) {
			return $html;
		}

		$found_list = false;

		while ( $processor->next_tag() ) {
			if ( 'UL' === $processor->get_tag() || 'OL' === $processor->get_tag() ) {
				$found_list = true;
				break;
			}
		}

		if ( ! $found_list ) {
			return $html;
		}

		if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
			return $html;
		}

		$list_depth     = $processor->get_current_depth();
		$item_count     = 0;
		$found_list_end = false;

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $list_depth ) {
				$found_list_end = true;
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

		if ( ! $found_list_end || $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			return $html;
		}

		if ( ! $processor->seek( 'first-list-opener' ) ) {
			return $html;
		}

		if ( ! $processor->set_attribute( 'data-item-count', (string) $item_count ) ) {
			return $html;
		}

		$processor->release_bookmark( 'first-list-opener' );

		return $processor->get_updated_html();
	} catch ( \Exception $e ) {
		return $html;
	}
}
