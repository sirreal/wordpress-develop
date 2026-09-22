<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_tag_name = null;
	$list_depth    = null;
	$count         = 0;
	$found_list    = false;
	$bookmark      = 'first-list-opener';

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( ! $found_list ) {
			if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
				continue;
			}

			$found_list    = true;
			$list_tag_name = $tag_name;
			$list_depth    = $processor->get_current_depth();
			$count         = 0;

			if ( ! $processor->set_bookmark( $bookmark ) ) {
				return $html;
			}

			continue;
		}

		if ( null === $list_depth ) {
			return $html;
		}

		if ( $processor->get_current_depth() < $list_depth ) {
			break;
		}

		if ( 'LI' === $tag_name && $processor->get_current_depth() === $list_depth + 1 ) {
			$count++;
		}
	}

	if ( ! $found_list || null === $list_tag_name || null === $list_depth ) {
		return $html;
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
	$processor->release_bookmark( $bookmark );

	return $processor->get_updated_html();
}
