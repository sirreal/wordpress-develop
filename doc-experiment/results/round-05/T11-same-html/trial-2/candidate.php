<?php

function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments to their canonical form.
	// The normalize method handles all the differences mentioned:
	// - optional/implied closing tags
	// - attribute quoting style
	// - tag-name case
	// - equivalent character references
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );
	
	// If either normalization failed, return false (unable to fully parse/represent)
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}
	
	// Compare the normalized forms for structural equivalence
	return $normalized_a === $normalized_b;
}
