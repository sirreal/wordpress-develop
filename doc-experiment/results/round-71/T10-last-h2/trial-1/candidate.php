<?php

declare(strict_types=1);

function mark_last_h2( string $html ): string {
	$processor = WP_HTML_Processor::create_full_parser( $html );

	if ( null === $processor ) {
		$processor = WP_HTML_Processor::create_fragment( $html );
	}

	if ( null === $processor ) {
		return $html;
	}

	$found_last_h2 = false;

	while ( $processor->next_tag( 'H2' ) ) {
		$processor->set_bookmark( 'last-h2' );
		$found_last_h2 = true;
	}

	if ( ! $found_last_h2 || null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( ! $processor->seek( 'last-h2' ) ) {
		return $html;
	}

	$processor->add_class( 'final-section' );

	return $processor->get_updated_html();
}
