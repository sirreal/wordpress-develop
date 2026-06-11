<?php

function can_normalize_fragment( string $html ): bool {
	// Use WP_HTML_Processor to attempt normalization
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If the processor could not be created, normalization is not possible
	if ( null === $processor ) {
		return false;
	}
	
	// Attempt to serialize the fragment
	$normalized = $processor->serialize();
	
	// If serialization returns null, the HTML contains unsupported markup
	if ( null === $normalized ) {
		return false;
	}
	
	// If serialization succeeded, normalization is possible
	return true;
}
