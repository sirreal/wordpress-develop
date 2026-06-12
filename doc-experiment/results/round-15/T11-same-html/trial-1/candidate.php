<?php

function is_same_html( string $a, string $b ): bool {
	// Parse the first HTML fragment
	$processor_a = WP_HTML_Processor::create_fragment( $a );
	if ( null === $processor_a ) {
		return false;
	}

	// Parse the second HTML fragment
	$processor_b = WP_HTML_Processor::create_fragment( $b );
	if ( null === $processor_b ) {
		return false;
	}

	// Serialize both to get normalized representations
	$normalized_a = $processor_a->serialize();
	if ( null === $normalized_a ) {
		return false;
	}

	$normalized_b = $processor_b->serialize();
	if ( null === $normalized_b ) {
		return false;
	}

	// Compare the normalized versions
	return $normalized_a === $normalized_b;
}
