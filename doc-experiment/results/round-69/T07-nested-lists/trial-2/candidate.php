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
		if ( null === $tag_name ) {
			continue;
		}

		$is_list = 'UL' === $tag_name || 'OL' === $tag_name;
		if ( ! $is_list ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			array_pop( $list_depths );
			continue;
		}

		if ( ! empty( $list_depths ) ) {
			$processor->add_class( 'nested-list' );
		}

		$list_depths[] = $processor->get_current_depth();
	}

	return $processor->get_updated_html();
}
