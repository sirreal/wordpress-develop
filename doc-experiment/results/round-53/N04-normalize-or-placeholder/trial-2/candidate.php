<?php

declare(strict_types=1);

function normalize_or_placeholder( string $html ): string {
	$normalized = WP_HTML_Processor::normalize( $html );

	if ( null !== $normalized ) {
		return $normalized;
	}

	return '<p>Unsupported HTML</p>';
}
