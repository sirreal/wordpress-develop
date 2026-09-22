<?php

/**
 * Determine whether two HTML fragments represent the same parsed structure.
 *
 * Compares the normalized DOM structure of two HTML fragments, ignoring differences
 * in attribute quoting style, optional/implied closing tags, tag-name case, and
 * equivalent character references. Differences in attribute order, element structure,
 * attribute values, or text content are considered different.
 *
 * @param string $a First HTML fragment.
 * @param string $b Second HTML fragment.
 * @return bool True if both fragments represent the same parsed structure, false otherwise.
 */
function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments using the HTML Processor.
	// This handles optional tags, tag-name case, attribute quoting, character references, etc.
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either input cannot be fully parsed/represented, return false.
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized versions.
	return $normalized_a === $normalized_b;
}
