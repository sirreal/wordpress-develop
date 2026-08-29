<?php

declare(strict_types=1);

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$found_list = false;
	$list_depth = null;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$found_list = true;
		$list_depth = $processor->get_current_depth();

		if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
			return $html;
		}

		break;
	}

	if ( ! $found_list || null === $list_depth ) {
		return $html;
	}

	$item_count = 0;

	while ( $processor->next_token() ) {
		if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
			$processor->release_bookmark( 'first-list-opener' );
			return $html;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( $processor->get_current_depth() < $list_depth ) {
				break;
			}
			continue;
		}

		if ( 'LI' === $processor->get_tag() && $processor->get_current_depth() === $list_depth + 1 ) {
			$item_count++;
		}
	}

	if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
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
