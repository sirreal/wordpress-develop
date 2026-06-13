<?php
/**
 * Mark nested lists with a class.
 *
 * @param string $html HTML fragment.
 * @return string
 */
function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$open_lists = array();

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name || ( 'UL' !== $tag_name && 'OL' !== $tag_name ) ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( ! empty( $open_lists ) ) {
				array_pop( $open_lists );
			}
			continue;
		}

		if ( ! empty( $open_lists ) ) {
			$processor->add_class( 'nested-list' );
		}

		$open_lists[] = $tag_name;
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
