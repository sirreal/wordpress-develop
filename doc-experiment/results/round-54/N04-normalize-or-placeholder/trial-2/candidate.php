<?php

function normalize_or_placeholder( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return '<p>Unsupported HTML</p>';
	}

	$normalized = WP_HTML_Processor::normalize( $html );

	if ( null === $normalized ) {
		return '<p>Unsupported HTML</p>';
	}

	return $normalized;
}
