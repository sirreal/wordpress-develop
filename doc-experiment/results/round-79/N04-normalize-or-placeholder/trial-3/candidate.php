<?php

function normalize_or_placeholder( string $html ): string {
	$fallback = '<p>Unsupported HTML</p>';

	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $fallback;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $fallback;
	}

	$normalized = $processor->serialize();
	if ( null === $normalized ) {
		return $fallback;
	}

	if ( null !== $processor->get_last_error() ) {
		return $fallback;
	}

	return $normalized;
}
