<?php

/**
 * Compare two HTML fragments to determine if they represent the same parsed structure.
 *
 * Two HTML fragments are considered the same if a browser would build the same DOM
 * from both. Differences in attribute quoting style, optional/implied closing tags,
 * tag-name case, and equivalent character references do not change the structure.
 * Differences in attribute order, element structure, attribute values, or text content
 * are significant.
 *
 * @param string $a First HTML fragment.
 * @param string $b Second HTML fragment.
 * @return bool True if the fragments represent the same parsed structure, false otherwise.
 */
function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments using the HTML Processor.
	// This handles optional tags, case differences, character references, etc.
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either fragment cannot be normalized (parsed), return false.
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized versions.
	return $normalized_a === $normalized_b;
}
