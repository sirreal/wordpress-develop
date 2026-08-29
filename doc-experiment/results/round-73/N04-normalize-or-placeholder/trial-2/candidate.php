<?php

function normalize_or_placeholder( string $html ): string {
	$fallback = '<p>Unsupported HTML</p>';

	if ( ! class_exists( 'WP_HTML_Processor' ) || ! method_exists( 'WP_HTML_Processor', 'normalize' ) ) {
		return $fallback;
	}

	$normalized = WP_HTML_Processor::normalize( $html );

	return null === $normalized ? $fallback : $normalized;
}
