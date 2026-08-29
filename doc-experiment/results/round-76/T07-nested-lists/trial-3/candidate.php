<?php
function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$open_list_ancestors = 0;

	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();

		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( $open_list_ancestors > 0 ) {
				$open_list_ancestors--;
			}
			continue;
		}

		if ( $open_list_ancestors > 0 ) {
			$processor->add_class( 'nested-list' );
		}

		$open_list_ancestors++;
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( $processor->paused_at_incomplete_token() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
