<?php

function normalize_or_placeholder( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) || ! method_exists( 'WP_HTML_Processor', 'create_fragment' ) ) {
		return '<p>Unsupported HTML</p>';
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '<p>Unsupported HTML</p>';
	}

	$normalized = $processor->serialize();
	if ( null === $normalized ) {
		return '<p>Unsupported HTML</p>';
	}

	return $normalized;
}
