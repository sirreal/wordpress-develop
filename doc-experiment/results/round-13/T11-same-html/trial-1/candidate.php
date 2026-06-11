<?php
/**
 * Compares two HTML fragments to determine if they represent the same parsed structure.
 *
 * @param string $a First HTML fragment.
 * @param string $b Second HTML fragment.
 * @return bool True if both fragments represent the same parsed structure, false otherwise.
 */
function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments.
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either normalization failed, return false.
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized forms.
	return $normalized_a === $normalized_b;
}
