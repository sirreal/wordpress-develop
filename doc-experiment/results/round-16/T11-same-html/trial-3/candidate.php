<?php

function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments. The normalize method handles:
	// - Parsing into a proper DOM structure
	// - Resolving optional/implied closing tags
	// - Normalizing tag names to lowercase
	// - Resolving attribute quote styles
	// - Decoding character references
	// - Detecting parse failures (returns null)
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either input cannot be fully parsed/represented, return false
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized forms
	return $normalized_a === $normalized_b;
}
