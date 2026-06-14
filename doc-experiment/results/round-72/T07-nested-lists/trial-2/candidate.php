<?php

function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$open_lists = 0;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag || ( 'UL' !== $tag && 'OL' !== $tag ) ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( $open_lists > 0 ) {
				$open_lists--;
			}
			continue;
		}

		if ( $open_lists > 0 ) {
			$processor->add_class( 'nested-list' );
		}

		$open_lists++;
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
