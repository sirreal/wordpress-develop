<?php

declare(strict_types=1);

function add_first_list_item_count( string $html ): string {
	if ( '' === $html ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$found_list = false;
	$depth      = null;
	$count      = 0;

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();

		if ( ! $found_list ) {
			if ( 'UL' !== $tag && 'OL' !== $tag ) {
				continue;
			}

			if ( $processor->is_tag_closer() ) {
				continue;
			}

			$found_list = true;
			$depth      = $processor->get_current_depth();

			if ( ! $processor->set_bookmark( 'first-list' ) ) {
				return $html;
			}

			break;
		}
	}

	if ( ! $found_list || null === $depth ) {
		return $html;
	}

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $depth ) {
			break;
		}

		if (
			'#tag' === $processor->get_token_type() &&
			! $processor->is_tag_closer() &&
			$processor->get_current_depth() === $depth + 1 &&
			'LI' === $processor->get_tag()
		) {
			$count++;
		}
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		$processor->release_bookmark( 'first-list' );
		return $html;
	}

	if ( ! $processor->seek( 'first-list' ) ) {
		$processor->release_bookmark( 'first-list' );
		return $html;
	}

	$processor->set_attribute( 'data-item-count', (string) $count );
	$processor->release_bookmark( 'first-list' );

	return $processor->get_updated_html();
}
