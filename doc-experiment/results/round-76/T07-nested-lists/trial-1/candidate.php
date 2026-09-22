<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$list_depths = array();

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( ! empty( $list_depths ) ) {
				array_pop( $list_depths );
			}
			continue;
		}

		if ( ! empty( $list_depths ) ) {
			$processor->add_class( 'nested-list' );
		}

		$list_depths[] = $processor->get_current_depth();
	}

	return null === $processor->get_last_error() ? $processor->get_updated_html() : $html;
}
