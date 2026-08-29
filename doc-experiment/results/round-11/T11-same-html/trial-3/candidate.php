<?php

function is_same_html( string $a, string $b ): bool {
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	// If either input cannot be fully parsed/represented, return false.
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized HTML strings.
	return $normalized_a === $normalized_b;
}
