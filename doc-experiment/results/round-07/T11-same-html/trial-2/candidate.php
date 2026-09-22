<?php

function is_same_html( string $a, string $b ): bool {
	// Attempt to create processors for both HTML fragments.
	$processor_a = WP_HTML_Processor::create_fragment( $a );
	$processor_b = WP_HTML_Processor::create_fragment( $b );

	// Return false if either processor creation failed.
	if ( null === $processor_a || null === $processor_b ) {
		return false;
	}

	// Serialize both HTML fragments to normalized form.
	$normalized_a = $processor_a->serialize();
	$normalized_b = $processor_b->serialize();

	// Return false if either serialization failed.
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized HTML strings.
	return $normalized_a === $normalized_b;
}
