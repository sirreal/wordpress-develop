<?php

function is_same_html( string $a, string $b ): bool {
	// Use the HTML Processor to normalize both fragments.
	// This handles parsing, structure normalization, and encoding.
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either failed to normalize (unable to fully parse), return false.
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized results.
	return $normalized_a === $normalized_b;
}
