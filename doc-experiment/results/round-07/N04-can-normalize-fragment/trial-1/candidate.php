<?php

/**
 * Determines whether the HTML API can produce a fully-normalized serialization of an HTML fragment.
 *
 * Some markup — for example certain misnested formatting elements — is not yet supported
 * by the HTML Processor, and normalization is not possible.
 *
 * @param string $html HTML fragment to check for normalization capability.
 * @return bool True if the HTML can be normalized, false if unsupported markup is encountered.
 */
function can_normalize_fragment( string $html ): bool {
	$normalized = WP_HTML_Processor::normalize( $html );
	return $normalized !== null;
}
