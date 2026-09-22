<?php

function normalize_or_placeholder( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return '<p>Unsupported HTML</p>';
	}

	$normalized = $processor->serialize();

	if ( null === $normalized || null !== $processor->get_last_error() ) {
		return '<p>Unsupported HTML</p>';
	}

	return $normalized;
}
