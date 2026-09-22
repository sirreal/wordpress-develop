<?php
/**
 * Compares two HTML fragments to determine if they represent the same parsed structure.
 *
 * @param string $a First HTML fragment to compare
 * @param string $b Second HTML fragment to compare
 * @return bool True if both fragments represent the same parsed structure, false otherwise
 */
function is_same_html( string $a, string $b ): bool {
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either fragment cannot be fully parsed/represented, return false
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized HTML strings for equality
	return $normalized_a === $normalized_b;
}
