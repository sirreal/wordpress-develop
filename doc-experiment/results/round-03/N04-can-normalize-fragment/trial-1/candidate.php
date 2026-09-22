<?php

/**
 * Determines whether the HTML API can produce a fully-normalized serialization of a given HTML fragment.
 *
 * Some markup that is not yet supported by the HTML Processor (such as certain 
 * mis-nested formatting elements) cannot be normalized. This function checks if the 
 * given HTML fragment can be successfully normalized.
 *
 * @param string $html The HTML fragment to check for normalization capability.
 * @return bool True if the HTML can be normalized, false if unsupported markup is present.
 */
function can_normalize_fragment( string $html ): bool {
	// Use WP_HTML_Processor to attempt normalization
	$normalized = WP_HTML_Processor::normalize( $html );
	
	// If normalization succeeded (returned a string), return true
	// If normalization failed (returned null), return false
	return $normalized !== null;
}
