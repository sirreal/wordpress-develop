<?php

function can_normalize_fragment( string $html ): bool {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation failed, normalization is not possible
	if ( null === $processor ) {
		return false;
	}
	
	// Attempt to serialize (normalize) the HTML
	$serialized = $processor->serialize();
	
	// If serialization returns null, the HTML contains unsupported markup
	if ( null === $serialized ) {
		return false;
	}
	
	// If we have a serialized result, normalization succeeded
	return true;
}
