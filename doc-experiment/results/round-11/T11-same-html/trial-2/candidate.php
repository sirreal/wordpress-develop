<?php

/**
 * Compare two HTML fragments for structural equivalence.
 *
 * Determines whether two HTML fragments represent the same parsed structure.
 * Differences in attribute quoting style, optional/implied closing tags,
 * tag-name case, and equivalent character references do not change the structure.
 * Differences in attribute order, element structure, attribute values, or text
 * content do change the structure.
 *
 * @param string $a First HTML fragment.
 * @param string $b Second HTML fragment.
 * @return bool Whether the fragments represent the same parsed structure.
 */
function is_same_html( string $a, string $b ): bool {
	// Normalize both fragments. If either fails to normalize, return false.
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either normalization failed, return false.
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized versions.
	return $normalized_a === $normalized_b;
}
