<?php

function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments using the WP_HTML_Processor.
	// This handles optional tags, attribute quoting, case normalization,
	// character references, etc.
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either input cannot be fully parsed/represented, return false.
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized forms for equality.
	return $normalized_a === $normalized_b;
}
