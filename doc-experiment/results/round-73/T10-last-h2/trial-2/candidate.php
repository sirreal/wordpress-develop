<?php

function mark_last_h2( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$found = false;

	while ( $processor->next_tag( 'h2' ) ) {
		$processor->set_bookmark( 'last-h2' );
		$found = true;
	}

	if ( ! $found ) {
		return $html;
	}

	if ( ! $processor->seek( 'last-h2' ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );
	$processor->release_bookmark( 'last-h2' );

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $processor->get_updated_html();
}
