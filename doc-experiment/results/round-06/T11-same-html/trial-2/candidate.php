<?php

function is_same_html( string $a, string $b ): bool {
	$normalized_a = WP_HTML_Processor::normalize( $a );
	$normalized_b = WP_HTML_Processor::normalize( $b );

	if ( $normalized_a === null || $normalized_b === null ) {
		return false;
	}

	return $normalized_a === $normalized_b;
}
