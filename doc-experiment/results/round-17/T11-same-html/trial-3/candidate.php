<?php

function is_same_html( string $a, string $b ): bool {
	$processor_a = WP_HTML_Processor::create_fragment( $a );
	$processor_b = WP_HTML_Processor::create_fragment( $b );
	
	// If either input cannot be parsed, return false
	if ( null === $processor_a || null === $processor_b ) {
		return false;
	}
	
	// Serialize both fragments to get normalized HTML
	$serialized_a = $processor_a->serialize();
	$serialized_b = $processor_b->serialize();
	
	// If either serialization failed, return false
	if ( null === $serialized_a || null === $serialized_b ) {
		return false;
	}
	
	// Compare the normalized forms
	return $serialized_a === $serialized_b;
}
