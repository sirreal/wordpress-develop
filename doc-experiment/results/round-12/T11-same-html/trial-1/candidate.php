<?php

function is_same_html( string $a, string $b ): bool {
	// Use WP_HTML_Processor::normalize() to normalize both HTML fragments
	// This will handle optional/implied tags, case differences, attribute quoting, etc.
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either fails to normalize (returns null), they're not the same
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized versions
	return $normalized_a === $normalized_b;
}
