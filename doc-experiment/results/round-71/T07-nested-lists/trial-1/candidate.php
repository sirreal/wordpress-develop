<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$list_stack = array();

	while ( $processor->next_token() ) {
		$tag_name = $processor->get_tag();

		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			array_pop( $list_stack );
			continue;
		}

		if ( ! empty( $list_stack ) ) {
			$processor->add_class( 'nested-list' );
		}

		$list_stack[] = $tag_name;
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
