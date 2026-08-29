<?php

function mark_last_h2( string $html ): string {
	$processor = WP_HTML_Processor::create_full_parser( $html );
	if ( null === $processor ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
	}

	if ( null === $processor ) {
		return $html;
	}

	$found = false;
	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		if ( 'H2' !== $processor->get_tag() ) {
			continue;
		}

		$processor->set_bookmark( 'last-h2' );
		$found = true;
	}

	if ( ! $found || null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( ! $processor->seek( 'last-h2' ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );
	return $processor->get_updated_html();
}
