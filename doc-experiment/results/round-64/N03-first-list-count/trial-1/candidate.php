<?php

declare( strict_types=1 );

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();
		if ( $processor->is_tag_closer() || ( 'UL' !== $tag_name && 'OL' !== $tag_name ) ) {
			continue;
		}

		$list_depth = $processor->get_current_depth();
		if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
			return $html;
		}

		$li_count = 0;
		$scan_ok  = true;

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $list_depth ) {
				break;
			}

			if (
				'#tag' === $processor->get_token_type() &&
				! $processor->is_tag_closer() &&
				'LI' === $processor->get_tag() &&
				$list_depth + 1 === $processor->get_current_depth()
			) {
				$li_count++;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			$scan_ok = false;
		}

		if ( ! $scan_ok ) {
			$processor->release_bookmark( 'first-list-opener' );
			return $html;
		}

		if ( ! $processor->seek( 'first-list-opener' ) ) {
			$processor->release_bookmark( 'first-list-opener' );
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $li_count );
		$processor->release_bookmark( 'first-list-opener' );

		return $processor->get_updated_html();
	}

	return $html;
}
