<?php

function is_same_html( string $a, string $b ): bool {
	// Create processors for both HTML fragments
	$processor_a = WP_HTML_Processor::create_fragment( $a );
	$processor_b = WP_HTML_Processor::create_fragment( $b );

	// If either processor fails to be created, return false
	if ( null === $processor_a || null === $processor_b ) {
		return false;
	}

	// Serialize both to normalized forms
	$normalized_a = $processor_a->serialize();
	$normalized_b = $processor_b->serialize();

	// If either serialization fails, return false
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}

	// Compare the normalized forms
	return $normalized_a === $normalized_b;
}
