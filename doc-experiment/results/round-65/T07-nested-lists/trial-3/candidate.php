<?php
function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$ancestor_lists = 0;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name || ( 'UL' !== $tag_name && 'OL' !== $tag_name ) ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( $ancestor_lists > 0 ) {
				$ancestor_lists--;
			}
			continue;
		}

		if ( $ancestor_lists > 0 ) {
			$processor->add_class( 'nested-list' );
		}

		$ancestor_lists++;
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
