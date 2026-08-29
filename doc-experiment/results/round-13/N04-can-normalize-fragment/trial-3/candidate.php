<?php

function can_normalize_fragment( string $html ): bool {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return false;
	}
	
	// Attempt to serialize (normalize) the HTML
	$serialized = $processor->serialize();
	
	// If serialization succeeds, normalization is possible
	if ( null !== $serialized ) {
		return true;
	}
	
	// If serialization failed, check if it's due to unsupported markup
	// get_last_error() returns non-null when the processor encounters unsupported markup
	if ( null !== $processor->get_last_error() ) {
		return false;
	}
	
	// If serialization is null but no error was set, treat as failure
	return false;
}
