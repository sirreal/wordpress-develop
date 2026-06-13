<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$list_depth = 0;

	while ( $processor->next_token() ) {
		$tag_name = $processor->get_tag();

		if ( null === $tag_name || ( 'UL' !== $tag_name && 'OL' !== $tag_name ) ) {
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

	return $processor->get_updated_html();
}
