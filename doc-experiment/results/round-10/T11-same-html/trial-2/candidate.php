<?php

function is_same_html( string $a, string $b ): bool {
	// Normalize both HTML fragments to their canonical form.
	// This handles differences in quoting, casing, optional closing tags, etc.
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );
	
	// If either fragment cannot be fully parsed/represented, return false.
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}
	
	// Compare the normalized forms for exact equality.
	return $normalized_a === $normalized_b;
}
