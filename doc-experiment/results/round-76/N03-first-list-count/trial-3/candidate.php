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
		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
			return $html;
		}

		$list_depth = $processor->get_current_depth();
		$count      = 0;
		$scan_ok    = true;

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

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			$scan_ok = false;
		}

		if ( ! $scan_ok || ! $processor->seek( 'first-list-opener' ) ) {
			$processor->release_bookmark( 'first-list-opener' );
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $count );
		$processor->release_bookmark( 'first-list-opener' );

		return $processor->get_updated_html();
	}

	return $html;
}
