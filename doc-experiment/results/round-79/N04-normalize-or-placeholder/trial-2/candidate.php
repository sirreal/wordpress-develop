<?php

declare(strict_types=1);

function normalize_or_placeholder( string $html ): string {
	$placeholder = '<p>Unsupported HTML</p>';

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $placeholder;
	}

	$normalized = $processor->serialize();
	if ( null === $normalized || null !== $processor->get_last_error() ) {
		return $placeholder;
	}

	return $normalized;
}
