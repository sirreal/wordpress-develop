<?php
function can_normalize_fragment( string $html ): bool {
	// Try to create an HTML processor for the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor couldn't be created, normalization is not possible
	if ( null === $processor ) {
		return false;
	}
	
	// Try to serialize/normalize the document
	// The serialize() method returns null if normalization fails
	$normalized = $processor->serialize();
	
	// Return true if normalization succeeded (non-null result)
	return null !== $normalized;
}
