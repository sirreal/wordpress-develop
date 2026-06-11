<?php

function is_same_html( string $a, string $b ): bool {
	$normalized_a = WP_HTML_Processor::normalize( $a );
	if ( null === $normalized_a ) {
		return false;
	}

	$normalized_b = WP_HTML_Processor::normalize( $b );
	if ( null === $normalized_b ) {
		return false;
	}

	return $normalized_a === $normalized_b;
}
