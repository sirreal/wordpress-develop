<?php
function can_normalize_fragment( string $html ): bool {
	// Try to create a fragment processor for the given HTML.
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If we can't even create a processor, normalization failed.
	if ( null === $processor ) {
		return false;
	}
	
	// Try to serialize/normalize the fragment.
	$normalized = $processor->serialize();
	
	// If serialize() returns null, the processor encountered unsupported markup.
	if ( null === $normalized ) {
		return false;
	}
	
	// If we got a normalized result, the HTML was processable.
	return true;
}
