<?php

/**
 * Determines whether an HTML fragment can be normalized using the HTML API.
 *
 * @param string $html The HTML fragment to check.
 * @return bool True if the HTML can be normalized, false otherwise.
 */
function can_normalize_fragment( string $html ): bool {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return false;
	}
	
	$result = $processor->serialize();
	
	return null !== $result;
}
