<?php
if ( ! function_exists( 'add_first_list_item_count' ) ) {
	function add_first_list_item_count( string $html ): string {
		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			return $html;
		}

		while ( $processor->next_tag() ) {
			if ( $processor->is_tag_closer() ) {
				continue;
			}

			$tag_name = $processor->get_tag();
			if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
				continue;
			}

			$container_depth = $processor->get_current_depth();
			if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
				return $html;
			}

			$item_count = 0;
			$scan_ok    = true;

			while ( $processor->next_token() ) {
				if ( $processor->get_current_depth() < $container_depth ) {
					break;
				}

				if (
					'#tag' === $processor->get_token_type() &&
					! $processor->is_tag_closer() &&
					$processor->get_current_depth() === $container_depth + 1 &&
					'LI' === $processor->get_tag()
				) {
					$item_count++;
				}
			}

			$scan_ok = ! $processor->paused_at_incomplete_token() && null === $processor->get_last_error();
			if ( ! $scan_ok ) {
				$processor->release_bookmark( 'first-list-opener' );
				return $html;
			}

			if ( ! $processor->seek( 'first-list-opener' ) ) {
				$processor->release_bookmark( 'first-list-opener' );
				return $html;
			}

			$processor->set_attribute( 'data-item-count', (string) $item_count );
			$updated_html = $processor->get_updated_html();
			$processor->release_bookmark( 'first-list-opener' );

			return $updated_html;
		}

		return $html;
	}
}
