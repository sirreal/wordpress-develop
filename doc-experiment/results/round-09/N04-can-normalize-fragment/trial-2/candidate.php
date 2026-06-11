<?php

function can_normalize_fragment( string $html ): bool {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If fragment processor creation fails, normalization is not possible
	if ( null === $processor ) {
		return false;
	}
	
	// Try to serialize/normalize the document
	$result = $processor->serialize();
	
	// If serialization returns null, the HTML couldn't be normalized
	if ( null === $result ) {
		return false;
	}
	
	// If serialization succeeded, normalization is possible
	return true;
}
