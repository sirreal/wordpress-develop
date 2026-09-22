<?php

function normalize_or_placeholder( string $html ): string {
	$fallback = '<p>Unsupported HTML</p>';

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $fallback;
	}

	$normalized = $processor->serialize();
	if ( null === $normalized ) {
		return $fallback;
	}

	return $normalized;
}
