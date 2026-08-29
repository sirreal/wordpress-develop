<?php

/**
 * Check whether HTML can be normalized.
 *
 * Given an HTML fragment (as found inside `<body>`), determine whether the
 * HTML API can produce a fully-normalized serialization of it.
 *
 * @param string $html HTML fragment to check for normalizability.
 * @return bool True if normalization succeeds, false otherwise.
 */
function can_normalize_fragment( string $html ): bool {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If the processor couldn't even be created, normalization is not possible.
	if ( null === $processor ) {
		return false;
	}
	
	// Try to serialize the HTML. If serialization returns null, the processor
	// encountered unsupported markup and normalization is not possible.
	$result = $processor->serialize();
	
	return null !== $result;
}
