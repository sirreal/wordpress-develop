<?php

function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments to canonical forms
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either could not be normalized, they don't represent parseable HTML
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized forms
	return $normalized_a === $normalized_b;
}
