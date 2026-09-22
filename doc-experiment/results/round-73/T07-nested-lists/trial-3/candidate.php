<?php

function mark_nested_lists( string $html ): string {
	if ( '' === $html ) {
		return $html;
	}

	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	$list_stack = array();

	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		$tag_name = $processor->get_tag();

		if ( null === $tag_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( ! empty( $list_stack ) ) {
				$top = end( $list_stack );
				if ( $top === $tag_name ) {
					array_pop( $list_stack );
				}
			}
			continue;
		}

		if ( 'UL' === $tag_name || 'OL' === $tag_name ) {
			if ( ! empty( $list_stack ) ) {
				$processor->add_class( 'nested-list' );
			}

			$list_stack[] = $tag_name;
		}
	}

	return $processor->get_updated_html();
}
