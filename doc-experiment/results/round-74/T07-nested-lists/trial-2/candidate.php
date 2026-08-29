<?php

function mark_nested_lists( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$list_depth = 0;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( $list_depth > 0 ) {
				$list_depth--;
			}
			continue;
		}

		if ( $list_depth > 0 ) {
			$processor->add_class( 'nested-list' );
		}

		$list_depth++;
	}

	if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
