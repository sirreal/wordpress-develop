<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$list_depth = null;
	$list_tag   = null;
	$count      = 0;
	$found_list  = false;
	$complete   = false;

	while ( $processor->next_token() ) {
		if ( null === $list_depth ) {
			if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
				continue;
			}

			$tag_name = $processor->get_tag();
			if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
				continue;
			}

			$list_depth = $processor->get_current_depth();
			$list_tag   = $tag_name;
			$found_list = true;
			continue;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( $processor->get_tag() === $list_tag && $processor->get_current_depth() === $list_depth - 1 ) {
				$complete = true;
				break;
			}

			continue;
		}

		if ( 'LI' === $processor->get_tag() && $processor->get_current_depth() === $list_depth + 1 ) {
			++$count;
		}
	}

	if ( ! $found_list || ! $complete || $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	$processor->seek( '1' );
	if ( ! $processor->next_tag( array( 'tag_name' => $list_tag, 'match_offset' => 1 ) ) ) {
		return $html;
	}

	if ( ! $processor->set_attribute( 'data-item-count', (string) $count ) ) {
		return $html;
	}

	return $processor->get_updated_html();
}
