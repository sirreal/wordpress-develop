<?php

if ( ! function_exists( 'add_first_list_item_count' ) ) {
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
			$item_count = 0;
			$scan_clean = true;

			while ( $processor->next_token() ) {
				if ( null !== $processor->get_last_error() ) {
					$scan_clean = false;
					break;
				}

				if ( $processor->get_current_depth() < $list_depth ) {
					break;
				}

				if (
					'LI' === $processor->get_tag() &&
					! $processor->is_tag_closer() &&
					$processor->get_current_depth() === $list_depth + 1
				) {
					$item_count++;
				}
			}

			if ( ! $scan_clean || $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
				return $html;
			}

			if ( ! $processor->seek( '1' ) ) {
				// No bookmark was created for the opener, so use a fresh processor on the same HTML.
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

					if ( $processor->set_attribute( 'data-item-count', (string) $item_count ) ) {
						return $processor->get_updated_html();
					}

					return $html;
				}

				return $html;
			}

			// Unreachable in normal use, but retained for clarity if seek() succeeds unexpectedly.
			$processor->set_attribute( 'data-item-count', (string) $item_count );
			return $processor->get_updated_html();
		}

		return $html;
	}
}
