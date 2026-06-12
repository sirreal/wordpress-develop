<?php

function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments to their canonical form.
	// If either cannot be fully parsed, normalize returns null.
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either fragment failed to parse completely, return false.
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized forms. They represent the same structure
	// if and only if they normalize to identical output.
	return $normalized_a === $normalized_b;
}
