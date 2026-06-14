<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$open_list_depth = 0;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( $open_list_depth > 0 ) {
				$open_list_depth--;
			}
			continue;
		}

		if ( $open_list_depth > 0 ) {
			$processor->add_class( 'nested-list' );
		}

		$open_list_depth++;
	}

	return $processor->get_updated_html();
}
