<?php

declare(strict_types=1);

function add_first_list_item_count( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$list_depth = $processor->get_current_depth();
		$count      = 0;
		$ok         = true;

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $list_depth ) {
				break;
			}

			if (
				'LI' === $processor->get_tag() &&
				! $processor->is_tag_closer() &&
				$processor->get_current_depth() === $list_depth + 1
			) {
				$count++;
			}
		}

		if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
			$ok = false;
		}

		if ( ! $ok ) {
			return $html;
		}

		if ( ! $processor->seek( 'list-opener' ) ) {
			$processor = WP_HTML_Processor::create_fragment( $html );
			if ( null === $processor ) {
				return $html;
			}
			if ( ! $processor->next_tag( array( 'tag_name' => $tag ) ) || $processor->is_tag_closer() ) {
				return $html;
			}
		}

		$processor->set_attribute( 'data-item-count', (string) $count );
		return $processor->get_updated_html();
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	return $html;
}
