<?php

declare( strict_types=1 );

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_depth = null;
	$count      = 0;
	$bookmark   = null;

	while ( $processor->next_tag() ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$bookmark   = 'first-list-opener';
		$list_depth = $processor->get_current_depth();
		if ( ! $processor->set_bookmark( $bookmark ) ) {
			return $html;
		}

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
			$processor->release_bookmark( $bookmark );
			return $html;
		}

		if ( ! $processor->seek( $bookmark ) ) {
			$processor->release_bookmark( $bookmark );
			return $html;
		}

		$processor->set_attribute( 'data-item-count', (string) $count );
		$updated_html = $processor->get_updated_html();
		$processor->release_bookmark( $bookmark );
		return $updated_html;
	}

	return $html;
}
