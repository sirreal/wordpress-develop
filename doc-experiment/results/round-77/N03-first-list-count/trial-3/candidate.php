<?php

declare(strict_types=1);

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_depth = null;
	$list_bookmark = null;
	$item_count = 0;

	while ( $processor->next_tag() ) {
		if ( null === $list_depth ) {
			$tag_name = $processor->get_tag();
			if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
				continue;
			}

			if ( $processor->is_tag_closer() ) {
				continue;
			}

			$list_depth = $processor->get_current_depth();
			if ( ! $processor->set_bookmark( 'first-list' ) ) {
				return $html;
			}

			$list_bookmark = 'first-list';
			continue;
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

	if ( null === $list_depth || null === $list_bookmark ) {
		return $html;
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		$processor->release_bookmark( $list_bookmark );
		return $html;
	}

	if ( ! $processor->seek( $list_bookmark ) ) {
		$processor->release_bookmark( $list_bookmark );
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );
	$updated_html = $processor->get_updated_html();
	$processor->release_bookmark( $list_bookmark );

	return $updated_html;
}
