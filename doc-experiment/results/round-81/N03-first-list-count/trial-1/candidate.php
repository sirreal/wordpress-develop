<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_found = false;
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
		$scan_ok    = true;

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
				$count++;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			$scan_ok = false;
		}

		if ( ! $scan_ok || ! $processor->seek( 'first-list' ) ) {
			$processor->release_bookmark( 'first-list' );
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $count );
		$processor->release_bookmark( 'first-list' );
		$list_found = true;
		break;
	}

	if ( ! $list_found ) {
		return $html;
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
