<?php

function normalize_or_placeholder( string $html ): string {
	$placeholder = '<p>Unsupported HTML</p>';

	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $placeholder;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $placeholder;
	}

	$output = $processor->serialize();
	if ( null === $output || null !== $processor->get_last_error() ) {
		return $placeholder;
	}

	return $output;
}
