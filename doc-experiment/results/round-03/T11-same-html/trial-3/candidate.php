<?php

/**
 * Compare two HTML fragments for structural equivalence.
 *
 * Determines whether two HTML fragments represent the same parsed structure,
 * accounting for differences in attribute quoting, optional/implied tags,
 * tag-name case, and equivalent character references.
 *
 * @param string $a First HTML fragment.
 * @param string $b Second HTML fragment.
 * @return bool True if both fragments parse to the same DOM structure, false otherwise.
 */
function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments to their canonical form
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either fragment cannot be fully parsed, return false
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized forms
	return $normalized_a === $normalized_b;
}
