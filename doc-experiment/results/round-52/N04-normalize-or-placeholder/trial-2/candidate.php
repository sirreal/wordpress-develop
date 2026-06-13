<?php

function normalize_or_placeholder( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return '<p>Unsupported HTML</p>';
	}

	try {
		$normalized = WP_HTML_Processor::normalize( $html );
	} catch ( Throwable $e ) {
		return '<p>Unsupported HTML</p>';
	}

	if ( null === $normalized ) {
		return '<p>Unsupported HTML</p>';
	}

	return $normalized;
}
