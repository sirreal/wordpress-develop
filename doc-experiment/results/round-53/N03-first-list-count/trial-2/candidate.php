<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_tag = null;
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( 'UL' === $tag || 'OL' === $tag ) {
			$list_tag = $tag;
			break;
		}
	}

	if ( null === $list_tag ) {
		return $html;
	}

	$list_depth = $processor->get_current_depth();
	$bookmark   = 'first-list-opener';
	if ( ! $processor->set_bookmark( $bookmark ) ) {
		return $html;
	}

	$item_count = 0;
	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $list_depth ) {
			break;
		}

		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		if ( 'LI' === $processor->get_tag() && $processor->get_current_depth() === $list_depth + 1 ) {
			++$item_count;
		}
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( ! $processor->seek( $bookmark ) ) {
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );
	return $processor->get_updated_html();
}
