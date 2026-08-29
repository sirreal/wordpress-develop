<?php

function normalize_or_placeholder( string $html ): string {
	if ( class_exists( 'WP_HTML_Processor' ) && method_exists( 'WP_HTML_Processor', 'normalize' ) ) {
		$normalized = WP_HTML_Processor::normalize( $html );
		if ( null !== $normalized ) {
			return $normalized;
		}
	}

	return '<p>Unsupported HTML</p>';
}
