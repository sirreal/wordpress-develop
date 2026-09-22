<?php

function add_first_list_item_count( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_depth = null;
	$bookmark   = 'first-list-opener';

	while ( $processor->next_tag() ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$list_depth = $processor->get_current_depth();
		if ( ! $processor->set_bookmark( $bookmark ) ) {
			return $html;
		}
		break;
	}

	if ( null === $list_depth ) {
		return $html;
	}

	$item_count = 0;
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
			$item_count++;
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

	$processor->set_attribute( 'data-item-count', (string) $item_count );
	$processor->release_bookmark( $bookmark );

	return $processor->get_updated_html();
}
