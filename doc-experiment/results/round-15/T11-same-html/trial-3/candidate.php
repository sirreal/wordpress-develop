<?php

/**
 * Compare two HTML fragments to determine if they represent the same parsed structure.
 *
 * @param string $a First HTML fragment.
 * @param string $b Second HTML fragment.
 * @return bool True if both fragments parse to the same structure, false otherwise.
 */
function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments using WP_HTML_Processor::normalize()
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );
	
	// If either normalization fails (returns null), return false
	if ( $normalized_a === null || $normalized_b === null ) {
		return false;
	}
	
	// Compare the normalized versions
	return $normalized_a === $normalized_b;
}
