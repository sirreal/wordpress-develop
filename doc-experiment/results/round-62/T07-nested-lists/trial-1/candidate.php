<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$list_stack = array();

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag_name = $processor->get_tag();

		if ( null === $tag_name || ( 'UL' !== $tag_name && 'OL' !== $tag_name ) ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			$top = array_pop( $list_stack );

			if ( null !== $top && $top !== $tag_name ) {
				$list_stack[] = $top;
			}

			continue;
		}

		if ( ! empty( $list_stack ) ) {
			$processor->add_class( 'nested-list' );
		}

		$list_stack[] = $tag_name;
	}

	return $processor->get_updated_html();
}
