<?php

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$found_list = false;
	$container_depth = null;
	$bookmark_name = 'first-list-opener';
	$item_count = 0;

	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$found_list = true;
		$container_depth = $processor->get_current_depth();
		if ( ! $processor->set_bookmark( $bookmark_name ) ) {
			return $html;
		}
		break;
	}

	if ( ! $found_list || null === $container_depth ) {
		return $html;
	}

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $container_depth ) {
			break;
		}

		if (
			'#tag' === $processor->get_token_type() &&
			! $processor->is_tag_closer() &&
			'LI' === $processor->get_tag() &&
			$processor->get_current_depth() === $container_depth + 1
		) {
			$item_count++;
		}
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		$processor->release_bookmark( $bookmark_name );
		return $html;
	}

	if ( ! $processor->seek( $bookmark_name ) ) {
		$processor->release_bookmark( $bookmark_name );
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $item_count );
	$processor->release_bookmark( $bookmark_name );

	return $processor->get_updated_html();
}
