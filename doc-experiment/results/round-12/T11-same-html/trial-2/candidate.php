<?php

function is_same_html( string $a, string $b ): bool {
	// Attempt to normalize both HTML fragments
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either fails to normalize (parse error), return false
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized versions
	return $normalized_a === $normalized_b;
}
