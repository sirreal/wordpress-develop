<?php

function is_same_html( string $a, string $b ): bool {
	// Create processors for both HTML fragments
	$processor_a = WP_HTML_Processor::create_fragment( $a );
	$processor_b = WP_HTML_Processor::create_fragment( $b );
	
	// If either processor failed to create, return false
	if ( null === $processor_a || null === $processor_b ) {
		return false;
	}
	
	// Serialize both to normalized form
	$normalized_a = $processor_a->serialize();
	$normalized_b = $processor_b->serialize();
	
	// If either serialization failed, return false
	if ( null === $normalized_a || null === $normalized_b ) {
		return false;
	}
	
	// Check if either processor encountered an error during parsing
	if ( null !== $processor_a->get_last_error() || null !== $processor_b->get_last_error() ) {
		return false;
	}
	
	// Compare the normalized HTML
	return $normalized_a === $normalized_b;
}
